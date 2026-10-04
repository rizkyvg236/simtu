<?php
session_start();
$host = 'localhost';
$db   = 'simtu_smpn236';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Helper: Direktori Arsip Waktu
function getDynamicUploadDir($base_module) {
    $year = date('Y');
    $month = date('m');
    $path = "uploads/" . $base_module . "/arsip/" . $year . "/" . $month . "/";
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
    return $path;
}

// Pastikan folder profil ada
if (!file_exists("uploads/profil")) {
    mkdir("uploads/profil", 0777, true);
}

// SINKRONISASI FOTO PROFIL GLOBAL
// Memastikan avatar terbaru langsung terupdate di seluruh halaman & memperbaiki broken link icon
$avatar_path = 'uploads/profil/default.png'; // Fallback default
if (isset($_SESSION['user_nip'])) {
    $stmt_ava = $pdo->prepare("SELECT logo_profil FROM users WHERE nip = ?");
    $stmt_ava->execute([$_SESSION['user_nip']]);
    $user_ava = $stmt_ava->fetch();
    
    // Validasi apakah string db tidak kosong & file fisiknya eksis di direktori
    if ($user_ava && !empty($user_ava['logo_profil']) && file_exists($user_ava['logo_profil'])) {
        $avatar_path = $user_ava['logo_profil'];
    }
}

// Helper: Penomoran Surat Keluar Otomatis (3 Digit Terakhir + Boxname)
function generateNomorSuratKeluar($pdo, $boxname = 'TU.SMPN236') {
    $year = date('Y');
    $stmt = $pdo->query("SELECT nomor_surat FROM surat_keluar WHERE nomor_surat LIKE '%/$year' ORDER BY no_agenda DESC LIMIT 1");
    $last_surat = $stmt->fetch();
    
    if($last_surat) {
        $parts = explode('/', $last_surat['nomor_surat']);
        $last_number = (int)$parts[0];
        $new_number = str_pad($last_number + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $new_number = "001";
    }
    return $new_number . "/" . $boxname . "/" . date('m') . "/" . $year;
}

// Helper: Dummy Function Notifikasi (Agar tidak error saat dipanggil oleh mutasi/suratmasuk)
function addNotification($pdo, $pesan, $link, $role_target) {
    // Logika tabel notifikasi database jika ada.
}
?>