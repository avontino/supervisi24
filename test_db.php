<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNOSTIK KONEKSI DATABASE SUPERVISI SMPN 24 MALANG ===\n\n";

if (!file_exists(__DIR__ . '/config.php')) {
    echo "[X] STATUS: File 'config.php' BELUM ADA di server!\n";
    echo "    Inilah penyebab aplikasi masih berjalan dalam 'Mode Offline (Lokal)'.\n\n";
    echo "    Langkah memperbaikinya di Terminal cPanel:\n";
    echo "    1. cp config.example.php config.php\n";
    echo "    2. nano config.php\n";
    echo "    3. Masukkan nama database, user database, dan password cPanel Anda.\n";
    exit;
}

echo "[V] File config.php ditemukan.\n";
require_once __DIR__ . '/config.php';

$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$port = defined('DB_PORT') ? DB_PORT : '3306';
$name = defined('DB_NAME') ? DB_NAME : '';
$user = defined('DB_USER') ? DB_USER : '';
$pass = defined('DB_PASS') ? DB_PASS : '';

echo "    Host     : $host:$port\n";
echo "    Database : $name\n";
echo "    Username : $user\n\n";

if (strpos($name, 'usernamecpanel') !== false || empty($name)) {
    echo "[!] PERINGATAN: DB_NAME di config.php masih memakai teks contoh ('$name')!\n";
    echo "    Silakan edit file config.php sesuai nama database riil di cPanel.\n";
    exit;
}

$dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "[V] KONEKSI KE MYSQL BERHASIL 100%! STATUS: ONLINE\n\n";
    
    // Cek tabel
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabel yang ditemukan (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        echo "  - $t\n";
    }
    
    if (in_array('guru', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM guru")->fetchColumn();
        echo "\n[V] Tabel 'guru' AKTIF dengan $count data guru.\n";
        echo "    Aplikasi Supervisi SMPN 24 Malang SIAP 100% ONLINE!\n";
    } else {
        echo "\n[X] Tabel 'guru' belum ada di database ini!\n";
        echo "    Solusi: Import database.sql dengan perintah:\n";
        echo "    mysql -u $user -p $name < database.sql\n";
    }
    
} catch (PDOException $e) {
    echo "[X] KONEKSI KE MYSQL GAGAL!\n";
    echo "    Pesan Error: " . $e->getMessage() . "\n\n";
    echo "    Solusi: Periksa kembali nama database, user, dan password di config.php.\n";
}
