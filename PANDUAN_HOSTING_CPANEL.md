# PANDUAN DEPLOYMENT & HOSTING cPANEL
## SISTEM SUPERVISI, COACHING & EVALUASI GURU - SMPN 24 MALANG

Aplikasi ini sudah dirancang **100% siap online** menggunakan arsitektur web modern (HTML5, TailwindCSS, FontAwesome, SheetJS, Chart.js) yang terhubung ke Backend REST API **PHP** dan Database **MySQL** sekolah.

---

### A. STRUKTUR BERKAS YANG DIUPLOAD KE cPANEL
Seluruh berkas ini berada di folder proyek `C:\2026-2027\PROYEK\APLIKASI SUPERVISI 24`:
1. `index.html` (atau rename dari `web_dashboard_supervisi_guru.html`) — Frontend Dashboard Utama.
2. `api.php` — Backend REST API pengolah data login NIP, sinkronisasi pra-observasi, observasi, coaching, rekap, & manajemen guru.
3. `database.sql` — Skrip struktur tabel MySQL & data awal (105 guru + akun admin/supervisor).
4. `.htaccess` — Konfigurasi keamanan direktori web Apache/LiteSpeed.
5. `LOGO SMP 24.png` & `Logo_Kota_Malang_color.png` — Aset visual kop laporan resmi.

---

### B. LANGKAH-LANGKAH DEPLOYMENT DI cPANEL SEKOLAH

#### 1. Buat Database & Pengguna MySQL di cPanel
1. Masuk ke cPanel hosting sekolah (misal: `https://smpn24malang.sch.id:2083`).
2. Buka menu **MySQL Database Wizard** (atau **MySQL Databases**).
3. Buat database baru, contoh: `smpn24ma_supervisi`.
4. Buat username database baru, contoh: `smpn24ma_user`.
5. Buat kata sandi yang kuat (misal: `Smpn24Supervisi2026!`).
6. Berikan hak akses penuh (**ALL PRIVILEGES**) kepada user tersebut ke database.

#### 2. Impor File `database.sql` Melalui phpMyAdmin
1. Di cPanel, buka menu **phpMyAdmin**.
2. Pilih nama database yang baru dibuat (`smpn24ma_supervisi`).
3. Klik tab menu **Import** di bagian atas.
4. Klik tombol **Choose File** dan pilih file `database.sql`.
5. Klik **Go / Kirim** di bagian bawah.
6. Pastikan tabel `guru`, `pengaturan`, `supervisi_pra`, `supervisi_obs`, dan `supervisi_coaching` berhasil terbuat.

#### 3. Sesuaikan Konfigurasi Database di `api.php`
Buka file `api.php`, cari baris 26–30:
```php
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1'); // atau 'localhost'
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'smpn24ma_supervisi'); // Ganti dengan nama database cPanel
define('DB_USER', getenv('DB_USER') ?: 'smpn24ma_user');      // Ganti dengan username database cPanel
define('DB_PASS', getenv('DB_PASS') ?: 'Smpn24Supervisi2026!'); // Ganti dengan password user database cPanel
```

#### 4. Upload File ke File Manager cPanel
1. Di cPanel, buka **File Manager**.
2. Masuk ke folder tujuan:
   - Jika ingin di subdomain (misal `https://supervisi.smpn24malang.sch.id`), upload ke direktori subdomainnya.
   - Jika ingin di subfolder (misal `https://smpn24malang.sch.id/supervisi`), buat folder `supervisi` di dalam `public_html/`, lalu upload semua file ke dalam folder tersebut.
3. Upload berkas:
   - `web_dashboard_supervisi_guru.html` (ganti nama/salin menjadi `index.html`)
   - `api.php`
   - `.htaccess`
   - File logo png (`LOGO SMP 24.png`, `Logo_Kota_Malang_color.png`)

---

### C. CARA LOGIN GURU & PENGGUNAAN MULTI-ROLE
1. **Login Utama Menggunakan NIP**:
   - Semua guru masuk ke sistem menggunakan **NIP** (Nomor Induk Pegawai).
   - Kata sandi awal default: **NIP** masing-masing guru atau `12345678`.
   - Guru dapat mengubah kata sandi mandiri lewat tombol **Ganti Sandi** di pojok kanan atas.

2. **Fitur Multi-Role Otomatis (Tanpa Perlu Ganti Akun)**:
   - Jika seorang guru bertindak sebagai **Supervisor**, setelah login akan muncul tombol pengalih peran di header:
     - Klik **Supervisor** untuk menilai guru binaan.
     - Klik **Guru (Mandiri)** untuk melihat hasil evaluasi dirinya sendiri.
   - Jika seorang guru bertindak sebagai **Superadmin** (contoh: Kepala Sekolah / Tim Kurikulum):
     - Memiliki akses penuh mengelola data guru, ekspor/impor Excel, dan pengaturan sekolah.
     - Dapat beralih peran instan antara **Superadmin**, **Supervisor**, maupun **Guru Mandiri**.
   - Jika guru murni (bukan supervisor/admin):
     - Sistem langsung membatasi akses hanya ke instrumen dan laporan mandiri miliknya demi menjaga kerahasiaan evaluasi antar-guru.

3. **Uji Coba Cepat (Demo Akun)**:
   - **Superadmin Utama / Guru 1**: NIP `19811100120020001` (Siti Kusuma, S.Pd) • Sandi `12345678`
   - **Guru Bertindak Supervisor / Guru 2**: NIP `19812100220030002` (Agus Hidayat, S.Pd) • Sandi `12345678`
   - **Guru Standar / Guru 7**: NIP `19817100720080007` (Dewi Lestari, S.Pd) • Sandi `12345678`
   - Akun Bypass Darurat Superadmin: Username `superadmin` • Sandi `admin123`

---

### D. PENGUJIAN LOKAL (LARAGON)
Aplikasi saat ini telah aktif dan dapat langsung diakses & dicoba di browser laptop ini melalui:
👉 **`http://localhost/supervisi24`**
