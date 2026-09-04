<#
.SYNOPSIS
    Daily backup for the Notary & PPAT Office Management System.

.DESCRIPTION
    Backs up the three things that cannot be recreated from the repository:

      1. The PostgreSQL database  (dumped from the Docker container)
      2. storage/app/private      (legal documents: KTP, Minuta Akta, Warkah)
      3. backend/.env             (holds APP_KEY, which decrypts NIK / NPWP)

    All three are required together. A database backup without APP_KEY leaves
    every NIK and NPWP permanently unreadable, because those columns use the
    'encrypted' cast (app/Models/Individual.php, app/Models/Company.php).

    Design notes:

      - Database dumps are timestamped and rotated. They are small (well under
        1 GB) so many generations can be kept cheaply.

      - Documents are mirrored ADDITIVELY, never with /MIR. Document versions
        are immutable by design (CLAUDE.md section 19) so nothing legitimately
        disappears from the source. Additive copying means an accidental
        deletion on the office PC is never propagated into the backup.

      - The dump is written inside the container and pulled out with docker cp.
        Piping binary output through PowerShell redirection corrupts it.

.PARAMETER Destination
    Backup root folder. Must be on an encrypted volume (BitLocker To Go) on a
    DIFFERENT physical disk from the office PC's system drive.

.PARAMETER KeepDays
    Delete database dumps older than this many days. Default 30.

.PARAMETER KeepMinimum
    Never drop below this many dumps regardless of age. Default 7.

.PARAMETER SkipDocuments
    Back up the database only. For quick ad-hoc runs, not for scheduled use.

.EXAMPLE
    .\backup.ps1 -Destination E:\backup-notaris

.EXAMPLE
    .\backup.ps1 -Destination E:\backup-notaris -KeepDays 90
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Destination,

    [int]$KeepDays = 30,

    [int]$KeepMinimum = 7,

    [switch]$SkipDocuments
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ContainerName = 'notary_ppat_postgres'
$ContainerTmp = '/tmp/notary-backup.dump'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $RepoRoot 'backend\.env'
$DocumentSource = Join-Path $RepoRoot 'backend\storage\app\private'

$Started = Get-Date
$Stamp = $Started.ToString('yyyy-MM-dd_HHmmss')

function Write-Step {
    param([string]$Message)
    Write-Host "  $Message"
}

function Write-Ok {
    param([string]$Message)
    Write-Host "  [OK] $Message" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Message)
    Write-Host "  [PERHATIAN] $Message" -ForegroundColor Yellow
}

function Get-EnvValue {
    param([string]$Path, [string]$Key)

    $match = Select-String -Path $Path -Pattern "^\s*$Key\s*=" -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -eq $match) { return $null }

    $value = $match.Line -replace "^\s*$Key\s*=\s*", ''
    $value = $value.Trim()
    if ($value.Length -ge 2 -and $value.StartsWith('"') -and $value.EndsWith('"')) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    return $value
}

# PowerShell 5.1 turns a native command's stderr into ErrorRecord objects
# (NativeCommandError). Under $ErrorActionPreference = 'Stop' that aborts the
# script even when the command succeeded — and pg_dump, psql and dropdb all
# write routine NOTICE and WARNING lines to stderr. Every native call goes
# through here, and success is judged by the exit code alone.
$script:NativeExit = 0
function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][AllowEmptyCollection()][string[]]$Arguments
    )

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $FilePath @Arguments 2>&1
        $script:NativeExit = $LASTEXITCODE
        return @($output | ForEach-Object { "$_" })
    }
    finally {
        $ErrorActionPreference = $previous
    }
}

function Format-Size {
    param([long]$Bytes)
    if ($Bytes -ge 1GB) { return '{0:N2} GB' -f ($Bytes / 1GB) }
    if ($Bytes -ge 1MB) { return '{0:N2} MB' -f ($Bytes / 1MB) }
    if ($Bytes -ge 1KB) { return '{0:N2} KB' -f ($Bytes / 1KB) }
    return "$Bytes B"
}

Write-Host ''
Write-Host '=== BACKUP NOTARY & PPAT OFFICE ===' -ForegroundColor Cyan
Write-Host "Waktu mulai : $($Started.ToString('yyyy-MM-dd HH:mm:ss'))"
Write-Host "Tujuan      : $Destination"
Write-Host ''

# ---------------------------------------------------------------------------
# 1. Preflight
# ---------------------------------------------------------------------------
Write-Host 'Langkah 1/6 - Pemeriksaan awal' -ForegroundColor Cyan

if (-not (Test-Path $EnvFile)) {
    throw "backend\.env tidak ditemukan di $EnvFile. Backup dibatalkan."
}

if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Perintah docker tidak ditemukan. Pastikan Docker Desktop terpasang.'
}

Invoke-Native -FilePath 'docker' -Arguments @('version', '--format', '{{.Server.Version}}') | Out-Null
if ($script:NativeExit -ne 0) {
    throw 'Docker tidak berjalan. Jalankan Docker Desktop lalu ulangi.'
}
Write-Ok 'Docker berjalan'

$running = Invoke-Native -FilePath 'docker' -Arguments @(
    'ps', '--filter', "name=$ContainerName", '--filter', 'status=running', '--format', '{{.Names}}'
)
if ($running -notcontains $ContainerName) {
    throw "Container $ContainerName tidak berjalan. Jalankan: docker compose up -d"
}
Write-Ok "Container $ContainerName aktif"

$dbName = Get-EnvValue -Path $EnvFile -Key 'DB_DATABASE'
$dbUser = Get-EnvValue -Path $EnvFile -Key 'DB_USERNAME'
$dbPass = Get-EnvValue -Path $EnvFile -Key 'DB_PASSWORD'
$appKey = Get-EnvValue -Path $EnvFile -Key 'APP_KEY'

if ([string]::IsNullOrWhiteSpace($dbName) -or [string]::IsNullOrWhiteSpace($dbUser)) {
    throw 'DB_DATABASE atau DB_USERNAME tidak terbaca dari backend\.env.'
}
if ([string]::IsNullOrWhiteSpace($appKey)) {
    throw 'APP_KEY kosong di backend\.env. Tanpa ini NIK dan NPWP tidak akan bisa dipulihkan.'
}
Write-Ok "Konfigurasi terbaca (database: $dbName)"

if (-not (Test-Path $Destination)) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    Write-Ok "Folder tujuan dibuat: $Destination"
}

$destRoot = [System.IO.Path]::GetPathRoot((Resolve-Path $Destination).Path)
$repoRootDrive = [System.IO.Path]::GetPathRoot($RepoRoot)
if ($destRoot -eq $repoRootDrive) {
    Write-Warn "Tujuan backup berada di drive yang sama ($destRoot) dengan data aslinya."
    Write-Warn 'Satu disk rusak berarti data dan backup hilang bersamaan. Gunakan disk terpisah.'
}

$destDrive = Get-PSDrive -Name $destRoot.Substring(0, 1) -ErrorAction SilentlyContinue
if ($null -ne $destDrive -and $null -ne $destDrive.Free) {
    Write-Ok "Ruang kosong di tujuan: $(Format-Size $destDrive.Free)"
    if ($destDrive.Free -lt 2GB) {
        Write-Warn 'Ruang kosong di bawah 2 GB. Kosongkan disk sebelum backup berikutnya.'
    }
}

$dbDir = Join-Path $Destination 'database'
$docDir = Join-Path $Destination 'documents'
$keyDir = Join-Path $Destination 'kunci'
foreach ($dir in @($dbDir, $docDir, $keyDir)) {
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
}

# ---------------------------------------------------------------------------
# 2. Dump the database inside the container
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host 'Langkah 2/6 - Membuat dump database' -ForegroundColor Cyan

$dumpOutput = Invoke-Native -FilePath 'docker' -Arguments @(
    'exec', '-e', "PGPASSWORD=$dbPass", $ContainerName,
    'pg_dump', '-U', $dbUser, '-d', $dbName, '--format=custom', '--compress=6', "--file=$ContainerTmp"
)
if ($script:NativeExit -ne 0) {
    foreach ($line in $dumpOutput) { Write-Host "      $line" -ForegroundColor DarkGray }
    throw "pg_dump gagal (exit $script:NativeExit). Backup dibatalkan."
}
Write-Ok 'Dump dibuat di dalam container'

# ---------------------------------------------------------------------------
# 3. Verify the dump is readable BEFORE trusting it
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host 'Langkah 3/6 - Memverifikasi dump' -ForegroundColor Cyan

$tocLines = Invoke-Native -FilePath 'docker' -Arguments @(
    'exec', $ContainerName, 'pg_restore', '--list', $ContainerTmp
)
if ($script:NativeExit -ne 0) {
    Invoke-Native -FilePath 'docker' -Arguments @('exec', $ContainerName, 'rm', '-f', $ContainerTmp) | Out-Null
    throw 'Dump tidak bisa dibaca kembali oleh pg_restore. File rusak, backup dibatalkan.'
}

$tableCount = ($tocLines | Where-Object { $_ -match '\sTABLE DATA\s' } | Measure-Object).Count
if ($tableCount -lt 1) {
    Invoke-Native -FilePath 'docker' -Arguments @('exec', $ContainerName, 'rm', '-f', $ContainerTmp) | Out-Null
    throw 'Dump tidak berisi satu pun tabel. Backup dibatalkan.'
}
Write-Ok "Dump valid dan berisi $tableCount tabel"

# ---------------------------------------------------------------------------
# 4. Pull the dump out of the container
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host 'Langkah 4/6 - Menyalin dump ke tujuan' -ForegroundColor Cyan

$dumpFile = Join-Path $dbDir "$Stamp.dump"
Invoke-Native -FilePath 'docker' -Arguments @('cp', "${ContainerName}:${ContainerTmp}", $dumpFile) | Out-Null
if ($script:NativeExit -ne 0) {
    throw "docker cp gagal (exit $script:NativeExit)."
}
Invoke-Native -FilePath 'docker' -Arguments @('exec', $ContainerName, 'rm', '-f', $ContainerTmp) | Out-Null

$dumpInfo = Get-Item $dumpFile
if ($dumpInfo.Length -eq 0) {
    Remove-Item $dumpFile -Force
    throw 'File dump kosong setelah disalin. Backup dibatalkan.'
}
$dumpHash = (Get-FileHash -Path $dumpFile -Algorithm SHA256).Hash
Write-Ok "Dump tersimpan: $(Split-Path -Leaf $dumpFile) ($(Format-Size $dumpInfo.Length))"

# ---------------------------------------------------------------------------
# 5. Mirror documents additively, and store the key material
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host 'Langkah 5/6 - Menyalin dokumen dan kunci' -ForegroundColor Cyan

$docCopied = 0
$docTotal = 0
if ($SkipDocuments) {
    Write-Warn 'Dokumen dilewati (-SkipDocuments). Jangan pakai opsi ini untuk backup terjadwal.'
}
elseif (-not (Test-Path $DocumentSource)) {
    Write-Warn "Folder dokumen belum ada di $DocumentSource. Dilewati."
}
else {
    # /E copies subdirectories including empty ones; no /MIR, so the backup is
    # never pruned to match the source. /XO skips older source files.
    Invoke-Native -FilePath 'robocopy.exe' -Arguments @(
        $DocumentSource, $docDir, '/E', '/COPY:DAT', '/R:2', '/W:5', '/NFL', '/NDL', '/NJH', '/NP'
    ) | Out-Null
    # robocopy uses exit codes 0-7 for success (0 = nothing new, 1 = copied,
    # 2 = extra files present). Only 8 and above are real failures.
    if ($script:NativeExit -ge 8) {
        throw "robocopy gagal menyalin dokumen (exit $script:NativeExit)."
    }

    $docTotal = (Get-ChildItem -Path $docDir -Recurse -File -ErrorAction SilentlyContinue | Measure-Object).Count
    $srcTotal = (Get-ChildItem -Path $DocumentSource -Recurse -File -ErrorAction SilentlyContinue | Measure-Object).Count
    $docCopied = $docTotal

    if ($docTotal -lt $srcTotal) {
        throw "Dokumen tidak lengkap: sumber $srcTotal file, backup $docTotal file."
    }
    Write-Ok "Dokumen tersalin: $docTotal file (sumber: $srcTotal)"
}

# APP_KEY and .env live in the backup so a restore is a single step. This is
# why the destination disk MUST be encrypted: it holds both the encrypted NIK
# and the key that opens it.
Copy-Item -Path $EnvFile -Destination (Join-Path $keyDir 'env-backend.txt') -Force

$keyNotice = @"
KUNCI ENKRIPSI - JANGAN SAMPAI HILANG
=====================================

APP_KEY : $appKey

Kunci ini membuka kolom NIK, NPWP, dan NPWP perusahaan yang disimpan
terenkripsi di database. Tanpa kunci ini, backup database TIDAK ADA GUNANYA
untuk data tersebut - angkanya tidak akan pernah bisa dibaca lagi.

YANG HARUS DILAKUKAN SEKALI SAJA:
  1. Cetak halaman ini.
  2. Simpan di lemari berkas, bersama dokumen asli kantor.
  3. Jangan simpan salinan cetak ini di dekat komputer.

Disk backup ini WAJIB terenkripsi (BitLocker To Go), karena berisi
data terenkripsi sekaligus kuncinya.

Dihasilkan otomatis: $($Started.ToString('yyyy-MM-dd HH:mm:ss'))
"@
$keyNoticeFile = Join-Path $keyDir 'CETAK-DAN-SIMPAN-DI-LEMARI.txt'
Set-Content -Path $keyNoticeFile -Value $keyNotice -Encoding UTF8
Write-Ok 'APP_KEY dan .env tersimpan di folder kunci'

# ---------------------------------------------------------------------------
# 6. Rotate old dumps and write the log
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host 'Langkah 6/6 - Rotasi dan pencatatan' -ForegroundColor Cyan

$removed = 0
# @() forces an array: a single match would otherwise be a bare FileInfo, which
# has no .Count under Set-StrictMode.
$dumps = @(Get-ChildItem -Path $dbDir -Filter '*.dump' | Sort-Object LastWriteTime -Descending)
if ($dumps.Count -gt $KeepMinimum) {
    $cutoff = (Get-Date).AddDays(-$KeepDays)
    foreach ($old in ($dumps | Select-Object -Skip $KeepMinimum)) {
        if ($old.LastWriteTime -lt $cutoff) {
            Remove-Item $old.FullName -Force
            $removed++
        }
    }
}
$remaining = (Get-ChildItem -Path $dbDir -Filter '*.dump' | Measure-Object).Count
Write-Ok "Dump tersimpan: $remaining generasi (dihapus: $removed)"

$duration = [int]((Get-Date) - $Started).TotalSeconds
$logLine = '{0} | dump={1} | ukuran={2} | tabel={3} | dokumen={4} | sha256={5} | durasi={6}s' -f `
    $Started.ToString('yyyy-MM-dd HH:mm:ss'),
    (Split-Path -Leaf $dumpFile),
    (Format-Size $dumpInfo.Length),
    $tableCount,
    $docCopied,
    $dumpHash.Substring(0, 16),
    $duration
Add-Content -Path (Join-Path $Destination 'backup-log.txt') -Value $logLine -Encoding UTF8

Write-Host ''
Write-Host '=== BACKUP SELESAI ===' -ForegroundColor Green
Write-Host "Durasi   : $duration detik"
Write-Host "Database : $(Split-Path -Leaf $dumpFile) ($(Format-Size $dumpInfo.Length), $tableCount tabel)"
Write-Host "Dokumen  : $docCopied file"
Write-Host "Log      : $(Join-Path $Destination 'backup-log.txt')"
Write-Host ''
Write-Host 'Backup yang belum pernah diuji pulihkan belum bisa dianggap cukup.' -ForegroundColor Yellow
Write-Host 'Jalankan restore.ps1 sekali sebulan untuk mengujinya.' -ForegroundColor Yellow
Write-Host ''

exit 0
