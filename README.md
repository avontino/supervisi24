# Sistem Informasi Supervisi Akademik & Evaluasi Guru
### SMP Negeri 24 Malang - Tahun Ajaran 2026/2027

Aplikasi berbasis web untuk digitalisasi proses supervisi akademik guru terintegrasi:
1. **Pra-Observasi**: Telaah Modul Ajar (Pembelajaran Mendalam / Deep Learning 30 Indikator & Modul BK 14 Aspek).
2. **Observasi Kelas**: Penilaian 27/28 Indikator Perilaku & Dimensi Pembelajaran Mendalam.
3. **Pasca-Observasi (Coaching Model TIRTA)**: Pencatatan tujuan, identifikasi, rencana aksi, dan tanggung jawab dengan bank pemantik pertanyaan.
4. **Laporan & Rekapitulasi**: Rekap capaian individu maupun kolektif dengan lembar cetak standar resmi bermaterai/Kop Surat dinas (Kota Malang & SMPN 24) serta ekspor Excel (.xlsx).
5. **Manajemen Pengguna (Super Admin)**: Manajemen login seluruh guru, reset sandi standar (`123456`), dan pembagian peran supervisor.

---

## 🚀 Panduan Deployment ke Hosting cPanel via Git & SSH

### 1. Persiapan Database di cPanel
1. Masuk ke **cPanel** > menu **MySQL® Databases** (atau **MySQL Database Wizard**).
2. Buat database baru, contoh: `usercpanel_supervisi`.
3. Buat user database baru, contoh: `usercpanel_spvuser` dan buat kata sandi yang aman.
4. Hubungkan user ke database dengan mencentang **ALL PRIVILEGES**.

---

### 2. Clone Repositori via SSH cPanel
1. Akses terminal SSH cPanel Anda:
   ```bash
   ssh username@domain-anda.sch.id -p 22
   ```
2. Arahkan direktori ke folder web target (misal folder utama `public_html` atau subdomain):
   ```bash
   cd ~/public_html
   # atau jika pada subdomain:
   # cd ~/public_html/supervisi
   ```
3. Lakukan clone dari repositori GitHub:
   ```bash
   git clone https://github.com/USERNAME_ANDA/NAMA_REPO.git .
   ```

---

### 3. Import Database
Import file `database.sql` ke database cPanel yang telah dibuat:
```bash
mysql -u usercpanel_spvuser -p usercpanel_supervisi < database.sql
```
*(Masukkan kata sandi database Anda saat diminta).*

---

### 4. Konfigurasi Database (`config.php`)
1. Salin template konfigurasi:
   ```bash
   cp config.example.php config.php
   ```
2. Buka dan edit file `config.php`:
   ```bash
   nano config.php
   ```
3. Sesuaikan konstanta:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_PORT', '3306');
   define('DB_NAME', 'usercpanel_supervisi');
   define('DB_USER', 'usercpanel_spvuser');
   define('DB_PASS', 'PasswordDatabaseAnda');
   ```
   Simpan dengan menekan `Ctrl + O`, lalu `Enter`, dan keluar dengan `Ctrl + X`.

---

### 5. Alur Pembaruan Kode di Masa Depan (Update Workflow)
Kapan saja Anda melakukan perbaikan atau pembaruan di komputer lokal:
1. **Di Komputer Lokal**:
   ```bash
   git add .
   git commit -m "Pembaruan fitur"
   git push origin main
   ```
2. **Di SSH cPanel**:
   ```bash
   cd ~/public_html
   git pull origin main
   ```
Aplikasi langsung terbarui tanpa mengubah database maupun konfigurasi password server.
