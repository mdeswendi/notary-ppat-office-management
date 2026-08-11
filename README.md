# Notary & PPAT Office Management System

Sistem manajemen kantor Notaris & PPAT (Pejabat Pembuat Akta Tanah). Aplikasi bilingual
(Indonesia / Inggris) dengan frontend Next.js dan backend Laravel.

> **Status: M0 — Foundation.**
> Fondasi sudah lengkap: autentikasi sesi, fondasi otorisasi, routing bilingual, dan
> application shell. **Belum ada modul bisnis** — Party, Project, Matter, Notary, PPAT,
> Warkah, Billing, dan Reports dikerjakan mulai M1.

## Arsitektur

```text
Browser
   │
   ▼
Next.js (localhost:3000)
   │  REST + cookie sesi (Sanctum SPA)
   ▼
Laravel (localhost:8000)
   │
   ├── PostgreSQL 18   127.0.0.1:5432   (Docker)
   └── Redis 8         127.0.0.1:6379   (Docker)
```

Backend Laravel adalah sumber kebenaran untuk autentikasi, otorisasi, validasi, dan aturan
bisnis. Pemeriksaan di frontend hanya untuk pengalaman pengguna.

## Struktur Repositori

```text
notary-ppat-office-management/
├── frontend/            # Aplikasi Next.js
├── backend/             # API Laravel
├── docs/                # Spesifikasi kanonik
├── infra/               # Konfigurasi infrastruktur
├── scripts/             # Skrip bantu
├── .github/workflows/   # CI quality gate
├── .editorconfig
├── .gitattributes
├── .gitignore
├── CLAUDE.md            # Konstitusi coding untuk asisten AI
├── README.md
└── docker-compose.yml   # Infrastruktur development lokal saja
```

## Prasyarat

| Kebutuhan | Versi | Catatan |
| --- | --- | --- |
| Node.js | 24.x LTS | Minimum Next.js adalah >= 20.9; lihat D-013 |
| pnpm | 11.x | Diaktifkan lewat Corepack yang menyertai Node 24 |
| PHP | >= 8.3 | Workstation saat ini memakai 8.4; jangan pakai fitur khusus 8.4 |
| Composer | 2.x | |
| Docker Desktop | terbaru | Hanya untuk PostgreSQL dan Redis |

Frontend dan backend berjalan **native**, bukan di dalam container.

## URL Development

```text
Frontend     http://localhost:3000
Backend      http://localhost:8000
PostgreSQL   127.0.0.1:5432
Redis        127.0.0.1:6379
```

Gunakan `localhost` secara konsisten di browser. Cookie sesi terikat pada nama host, jadi
mencampur `localhost` dan `127.0.0.1` dalam satu alur login akan memutus sesi.

## Penyiapan dari Clone Baru

Jalankan ketiga langkah berikut berurutan. Perintah diberi label direktori asalnya.

### 1. Infrastruktur — dari root repositori

```bash
docker compose up -d
docker compose ps
```

Compose hanya menjalankan PostgreSQL dan Redis. Keduanya di-bind ke `127.0.0.1`, jadi tidak
terekspos ke jaringan.

Kata sandi PostgreSQL memakai fallback khusus development yang tertulis langsung di
`docker-compose.yml`. Untuk memakai kata sandi sendiri, buat `.env` di root berisi
`POSTGRES_PASSWORD=...` sebelum container pertama kali dibuat, lalu pakai nilai yang sama di
langkah berikutnya. Berkas itu tidak ikut ter-commit.

Menghentikan container tanpa menghapus data:

```bash
docker compose down
```

> **Peringatan:** `docker compose down -v` ikut menghapus named volume
> `notary_ppat_postgres_data` dan `notary_ppat_redis_data` beserta seluruh isinya. Jangan
> dipakai sebagai perintah shutdown harian.

### 2. Backend — dari `backend/`

```bash
cd backend
composer install
```

Buat `.env` lokal dari contoh yang tersedia:

```bash
# PowerShell
Copy-Item .env.example .env

# bash
cp .env.example .env
```

```bash
php artisan key:generate
```

Lalu buka `backend/.env` dan isi `DB_PASSWORD` dengan kata sandi PostgreSQL yang dipakai
container pada langkah 1 — nilai fallback development-nya terlihat di `docker-compose.yml`.

`backend/.env` sudah masuk `.gitignore`. **Jangan** meng-commit berkas itu, dan jangan
membagikan `APP_KEY`.

Jalankan migrasi:

```bash
php artisan migrate
```

> `composer install` **tidak** membuat `.env` dan tidak membuat `APP_KEY`. Hook itu hanya
> berjalan saat repositori pertama kali dibuat dengan `create-project`. Karena itu kedua
> langkah di atas wajib dilakukan manual pada setiap clone baru. Lihat D-019.

### 3. Frontend — dari `frontend/`

```bash
cd frontend
corepack enable
pnpm install --frozen-lockfile
```

Berkas environment **tidak wajib**. Aplikasi sudah memakai default `http://localhost:8000`
untuk API dan `http://localhost:3000` untuk dirinya sendiri. Salin `.env.example` menjadi
`.env.local` hanya bila salah satu URL itu perlu diubah.

Seluruh variabel berawalan `NEXT_PUBLIC_` terlihat di browser. Jangan pernah menaruh kata
sandi database, `APP_KEY`, atau rahasia lain di sana.

## Menjalankan Aplikasi

Dua terminal:

```bash
# terminal 1 — dari backend/
php artisan serve --host=127.0.0.1 --port=8000

# terminal 2 — dari frontend/
pnpm dev
```

Buka <http://localhost:3000>. Pengguna anonim diarahkan ke `/id/login`.

Belum ada pengguna bawaan dan **tidak ada seeder akun**. Siapkan deployment baru dengan:

```bash
# dari backend/
php artisan app:bootstrap
```

Perintah itu membuat satu Organization, satu Office, sembilan role bawaan, seluruh 171
permission kanonik, dan administrator pertama. Perintah berjalan interaktif dan menanyakan
kata sandi tanpa menampilkannya — kata sandi tidak pernah diterima sebagai argumen baris
perintah (D-060).

Perintah ini **sekali jalan**. Deployment yang sudah terisi ditolak, bukan ditimpa
(D-058), jadi menjalankannya lagi tidak akan mengembalikan role yang sudah sengaja dihapus.

Bila hanya perlu menyinkronkan katalog permission tanpa mem-bootstrap apa pun:

```bash
php artisan permissions:sync
```

Perintah itu aditif dan idempoten — menambah permission kanonik yang belum ada, tidak
pernah menghapus, dan aman dijalankan berulang.

## Perintah Mutu

Backend, dari `backend/`:

```bash
vendor/bin/pint --test     # format
php artisan test           # Pest, SQLite in-memory, tidak butuh Docker
php artisan migrate:status
```

Frontend, dari `frontend/`:

```bash
pnpm install --frozen-lockfile
pnpm format:check
pnpm lint
pnpm typecheck
pnpm build
```

Perintah yang sama dijalankan otomatis oleh CI pada setiap push dan pull request — lihat
`.github/workflows/quality.yml`.

## Rute dan Bahasa

Rute selalu diawali locale, dan nama rute tetap dalam bahasa Inggris di kedua locale:

```text
/id/login       /en/login
/id/dashboard   /en/dashboard
```

`/` selalu mengarah ke `/id`. Locale ditentukan **hanya** oleh URL — tanpa deteksi
`accept-language` dan tanpa cookie locale. Lihat D-020.

## Dokumentasi

Baca `CLAUDE.md` dan berkas relevan di `docs/` sebelum menulis kode.

| Berkas | Isi |
| --- | --- |
| [docs/00_PROJECT_OVERVIEW.md](docs/00_PROJECT_OVERVIEW.md) | Ruang lingkup produk, hierarki bisnis, milestone |
| [docs/01_ARCHITECTURE.md](docs/01_ARCHITECTURE.md) | Arsitektur sistem dan struktur repositori |
| [docs/02_MENU_AND_PERMISSIONS.md](docs/02_MENU_AND_PERMISSIONS.md) | Struktur menu, role, matriks permission |
| [docs/03_DATABASE_ERD.md](docs/03_DATABASE_ERD.md) | Skema database dan ERD |
| [docs/04_UI_DESIGN_SYSTEM.md](docs/04_UI_DESIGN_SYSTEM.md) | Design system dan wireframe |
| [docs/05_I18N_LEGAL_TERMINOLOGY.md](docs/05_I18N_LEGAL_TERMINOLOGY.md) | Aturan bilingual dan terminologi hukum |
| [docs/06_API_CONVENTIONS.md](docs/06_API_CONVENTIONS.md) | Konvensi REST API |
| [docs/07_SECURITY_RULES.md](docs/07_SECURITY_RULES.md) | Aturan keamanan |
| [docs/08_NOTARY_WORKFLOW.md](docs/08_NOTARY_WORKFLOW.md) | **DRAFT — DOMAIN VALIDATION REQUIRED** |
| [docs/09_PPAT_WORKFLOW.md](docs/09_PPAT_WORKFLOW.md) | **DRAFT — DOMAIN VALIDATION REQUIRED** |
| [docs/10_M0_FOUNDATION.md](docs/10_M0_FOUNDATION.md) | Spesifikasi implementasi M0 |
| [docs/11_LEGAL_REFERENCES.md](docs/11_LEGAL_REFERENCES.md) | Register rujukan hukum |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Keputusan kanonik dan aturan presedensi |
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | Riwayat perubahan |

Dokumen 08 dan 09 **tidak boleh** dipakai untuk mengimplementasi alur kerja hukum sebelum
divalidasi oleh sumber domain.

## Milestone

```text
M0   Foundation                  selesai
M1   Identity & Access Management
M2   Party / Individual / Company
M3   Project Management
M4   Matter & Workflow Engine
M5   Documents & Tasks
M6   Notary Module
M7   PPAT Module
M8   Dashboard, Billing & Reports
```

Urutan ini mengikuti [docs/10_M0_FOUNDATION.md](docs/10_M0_FOUNDATION.md) dan `CLAUDE.md` §2.

## Lisensi

Belum ditentukan.
