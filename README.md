# Notary & PPAT Office Management System

Sistem manajemen kantor Notaris & PPAT (Pejabat Pembuat Akta Tanah).

> **Status: M0.1 — Repository Foundation.**
> Dokumentasi dan kerangka repositori sudah ada.
> Frontend (Next.js) dan backend (Laravel) **belum diinisialisasi**.
> Belum ada dependensi terpasang, belum ada migrasi, belum ada modul bisnis.

## Struktur Repositori

```text
notary-ppat-office-management/
├── frontend/            # Aplikasi Next.js — belum diinisialisasi
├── backend/             # API Laravel — belum diinisialisasi
├── docs/                # Spesifikasi kanonik
├── infra/               # Konfigurasi infrastruktur
├── scripts/             # Skrip bantu
├── .github/             # Reserved untuk CI/CD — belum ada workflow
├── .editorconfig
├── .gitattributes
├── .gitignore
├── CLAUDE.md            # Konstitusi coding untuk asisten AI
├── README.md
└── docker-compose.yml   # Infrastruktur development lokal saja
```

## Technology Baseline

| Lapisan | Baseline |
| --- | --- |
| Frontend | Next.js 16.x, TypeScript, Tailwind CSS, shadcn/ui, next-intl, TanStack Query |
| Runtime | Node.js >= 20.9, pnpm |
| Backend | Laravel 13.x, PHP >= 8.3, Sanctum, Spatie Laravel Permission |
| Database | PostgreSQL 18.x (minor release terbaru yang didukung) |
| Cache/Queue | Redis 8.x |
| Testing | Pest (backend), lint/typecheck/build (frontend) |

Versi di atas berasal dari spesifikasi dan **belum diverifikasi** terhadap rilis yang
tersedia. Periksa sebelum instalasi.

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
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | Riwayat perubahan dokumentasi |

Dokumen 08 dan 09 **tidak boleh** dipakai untuk mengimplementasi alur kerja hukum sebelum
divalidasi oleh sumber domain.

## Menjalankan Infrastruktur Lokal

Hanya PostgreSQL dan Redis. Frontend dan backend berjalan native, bukan dalam container.

```bash
docker compose up -d
docker compose ps
```

Port yang dipakai (di-bind ke `127.0.0.1` saja):

```text
PostgreSQL   5432
Redis        6379
```

Kata sandi PostgreSQL memakai fallback khusus development. Untuk menggantinya, buat berkas
`.env` lokal di root berisi `POSTGRES_PASSWORD=...`. Berkas itu tidak ikut ter-commit.

Menghentikan:

```bash
docker compose down
```

Menghapus sekaligus volume data:

```bash
docker compose down -v
```

## Langkah Berikutnya

Urutan pengerjaan mengikuti [docs/10_M0_FOUNDATION.md](docs/10_M0_FOUNDATION.md) §66:

```text
M0.1   Repository & environment      ← sedang berjalan
M0.2   Frontend initialization
M0.3   Backend initialization
M0.4   PostgreSQL & Redis
M0.5   i18n
M0.6   Design system
M0.7   Authentication
M0.8   Authorization foundation
M0.9   Application shell
M0.10  Tests & documentation
```

Perintah build, run, dan test akan ditambahkan setelah M0.2 dan M0.3.

## Lisensi

Belum ditentukan.
