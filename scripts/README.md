# Backup & Pemulihan Data

Panduan operasional untuk PC kantor. Ditulis dalam bahasa Indonesia karena yang
menjalankan adalah staf kantor, bukan pengembang.

---

## 1. Apa yang di-backup, dan kenapa ketiganya wajib

| Yang disalin | Sumber | Kalau hilang |
|---|---|---|
| **Database** | container `notary_ppat_postgres` | Seluruh data klien, matter, akta, dan audit log hilang |
| **Dokumen** | `backend/storage/app/private` | Scan KTP, sertifikat, Minuta Akta, Warkah hilang |
| **`APP_KEY`** | `backend/.env` | **NIK dan NPWP tidak akan pernah terbaca lagi**, walau database utuh |

Poin ketiga sering terlewat dan akibatnya permanen. Kolom `nik`, `npwp`, dan
`tax_id` disimpan terenkripsi ([`Individual.php`](../backend/app/Models/Individual.php),
[`Company.php`](../backend/app/Models/Company.php)). Kuncinya adalah `APP_KEY`.
Database tanpa kunci itu = brankas tanpa kombinasi.

---

## 2. Persiapan — cukup sekali

### a. Siapkan disk backup terenkripsi

Gunakan hard disk eksternal **khusus untuk backup**, jangan dipakai hal lain.

1. Colok disk, buka **File Explorer**
2. Klik kanan drive → **Turn on BitLocker**
3. Pilih password (bukan smart card), simpan recovery key di tempat aman
4. Tunggu enkripsi selesai

Ini bukan opsional. Disk backup berisi data terenkripsi **sekaligus kuncinya** —
kalau disk itu hilang tanpa BitLocker, seluruh data klien terbuka.

### b. Aktifkan BitLocker di PC kantor juga

**Settings → Privacy & security → Device encryption** (atau BitLocker di Windows Pro).

### c. Cetak APP_KEY dan simpan di lemari

Setelah backup pertama, buka:

```
<disk-backup>\kunci\CETAK-DAN-SIMPAN-DI-LEMARI.txt
```

Cetak, lalu simpan di lemari berkas bersama dokumen asli. Ini salinan terakhir
kalau PC dan disk backup sama-sama hilang. Jangan simpan cetakan ini di dekat
komputer.

---

## 3. Menjalankan backup manual

Buka **PowerShell**, lalu:

```powershell
cd D:\Projects\notary-ppat-office-management\scripts
.\backup.ps1 -Destination E:\backup-notaris
```

Ganti `E:` dengan huruf drive disk backup Anda.

Kalau muncul pesan tentang *execution policy*:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\backup.ps1 -Destination E:\backup-notaris
```

Backup akan **berhenti dan membatalkan diri** kalau ada yang tidak beres —
container mati, dump tidak bisa dibaca ulang, atau jumlah dokumen tidak cocok.
Tidak ada backup setengah jadi yang diam-diam dianggap berhasil.

### Opsi

| Opsi | Arti |
|---|---|
| `-KeepDays 90` | Simpan dump sampai 90 hari (default 30) |
| `-KeepMinimum 14` | Minimal 14 generasi disimpan berapa pun umurnya (default 7) |
| `-SkipDocuments` | Database saja — untuk uji cepat, **jangan** untuk backup terjadwal |

---

## 4. Menjadwalkan otomatis setiap hari

Jalankan **sekali** di PowerShell **sebagai Administrator**, sesuaikan path dan drive:

```powershell
schtasks /Create /TN "Backup Notaris" /SC DAILY /ST 18:00 /RL HIGHEST /F /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"D:\Projects\notary-ppat-office-management\scripts\backup.ps1\" -Destination E:\backup-notaris"
```

Pilih jam **setelah kantor tutup**, dan pastikan:

- PC kantor menyala pada jam itu
- Docker Desktop berjalan (set **Start Docker Desktop when you log in**)
- Disk backup tercolok dan sudah di-unlock BitLocker

Periksa hasilnya berkala di `E:\backup-notaris\backup-log.txt`. Satu baris per
backup, berisi tanggal, ukuran, jumlah tabel, jumlah dokumen, dan checksum.

---

## 5. Uji pemulihan — sebulan sekali, wajib

[`docs/07_SECURITY_RULES.md`](../docs/07_SECURITY_RULES.md) menyatakan:

> *"A backup that has never been restored in testing should not be considered sufficient."*

Backup yang tidak pernah diuji itu belum tentu backup. Ujilah:

```powershell
.\restore.ps1 -Source E:\backup-notaris
```

Mode ini **aman**: memulihkan ke database sementara, menghitung isinya,
menampilkan hasilnya, lalu menghapus database sementara itu. Database aktif
tidak disentuh sama sekali.

Yang harus Anda lihat: daftar tabel dengan jumlah baris yang masuk akal.
Kalau kosong atau jauh lebih sedikit dari seharusnya, backup Anda bermasalah —
selesaikan sekarang, jangan tunggu musibah.

Catat tanggal setiap uji di buku atau spreadsheet.

---

## 6. Kalau musibah benar-benar terjadi

1. **Jangan panik, dan jangan hapus apa pun.**
2. Pastikan Docker berjalan dan container hidup: `docker compose up -d`
3. Pulihkan database dan dokumen:

   ```powershell
   .\restore.ps1 -Source E:\backup-notaris -Mode Production -RestoreDocuments
   ```

   Script akan meminta Anda mengetik `PULIHKAN` sebagai konfirmasi.

4. **Samakan `APP_KEY`.** Bandingkan `APP_KEY` di `backend\.env` dengan salinan
   di `E:\backup-notaris\kunci\`. Kalau berbeda, salin yang dari backup —
   kalau tidak, NIK dan NPWP akan gagal dibaca.

5. Jalankan migrasi kalau kode aplikasi lebih baru dari dump:

   ```powershell
   cd ..\backend
   php artisan migrate
   ```

---

## 7. Batasan yang harus disadari

Backup ini melindungi dari **disk rusak, file terhapus, dan salah input**.
Backup ini **tidak** melindungi dari:

- **Kantor kebakaran, kebanjiran, atau kemalingan** — PC dan disk backup ada di
  ruangan yang sama, hilang bersamaan. Bawa pulang salinan disk kedua secara
  berkala, atau simpan salinan di lokasi lain.
- **Kesalahan yang baru ketahuan berbulan-bulan kemudian** — dump lama sudah
  dirotasi habis. Naikkan `-KeepDays` kalau ini jadi kekhawatiran.
- **Disk backup yang tidak pernah dicolok** — backup terjadwal akan gagal diam-diam
  kalau drive-nya tidak ada. Periksa `backup-log.txt` setiap minggu.

Berkas asli di lemari tetap jadi jaring pengaman terakhir kantor. Sistem ini
lapisan kerja, bukan pengganti arsip fisik.
