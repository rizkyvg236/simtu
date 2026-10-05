<?php
require 'koneksi.php';

// Cek autentikasi dan otorisasi hak akses[cite: 2]
if (!isset($_SESSION['user_nip']) || !in_array($_SESSION['role'], ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke modul Beban Ajar.'];
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];

// Auto-patcher: Pastikan tabel beban_ajar dan kolom tambahan sudah ada secara otomatis[cite: 4]
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS beban_ajar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nip VARCHAR(50) NOT NULL,
        mapel_diajarkan VARCHAR(150) NOT NULL,
        jumlah_kelas INT DEFAULT 1,
        rombel VARCHAR(50) DEFAULT NULL,
        nomor_sk VARCHAR(100) DEFAULT NULL,
        tgl_sk DATE DEFAULT NULL,
        tugas_tambahan VARCHAR(150) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("ALTER TABLE beban_ajar 
        ADD COLUMN IF NOT EXISTS rombel VARCHAR(50) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS nomor_sk VARCHAR(100) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS tgl_sk DATE DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS tugas_tambahan VARCHAR(150) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS jumlah_kelas INT DEFAULT 1");
} catch (\PDOException $e) {
    // Abaikan jika sudah ada atau hak akses terbatas
}

// Ambil data pegawai untuk dropdown form[cite: 2]
$stmt_pegawai = $pdo->query("SELECT nip, nama_pegawai, status_pegawai, guru_mapel, tugas_tambahan FROM pegawai ORDER BY nama_pegawai ASC");
$daftar_pegawai = $stmt_pegawai->fetchAll(PDO::FETCH_ASSOC);

// Ambil daftar Rombel unik dari Master Data Siswa[cite: 4]
$daftar_rombel = [];
try {
    $daftar_rombel = $pdo->query("SELECT DISTINCT rombel FROM siswa WHERE rombel IS NOT NULL AND rombel != '' ORDER BY rombel ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $daftar_rombel = [];
}

// ==========================================
// LOGIKA PROSES (POST & GET)
// ==========================================

// A. Download Template Excel (.csv) Beban Ajar[cite: 4, 5]
if (isset($_GET['download_template_beban'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Template_Import_Beban_Ajar_SMPN236.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['nip', 'rombel', 'nomor_sk', 'tgl_sk(yyyy-mm-dd)', 'mapel_diajarkan']);
    fputcsv($output, ['198001012010011001', 'A', 'SK/123/2026', '2026-07-01', 'Matematika']);
    fclose($output);
    exit;
}

// B. Import Data Beban Ajar via Excel/CSV[cite: 4, 5]
if (isset($_POST['import_beban'])) {
    if (isset($_FILES['file_excel_beban']['name']) && !empty($_FILES['file_excel_beban']['name'])) {
        $filename = $_FILES['file_excel_beban']['tmp_name'];
        $handle = fopen($filename, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); 
            $sukses = 0;
            try {
                $stmt_get_tugas = $pdo->prepare("SELECT tugas_tambahan FROM pegawai WHERE nip = ?");
                $stmt = $pdo->prepare("INSERT INTO beban_ajar (nip, rombel, nomor_sk, tgl_sk, tugas_tambahan, mapel_diajarkan, jumlah_kelas) VALUES (?, ?, ?, ?, ?, ?, 1)");
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (isset($data[0]) && !empty(trim($data[0]))) {
                        $nip = trim($data[0]);
                        $rombel = trim($data[1] ?? '');
                        $nomor_sk = trim($data[2] ?? '');
                        $tgl_raw = trim($data[3] ?? '');
                        $tgl_sk = (!empty($tgl_raw)) ? ((strpos($tgl_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tgl_raw))) : $tgl_raw) : null;
                        $mapel_diajarkan = trim($data[4] ?? '');

                        // Otomatis ambil tugas_tambahan dari Master Data Pegawai berdasarkan NIP[cite: 5]
                        $stmt_get_tugas->execute([$nip]);
                        $peg_data = $stmt_get_tugas->fetch(PDO::FETCH_ASSOC);
                        $tugas_tambahan = ($peg_data && !empty($peg_data['tugas_tambahan'])) ? $peg_data['tugas_tambahan'] : '-';

                        $stmt->execute([$nip, $rombel, $nomor_sk, $tgl_sk, $tugas_tambahan, $mapel_diajarkan]);
                        $sukses++;
                    }
                }
                $_SESSION['alert'] = ['type' => 'success', 'title' => 'Import Berhasil', 'text' => "Sebanyak $sukses data beban ajar berhasil diimpor."];
            } catch (PDOException $e) {
                $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Import', 'text' => 'Terjadi kesalahan pada database: ' . $e->getMessage()];
            }
            fclose($handle);
        }
    }
    header("Location: beban_ajar.php");
    exit;
}

// 1. Tambah Data Beban Ajar[cite: 2, 4, 5]
if (isset($_POST['tambah_beban'])) {
    $nip = $_POST['nip'];
    $mapel_diajarkan = $_POST['mapel_diajarkan'];
    $jumlah_kelas = 1; 
    $rombel = $_POST['rombel'] ?? '';
    $nomor_sk = $_POST['nomor_sk'] ?? '';
    $tgl_sk = !empty($_POST['tgl_sk']) ? $_POST['tgl_sk'] : null;

    try {
        // Otomatis ambil tugas_tambahan dari tabel pegawai berdasarkan NIP[cite: 5]
        $stmt_tugas = $pdo->prepare("SELECT tugas_tambahan FROM pegawai WHERE nip = ?");
        $stmt_tugas->execute([$nip]);
        $peg = $stmt_tugas->fetch(PDO::FETCH_ASSOC);
        $tugas_tambahan = ($peg && !empty($peg['tugas_tambahan'])) ? $peg['tugas_tambahan'] : '-';

        $stmt = $pdo->prepare("INSERT INTO beban_ajar (nip, mapel_diajarkan, jumlah_kelas, rombel, nomor_sk, tgl_sk, tugas_tambahan) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nip, $mapel_diajarkan, $jumlah_kelas, $rombel, $nomor_sk, $tgl_sk, $tugas_tambahan]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data beban ajar berhasil ditambahkan.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => $e->getMessage()];
    }
    header("Location: beban_ajar.php");
    exit;
}

// 2. Edit Data Beban Ajar[cite: 2, 4, 5]
if (isset($_POST['edit_beban'])) {
    $id = $_POST['id'];
    $nip = $_POST['nip'];
    $mapel_diajarkan = $_POST['mapel_diajarkan'];
    $jumlah_kelas = 1; 
    $rombel = $_POST['rombel'] ?? '';
    $nomor_sk = $_POST['nomor_sk'] ?? '';
    $tgl_sk = !empty($_POST['tgl_sk']) ? $_POST['tgl_sk'] : null;

    try {
        // Otomatis ambil tugas_tambahan dari tabel pegawai berdasarkan NIP[cite: 5]
        $stmt_tugas = $pdo->prepare("SELECT tugas_tambahan FROM pegawai WHERE nip = ?");
        $stmt_tugas->execute([$nip]);
        $peg = $stmt_tugas->fetch(PDO::FETCH_ASSOC);
        $tugas_tambahan = ($peg && !empty($peg['tugas_tambahan'])) ? $peg['tugas_tambahan'] : '-';

        $stmt = $pdo->prepare("UPDATE beban_ajar SET nip = ?, mapel_diajarkan = ?, jumlah_kelas = ?, rombel = ?, nomor_sk = ?, tgl_sk = ?, tugas_tambahan = ? WHERE id = ?");
        $stmt->execute([$nip, $mapel_diajarkan, $jumlah_kelas, $rombel, $nomor_sk, $tgl_sk, $tugas_tambahan, $id]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data beban ajar berhasil diperbarui.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => $e->getMessage()];
    }
    header("Location: beban_ajar.php");
    exit;
}

// 3. Hapus Data Satuan[cite: 2, 4]
if (isset($_POST['hapus_beban'])) {
    $id = $_POST['id'];
    try {
        $pdo->prepare("DELETE FROM beban_ajar WHERE id = ?")->execute([$id]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Data beban ajar berhasil dihapus.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => $e->getMessage()];
    }
    header("Location: beban_ajar.php");
    exit;
}

// 4. Hapus Semua Data[cite: 2, 4]
if (isset($_POST['hapus_semua'])) {
    try {
        $pdo->query("DELETE FROM beban_ajar");
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Seluruh data beban ajar berhasil dikosongkan.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => $e->getMessage()];
    }
    header("Location: beban_ajar.php");
    exit;
}

// Ambil data beban ajar dengan join ke tabel pegawai[cite: 2, 4, 5]
try {
    $query = "SELECT b.*, p.nama_pegawai, p.status_pegawai, p.guru_mapel, p.tugas_tambahan as tugas_pegawai 
              FROM beban_ajar b 
              LEFT JOIN pegawai p ON b.nip = p.nip 
              ORDER BY b.id DESC";
    $data_beban = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_beban = [];
}

function hitungBebanAjar($mapel, $tugas, $nip, &$tugas_dihitung) {
    $jam_mapel = 0;
    if (strpos($mapel, 'Pendidikan Agama Islam') !== false || strpos($mapel, 'Pendidikan Agama Kristen') !== false || strpos($mapel, 'PJOK') !== false || strpos($mapel, 'Informatika') !== false || strpos($mapel, 'Seni Budaya') !== false) {
        $jam_mapel = 3;
    } elseif (strpos($mapel, 'Bahasa Indonesia') !== false) {
        $jam_mapel = 6;
    } elseif (strpos($mapel, 'Matematika') !== false || strpos($mapel, 'Ilmu Pengetahuan Alam') !== false) {
        $jam_mapel = 5;
    } elseif (strpos($mapel, 'Bahasa Inggris') !== false || strpos($mapel, 'Ilmu Pengetahuan Sosial') !== false) {
        $jam_mapel = 4;
    } elseif (strpos($mapel, 'Bimbingan Konseling') !== false) {
        $jam_mapel = 1;
    }

    $jam_tugas = 0;
    if (!in_array($nip, $tugas_dihitung, true)) {
        if (strpos($tugas, 'Wakil Kepala Sekolah') !== false) {
            $jam_tugas = 12;
        } elseif (strpos($tugas, 'Kepala Sekolah') !== false) {
            $jam_tugas = 24;
        } elseif (strpos($tugas, 'Kepala Laboratorium') !== false || strpos($tugas, 'Kepala Perpustakaan') !== false) {
            $jam_tugas = 12;
        } elseif (strpos($tugas, 'Wali Kelas') !== false) {
            $jam_tugas = 2;
        }

        if ($tugas !== '-' && $tugas !== null && $tugas !== '') {
            $tugas_dihitung[] = $nip;
        }
    }

    return [
        'mapel' => $jam_mapel,
        'tugas' => $jam_tugas,
        'total' => $jam_mapel + $jam_tugas,
    ];
}

if (isset($_GET['export_excel'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Beban_Ajar_Guru_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'NIP / NIK', 'Nama Pegawai', 'Status Pegawai', 'Rombel', 'Nomor SK', 'Tanggal SK', 'Tugas Tambahan', 'Mapel yang Diajarkan', 'Beban Jam']);

    $tugas_dihitung = [];
    foreach ($data_beban as $index => $row) {
        $nip = $row['nip'] ?? '';
        $mapel = $row['mapel_diajarkan'] ?? '';
        $tugas = !empty($row['tugas_pegawai']) ? $row['tugas_pegawai'] : ($row['tugas_tambahan'] ?? '-');
        $beban = hitungBebanAjar($mapel, $tugas, $nip, $tugas_dihitung);

        fputcsv($output, [
            $index + 1,
            $nip ?: '-',
            $row['nama_pegawai'] ?? '-',
            $row['status_pegawai'] ?? '-',
            $row['rombel'] ?? '-',
            $row['nomor_sk'] ?? '-',
            !empty($row['tgl_sk']) ? date('d/m/Y', strtotime($row['tgl_sk'])) : '-',
            $tugas,
            $mapel ?: '-',
            $beban['total'] . ' Jam'
        ]);
    }

    fclose($output);
    exit;
}

if (isset($_GET['export_pdf'])) {
    $judul = 'Laporan Beban Ajar Guru';
    $tanggal = date('d-m-Y');
    echo '<!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>' . htmlspecialchars($judul) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
            h2 { text-align: center; margin-bottom: 8px; }
            .meta { text-align: right; font-size: 12px; margin-bottom: 18px; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; vertical-align: top; }
            th { background: #f3f4f6; }
            @media print { body { margin: 0; } }
        </style>
    </head>
    <body>
        <h2>' . htmlspecialchars($judul) . '</h2>
        <div class="meta">Tanggal: ' . htmlspecialchars($tanggal) . '</div>
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>NIP / NIK</th>
                    <th>Nama Pegawai</th>
                    <th>Status</th>
                    <th>Rombel</th>
                    <th>Nomor SK</th>
                    <th>Tgl SK</th>
                    <th>Tugas Tambahan</th>
                    <th>Mapel</th>
                    <th>Beban Jam</th>
                </tr>
            </thead>
            <tbody>';

    if (!empty($data_beban)) {
        $tugas_dihitung = [];
        foreach ($data_beban as $index => $row) {
            $nip = $row['nip'] ?? '';
            $mapel = $row['mapel_diajarkan'] ?? '';
            $tugas = !empty($row['tugas_pegawai']) ? $row['tugas_pegawai'] : ($row['tugas_tambahan'] ?? '-');
            $beban = hitungBebanAjar($mapel, $tugas, $nip, $tugas_dihitung);

            echo '<tr>
                <td>' . ($index + 1) . '</td>
                <td>' . htmlspecialchars($nip ?: '-') . '</td>
                <td>' . htmlspecialchars($row['nama_pegawai'] ?? '-') . '</td>
                <td>' . htmlspecialchars($row['status_pegawai'] ?? '-') . '</td>
                <td>' . htmlspecialchars($row['rombel'] ?? '-') . '</td>
                <td>' . htmlspecialchars($row['nomor_sk'] ?? '-') . '</td>
                <td>' . (!empty($row['tgl_sk']) ? htmlspecialchars(date('d/m/Y', strtotime($row['tgl_sk']))) : '-') . '</td>
                <td>' . htmlspecialchars($tugas) . '</td>
                <td>' . htmlspecialchars($mapel ?: '-') . '</td>
                <td>' . htmlspecialchars($beban['total'] . ' Jam') . '</td>
            </tr>';
        }
    } else {
        echo '<tr><td colspan="10" style="text-align:center;">Tidak ada data</td></tr>';
    }

    echo '</tbody>
        </table>
        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                    setTimeout(function() { window.close(); }, 1000);
                }, 300);
            };
        </script>
    </body>
    </html>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Beban Ajar Guru | SIMTU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<!-- SIDEBAR SESUAI STRUKTUR -->
<div class="sidebar" id="sidebar">
    <div class="p-3 text-center border-bottom border-secondary mb-3">
        <h4 class="logo-text m-0"> <img src="uploads/assets/logo-login.png" alt="LOGO" style="height: 40px; vertical-align: middle; margin-right: 8px;"> <span class="menu-text">SIM-TU 236</span></h4>
    </div>
    
    <?php 
    $current_page = basename($_SERVER['PHP_SELF']); 
    $cek_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
    ?>
<!-- 1. Dashboard -->
    <a href="dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
        <i class="fas fa-chart-pie"></i> <span class="menu-text">Dashboard</span>
    </a>
<!-- 2. Manajemen User -->      
    <?php if(in_array($cek_role, ['Administrator'])): ?>
    <a href="admin.php" class="<?= $current_page == 'admin.php' ? 'active' : '' ?>">
        <i class="fas fa-users-cog"></i> <span class="menu-text">Manajemen User</span>
    </a>
    <?php endif; ?>
<!-- 3. Menu Kepegawaian (Dropdown) -->
    <?php 
    $is_kepegawaian = in_array($current_page, ['duk.php', 'arsip_kepeg.php', 'beban_ajar.php']);
    if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): 
    ?>
    <a data-bs-toggle="collapse" href="#menuKepegawaian" role="button" aria-expanded="<?= $is_kepegawaian ? 'true' : 'false' ?>" aria-controls="menuKepegawaian" class="<?= $is_kepegawaian ? 'active text-white' : '' ?>">
        <i class="fas fa-user-tie"></i> <span class="menu-text">Kepegawaian</span>
        <i class="fas fa-chevron-down ms-auto menu-text" style="font-size: 0.8rem;"></i>
    </a>
    <div class="collapse <?= $is_kepegawaian ? 'show' : '' ?>" id="menuKepegawaian" data-bs-parent="#sidebar">
        <?php if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="duk.php" class="<?= $current_page == 'duk.php' ? 'active' : '' ?>"><i class="fas fa-list-ol"></i> <span class="menu-text">DUK & Masa Bakti</span></a>
        <?php endif; ?>
        <?php if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="arsip_kepeg.php" class="<?= $current_page == 'arsip_kepeg.php' ? 'active' : '' ?>"><i class="fa-regular fa-folder" style="color: rgb(255, 255, 255);"></i> <span class="menu-text">Arsip Kepegawaian</span></a>
        <?php endif; ?>
        <?php if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="beban_ajar.php" class="<?= $current_page == 'beban_ajar.php' ? 'active' : '' ?>"><i class="fas fa-book-reader" style="color: rgb(255, 255, 255);"></i> <span class="menu-text">Beban Ajar</span></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<!-- 4. Menu Persuratan (Dropdown) -->
    <?php 
    $is_persuratan = in_array($current_page, ['suratmasuk.php', 'suratkeluar.php']);
    if(in_array($cek_role, ['Administrator', 'TU_Persuratan', 'Kepala_Sekolah', 'Kepala_TU'])): 
    ?>
    <a data-bs-toggle="collapse" href="#menuPersuratan" role="button" aria-expanded="<?= $is_persuratan ? 'true' : 'false' ?>" aria-controls="menuPersuratan" class="<?= $is_persuratan ? 'active text-white' : '' ?>">
        <i class="fas fa-envelope"></i> <span class="menu-text">Persuratan</span>
        <i class="fas fa-chevron-down ms-auto menu-text" style="font-size: 0.8rem;"></i>
    </a>
    <div class="collapse <?= $is_persuratan ? 'show' : '' ?>" id="menuPersuratan" data-bs-parent="#sidebar">
        <?php if(in_array($cek_role, ['Administrator', 'TU_Persuratan', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="suratmasuk.php" class="<?= $current_page == 'suratmasuk.php' ? 'active' : '' ?>"><i class="fas fa-envelope-open-text"></i> <span class="menu-text">Surat Masuk</span></a>
        <?php endif; ?>
        <?php if(in_array($cek_role, ['Administrator', 'TU_Persuratan', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="suratkeluar.php" class="<?= $current_page == 'suratkeluar.php' ? 'active' : '' ?>"><i class="fas fa-paper-plane"></i> <span class="menu-text">Surat Keluar</span></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<!-- 5. Menu Kesiswaan (Dropdown) -->    
    <?php 
    $is_kesiswaan = in_array($current_page, ['mutasi.php', 'piket.php']);
    if(in_array($cek_role, ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU', 'Guru_Piket'])): 
    ?>
    <a data-bs-toggle="collapse" href="#menuKesiswaan" role="button" aria-expanded="<?= $is_kesiswaan ? 'true' : 'false' ?>" aria-controls="menuKesiswaan" class="<?= $is_kesiswaan ? 'active text-white' : '' ?>">
        <i class="fas fa-users"></i> <span class="menu-text">Kesiswaan</span>
        <i class="fas fa-chevron-down ms-auto menu-text" style="font-size: 0.8rem;"></i>
    </a>
    <div class="collapse <?= $is_kesiswaan ? 'show' : '' ?>" id="menuKesiswaan" data-bs-parent="#sidebar">
        <?php if(in_array($cek_role, ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="mutasi.php" class="<?= $current_page == 'mutasi.php' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> <span class="menu-text">Mutasi Siswa</span></a>
        <?php endif; ?>
        <?php if(in_array($cek_role, ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU', 'Guru_Piket'])): ?>
        <a href="piket.php" class="<?= $current_page == 'piket.php' ? 'active' : '' ?>"><i class="fas fa-clipboard-user"></i> <span class="menu-text">Piket Pintar</span></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<!-- 6. Menu Laporan / Report -->
    <?php if(in_array($cek_role, ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU', 'Guru_Piket', 'TU_Persuratan'])): ?>
    <a href="laporan.php" class="<?= $current_page == 'laporan.php' ? 'active' : '' ?>">
        <i class="fas fa-file-export"></i> <span class="menu-text">Report</span>
    </a>
    <?php endif; ?>
 <!-- 7. Logout -->   
    <a href="logout.php" class="mt-auto text-danger"><i class="fas fa-sign-out-alt"></i> <span class="menu-text">Logout</span></a>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="main-content">
    <div class="top-header mb-4">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">Manajemen Beban Ajar Guru</h5>
        <div class="d-flex align-items-center">
            <img src="<?= $avatar_path ?? 'uploads/profil/default.png' ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
            <div>
                <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($_SESSION['role']) ?></small>
            </div>
        </div>
    </div>

    <!-- KONTEN UTAMA: TABEL BEBAN AJAR -->
    <div class="card border-0 shadow-sm p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-book-reader"></i> Daftar Beban Ajar Guru</h5>
            <div>
                <a href="beban_ajar.php?export_excel=true" class="btn btn-sm btn-outline-success me-2">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="beban_ajar.php?export_pdf=true" target="_blank" class="btn btn-sm btn-outline-danger me-2">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="beban_ajar.php?download_template_beban=true" class="btn btn-sm btn-outline-success me-2">
                    <i class="fas fa-download"></i> Download Format Excel
                </a>
                <button class="btn btn-sm btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalUploadBeban">
                    <i class="fas fa-file-excel"></i> Import Excel
                </button>
                <button class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#modalTambahBeban">
                    <i class="fas fa-plus"></i> Tambah Beban Ajar
                </button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus SELURUH data beban ajar?');">
                    <button type="submit" name="hapus_semua" class="btn btn-sm btn-danger">
                        <i class="fas fa-trash-alt"></i> Hapus Semua
                    </button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead style="background-color: var(--bg-light);">
                    <tr>
                        <th>No</th>
                        <th>NIP / NIK</th>
                        <th>Nama Pegawai</th>
                        <th>Status Pegawai</th>
                        <th>Rombel</th>
                        <th>Nomor SK</th>
                        <th>Tgl SK</th>
                        <th>Tugas Tambahan</th>
                        <th>Mapel yang Diajarkan</th>
                        <th>Beban Jam</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($data_beban) > 0): $no=1; 
                        $tugas_dihitung = [];
                        foreach($data_beban as $row): 
                            $nip = $row['nip'];
                            $mapel = $row['mapel_diajarkan'];
                            $tugas = !empty($row['tugas_pegawai']) ? $row['tugas_pegawai'] : ($row['tugas_tambahan'] ?? '-');
                            $beban = hitungBebanAjar($mapel, $tugas, $nip, $tugas_dihitung);
                            $jam_mapel = $beban['mapel'];
                            $jam_tugas = $beban['tugas'];
                            $total_beban_jam = $beban['total'];
                    ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td><strong><?= htmlspecialchars($row['nip']) ?></strong></td>
                        <td><?= htmlspecialchars($row['nama_pegawai'] ?? 'Tidak Ditemukan') ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($row['status_pegawai'] ?? '-') ?></span></td>
                        <td><?= htmlspecialchars($row['rombel'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['nomor_sk'] ?? '-') ?></td>
                        <td><?= (!empty($row['tgl_sk']) && $row['tgl_sk'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tgl_sk'])) : '-' ?></td>
                        <td><?= htmlspecialchars($tugas) ?></td>
                        <td><strong><?= htmlspecialchars($mapel) ?></strong></td>
                        <td>
                            <span class="badge bg-info text-dark"><?= $total_beban_jam ?> Jam</span><br>
                            <small class="text-muted">(Mapel: <?= $jam_mapel ?> + Tugas: <?= $jam_tugas ?>)</small>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-warning text-white" data-bs-toggle="modal" data-bs-target="#modalEditBeban<?= $row['id'] ?>"><i class="fas fa-edit"></i></button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data beban ajar ini?');">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" name="hapus_beban" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>

                    <!-- MODAL EDIT BEBAN AJAR -->
                    <div class="modal fade" id="modalEditBeban<?= $row['id'] ?>" tabindex="-1">
                      <div class="modal-dialog">
                        <div class="modal-content">
                          <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                            <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Beban Ajar Guru</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                          </div>
                          <form method="POST">
                              <div class="modal-body">
                                  <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                  <div class="mb-3">
                                      <label>Pilih Pegawai / Guru</label>
                                      <select name="nip" class="form-select" required>
                                          <?php foreach($daftar_pegawai as $p): ?>
                                            <option value="<?= $p['nip'] ?>" <?= $p['nip'] == $row['nip'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($p['nama_pegawai']) ?> (NIP: <?= $p['nip'] ?>)
                                            </option>
                                          <?php endforeach; ?>
                                      </select>
                                  </div>
                                  <div class="mb-3">
                                      <label>Rombel (Master Data Siswa)</label>
                                      <select name="rombel" class="form-select" required>
                                          <option value="">-- Pilih Rombel --</option>
                                          <?php foreach($daftar_rombel as $r): ?>
                                              <option value="<?= htmlspecialchars($r) ?>" <?= (isset($row['rombel']) && $row['rombel'] == $r) ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                          <?php endforeach; ?>
                                      </select>
                                  </div>
                                  <div class="row">
                                      <div class="col-md-6 mb-3">
                                          <label>Nomor SK</label>
                                          <input type="text" name="nomor_sk" class="form-control" value="<?= htmlspecialchars($row['nomor_sk'] ?? '') ?>" placeholder="Nomor SK">
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label>Tgl SK</label>
                                          <input type="date" name="tgl_sk" class="form-control" value="<?= htmlspecialchars($row['tgl_sk'] ?? '') ?>">
                                      </div>
                                  </div>
                                  <div class="mb-3">
                                      <label>Mapel yang Diajarkan</label>
                                      <select name="mapel_diajarkan" class="form-select" required>
                                          <?php 
                                          $list_mapel = [
                                              'Pendidikan Agama Islam (PAI)',
                                              'Pendidikan Agama Kristen (PAK)',
                                              'Bahasa Indonesia',
                                              'Matematika',
                                              'Bahasa Inggris',
                                              'Ilmu Pengetahuan Alam (IPA)',
                                              'Ilmu Pengetahuan Sosial (IPS)',
                                              'PJOK',
                                              'Informatika (TIK)',
                                              'Seni Budaya & Prakarya',
                                              'Bimbingan Konseling'
                                          ];
                                          foreach($list_mapel as $m) {
                                              $sel = ($row['mapel_diajarkan'] == $m) ? 'selected' : '';
                                              echo "<option value=\"$m\" $sel>$m</option>";
                                          }
                                          ?>
                                      </select>
                                  </div>
                              </div>
                              <div class="modal-footer">
                                <button type="submit" name="edit_beban" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Perubahan</button>
                              </div>
                          </form>
                        </div>
                      </div>
                    </div>

                    <?php endforeach; else: ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">Belum ada data Beban Ajar Guru.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH BEBAN AJAR -->
<div class="modal fade" id="modalTambahBeban" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-book-reader"></i> Tambah Beban Ajar Guru</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <div class="modal-body">
              <div class="mb-3">
                  <label>Pilih Pegawai / Guru</label>
                  <select name="nip" class="form-select" required>
                      <option value="">-- Pilih Pegawai --</option>
                      <?php foreach($daftar_pegawai as $p): ?>
                        <option value="<?= $p['nip'] ?>"><?= htmlspecialchars($p['nama_pegawai']) ?> (NIP: <?= $p['nip'] ?>)</option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="mb-3">
                  <label>Rombel (Master Data Siswa)</label>
                  <select name="rombel" class="form-select" required>
                      <option value="">-- Pilih Rombel --</option>
                      <?php foreach($daftar_rombel as $r): ?>
                          <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label>Nomor SK</label>
                      <input type="text" name="nomor_sk" class="form-control" placeholder="Nomor SK">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label>Tgl SK</label>
                      <input type="date" name="tgl_sk" class="form-control">
                  </div>
              </div>
              <div class="mb-3">
                  <label>Mapel yang Diajarkan</label>
                  <select name="mapel_diajarkan" class="form-select" required>
                      <option value="" disabled selected>-- Pilih Mata Pelajaran --</option>
                      <option value="Pendidikan Agama Islam (PAI)">Pendidikan Agama Islam (PAI) - 3 Jam</option>
                      <option value="Pendidikan Agama Kristen (PAK)">Pendidikan Agama Kristen (PAK) - 3 Jam</option>
                      <option value="Bahasa Indonesia">Bahasa Indonesia - 6 Jam</option>
                      <option value="Matematika">Matematika - 5 Jam</option>
                      <option value="Bahasa Inggris">Bahasa Inggris - 4 Jam</option>
                      <option value="Ilmu Pengetahuan Alam (IPA)">Ilmu Pengetahuan Alam (IPA) - 5 Jam</option>
                      <option value="Ilmu Pengetahuan Sosial (IPS)">Ilmu Pengetahuan Sosial (IPS) - 4 Jam</option>
                      <option value="PJOK">PJOK - 3 Jam</option>
                      <option value="Informatika (TIK)">Informatika (TIK) - 3 Jam</option>
                      <option value="Seni Budaya & Prakarya">Seni Budaya & Prakarya - 3 Jam</option>
                      <option value="Bimbingan Konseling">Bimbingan Konseling - 1 Jam</option>
                  </select>
              </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="tambah_beban" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Beban Ajar</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL UPLOAD / IMPORT EXCEL BEBAN AJAR -->
<div class="modal fade" id="modalUploadBeban" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-file-excel"></i> Import Data Beban Ajar (Excel/CSV)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label">Pilih File Format Excel/CSV (.csv)</label>
                  <input type="file" name="file_excel_beban" class="form-control" accept=".csv" required>
                  <small class="text-muted">Pastikan struktur kolom sesuai dengan format template yang disediakan.</small>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="import_beban" class="btn" style="background: var(--navy-blue); color: var(--gold);">Import Data</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // JS Toggle Sidebar
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('main-content').classList.toggle('expanded');
    });

    // SweetAlert2 Notification Handling
    <?php if(isset($_SESSION['alert'])): ?>
        Swal.fire({ 
            icon: '<?= $_SESSION['alert']['type'] ?>', 
            title: '<?= $_SESSION['alert']['title'] ?>', 
            text: '<?= $_SESSION['alert']['text'] ?>',
            confirmButtonColor: '#0A192F'
        });
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>
</script>
</body>
</html>