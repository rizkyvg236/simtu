<?php
// Memanggil koneksi.php yang sudah mencakup session_start()
require 'koneksi.php';

// 1. Kosongkan semua variabel array sesi
$_SESSION = array();

// 2. Hapus cookie sesi jika cookie tersebut digunakan (opsional tapi disarankan untuk keamanan penuh)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Hancurkan sesi di sisi server
session_destroy();

// 4. Redirect kembali ke halaman login dengan status logout
header("Location: login.php?pesan=logout");
exit;
?>