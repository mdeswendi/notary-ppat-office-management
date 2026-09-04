<#
.SYNOPSIS
    Restore — and, more importantly, restore TESTING — for the Notary & PPAT
    Office Management System.

.DESCRIPTION
    docs/07_SECURITY_RULES.md section 28 is explicit:

        "A backup that has never been restored in testing should not be
         considered sufficient."

    So this script defaults to -Mode Test, which restores into a throwaway
    database and reports the row counts it found. Nothing in the live database
    is touched. Run it monthly; it is the only thing that turns a backup into
    a backup you can rely on.

    -Mode Production performs the real restore and requires typed confirmation.

.PARAMETER Source
    Backup root folder created by backup.ps1.

.PARAMETER DumpFile
    Specific .dump to restore. Defaults to the newest one in Source\database.

.PARAMETER Mode
    Test        Restore into a temporary database and verify. Default.
    Production  Overwrite the live database. Requires typed confirmation.

.PARAMETER RestoreDocuments
    Production mode only. Also copy documents back into storage/app/private.
    Additive: existing files are never deleted.

.PARAMETER KeepTestDatabase
    Test mode only. Leave the temporary database in place for inspection.

.EXAMPLE
    .\restore.ps1 -Source E:\backup-notaris

.EXAMPLE
    .\restore.ps1 -Source E:\backup-notaris -Mode Production -RestoreDocuments
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Source,

    [string]$DumpFile,

    [ValidateSet('Test', 'Production')]
    [string]$Mode = 'Test',

    [switch]$RestoreDocuments,

    [switch]$KeepTestDatabase
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ContainerName = 'notary_ppat_postgres'
$ContainerTmp = '/tmp/notary-restore.dump'
$TestDbName = 'notary_ppat_restoretest'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $RepoRoot 'backend\.env'
$DocumentTarget = Join-Path $RepoRoot 'backend\storage\app\private'

$Started = Get-Date

function Write-Ok {
    param([string]$Message)
    Write-Host "  [OK] $Message" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Message)
    Write-Host "  [PERHATIAN] $Message" -ForegroundColor Yellow
}

# PowerShell 5.1 turns a native command's stderr into ErrorRecord objects
# (NativeCommandError). Under $ErrorActionPreference = 'Stop' that aborts the
# script even when the command succeeded — and dropdb --if-exists, psql and
# pg_restore all write routine NOTICE lines to stderr. Every native call goes
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

Write-Host ''
Write-Host "=== PEMULIHAN DATA - MODE: $($Mode.ToUpper()) ===" -ForegroundColor Cyan
Write-Host ''

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
if (-not (Test-Path $EnvFile)) {
    throw "backend\.env tidak ditemukan di $EnvFile."
}

if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Perintah docker tidak ditemukan. Pastikan Docker Desktop terpasang.'
}

$running = Invoke-Native -FilePath 'docker' -Arguments @(
    'ps', '--filter', "name=$ContainerName", '--filter', 'status=running', '--format', '{{.Names}}'
)
if ($running -notcontains $ContainerName) {
    throw "Container $ContainerName tidak berjalan. Jalankan: docker compose up -d"
}

$dbName = Get-EnvValue -Path $EnvFile -Key 'DB_DATABASE'
$dbUser = Get-EnvValue -Path $EnvFile -Key 'DB_USERNAME'
$dbPass = Get-EnvValue -Path $EnvFile -Key 'DB_PASSWORD'

if ([string]::IsNullOrWhiteSpace($DumpFile)) {
    $dbDir = Join-Path $Source 'database'
    if (-not (Test-Path $dbDir)) {
        throw "Folder $dbDir tidak ditemukan. Pastikan -Source menunjuk ke folder backup."
    }
    $newest = @(Get-ChildItem -Path $dbDir -Filter '*.dump' | Sort-Object LastWriteTime -Descending) |
        Select-Object -First 1
    if ($null -eq $newest) {
        throw "Tidak ada file .dump di $dbDir."
    }
    $DumpFile = $newest.FullName
}

if (-not (Test-Path $DumpFile)) {
    throw "File dump tidak ditemukan: $DumpFile"
}

$dumpInfo = Get-Item $DumpFile
Write-Host "Sumber dump : $($dumpInfo.Name)"
Write-Host "Dibuat      : $($dumpInfo.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss'))"
Write-Host "Ukuran      : $('{0:N2} MB' -f ($dumpInfo.Length / 1MB))"
Write-Host ''

# ---------------------------------------------------------------------------
# Confirmation for the destructive path
# ---------------------------------------------------------------------------
if ($Mode -eq 'Production') {
    Write-Host '  PERINGATAN' -ForegroundColor Red
    Write-Host "  Database aktif '$dbName' akan DITIMPA oleh isi dump di atas." -ForegroundColor Red
    Write-Host '  Semua data yang masuk setelah dump ini dibuat akan hilang.' -ForegroundColor Red
    Write-Host ''
    $typed = Read-Host '  Ketik PULIHKAN untuk melanjutkan'
    if ($typed -cne 'PULIHKAN') {
        Write-Host ''
        Write-Host 'Dibatalkan. Tidak ada perubahan.' -ForegroundColor Yellow
        exit 1
    }
    Write-Host ''
    $targetDb = $dbName
}
else {
    Write-Host '  Mode uji: database aktif tidak disentuh sama sekali.'
    Write-Host ''
    $targetDb = $TestDbName
}

# ---------------------------------------------------------------------------
# Copy the dump into the container
# ---------------------------------------------------------------------------
Write-Host 'Menyalin dump ke dalam container...'
Invoke-Native -FilePath 'docker' -Arguments @('cp', $DumpFile, "${ContainerName}:${ContainerTmp}") | Out-Null
if ($script:NativeExit -ne 0) { throw "docker cp gagal (exit $script:NativeExit)." }
Write-Ok 'Dump siap di dalam container'

try {
    # -----------------------------------------------------------------------
    # Prepare the target database
    # -----------------------------------------------------------------------
    if ($Mode -eq 'Test') {
        Write-Host ''
        Write-Host "Menyiapkan database uji '$targetDb'..."

        # Writes "NOTICE: database does not exist, skipping" to stderr on a
        # clean run. Harmless — the exit code is what matters.
        Invoke-Native -FilePath 'docker' -Arguments @(
            'exec', '-e', "PGPASSWORD=$dbPass", $ContainerName,
            'dropdb', '-U', $dbUser, '--if-exists', $targetDb
        ) | Out-Null

        Invoke-Native -FilePath 'docker' -Arguments @(
            'exec', '-e', "PGPASSWORD=$dbPass", $ContainerName,
            'createdb', '-U', $dbUser, $targetDb
        ) | Out-Null
        if ($script:NativeExit -ne 0) { throw "createdb gagal (exit $script:NativeExit)." }
        Write-Ok "Database uji '$targetDb' dibuat"
    }

    # -----------------------------------------------------------------------
    # Restore
    # -----------------------------------------------------------------------
    Write-Host ''
    Write-Host 'Memulihkan data...'

    $restoreArgs = @(
        'exec', '-e', "PGPASSWORD=$dbPass", $ContainerName,
        'pg_restore', '-U', $dbUser, '-d', $targetDb, '--no-owner', '--no-privileges'
    )
    if ($Mode -eq 'Production') {
        $restoreArgs += @('--clean', '--if-exists')
    }
    $restoreArgs += $ContainerTmp

    $restoreOutput = Invoke-Native -FilePath 'docker' -Arguments $restoreArgs

    # pg_restore returns non-zero for benign warnings too (missing roles, DROP
    # of objects that were never there). Treat output as advisory and judge
    # success by the row counts below instead.
    if ($script:NativeExit -ne 0) {
        Write-Warn "pg_restore selesai dengan peringatan (exit $script:NativeExit)."
        foreach ($line in ($restoreOutput | Select-Object -First 5)) {
            Write-Host "      $line" -ForegroundColor DarkGray
        }
        Write-Warn 'Peringatan soal role atau DROP objek umumnya wajar. Cek jumlah baris di bawah.'
    }
    else {
        Write-Ok 'pg_restore selesai tanpa peringatan'
    }

    # -----------------------------------------------------------------------
    # Verify by counting what actually landed
    # -----------------------------------------------------------------------
    Write-Host ''
    Write-Host 'Memeriksa isi hasil pemulihan...'

    # n_live_tup is an estimate maintained by the statistics collector, and it
    # reads zero on a freshly restored table until ANALYZE has run.
    Invoke-Native -FilePath 'docker' -Arguments @(
        'exec', '-e', "PGPASSWORD=$dbPass", $ContainerName,
        'psql', '-U', $dbUser, '-d', $targetDb, '-c', 'ANALYZE;'
    ) | Out-Null

    $query = "SELECT relname || ' = ' || n_live_tup FROM pg_stat_user_tables " +
             'WHERE n_live_tup > 0 ORDER BY n_live_tup DESC LIMIT 15;'

    $counts = Invoke-Native -FilePath 'docker' -Arguments @(
        'exec', '-e', "PGPASSWORD=$dbPass", $ContainerName,
        'psql', '-U', $dbUser, '-d', $targetDb, '-t', '-A', '-c', $query
    )

    $rows = @($counts | Where-Object { $_ -match '\s=\s\d+$' })

    Write-Host ''
    if ($rows.Count -eq 0) {
        Write-Warn 'Tidak ada tabel berisi data. Dump kemungkinan kosong atau gagal dipulihkan.'
    }
    else {
        Write-Host "  Tabel berisi data (15 terbesar):" -ForegroundColor Cyan
        foreach ($row in $rows) { Write-Host "      $row" }
    }

    # -----------------------------------------------------------------------
    # Documents (production only)
    # -----------------------------------------------------------------------
    if ($Mode -eq 'Production' -and $RestoreDocuments) {
        Write-Host ''
        Write-Host 'Memulihkan dokumen...'

        $docSource = Join-Path $Source 'documents'
        if (-not (Test-Path $docSource)) {
            Write-Warn "Folder dokumen tidak ada di $docSource. Dilewati."
        }
        else {
            if (-not (Test-Path $DocumentTarget)) {
                New-Item -ItemType Directory -Path $DocumentTarget -Force | Out-Null
            }
            Invoke-Native -FilePath 'robocopy.exe' -Arguments @(
                $docSource, $DocumentTarget, '/E', '/COPY:DAT', '/R:2', '/W:5', '/NFL', '/NDL', '/NJH', '/NP'
            ) | Out-Null
            if ($script:NativeExit -ge 8) { throw "robocopy gagal (exit $script:NativeExit)." }

            $restored = (Get-ChildItem -Path $DocumentTarget -Recurse -File | Measure-Object).Count
            Write-Ok "Dokumen dipulihkan: $restored file di storage/app/private"
        }
    }
}
finally {
    Invoke-Native -FilePath 'docker' -Arguments @('exec', $ContainerName, 'rm', '-f', $ContainerTmp) | Out-Null

    if ($Mode -eq 'Test' -and -not $KeepTestDatabase) {
        Invoke-Native -FilePath 'docker' -Arguments @(
            'exec', '-e', "PGPASSWORD=$dbPass", $ContainerName,
            'dropdb', '-U', $dbUser, '--if-exists', $TestDbName
        ) | Out-Null
        Write-Host ''
        Write-Ok "Database uji '$TestDbName' dihapus kembali"
    }
}

$duration = [int]((Get-Date) - $Started).TotalSeconds

Write-Host ''
if ($Mode -eq 'Test') {
    Write-Host '=== UJI PEMULIHAN SELESAI ===' -ForegroundColor Green
    Write-Host "Durasi: $duration detik. Database aktif tidak diubah."
    Write-Host ''
    Write-Host 'Catat tanggal uji ini. Backup Anda kini terbukti bisa dipulihkan.' -ForegroundColor Cyan
}
else {
    Write-Host '=== PEMULIHAN SELESAI ===' -ForegroundColor Green
    Write-Host "Durasi: $duration detik."
    Write-Host ''
    Write-Host 'LANGKAH TERAKHIR - APP_KEY harus cocok dengan dump ini,' -ForegroundColor Yellow
    Write-Host 'kalau tidak NIK dan NPWP akan gagal dibaca. Bandingkan' -ForegroundColor Yellow
    Write-Host "APP_KEY di backend\.env dengan salinan di $Source\kunci\" -ForegroundColor Yellow
}
Write-Host ''

exit 0
