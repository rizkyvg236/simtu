<?php
require 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nip = $_POST['nip'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE nip = ?");
    $stmt->execute([$nip]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_nip'] = $user['nip'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['nama'] = $user['nama_lengkap'];
        $_SESSION['jabatan'] = $user['jabatan'];
        $_SESSION['logo_profil'] = (!empty($user['logo_profil']) && file_exists($user['logo_profil'])) ? $user['logo_profil'] : 'uploads/profil/default.png';
        
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Login Berhasil', 'text' => 'Selamat datang di SIMTU SMPN 236 Jakarta.'];
        header("Location: dashboard.php");
        exit;
    } else {
        $alert = ['type' => 'error', 'title' => 'Login Gagal', 'text' => 'NIP atau Password salah!'];
    }
}

// Cek Keberadaan Aset Logo dan Background
$bg_image_path = "uploads/assets/bg-login.jpg";
$logo_image_path = "uploads/assets/logo-login.png";

$bg_image = file_exists($bg_image_path) ? $bg_image_path . "?v=" . filemtime($bg_image_path) : "";
$logo_image = file_exists($logo_image_path) ? $logo_image_path . "?v=" . filemtime($logo_image_path) : "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>LOGIN | SIMTU SMPN 236</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="login-body" style="<?= $bg_image ? "background-image: url('$bg_image');" : "background-color: var(--navy-blue);" ?>">
    
    <div class="container d-flex justify-content-center align-items-center">
        <div class="row justify-content-center w-100">
            <div class="col-md-5">
                <div class="card p-4 shadow-lg border-0 login-card">
                    <div class="text-center mb-4">
                        <?php if($logo_image): ?>
                            <img src="<?= $logo_image ?>" alt="Logo SIMTU" style="max-height: 75px; max-width: 100%; width: auto; object-fit: contain; margin-bottom: 12px;">
                        <?php else: ?>
                            <h3 style="color: var(--navy-blue); font-weight: 700;">SIM-TU</h3>
                        <?php endif; ?>
                        <p class="text-muted fw-bold mb-0">LOGIN PAGE</p>
                        <p class="text-muted fw-bold mb-0">SISTEM INFORMASI MANAJEMEN TATA USAHA</p>
                        <p class="text-muted fw-bold mb-0">SMP NEGERI 236 JAKARTA</p>
                    </div>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="fw-semibold">NIP / ID Pengguna</label>
                            <input type="text" name="nip" class="form-control" required placeholder="Masukkan NIP">
                        </div>
                        <div class="mb-4">
                            <label class="fw-semibold">Password</label>
                            <input type="password" name="password" class="form-control" required placeholder="Masukkan Password">
                        </div>
                        <button type="submit" class="btn w-100 py-2 shadow-sm" style="background: var(--gold); color: var(--navy-blue); font-weight: 700; font-size: 1.1rem;">LOGIN SISTEM</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER ANIMASI BERJALAN (RUNNING TEXT) -->
    <div class="login-footer">
        <div class="marquee-container">
            <span>CREATED by Rizky Agustian, S.Kom &mdash; SISTEM INFORMASI MANAJEMEN TATA USAHA (SIMTU) SMP NEGERI 236 JAKARTA</span>
        </div>
    </div>

<?php if(isset($_GET['pesan']) && $_GET['pesan'] == 'logout'): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'WELCOME BACK!',
        text: 'SELAMAT DATANG di SIM-TU SMPN 236 JAKARTA.',
        confirmButtonColor: '#0A192F'
     });
</script>
<?php endif; ?>
<?php if(isset($alert)): ?>
<script>
    Swal.fire({
        icon: '<?= $alert['type'] ?>',
        title: '<?= $alert['title'] ?>',
        text: '<?= $alert['text'] ?>',
        confirmButtonColor: '#0A192F'
    });
</script>
<?php endif; ?>
</body>
</html>