<?php
/**
 * ====================================================================
 * KONFIGURASI DATABASE HOSTING CPANEL / PRODUCTION
 * ====================================================================
 * Salin file ini menjadi 'config.php' pada server cPanel Anda:
 *   cp config.example.php config.php
 * Kemudian sesuaikan DB_NAME, DB_USER, dan DB_PASS sesuai data di cPanel.
 */

define('DB_HOST', 'localhost'); // Biasanya 'localhost' di cPanel
define('DB_PORT', '3306');
define('DB_NAME', 'usernamecpanel_supervisi24'); // Ganti dengan nama database cPanel Anda
define('DB_USER', 'usernamecpanel_spvuser');     // Ganti dengan username database cPanel Anda
define('DB_PASS', 'PasswordDatabaseAnda123!');   // Ganti dengan password database cPanel Anda
