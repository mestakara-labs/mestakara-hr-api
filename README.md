# HR Mestakara — API Backend

API RESTful untuk **HR Dashboard Mestakara** (PTPN I Regional 2 — Unit Agrowisata).
Dibuat dengan Laravel 13 dan dipakai oleh front end Next.js di `../web`.

Rancangan database mengikuti **ERD Backend HR Mestakara v1.5** (`../web/docs/erd/`). Bentuk data
mengikuti kontrak front end (`../web/src/types/api/*.ts`) dan aturan bisnis di data mock
(`../web/src/lib/mock/**`), sehingga front end cukup mengganti `AUTH_MOCK=false`.

Kode ditulis dengan tiga prinsip **SANGAT MUDAH**: mudah dipahami alurnya, mudah dibaca, dan mudah
diubah secara manual. Nama kode memakai Bahasa Inggris, sedangkan komentar dan pesan memakai Bahasa Indonesia.

---

## Daftar Isi

1. [Teknologi](#1-teknologi)
2. [Cara Menjalankan](#2-cara-menjalankan)
3. [Menyambungkan Front End](#3-menyambungkan-front-end)
4. [Dokumentasi API (Swagger / OpenAPI)](#4-dokumentasi-api-swagger--openapi)
5. [Aturan Umum API](#5-aturan-umum-api)
6. [Daftar Endpoint](#6-daftar-endpoint)
7. [Struktur Kode](#7-struktur-kode)
8. [Alur Fitur Utama](#8-alur-fitur-utama)
9. [Jadwal Otomatis (Cron)](#9-jadwal-otomatis-cron)
10. [Penyimpanan File](#10-penyimpanan-file)
11. [Pengujian & Format Kode](#11-pengujian--format-kode)
12. [Cara Menambah Fitur Baru](#12-cara-menambah-fitur-baru)
13. [Keputusan Desain & Perbedaan dengan ERD](#13-keputusan-desain--perbedaan-dengan-erd)
14. [Pekerjaan Berikutnya](#14-pekerjaan-berikutnya)

---

## 1. Teknologi

| Komponen | Keterangan |
|---|---|
| PHP | 8.4 (dibutuhkan untuk membaca form `PUT` multipart) |
| Laravel | 13 |
| Database | MySQL 8 (pengujian otomatis memakai SQLite in-memory) |
| Login | Laravel Sanctum: token Bearer, berlaku 8 jam |
| Dokumentasi API | [Scramble](https://scramble.dedoc.co): dibuat otomatis dari kode, tampil di `/docs/api` |
| Id data | UUID v7 (trait `HasUuids`): urut waktu, aman dipakai di URL |

---

## 2. Cara Menjalankan

### Prasyarat
- PHP 8.4 dengan ekstensi `pdo_mysql`, `fileinfo`, `mbstring`
- Composer 2
- MySQL 8 (mis. dari Laragon) di `127.0.0.1:3306`, user `root` tanpa kata sandi

### Langkah pertama kali

1. Pasang dependensi:

   ```bash
   composer install
   ```

2. Salin pengaturan lalu buat kunci aplikasi:

   ```bash
   cp .env.example .env
   ```

   ```bash
   php artisan key:generate
   ```

3. Buat database `mestakara_hr` (sekali saja):

   ```bash
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS mestakara_hr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```

4. Buat tabel dan akun login awal:

   ```bash
   php artisan migrate --seed
   ```

5. Jalankan server API di port 8000:

   ```bash
   php artisan serve --port=8000
   ```

API tersedia di `http://127.0.0.1:8000/api/v1`, dan dokumentasinya di `http://127.0.0.1:8000/docs/api`.

### Akun login awal (dari `database/seeders/UserSeeder.php`)

| NIK | Email | Kata sandi |
|---|---|---|
| 10928374 | admin.sdm@agrowisata.test | Mestakara#2026 |
| 10928375 | staf.sdm@agrowisata.test | Mestakara#2026 |

> **Ganti kata sandi ini sebelum aplikasi dipakai di server produksi.**

### Mengosongkan database (kembali ke kondisi awal)

```bash
php artisan migrate:fresh --seed
```

---

## 3. Menyambungkan Front End

Di `../web/.env.local`:

```
BACKEND_URL=http://127.0.0.1:8000/api/v1
AUTH_MOCK=false
```

Front end memanggil API lewat route handler Next.js (`/api/...`). Route handler itu meneruskan
permintaan ke backend dengan token dari cookie `session_token`. Karena itu, **alamat file** yang dikirim
API (mis. `photo_url`) diawali `/api/...` (diatur lewat `HR_FILE_URL_PREFIX`).

Penyesuaian kecil di front end:
- `src/types/api/user.ts`: `UserResource.id` sekarang **UUID (string)**, bukan `number`.
- Foto karyawan dibuka lewat `/api/employees/{id}/files?type=foto`. Route ini sudah diteruskan ke backend
  saat `AUTH_MOCK=false`.

---

## 4. Dokumentasi API (Swagger / OpenAPI)

Buka **`http://127.0.0.1:8000/docs/api`** (hanya saat `APP_ENV=local`).

- Semua endpoint, isian, aturan validasi, dan bentuk respons **dibuat otomatis dari kode**:
  route, FormRequest, dan Resource. Dokumentasinya tidak perlu ditulis terpisah.
- Judul & keterangan endpoint diambil dari PHPDoc di atas method controller.
- Cara mencoba: panggil `POST /login`, salin `data.token`, lalu tempel di **Auth** pada panel "Try It".
- Ekspor berkas OpenAPI (untuk Postman, dsb.):

  ```bash
  php artisan scramble:export
  ```

Pengaturannya ada di `config/scramble.php`, dan penyesuaian bentuk respons error di `app/Support/ApiDocumentation.php`.

---

## 5. Aturan Umum API

### Alamat & login
- Semua alamat diawali **`/api/v1`**.
- Semua endpoint (kecuali `POST /login`) wajib mengirim header `Authorization: Bearer <token>`.

### Bentuk respons (selalu sama)

```json
{ "success": true, "message": "Bidang berhasil ditambahkan.", "data": { } }
```

Kalau isian tidak valid (422), pesan per isian ada di `errors`:

```json
{
  "success": false,
  "message": "Data yang dikirim belum benar.",
  "data": null,
  "errors": { "name": ["Nama Bidang wajib diisi"], "items.baru-1.position_id": ["Pilih Jabatan"] }
}
```

Pesan validasi **sama persis** dengan pesan di front end (mock), jadi front end bisa langsung menampilkannya.
Error per baris daftar memakai nama isian `items.<id baris>.<isian>`, `members.<urutan>.<isian>`, atau `slots.<urutan>.<isian>`.

| Status | Arti |
|---|---|
| 200 / 201 | Berhasil (201 = data baru dibuat) |
| 401 | Belum login / token tidak berlaku |
| 403 | Tidak diizinkan (mis. akun dinonaktifkan) |
| 404 | Data atau alamat tidak ditemukan |
| 409 | Ditolak karena keadaan data (mis. data masih dipakai, usulan bukan Draf) |
| 422 | Isian tidak valid atau aturan bisnis dilanggar |

Semua error diubah ke bentuk di atas di `bootstrap/app.php`.

### Form dengan file
Form yang berisi file dikirim sebagai `multipart/form-data`, termasuk untuk `PUT`. Daftar di dalam form
(mis. `items`, `educations`) dikirim sebagai **teks JSON**. File lama dipertahankan dengan penanda `keep_*`
(mis. `keep_photo=1`). Baris baru dari front end memakai id sementara `baru-...`.

### Id
Semua id berupa **UUID v7**, mis. `01a13ebb-b3a4-7321-b228-8e5d5c27fed8`.

---

## 6. Daftar Endpoint

Rincian isian dan respons ada di `/docs/api`. Daftar lengkap bisa dilihat dengan `php artisan route:list --path=api`.

| Modul | Endpoint |
|---|---|
| **Login** | `POST /login`, `GET /me`, `POST /logout` |
| **Organisasi** | `GET /organization` · CRUD `/divisions`, `/sub-units`, `/placement-categories`, `/placement-objects`, `/positions` |
| **Data Karyawan** | CRUD `/employees` (multipart) · `GET /employees/{id}/files?type=foto\|ijazah` · `GET /employees/{id}/status-history` |
| **Jabatan Karyawan** | `GET /position-assignments` (`?employee_id=`) · `GET /position-assignments/{id}` · `/{id}/decree-file` · `/{id}/supervisor-history` · `POST /position-assignments/supervisors` (Ubah Atasan) |
| **Mutasi & Penempatan** | CRUD `/transfers` (multipart) · `POST /transfers/preview` · `/{id}/activate` · `/{id}/cancel` · `/{id}/basis-document` · `/{id}/items/{itemId}/excerpt` · `GET /{id}/documents?type=sk\|petikan\|scan\|scan-petikan\|dasar` |
| **Penambahan Karyawan** | CRUD `/employee-activations` (multipart) · `/{id}/documents` · `/{id}/cancel` · `GET /{id}/files` |
| **Penonaktifan Karyawan** | CRUD `/employee-terminations` (multipart) · `POST /employee-terminations/preview` · `/{id}/documents` · `/{id}/cancel` · `GET /{id}/files` |
| **Shift & Jadwal** | CRUD `/shifts`, `/holidays`, `/work-patterns`, `/work-groups` · `GET /work-groups/{id}/members` · `PUT /work-groups/{id}/members/{memberId}` · `GET /work-groups/{id}/projection` |
| **Jadwal Kerja** | `/schedule-periods` (daftar, buat, detail, hapus) · `/{id}/publish` · `/{id}/lock` · `/{id}/regenerate/preview` · `/{id}/regenerate` · `PUT /schedules/{id}` · `GET /schedules/{id}/changes` |

CRUD = `GET` daftar, `POST` tambah, `GET /{id}` detail, `PUT /{id}` ubah, `DELETE /{id}` hapus.

---

## 7. Struktur Kode

```
app/
├─ Enums/                     Nilai tetap (status, jenis) — teks SAMA dengan front end & ERD Bab 4
├─ Http/
│  ├─ Controllers/Api/V1/<Modul>/   Controller TIPIS: terima request -> panggil service -> kirim resource
│  ├─ Requests/<Modul>/             Validasi isian + pesan Bahasa Indonesia (FormRequest)
│  └─ Resources/<Modul>/            Bentuk JSON respons = types/api/*.ts di front end
├─ Models/                    Model Eloquent: relasi + perhitungan kecil (mis. Employee::missingFields)
│  └─ Concerns/               Trait bersama (HasStatusDocument: kolom dokumen + penandatangan)
├─ Services/                  ATURAN BISNIS & alur yang mengubah data (di dalam transaksi)
│  ├─ Transfer/               Mutasi: TransferService, TransferRules, SupervisorPlanner, TransferApplier, ...
│  ├─ Activation/             Penambahan: ActivationService, ActivationRules
│  ├─ Termination/            Penonaktifan: TerminationService, TerminationRules, TerminationImpact
│  ├─ Schedule/               Jadwal: Rotation (rumus), ScheduleGenerator, ScheduleRegenerator, SchedulePeriodService
│  ├─ EmployeeService.php           simpan karyawan + pendidikan + batih + file
│  ├─ EmployeeStatusService.php     status kepegawaian dari riwayat + masa kerja
│  ├─ SupervisorService.php         SATU-SATUNYA tempat mengganti atasan (selalu tercatat di riwayat)
│  ├─ FileStorage.php               SATU-SATUNYA tempat menyimpan / mengirim file
│  ├─ OpenProposals.php             cek karyawan sedang di usulan lain yang belum selesai
│  └─ StatusDocumentService.php     simpan dokumen dasar (jenis, nomor, tanggal, file, penandatangan)
├─ Support/                   Alat bantu kecil tanpa database
│  ├─ ApiResponse.php               bentuk respons { success, message, data, errors? }
│  ├─ StatusDocument.php            aturan dokumen dasar & penandatangan
│  ├─ PlacementRules.php            aturan rencana penempatan (Mutasi & Penambahan)
│  ├─ UploadRule.php                aturan format & ukuran file
│  ├─ ExistingIds.php, SimplePdf.php, ApiDocumentation.php
config/hr.php                 Pengaturan khusus HR (nama unit, disk file, awalan alamat file)
routes/api.php                SEMUA route API, dikelompokkan per modul
routes/console.php            Perintah artisan & jadwal cron
database/migrations/          Satu file per kelompok tabel ERD (urut sesuai modul)
tests/Feature/<Modul>/        Pengujian otomatis per modul
```

### Alur satu permintaan

```
Front end ──> routes/api.php ──> FormRequest (validasi) ──> Controller ──> Service (aturan & simpan)
                                                                 │                 │
                                                                 │                 └─> Model ──> MySQL
                                                                 └──> Resource (bentuk JSON) ──> ApiResponse
```

**Cara membaca kode sebuah fitur:** mulai dari `routes/api.php`, lalu buka controller-nya. Setiap method
controller punya PHPDoc dan hanya beberapa baris. Dari sana ikuti FormRequest (isian), Service (aturan),
dan Resource (bentuk respons).

---

## 8. Alur Fitur Utama

### Status kepegawaian (`EmployeeStatusService`)
Status **Belum Aktif / Aktif / Nonaktif** dihitung dari **riwayat**: item usulan Penambahan dan Penonaktifan
yang Berlaku, dengan TMT ≤ hari ini. Kolom `employees.active_status` hanya salinan untuk filter cepat. Salinan
ini diperbarui saat usulan berlaku/dibatalkan, dan setiap hari oleh cron. Akun login ikut dinonaktifkan saat
karyawan menjadi Nonaktif. Masa kerja (`service_months`) = jumlah masa aktif saja.

### Mutasi & Penempatan (`app/Services/Transfer/`)
```
Draf ──(simpan & lanjutkan, jalur Template)──> Menunggu Dokumen ──(unggah scan SK)──> Berlaku
Draf ──(simpan & lanjutkan, jalur Unggah + scan SK)─────────────────────────────────> Berlaku
Menunggu Dokumen / Berlaku ──(batalkan)──> Dibatalkan (Berlaku: semua perubahan dikembalikan)
```
- `TransferRules`: semua pemeriksaan isian.
- `SupervisorPlanner`: pengalihan atasan otomatis. Bawahan dialihkan ke pemegang baru jabatan, atau naik
  ke atasan berikutnya kalau jabatannya kosong. Ada juga fitur "Kembalikan bawahan".
- `TransferApplier`: memberlakukan usulan (penugasan baru dibuat, yang lama diakhiri, atasan dialihkan) dan
  membatalkannya.
- `TransferDocument`: SK & petikan (PDF/Word **sementara**, lihat bagian 14).

### Penambahan Karyawan (`app/Services/Activation/`)
`Draf ──> Berlaku ──> Dibatalkan`. Saat Berlaku, rencana penempatan menjadi penugasan jabatan mulai TMT,
dan status karyawan menjadi Aktif pada TMT. Dokumen boleh Kolektif atau Per Orang, dan sifatnya opsional.

### Penonaktifan Karyawan (`app/Services/Termination/`)
`Draf ──> Berlaku ──> Dibatalkan`. Dokumen **wajib**. Saat Berlaku: jabatan dan keanggotaan regu diakhiri
sehari sebelum TMT, bawahan dialihkan ke atasan karyawan itu, dan akun login dinonaktifkan pada TMT.
`TerminationImpact` menghitung dampaknya untuk Pratinjau Dampak dan untuk saat usulan diberlakukan.

### Shift & Jadwal (`app/Services/Schedule/`)
```
Shift (jam) -> Pola Kerja (slot × hari siklus) -> Regu Kerja (anggota + rotasi) -> Jadwal (per tanggal)
```
- `Rotation`: rumus rotasi, sama persis dengan front end (`src/lib/schedule-rules.ts`).
- `ScheduleGenerator`: membuat baris jadwal dan menyalin jam shift (shift malam selesai keesokan hari).
- `ScheduleRegenerator`: "Buat Ulang dari Tanggal" (pratinjau, terapkan, dan `outdated_count`).
- Periode jadwal: `Draf → Terbit → Terkunci`. Hanya bulan berjalan & bulan depan yang bisa dibuat, dan
  ubahan setelah Terbit wajib disertai alasan.

---

## 9. Jadwal Otomatis (Cron)

| Perintah | Jadwal | Fungsi |
|---|---|---|
| `php artisan hr:refresh-employee-status` | setiap hari 00:05 | Perbarui status kepegawaian & akun login untuk TMT yang baru tiba |
| `php artisan hr:generate-schedules` | tanggal 20, 01:00 | Buat jadwal Draf bulan depan untuk semua regu (pembuat: "Sistem") |

Di server, cukup pasang **satu** cron yang menjalankan scheduler Laravel setiap menit:

```
* * * * * cd /path/ke/api && php artisan schedule:run >> /dev/null 2>&1
```

Lihat daftar jadwal:

```bash
php artisan schedule:list
```

Perintah di atas juga bisa dijalankan manual kapan saja. Jadwalnya diatur di `routes/console.php`.

---

## 10. Penyimpanan File

Semua file unggahan (foto, ijazah, SK, dokumen dasar, petikan) disimpan lewat `app/Services/FileStorage.php`:
- isi file disimpan di disk Laravel (`HR_FILE_DISK`, bawaan `local` = `storage/app/private`);
- keterangannya (nama asli, ukuran, jenis) dicatat di tabel `files`, sedangkan tabel lain cukup menyimpan `*_file_id`;
- format & ukuran diperiksa oleh `app/Support/UploadRule.php` (foto JPG/PNG maks. 2 MB, dokumen PDF/JPG/PNG maks. 5 MB);
- file yang diganti atau dihapus ikut dibersihkan dari disk.

Untuk pindah ke penyimpanan awan (mis. S3), cukup atur disk-nya di `config/filesystems.php` dan ubah `HR_FILE_DISK`.

---

## 11. Pengujian & Format Kode

Jalankan semua pengujian otomatis (memakai SQLite in-memory, tidak menyentuh database MySQL):

```bash
php artisan test --compact
```

Rapikan format kode (wajib sebelum commit):

```bash
vendor/bin/pint --dirty
```

Pengujian ada di `tests/Feature/<Modul>/`. Setiap file menguji alur utama, pesan validasi, dan aturan bisnis
satu modul. Factory data contoh ada di `database/factories/`. `Employee::factory()->active()` membuat karyawan
Aktif lengkap dengan riwayat Penambahannya.

---

## 12. Cara Menambah Fitur Baru

Contoh: menambah master "Golongan".

1. **Migration**: `php artisan make:migration create_grades_table`. Pakai `$table->uuid('id')->primary()` dan
   `foreignUuid()` untuk relasi.
2. **Model** di `app/Models/`: tambahkan `use HasUuids`, atribut `#[Fillable([...])]`, dan relasinya.
3. **FormRequest** di `app/Http/Requests/<Modul>/`: aturan isian + `messages()` Bahasa Indonesia.
4. **Resource** di `app/Http/Resources/<Modul>/`: bentuk JSON yang dibutuhkan front end.
5. **Controller** di `app/Http/Controllers/Api/V1/<Modul>/`: tipis, dengan PHPDoc judul di setiap method
   (otomatis tampil di `/docs/api`). Kembalikan `ApiResponse::success(...)`.
6. Kalau ada aturan bisnis yang mengubah beberapa tabel, taruh di **Service** (`app/Services/`) di dalam
   `DB::transaction`.
7. **Route** di `routes/api.php`, di kelompok modul yang sesuai (mis. `Route::apiResource('grades', GradeController::class)`).
8. **Pengujian** di `tests/Feature/<Modul>/`, lalu jalankan `php artisan test --compact` dan `vendor/bin/pint --dirty`.

Pola yang sama dipakai di semua modul. Contoh yang paling sederhana untuk ditiru adalah modul Bidang:
`DivisionController`, `DivisionRequest`, dan `DivisionResource`.

---

## 13. Keputusan Desain & Perbedaan dengan ERD

| Hal | Keputusan | Alasan |
|---|---|---|
| Id | UUID v7, bukan BIGINT | Diminta pemilik proyek; urut waktu sehingga index tetap cepat |
| `supervisor_changes.vacated_position_id` | **Kolom tambahan** (tidak ada di ERD v1.5) | Dibutuhkan fitur "Kembalikan bawahan" di front end |
| `transfers.decree_number` | Index biasa, bukan unique | Nomor SK usulan yang Dibatalkan boleh dipakai lagi; keunikannya diperiksa aplikasi |
| `transfer_items.position_id/sub_unit_id/assignment_status` | Boleh kosong | Usulan Draf boleh belum lengkap |
| `pattern_slots (work_pattern_id, code)` | Index biasa, bukan unique | Supaya kode dua slot bisa ditukar dalam satu kali simpan |
| Aturan tambahan dari ERD Bab 5 | Pensiun/Pensiun Dini hanya Karyawan Tetap; Kontrak Berakhir bukan Karyawan Tetap; anggota & ketua regu harus Aktif | Belum diperiksa di front end (mock), jadi pesannya datang dari server |
| Slot pola & shift | Slot yang dipakai anggota regu dan shift yang ada di jadwal tidak bisa dihapus | Menjaga riwayat tetap utuh |

---

## 14. Pekerjaan Berikutnya

- **Template SK & petikan resmi**: saat ini `TransferDocument` membuat PDF/Word sederhana berisi data usulan.
  Ganti dengan template resmi (mis. library PDF / `.docx`) setelah formatnya disepakati.
- **Data awal**: seeder baru berisi akun login. Data SK struktur organisasi (`organization_decrees`), master
  organisasi, dan karyawan bisa ditambahkan lewat seeder bila dibutuhkan.
- **ERD**: tambahkan `supervisor_changes.vacated_position_id` dan catatan di bagian 13 ke ERD v1.6.
- Fitur yang belum ada di front end: presensi (scan & rekap), cuti/izin, tukar shift, persetujuan ketua regu,
  kinerja, pelatihan.
