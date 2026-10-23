<?php
/**
 * ====================================================================
 * BACKEND REST API: SISTEM SUPERVISI AKADEMIK SMPN 24 MALANG
 * ====================================================================
 * Kompatibel dengan PHP 7.4+ s.d. PHP 8.3+
 * Siap dipasang di hosting cPanel sekolah maupun Laragon lokal.
 */

session_start();

header('Content-Type: application/json; charset=UTF-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --------------------------------------------------------------------
// KONFIGURASI DATABASE MYSQL
// Jika file config.php tersedia (di cPanel / server), muat dari file tersebut.
// Jika tidak, gunakan environment variables atau default lokal.
// --------------------------------------------------------------------
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'supervisi_smpn24');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');

function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Koneksi database MySQL gagal: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    return $pdo;
}

function getJsonInput() {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $raw = trim($raw, "\xEF\xBB\xBF \t\n\r\0\x0B");
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        if (function_exists('mb_convert_encoding')) {
            $rawClean = mb_convert_encoding($raw, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
            $data = json_decode($rawClean, true);
            if (is_array($data)) return $data;
        }
    }
    if (!empty($_POST)) return $_POST;
    return [];
}

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

try {
    $pdo = getDb();

    switch ($action) {

        // ------------------------------------------------------------
        // 1. GET ALL DATA / INIT DATA
        // ------------------------------------------------------------
        case 'get_data':
            // Pengaturan
            $stmtSet = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
            $setRow = $stmtSet->fetch();
            $settings = [
                'sekolah' => $setRow['sekolah'] ?? 'SMP Negeri 24 Malang',
                'periode' => $setRow['periode'] ?? 'Tahun Ajaran 2026/2027 • Semester Ganjil',
                'kepalaSekolah' => [
                    'nama' => $setRow['ks_nama'] ?? 'Drs. Teguh Edy Purwanta',
                    'nip' => $setRow['ks_nip'] ?? '196710202000031006'
                ],
                'pengawasSekolah' => [
                    'nama' => $setRow['pengawas_nama'] ?? 'Drs. Mochamad Solikin',
                    'nip' => $setRow['pengawas_nip'] ?? '196808061998021005'
                ],
                'supervisors' => json_decode($setRow['supervisors_json'] ?? '[]', true)
            ];

            // Cek Session Logged In User
            $currentUser = $_SESSION['user'] ?? null;

            // Pastikan tabel supervisi_pengembangan_diri dan kolom analisis observasi ada
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS supervisi_pengembangan_diri (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    guru_id INT NOT NULL,
                    items_json LONGTEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_pd_guru (guru_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("ALTER TABLE supervisi_pengembangan_diri MODIFY items_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Exception $e) {}

            try {
                $pdo->exec("ALTER TABLE supervisi_obs ADD COLUMN IF NOT EXISTS obs_kekuatan TEXT");
                $pdo->exec("ALTER TABLE supervisi_obs ADD COLUMN IF NOT EXISTS obs_kelemahan TEXT");
            } catch (Exception $e) {}

            // Query Guru beserta relasi pra, obs, coaching, dan pengembangan diri
            $sql = "
                SELECT 
                    g.id, g.nip, g.nama, g.mapel, g.kelas, g.password,
                    g.is_supervisor, g.is_superadmin, g.supervisor_id, g.target_perilaku, g.status,
                    p.materi_topik, p.tujuan_pembelajaran, p.tanggal_telaah,
                    p.rpp_scores, p.bk_scores, p.bk_dl_scores, p.bk_dl_notes, p.pra_feedback, p.catatan_pra,
                    o.obs_scores, o.obs_notes, o.deep_learning, o.catatan_fakta, o.tanggal_observasi,
                    o.obs_kekuatan, o.obs_kelemahan,
                    c.tujuan as coach_tujuan, c.identifikasi as coach_identifikasi, 
                    c.rencana as coach_rencana, c.tanggung_jawab as coach_tanggung_jawab, c.tanggal_coaching,
                    pd.items_json as pd_items_json
                FROM guru g
                LEFT JOIN supervisi_pra p ON g.id = p.guru_id
                LEFT JOIN supervisi_obs o ON g.id = o.guru_id
                LEFT JOIN supervisi_coaching c ON g.id = c.guru_id
                LEFT JOIN supervisi_pengembangan_diri pd ON g.id = pd.guru_id
                ORDER BY g.id ASC
            ";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();

            $guruList = [];
            foreach ($rows as $r) {
                $guruList[] = [
                    'id' => (int)$r['id'],
                    'nip' => (string)$r['nip'],
                    'nama' => $r['nama'],
                    'mapel' => $r['mapel'],
                    'kelas' => $r['kelas'],
                    'password' => $r['password'],
                    'is_supervisor' => (int)$r['is_supervisor'],
                    'is_superadmin' => (int)$r['is_superadmin'],
                    'supervisorId' => (int)$r['supervisor_id'],
                    'targetPerilaku' => $r['target_perilaku'],
                    'status' => $r['status'],
                    'materiTopik' => $r['materi_topik'] ?? '',
                    'tujuanPembelajaran' => $r['tujuan_pembelajaran'] ?? '',
                    'tanggalTelaah' => $r['tanggal_telaah'] ?? '',
                    'rppScores' => json_decode($r['rpp_scores'] ?? '{}', true) ?: (object)[],
                    'bkScores' => json_decode($r['bk_scores'] ?? '{}', true) ?: (object)[],
                    'bkDlScores' => json_decode($r['bk_dl_scores'] ?? '{}', true) ?: (object)[],
                    'bkDlNotes' => json_decode($r['bk_dl_notes'] ?? '{}', true) ?: (object)[],
                    'praFeedback' => json_decode($r['pra_feedback'] ?? '{}', true) ?: (object)[],
                    'catatanPra' => $r['catatan_pra'] ?? '',
                    'obsScores' => json_decode($r['obs_scores'] ?? '{}', true) ?: (object)[],
                    'obsNotes' => json_decode($r['obs_notes'] ?? '{}', true) ?: (object)[],
                    'deepLearning' => json_decode($r['deep_learning'] ?? '{}', true) ?: [
                        'mindful' => 0, 'meaningful' => 0, 'joyful' => 0, 'memahami' => 0, 'mengaplikasi' => 0, 'merefleksi' => 0
                    ],
                    'catatanFakta' => $r['catatan_fakta'] ?? '',
                    'obsKekuatan' => $r['obs_kekuatan'] ?? '',
                    'obsKelemahan' => $r['obs_kelemahan'] ?? '',
                    'coaching' => [
                        'tujuan' => $r['coach_tujuan'] ?? '',
                        'identifikasi' => $r['coach_identifikasi'] ?? '',
                        'rencana' => $r['coach_rencana'] ?? '',
                        'tanggungJawab' => $r['coach_tanggung_jawab'] ?? '',
                    ],
                    'pengembanganDiri' => (function() use ($r) {
                        $pdRaw = $r['pd_items_json'] ?? '';
                        if (empty($pdRaw)) return [];
                        $dec = json_decode($pdRaw, true);
                        if (is_array($dec)) return $dec;
                        $dec2 = json_decode(stripslashes($pdRaw), true);
                        if (is_array($dec2)) return $dec2;
                        return [];
                    })()
                ];
            }

            echo json_encode([
                'success' => true,
                'appVersion' => '2026.10.23.0710',
                'currentUser' => $currentUser,
                'appSettings' => $settings,
                'guruDatabase' => $guruList
            ]);
            break;

        // ------------------------------------------------------------
        // 2. LOGIN (BERBASIS NIP & MULTI-ROLE)
        // ------------------------------------------------------------
        case 'login':
            $input = getJsonInput();
            $nip = trim($input['nip'] ?? $input['username'] ?? '');
            $password = trim($input['password'] ?? '');

            if (!$nip || !$password) {
                echo json_encode(['success' => false, 'error' => 'Harap masukkan NIP dan Kata Sandi!']);
                exit;
            }

            // Cek Superadmin Khusus (fallback bypass username superadmin/admin)
            if (($nip === 'superadmin' || $nip === 'admin') && ($password === 'admin123' || $password === 'superadmin' || $password === '123456' || $password === '12345678')) {
                $user = [
                    'id' => 1,
                    'nip' => 'superadmin',
                    'nama' => 'Superadmin Utama',
                    'mapel' => 'Manajemen',
                    'kelas' => 'Semua',
                    'is_supervisor' => 1,
                    'is_superadmin' => 1,
                    'available_roles' => ['guru', 'supervisor', 'superadmin'],
                    'active_role' => 'superadmin'
                ];
                $_SESSION['user'] = $user;
                echo json_encode(['success' => true, 'user' => $user]);
                exit;
            }

            // Cari di Database Berdasarkan NIP
            $stmt = $pdo->prepare("SELECT * FROM guru WHERE nip = ? LIMIT 1");
            $stmt->execute([$nip]);
            $guru = $stmt->fetch();

            if (!$guru) {
                echo json_encode(['success' => false, 'error' => 'NIP guru tidak ditemukan dalam database!']);
                exit;
            }

            // Verifikasi Password (bisa plain match NIP, hash, atau default 12345678)
            $valid = false;
            if ($password === $guru['password'] || $password === $guru['nip'] || $password === '123456' || $password === '12345678') {
                $valid = true;
            } elseif (password_verify($password, $guru['password'])) {
                $valid = true;
            }

            if (!$valid) {
                echo json_encode(['success' => false, 'error' => 'Kata sandi tidak sesuai!']);
                exit;
            }

            // Tentukan Available Roles
            $roles = ['guru'];
            if ((int)$guru['is_supervisor'] === 1) $roles[] = 'supervisor';
            if ((int)$guru['is_superadmin'] === 1) $roles[] = 'superadmin';

            // Active Role Default
            $activeRole = 'guru';
            if ((int)$guru['is_superadmin'] === 1) $activeRole = 'superadmin';
            elseif ((int)$guru['is_supervisor'] === 1) $activeRole = 'supervisor';

            // Cari apakah user ini terdaftar sebagai supervisor di tabel pengaturan
            $spvId = null;
            $setStmt = $pdo->query("SELECT supervisors_json FROM pengaturan LIMIT 1");
            $spvJson = $setStmt ? $setStmt->fetchColumn() : null;
            if ($spvJson) {
                $spvArr = json_decode($spvJson, true) ?: [];
                $cleanUserNip = preg_replace('/\D/', '', (string)$guru['nip']);
                foreach ($spvArr as $spvItem) {
                    $itemNip = preg_replace('/\D/', '', (string)($spvItem['nip'] ?? ''));
                    if ($itemNip && $cleanUserNip && $itemNip === $cleanUserNip) {
                        $spvId = (int)$spvItem['id'];
                        break;
                    }
                }
            }

            $user = [
                'id' => (int)$guru['id'],
                'nip' => (string)$guru['nip'],
                'nama' => $guru['nama'],
                'mapel' => $guru['mapel'],
                'kelas' => $guru['kelas'],
                'is_supervisor' => (int)$guru['is_supervisor'],
                'is_superadmin' => (int)$guru['is_superadmin'],
                'active_spv_id' => $spvId,
                'available_roles' => $roles,
                'active_role' => $activeRole
            ];

            $_SESSION['user'] = $user;
            echo json_encode(['success' => true, 'user' => $user]);
            break;

        // ------------------------------------------------------------
        // 3. SWITCH ROLE
        // ------------------------------------------------------------
        case 'switch_role':
            $input = getJsonInput();
            $newRole = trim($input['role'] ?? 'guru');

            if (isset($_SESSION['user']) && in_array($newRole, $_SESSION['user']['available_roles'])) {
                $_SESSION['user']['active_role'] = $newRole;
                echo json_encode(['success' => true, 'active_role' => $newRole]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Role tidak diizinkan.']);
            }
            break;

        // ------------------------------------------------------------
        // 4. LOGOUT
        // ------------------------------------------------------------
        case 'logout':
            $_SESSION['user'] = null;
            session_destroy();
            echo json_encode(['success' => true]);
            break;

        // ------------------------------------------------------------
        // 5. SIMPAN PRA-OBSERVASI (TELAAH MODUL AJAR / RPP)
        // ------------------------------------------------------------
        case 'save_pra':
            $input = getJsonInput();
            $guruId = (int)($input['guruId'] ?? $input['id'] ?? 0);
            if (!$guruId) {
                echo json_encode(['success' => false, 'error' => 'ID Guru wajib disertakan.']);
                exit;
            }

            $materi = trim($input['materiTopik'] ?? '');
            $tujuan = trim($input['tujuanPembelajaran'] ?? '');
            $tanggal = trim($input['tanggalTelaah'] ?? date('Y-m-d'));
            $rppScores = json_encode($input['rppScores'] ?? []);
            $bkScores = json_encode($input['bkScores'] ?? []);
            $bkDlScores = json_encode($input['bkDlScores'] ?? []);
            $bkDlNotes = json_encode($input['bkDlNotes'] ?? [], JSON_UNESCAPED_UNICODE);
            $praFeedback = json_encode($input['praFeedback'] ?? [], JSON_UNESCAPED_UNICODE);
            $catatanPra = trim($input['catatanPra'] ?? '');

            // Upsert ke supervisi_pra
            $sql = "
                INSERT INTO supervisi_pra 
                    (guru_id, materi_topik, tujuan_pembelajaran, tanggal_telaah, rpp_scores, bk_scores, bk_dl_scores, bk_dl_notes, pra_feedback, catatan_pra)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    materi_topik = VALUES(materi_topik),
                    tujuan_pembelajaran = VALUES(tujuan_pembelajaran),
                    tanggal_telaah = VALUES(tanggal_telaah),
                    rpp_scores = VALUES(rpp_scores),
                    bk_scores = VALUES(bk_scores),
                    bk_dl_scores = VALUES(bk_dl_scores),
                    bk_dl_notes = VALUES(bk_dl_notes),
                    pra_feedback = VALUES(pra_feedback),
                    catatan_pra = VALUES(catatan_pra)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$guruId, $materi, $tujuan, $tanggal, $rppScores, $bkScores, $bkDlScores, $bkDlNotes, $praFeedback, $catatanPra]);

            // Update status guru jika sebelumnya Belum menjadi Tindak Lanjut
            $pdo->prepare("UPDATE guru SET status = IF(status = 'Belum', 'Tindak Lanjut', status) WHERE id = ?")->execute([$guruId]);

            // Jika ada perubahan supervisorId
            if (!empty($input['supervisorId'])) {
                $pdo->prepare("UPDATE guru SET supervisor_id = ? WHERE id = ?")->execute([(int)$input['supervisorId'], $guruId]);
            }

            // Jika ada perubahan targetPerilaku
            if (!empty($input['targetPerilaku'])) {
                $pdo->prepare("UPDATE guru SET target_perilaku = ? WHERE id = ?")->execute([trim($input['targetPerilaku']), $guruId]);
            }

            echo json_encode(['success' => true, 'message' => 'Hasil Telaah Modul Ajar berhasil disimpan ke database server!']);
            break;

        // ------------------------------------------------------------
        // 6. SIMPAN OBSERVASI KELAS
        // ------------------------------------------------------------
        case 'save_obs':
            $input = getJsonInput();
            $guruId = (int)($input['guruId'] ?? $input['id'] ?? 0);
            if (!$guruId) {
                echo json_encode(['success' => false, 'error' => 'ID Guru wajib disertakan.']);
                exit;
            }

            $obsScores = json_encode($input['obsScores'] ?? []);
            $obsNotes = json_encode($input['obsNotes'] ?? [], JSON_UNESCAPED_UNICODE);
            $deepLearning = json_encode($input['deepLearning'] ?? []);
            $catatanFakta = trim($input['catatanFakta'] ?? '');
            $obsKekuatan = trim($input['obsKekuatan'] ?? '');
            $obsKelemahan = trim($input['obsKelemahan'] ?? '');
            $tanggal = date('Y-m-d');

            try {
                $pdo->exec("ALTER TABLE supervisi_obs ADD COLUMN IF NOT EXISTS obs_kekuatan TEXT");
                $pdo->exec("ALTER TABLE supervisi_obs ADD COLUMN IF NOT EXISTS obs_kelemahan TEXT");
            } catch (Exception $e) {}

            $sql = "
                INSERT INTO supervisi_obs 
                    (guru_id, obs_scores, obs_notes, deep_learning, catatan_fakta, tanggal_observasi, obs_kekuatan, obs_kelemahan)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    obs_scores = VALUES(obs_scores),
                    obs_notes = VALUES(obs_notes),
                    deep_learning = VALUES(deep_learning),
                    catatan_fakta = VALUES(catatan_fakta),
                    tanggal_observasi = VALUES(tanggal_observasi),
                    obs_kekuatan = VALUES(obs_kekuatan),
                    obs_kelemahan = VALUES(obs_kelemahan)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$guruId, $obsScores, $obsNotes, $deepLearning, $catatanFakta, $tanggal, $obsKekuatan, $obsKelemahan]);

            // Update status menjadi Tindak Lanjut jika belum Selesai
            $pdo->prepare("UPDATE guru SET status = 'Tindak Lanjut' WHERE id = ? AND status != 'Selesai'")->execute([$guruId]);

            echo json_encode(['success' => true, 'message' => 'Hasil Observasi Kelas berhasil disimpan ke server!']);
            break;

        // ------------------------------------------------------------
        // 7. SIMPAN COACHING MODEL TIRTA
        // ------------------------------------------------------------
        case 'save_coaching':
            $input = getJsonInput();
            $guruId = (int)($input['guruId'] ?? $input['id'] ?? 0);
            if (!$guruId) {
                echo json_encode(['success' => false, 'error' => 'ID Guru wajib disertakan.']);
                exit;
            }

            $tujuan = trim($input['tujuan'] ?? '');
            $identifikasi = trim($input['identifikasi'] ?? '');
            $rencana = trim($input['rencana'] ?? '');
            $tanggungJawab = trim($input['tanggungJawab'] ?? '');
            $tanggal = date('Y-m-d');

            $sql = "
                INSERT INTO supervisi_coaching 
                    (guru_id, tujuan, identifikasi, rencana, tanggung_jawab, tanggal_coaching)
                VALUES 
                    (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    tujuan = VALUES(tujuan),
                    identifikasi = VALUES(identifikasi),
                    rencana = VALUES(rencana),
                    tanggung_jawab = VALUES(tanggung_jawab),
                    tanggal_coaching = VALUES(tanggal_coaching)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$guruId, $tujuan, $identifikasi, $rencana, $tanggungJawab, $tanggal]);

            echo json_encode(['success' => true, 'message' => 'Catatan Percakapan Coaching TIRTA tersimpan di server!']);
            break;

        // ------------------------------------------------------------
        // 7a. APPROVE / PENGESAHAN SUPERVISI
        // ------------------------------------------------------------
        case 'approve_supervisi':
            $input = getJsonInput();
            $guruId = (int)($input['guruId'] ?? $input['id'] ?? 0);
            $status = trim($input['status'] ?? 'Selesai');
            if (!in_array($status, ['Selesai', 'Tindak Lanjut', 'Belum'])) {
                $status = 'Selesai';
            }
            if (!$guruId) {
                echo json_encode(['success' => false, 'error' => 'ID Guru wajib disertakan.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE guru SET status = ? WHERE id = ?");
            $stmt->execute([$status, $guruId]);
            echo json_encode(['success' => true, 'message' => "Status supervisi guru berhasil diubah menjadi {$status}!"]);
            break;

        // ------------------------------------------------------------
        // 7b. SIMPAN PENGEMBANGAN DIRI GURU
        // ------------------------------------------------------------
        case 'save_pengembangan_diri':
            $input = getJsonInput();
            $guruId = (int)($input['guruId'] ?? $input['id'] ?? 0);
            if (!$guruId) {
                echo json_encode(['success' => false, 'error' => 'ID Guru wajib disertakan.']);
                exit;
            }

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS supervisi_pengembangan_diri (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    guru_id INT NOT NULL,
                    items_json LONGTEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_pd_guru (guru_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("ALTER TABLE supervisi_pengembangan_diri MODIFY items_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Exception $e) {}

            $items = is_array($input['items'] ?? null) ? $input['items'] : [];
            $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
            if ($itemsJson === false) {
                $itemsJson = json_encode($items);
            }

            // Cek apakah data guru_id sudah ada di database
            $stmtCheck = $pdo->prepare("SELECT id FROM supervisi_pengembangan_diri WHERE guru_id = ? LIMIT 1");
            $stmtCheck->execute([$guruId]);
            $exists = $stmtCheck->fetch();

            if ($exists) {
                $stmtSave = $pdo->prepare("UPDATE supervisi_pengembangan_diri SET items_json = ?, updated_at = CURRENT_TIMESTAMP WHERE guru_id = ?");
                $stmtSave->execute([$itemsJson, $guruId]);
            } else {
                $stmtSave = $pdo->prepare("INSERT INTO supervisi_pengembangan_diri (guru_id, items_json) VALUES (?, ?)");
                $stmtSave->execute([$guruId, $itemsJson]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Portofolio Pengembangan Diri (' . count($items) . ' kegiatan) berhasil disimpan ke server MySQL!',
                'itemsCount' => count($items)
            ]);
            break;

        // ------------------------------------------------------------
        // 8. SIMPAN PENGATURAN SISTEM
        // ------------------------------------------------------------
        case 'save_settings':
            $input = getJsonInput();
            $sekolah = trim($input['sekolah'] ?? 'SMP Negeri 24 Malang');
            $periode = trim($input['periode'] ?? '');
            $ksNama = trim($input['ks_nama'] ?? $input['kepalaSekolah']['nama'] ?? '');
            $ksNip = trim($input['ks_nip'] ?? $input['kepalaSekolah']['nip'] ?? '');
            $pengawasNama = trim($input['pengawas_nama'] ?? $input['pengawasSekolah']['nama'] ?? 'Drs. Mochamad Solikin');
            $pengawasNip = trim($input['pengawas_nip'] ?? $input['pengawasSekolah']['nip'] ?? '196808061998021005');
            $spvJson = json_encode($input['supervisors'] ?? [], JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("
                UPDATE pengaturan 
                SET sekolah = ?, periode = ?, ks_nama = ?, ks_nip = ?, pengawas_nama = ?, pengawas_nip = ?, supervisors_json = ? 
                WHERE id = 1
            ");
            $stmt->execute([$sekolah, $periode, $ksNama, $ksNip, $pengawasNama, $pengawasNip, $spvJson]);

            echo json_encode(['success' => true, 'message' => 'Pengaturan sistem berhasil diperbarui di database!']);
            break;

        // ------------------------------------------------------------
        // 9. TAMBAH GURU MANUAL
        // ------------------------------------------------------------
        case 'add_guru':
            $input = getJsonInput();
            $nama = trim($input['nama'] ?? '');
            $nip = trim($input['nip'] ?? '');
            $mapel = trim($input['mapel'] ?? 'Umum');
            $kelas = trim($input['kelas'] ?? 'Kelas 7');
            $spvId = (int)($input['supervisorId'] ?? 1);
            $target = trim($input['targetPerilaku'] ?? 'Instruksi Pembelajaran');
            $isBk = (strtoupper($mapel) === 'BK' || strtoupper($mapel) === 'BIMBINGAN KONSELING');
            $finalMapel = $isBk ? 'BK' : $mapel;

            if (!$nama || !$nip) {
                echo json_encode(['success' => false, 'error' => 'Nama dan NIP wajib diisi!']);
                exit;
            }

            // Cek duplikasi NIP
            $check = $pdo->prepare("SELECT id FROM guru WHERE nip = ?");
            $check->execute([$nip]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'NIP sudah terdaftar dalam sistem!']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO guru (nama, nip, mapel, kelas, password, is_supervisor, is_superadmin, supervisor_id, target_perilaku, status)
                VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, 'Belum')
            ");
            $stmt->execute([$nama, $nip, $finalMapel, $kelas, $nip, $spvId, $target]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode(['success' => true, 'newId' => $newId, 'message' => "Guru {$nama} berhasil ditambahkan!"]);
            break;

        // ------------------------------------------------------------
        // 10. EDIT GURU
        // ------------------------------------------------------------
        case 'edit_guru':
            $input = getJsonInput();
            $id = (int)($input['id'] ?? 0);
            $nama = trim($input['nama'] ?? '');
            $nip = trim($input['nip'] ?? '');
            $mapel = trim($input['mapel'] ?? 'Umum');
            $kelas = trim($input['kelas'] ?? 'Kelas 7');
            $spvId = (int)($input['supervisorId'] ?? 1);
            $target = trim($input['targetPerilaku'] ?? 'Instruksi Pembelajaran');
            $isBk = (strtoupper($mapel) === 'BK' || strtoupper($mapel) === 'BIMBINGAN KONSELING');
            $finalMapel = $isBk ? 'BK' : $mapel;

            if (!$id || !$nama || !$nip) {
                echo json_encode(['success' => false, 'error' => 'Data edit tidak lengkap!']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE guru 
                SET nama = ?, nip = ?, mapel = ?, kelas = ?, supervisor_id = ?, target_perilaku = ?
                WHERE id = ?
            ");
            $stmt->execute([$nama, $nip, $finalMapel, $kelas, $spvId, $target, $id]);

            echo json_encode(['success' => true, 'message' => "Data guru {$nama} berhasil diperbarui!"]);
            break;

        // ------------------------------------------------------------
        // 11. HAPUS GURU TUNGGAL
        // ------------------------------------------------------------
        case 'delete_guru':
            $input = getJsonInput();
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID Guru tidak valid.']);
                exit;
            }
            $pdo->prepare("DELETE FROM guru WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Guru berhasil dihapus.']);
            break;

        // ------------------------------------------------------------
        // 12. HAPUS GURU TERPILIH (BULK)
        // ------------------------------------------------------------
        case 'delete_selected_guru':
            $input = getJsonInput();
            $ids = $input['ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) {
                echo json_encode(['success' => false, 'error' => 'Tidak ada guru yang dipilih.']);
                exit;
            }
            $inClause = implode(',', array_map('intval', $ids));
            $pdo->exec("DELETE FROM guru WHERE id IN ($inClause)");
            echo json_encode(['success' => true, 'message' => count($ids) . ' guru terpilih berhasil dihapus.']);
            break;

        // ------------------------------------------------------------
        // 13. KOSONGKAN SELURUH DATABASE GURU
        // ------------------------------------------------------------
        case 'delete_all_guru':
            $pdo->exec("DELETE FROM guru");
            echo json_encode(['success' => true, 'message' => 'Seluruh database guru berhasil dikosongkan.']);
            break;

        // ------------------------------------------------------------
        // 14. UNGGAH KOLEKTIF DARI EXCEL
        // ------------------------------------------------------------
        case 'import_excel_guru':
            $input = getJsonInput();
            $teachers = $input['teachers'] ?? [];
            if (!is_array($teachers) || count($teachers) === 0) {
                echo json_encode(['success' => false, 'error' => 'Data impor kosong.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO guru (nama, nip, mapel, kelas, password, is_supervisor, is_superadmin, supervisor_id, target_perilaku, status)
                VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, 'Belum')
                ON DUPLICATE KEY UPDATE nama = VALUES(nama), mapel = VALUES(mapel), kelas = VALUES(kelas)
            ");

            $imported = 0;
            foreach ($teachers as $idx => $t) {
                $nama = trim($t['nama'] ?? "Guru Baru " . ($idx + 1));
                $nip = trim($t['nip'] ?? "NIP_" . time() . "_" . $idx);
                $mapel = trim($t['mapel'] ?? 'Umum');
                $kelas = trim($t['kelas'] ?? 'Kelas 7');
                $spvId = ($idx % 6) + 1;
                $target = (strtoupper($mapel) === 'BK') ? 'Perhatian dan Kepedulian' : 'Instruksi Pembelajaran';

                $stmt->execute([$nama, $nip, $mapel, $kelas, $nip, $spvId, $target]);
                $imported++;
            }

            echo json_encode(['success' => true, 'importedCount' => $imported, 'message' => "Berhasil mengimpor {$imported} guru ke server!"]);
            break;

        // ------------------------------------------------------------
        // 15. GANTI KATA SANDI
        // ------------------------------------------------------------
        case 'change_password':
            $input = getJsonInput();
            $userId = (int)($input['userId'] ?? ($_SESSION['user']['id'] ?? 0));
            $newPassword = trim($input['newPassword'] ?? '');

            if (!$userId || strlen($newPassword) < 4) {
                echo json_encode(['success' => false, 'error' => 'Kata sandi minimal 4 karakter!']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE guru SET password = ? WHERE id = ?");
            $stmt->execute([$newPassword, $userId]);

            if (isset($_SESSION['user']) && $_SESSION['user']['id'] === $userId) {
                $_SESSION['user']['password'] = $newPassword;
            }

            echo json_encode(['success' => true, 'message' => 'Kata sandi berhasil diperbarui di database!']);
            break;

        // ------------------------------------------------------------
        // 16. MANAJEMEN USER (SUPER ADMIN: TAMBAH / EDIT USER DENGAN HAK AKSES)
        // ------------------------------------------------------------
        case 'save_user':
            $input = getJsonInput();
            $id = (int)($input['id'] ?? 0);
            $nama = trim($input['nama'] ?? '');
            $nip = trim($input['nip'] ?? '');
            $mapel = trim($input['mapel'] ?? 'Umum');
            $kelas = trim($input['kelas'] ?? 'Kelas 7');
            $spvId = (int)($input['supervisorId'] ?? $input['supervisor_id'] ?? 1);
            $target = trim($input['targetPerilaku'] ?? $input['target_perilaku'] ?? 'Instruksi Pembelajaran');
            $isSupervisor = !empty($input['is_supervisor']) ? 1 : 0;
            $isSuperadmin = !empty($input['is_superadmin']) ? 1 : 0;
            $password = trim($input['password'] ?? '');

            if (!$nama || !$nip) {
                echo json_encode(['success' => false, 'error' => 'Nama lengkap dan NIP wajib diisi!']);
                exit;
            }

            if ($id > 0) {
                // Update User
                $check = $pdo->prepare("SELECT id FROM guru WHERE nip = ? AND id != ?");
                $check->execute([$nip, $id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'error' => "NIP {$nip} sudah digunakan oleh guru lain!"]);
                    exit;
                }

                if ($password !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE guru 
                        SET nama = ?, nip = ?, mapel = ?, kelas = ?, is_supervisor = ?, is_superadmin = ?, supervisor_id = ?, target_perilaku = ?, password = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$nama, $nip, $mapel, $kelas, $isSupervisor, $isSuperadmin, $spvId, $target, $password, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE guru 
                        SET nama = ?, nip = ?, mapel = ?, kelas = ?, is_supervisor = ?, is_superadmin = ?, supervisor_id = ?, target_perilaku = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$nama, $nip, $mapel, $kelas, $isSupervisor, $isSuperadmin, $spvId, $target, $id]);
                }

                echo json_encode(['success' => true, 'message' => "Akun {$nama} berhasil diperbarui!"]);
            } else {
                // Tambah User Baru
                $check = $pdo->prepare("SELECT id FROM guru WHERE nip = ?");
                $check->execute([$nip]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'error' => "NIP {$nip} sudah terdaftar dalam sistem!"]);
                    exit;
                }

                $passFinal = $password !== '' ? $password : '123456';
                $stmt = $pdo->prepare("
                    INSERT INTO guru (nama, nip, mapel, kelas, password, is_supervisor, is_superadmin, supervisor_id, target_perilaku, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Belum')
                ");
                $stmt->execute([$nama, $nip, $mapel, $kelas, $passFinal, $isSupervisor, $isSuperadmin, $spvId, $target]);
                $newId = (int)$pdo->lastInsertId();

                echo json_encode(['success' => true, 'newId' => $newId, 'message' => "User baru {$nama} berhasil ditambahkan!"]);
            }
            break;

        // ------------------------------------------------------------
        // 17. RESET PASSWORD USER (SUPER ADMIN)
        // ------------------------------------------------------------
        case 'reset_password_admin':
            $input = getJsonInput();
            $id = (int)($input['id'] ?? $input['userId'] ?? 0);
            $newPass = trim($input['password'] ?? '123456');

            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID User tidak valid.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE guru SET password = ? WHERE id = ?");
            $stmt->execute([$newPass, $id]);

            echo json_encode(['success' => true, 'message' => "Kata sandi user berhasil direset menjadi '{$newPass}'!"]);
            break;

        // ------------------------------------------------------------
        // 18. HAPUS USER (SUPER ADMIN)
        // ------------------------------------------------------------
        case 'delete_user_admin':
            $input = getJsonInput();
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID User tidak valid.']);
                exit;
            }

            $pdo->prepare("DELETE FROM guru WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Akun pengguna berhasil dihapus.']);
            break;


        case 'check_version':
            echo json_encode([
                'success' => true,
                'appVersion' => '2026.10.23.0710',
                'timestamp' => date('c')
            ]);
            break;

        default:
            echo json_encode([
                'success' => true,
                'appVersion' => '2026.10.23.0710',
                'status' => 'online',
                'service' => 'API Supervisi Akademik SMPN 24 Malang',
                'timestamp' => date('c')
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Terjadi kesalahan server: ' . $e->getMessage()
    ]);
}
