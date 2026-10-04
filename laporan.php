<?php
require 'koneksi.php';

// Cek autentikasi dan otorisasi hak akses
if (!isset($_SESSION['user_nip']) || !in_array($_SESSION['role'], ['Administrator', 'TU_Kesiswaan', 'TU_Persuratan', 'Kepala_Sekolah', 'Kepala_TU', 'Guru_Piket'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke modul Laporan.'];
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];

// Filter kategori laporan & parameter dinamis
$jenis_laporan = $_GET['jenis'] ?? 'mutasi';
$start_date    = $_GET['start_date'] ?? date('Y-01-01');
$end_date      = $_GET['end_date'] ?? date('Y-m-d');
$top_margin    = $_GET['top_margin'] ?? '10';    
$bottom_margin = $_GET['bottom_margin'] ?? '10'; 
$custom_title  = $_GET['custom_title'] ?? ''; // Parameter Judul Kustom

// Daftar Rombel & Hitung Total Siswa per Rombel untuk Acuan Otomatis
$list_rombel = ['7A', '7B', '7C', '7D', '7E', '7F', '7G', '8A', '8B', '8C', '8D', '8E', '8F', '8G', '9A', '9B', '9C', '9D', '9E', '9F', '9G'];
$total_siswa_per_rombel = [];
foreach($list_rombel as $r) {
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM siswa WHERE rombel = ? AND status = 'Aktif'");
    $stmt_count->execute([$r]);
    $total_siswa_per_rombel[$r] = (int)$stmt_count->fetchColumn();
}

// ==========================================
// LOGIKA EXPORT EXCEL (CSV)
// ==========================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Penamaan file dinamis menyesuaikan judul kustom jika ada
    $nama_file_dasar = !empty($custom_title) ? preg_replace('/[^A-Za-z0-9\-]/', '_', $custom_title) : "Laporan_" . ucfirst($jenis_laporan);
    $filename = $nama_file_dasar . "_" . date('Y-m-d') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');

    if ($jenis_laporan == 'mutasi') {
        fputcsv($output, ['ID', 'Jenis Mutasi', 'No Surat Pindah', 'NISN', 'Nama Siswa', 'Kelas', 'Sekolah Asal/Tujuan', 'Alasan', 'Tanggal']);
        $stmt = $pdo->prepare("SELECT m.*, s.nama_lengkap, s.kelas, s.rombel FROM mutasi m JOIN siswa s ON m.nisn = s.nisn WHERE DATE(m.created_at) BETWEEN ? AND ? ORDER BY m.created_at DESC");
        $stmt->execute([$start_date, $end_date]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, [$row['id'], $row['jenis_mutasi'], $row['no_surat_pindah'], $row['nisn'], $row['nama_lengkap'], $row['kelas'], $row['sekolah_tujuan_asal'], $row['alasan_pindah'], $row['created_at']]);
        }
    } elseif ($jenis_laporan == 'surat_masuk') {
        fputcsv($output, ['No Agenda', 'Tanggal Diterima', 'Nomor Surat', 'Pengirim', 'Perihal', 'Status Disposisi']);
        $stmt = $pdo->prepare("SELECT * FROM surat_masuk WHERE tgl_diterima BETWEEN ? AND ? ORDER BY no_agenda DESC");
        $stmt->execute([$start_date, $end_date]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, [$row['no_agenda'], $row['tgl_diterima'], $row['nomor_surat'], $row['pengirim'], $row['perihal'], $row['status_disposisi']]);
        }
    } elseif ($jenis_laporan == 'surat_keluar') {
        fputcsv($output, ['No Agenda', 'Tanggal Dikirim', 'Nomor Surat', 'Tujuan', 'Perihal', 'Status Disposisi']);
        $stmt = $pdo->prepare("SELECT * FROM surat_keluar WHERE tgl_dikirim BETWEEN ? AND ? ORDER BY no_agenda DESC");
        $stmt->execute([$start_date, $end_date]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, [$row['no_agenda'], $row['tgl_dikirim'], $row['nomor_surat'], $row['tujuan'], $row['perihal'], $row['status_disposisi']]);
        }
    } elseif ($jenis_laporan == 'absensi') {
        // UPDATE CSV EXPORT: MENGGUNAKAN LEFT JOIN & LOGIKA PENJUMLAHAN ROMBEL AKURAT
        fputcsv($output, ['Hari', 'Tanggal', 'Rombel', 'NISN', 'Nama Siswa', 'Keterangan', 'Alasan', 'Jumlah Siswa Masuk', 'Petugas Piket']);
        $stmt = $pdo->prepare("SELECT pa.*, s.nama_lengkap FROM piket_absensi pa LEFT JOIN siswa s ON pa.nisn = s.nisn WHERE pa.tanggal BETWEEN ? AND ? ORDER BY pa.tanggal DESC, pa.rombel ASC");
        $stmt->execute([$start_date, $end_date]);
        $rows_csv = $stmt->fetchAll();
        
        // Kalkulasi akurat total yang tidak masuk di CSV
        $absen_count_csv = [];
        foreach($rows_csv as $r) {
            $key = $r['tanggal'].'_'.$r['rombel'];
            if(!isset($absen_count_csv[$key])) { $absen_count_csv[$key] = 0; }
            if ($r['nisn'] !== 'NIHIL' && $r['keterangan'] !== 'NIHIL') {
                $absen_count_csv[$key]++;
            }
        }
        
        foreach ($rows_csv as $row) {
            $key = $row['tanggal'].'_'.$row['rombel'];
            $tot_rombel = $total_siswa_per_rombel[$row['rombel']] ?? 0;
            $jml_masuk_csv = max(0, $tot_rombel - ($absen_count_csv[$key] ?? 0));
            
            $disp_nama = ($row['keterangan'] === 'NIHIL' || $row['nisn'] === 'NIHIL') ? 'SELURUH SISWA MASUK' : $row['nama_lengkap'];
            $disp_nisn = ($row['keterangan'] === 'NIHIL' || $row['nisn'] === 'NIHIL') ? 'NIHIL' : $row['nisn'];

            fputcsv($output, [$row['hari'], $row['tanggal'], $row['rombel'], $disp_nisn, $disp_nama, $row['keterangan'], $row['alasan'], $jml_masuk_csv, $row['petugas']]);
        }
    } elseif ($jenis_laporan == 'pulang_cepat') {
        fputcsv($output, ['Hari', 'Tanggal', 'Rombel', 'NISN', 'Nama Siswa', 'Alasan Pulang', 'Petugas Piket']);
        $stmt = $pdo->prepare("SELECT pp.*, s.nama_lengkap FROM piket_pulang_cepat pp JOIN siswa s ON pp.nisn = s.nisn WHERE pp.tanggal BETWEEN ? AND ? ORDER BY pp.tanggal DESC");
        $stmt->execute([$start_date, $end_date]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, [$row['hari'], $row['tanggal'], $row['rombel'], $row['nisn'], $row['nama_lengkap'], $row['alasan'], $row['petugas']]);
        }
    }
    fclose($output);
    exit;
}

// Fetch data untuk preview tabel di web
if ($jenis_laporan == 'mutasi') {
    $stmt = $pdo->prepare("SELECT m.*, s.nama_lengkap, s.kelas FROM mutasi m JOIN siswa s ON m.nisn = s.nisn WHERE DATE(m.created_at) BETWEEN ? AND ? ORDER BY m.created_at DESC");
    $stmt->execute([$start_date, $end_date]);
    $data_laporan = $stmt->fetchAll();
} elseif ($jenis_laporan == 'surat_masuk') {
    $stmt = $pdo->prepare("SELECT * FROM surat_masuk WHERE tgl_diterima BETWEEN ? AND ? ORDER BY no_agenda DESC");
    $stmt->execute([$start_date, $end_date]);
    $data_laporan = $stmt->fetchAll();
} elseif ($jenis_laporan == 'surat_keluar') {
    $stmt = $pdo->prepare("SELECT * FROM surat_keluar WHERE tgl_dikirim BETWEEN ? AND ? ORDER BY no_agenda DESC");
    $stmt->execute([$start_date, $end_date]);
    $data_laporan = $stmt->fetchAll();
} elseif ($jenis_laporan == 'absensi') {
    // UPDATE: LEFT JOIN dan Grouping Tanggal -> Rombel
    $stmt = $pdo->prepare("SELECT pa.*, s.nama_lengkap FROM piket_absensi pa LEFT JOIN siswa s ON pa.nisn = s.nisn WHERE pa.tanggal BETWEEN ? AND ? ORDER BY pa.tanggal DESC, pa.rombel ASC, pa.id DESC");
    $stmt->execute([$start_date, $end_date]);
    $data_laporan = $stmt->fetchAll();
    
    $jumlah_absen_per_tgl_rombel = [];
    $grouped_absensi = [];
    
    foreach($data_laporan as $row) {
        $tgl = $row['tanggal'];
        $rmb = $row['rombel'];
        $key = $tgl . '_' . $rmb;

        if (!isset($jumlah_absen_per_tgl_rombel[$key])) {
            $jumlah_absen_per_tgl_rombel[$key] = 0;
        }
        if ($row['nisn'] !== 'NIHIL' && $row['keterangan'] !== 'NIHIL') {
            $jumlah_absen_per_tgl_rombel[$key] += 1;
        }
        
        if (!isset($grouped_absensi[$tgl])) {
            $grouped_absensi[$tgl] = [];
        }
        if (!isset($grouped_absensi[$tgl][$rmb])) {
            $grouped_absensi[$tgl][$rmb] = [];
        }
        $grouped_absensi[$tgl][$rmb][] = $row;
    }
} elseif ($jenis_laporan == 'pulang_cepat') {
    $stmt = $pdo->prepare("SELECT pp.*, s.nama_lengkap FROM piket_pulang_cepat pp JOIN siswa s ON pp.nisn = s.nisn WHERE pp.tanggal BETWEEN ? AND ? ORDER BY pp.tanggal DESC");
    $stmt->execute([$start_date, $end_date]);
    $data_laporan = $stmt->fetchAll();
} else {
    $data_laporan = [];
}

// Deteksi file gambar kop surat
$kop_surat_file = "";
foreach(['png', 'jpg', 'jpeg'] as $ext) {
    if(file_exists("uploads/assets/kop-surat." . $ext)) {
        $kop_surat_file = "uploads/assets/kop-surat." . $ext;
        break;
    }
}

// FORMAT TANGGAL DAN TANDA TANGAN
$bulanIndo = ['01'=>'Januari', '02'=>'Februari', '03'=>'Maret', '04'=>'April', '05'=>'Mei', '06'=>'Juni', '07'=>'Juli', '08'=>'Agustus', '09'=>'September', '10'=>'Oktober', '11'=>'November', '12'=>'Desember'];
$tgl_cetak = date('d') . ' ' . $bulanIndo[date('m')] . ' ' . date('Y');

$ttd_settings = ['tempat' => 'Jakarta', 'nama_kepsek' => '[Nama Kepala Sekolah]', 'nip_kepsek' => '[NIP]'];
if(file_exists('uploads/assets/ttd_settings.json')) {
    $ttd_settings = json_decode(file_get_contents('uploads/assets/ttd_settings.json'), true);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Export Laporan | SIMTU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @media print {
            @page {
                size: Legal portrait;
                margin: <?= htmlspecialchars($top_margin) ?>mm 10mm <?= htmlspecialchars($bottom_margin) ?>mm 10mm;
            }
            body { background: white !important; color: black !important; font-size: 11pt; }
            .sidebar, .top-header, .no-print, form { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
            .card { border: none !important; box-shadow: none !important; padding: 0 !important; width: 100% !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 15px; width: 100% !important; }
            .print-header img.kop-img { width: 100% !important; height: auto !important; max-height: none !important; object-fit: cover !important; display: block; margin: 0 auto 5mm auto; }
            table { font-size: 10pt !important; margin-bottom: 20px !important; }
            
            .signature-wrapper {
                display: flex !important;
                justify-content: flex-end;
                width: 100%;
                margin-top: 40px;
                page-break-inside: avoid;
            }
            .print-signature {
                width: 250px;
                font-family: "Times New Roman", Times, serif;
                font-size: 12pt;
                text-align: left;
            }
        }
        .signature-wrapper, .print-header { display: none; }
    </style>
</head>
<body>

<!-- Sidebar Dinamis Universal -->
<div class="sidebar" id="sidebar">
    <div class="p-3 text-center border-bottom border-secondary mb-3">
        <h4 class="logo-text m-0"> <img src="uploads/assets/logo-login.png" alt="LOGO" style="height: 40px; vertical-align: middle; margin-right: 8px;"> <span class="menu-text">SIM-TU 236</span></h4>
    </div>
    
    <?php 
    $current_page = basename($_SERVER['PHP_SELF']); 
    $cek_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
    ?>

    <a href="dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
        <i class="fas fa-chart-pie"></i> <span class="menu-text">Dashboard</span>
    </a>
    
    <?php if(in_array($cek_role, ['Administrator'])): ?>
    <a href="admin.php" class="<?= $current_page == 'admin.php' ? 'active' : '' ?>">
        <i class="fas fa-users-cog"></i> <span class="menu-text">Manajemen User</span>
    </a>
    <?php endif; ?>

    <?php 
    $is_kepegawaian = in_array($current_page, ['duk.php', 'arsip_kepeg.php']);
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
        <a href="arsip_kepeg.php" class="<?= $current_page == 'arsipkepegawaian.php' ? 'active' : '' ?>"><i class="fa-regular fa-folder" style="color: rgb(255, 255, 255);"></i></i> <span class="menu-text">Arsip Kepegawaian</span></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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

    <?php if(in_array($cek_role, ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU', 'Guru_Piket', 'TU_Persuratan'])): ?>
    <a href="laporan.php" class="<?= $current_page == 'laporan.php' ? 'active' : '' ?>">
        <i class="fas fa-file-export"></i> <span class="menu-text">Report</span>
    </a>
    <?php endif; ?>
    
    <a href="logout.php" class="mt-auto text-danger"><i class="fas fa-sign-out-alt"></i> <span class="menu-text">Logout</span></a>
</div>

<!-- Main Content -->
<div class="main-content" id="main-content">
    <div class="top-header mb-4 no-print">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">Pusat Export Laporan & Arsip</h5>
        <div class="d-flex align-items-center">
            <img src="<?= $avatar_path ?? 'uploads/profil/default.png' ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
            <div>
                <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($role) ?></small>
            </div>
        </div>
    </div>

    <!-- KOP SURAT KHUSUS MODE PRINT / PDF -->
    <div class="print-header">
        <?php if(!empty($kop_surat_file)): ?>
            <img src="<?= $kop_surat_file ?>" alt="Kop Surat" class="kop-img">
        <?php else: ?>
            <h4>PEMERINTAH PROVINSI DKI JAKARTA</h4>
            <h4>DINAS PENDIDIKAN - SMP NEGERI 236 JAKARTA</h4>
            <p>Jl. Sekolah No. 236, Jakarta | Email: info@smpn236jkt.sch.id</p>
            <hr style="border: 2px solid black; margin: 5px 0 15px 0;">
        <?php endif; ?>
        
        <?php 
            // Logika Penentuan Judul Laporan Saat Print
            $default_judul = 'REKAPITULASI ' . strtoupper(str_replace('_', ' ', $jenis_laporan));
            $judul_cetak = !empty($custom_title) ? strtoupper($custom_title) : $default_judul;
        ?>
        <h6 class="fw-bold mt-2"><?= htmlspecialchars($judul_cetak) ?></h6>
        <p class="small text-muted">Periode: <?= date('d/m/Y', strtotime($start_date)) ?> s.d. <?= date('d/m/Y', strtotime($end_date)) ?></p>
    </div>

    <!-- Filter & Margin Setting Card -->
    <div class="card border-0 shadow-sm p-4 mb-4 no-print">
        <h5 style="color: var(--navy-blue);" class="mb-3"><i class="fas fa-filter"></i> Filter Data & Pengaturan Laporan</h5>
        <form method="GET">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Jenis Laporan</label>
                    <select name="jenis" class="form-select">
                        <option value="mutasi" <?= $jenis_laporan == 'mutasi' ? 'selected' : '' ?>>Mutasi Siswa</option>
                        <option value="surat_masuk" <?= $jenis_laporan == 'surat_masuk' ? 'selected' : '' ?>>Surat Masuk</option>
                        <option value="surat_keluar" <?= $jenis_laporan == 'surat_keluar' ? 'selected' : '' ?>>Surat Keluar</option>
                        <option value="absensi" <?= $jenis_laporan == 'absensi' ? 'selected' : '' ?>>Absensi</option>
                        <option value="pulang_cepat" <?= $jenis_laporan == 'pulang_cepat' ? 'selected' : '' ?>>Pulang Cepat</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label text-primary fw-bold">Judul Kustom (Opsional)</label>
                    <input type="text" name="custom_title" class="form-control" placeholder="Ubah judul header cetak..." value="<?= htmlspecialchars($custom_title) ?>">
                </div>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Margin Atas (mm)</label>
                    <input type="number" name="top_margin" class="form-control" value="<?= $top_margin ?>" min="0" max="50">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Margin Bawah (mm)</label>
                    <input type="number" name="bottom_margin" class="form-control" value="<?= $bottom_margin ?>" min="0" max="50">
                </div>
                <div class="col-md-6 text-end">
                    <button type="submit" class="btn w-100" style="background: var(--navy-blue); color: var(--gold);"><i class="fas fa-check"></i> Terapkan Pengaturan</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Data Preview Table Card -->
    <div class="card border-0 shadow-sm p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="no-print">
                <a href="laporan.php?jenis=<?= $jenis_laporan ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&top_margin=<?= $top_margin ?>&bottom_margin=<?= $bottom_margin ?>&custom_title=<?= urlencode($custom_title) ?>&export=excel" class="btn btn-success me-2">
                    <i class="fas fa-file-excel"></i> Export Excel (CSV)
                </a>
                <button class="btn btn-danger" onclick="window.print()">
                    <i class="fas fa-file-pdf"></i> Cetak / Export PDF (Portrait)
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead style="background-color: var(--bg-light);">
                    <?php if($jenis_laporan == 'mutasi'): ?>
                        <tr>
                            <th>No</th>
                            <th>Jenis</th>
                            <th>No Surat Pindah</th>
                            <th>NISN</th>
                            <th>Nama Siswa</th>
                            <th>Kelas</th>
                            <th>Sekolah Asal/Tujuan</th>
                            <th>Alasan</th>
                            <th>Tanggal</th>
                        </tr>
                    <?php elseif($jenis_laporan == 'surat_masuk'): ?>
                        <tr>
                            <th>Agenda</th>
                            <th>Tgl Diterima</th>
                            <th>Nomor Surat</th>
                            <th>Pengirim</th>
                            <th>Perihal</th>
                            <th>Disposisi</th>
                        </tr>
                    <?php elseif($jenis_laporan == 'surat_keluar'): ?>
                        <tr>
                            <th>Agenda</th>
                            <th>Tgl Dikirim</th>
                            <th>Nomor Surat</th>
                            <th>Tujuan</th>
                            <th>Perihal</th>
                            <th>Disposisi</th>
                        </tr>
                    <?php elseif($jenis_laporan == 'absensi'): ?>
                        <tr class="text-center">
                            <th>No</th>
                            <th>Hari /<br>Tanggal</th>
                            <th>Rombel</th>
                            <th>NISN & Nama Siswa</th>
                            <th>Keterangan</th>
                            <th>Alasan</th>
                            <th>Jumlah Siswa<br>Masuk</th>
                            <th>Petugas Piket</th>
                        </tr>
                    <?php elseif($jenis_laporan == 'pulang_cepat'): ?>
                        <tr>
                            <th>No</th>
                            <th>Hari / Tanggal</th>
                            <th>Rombel</th>
                            <th>NISN & Nama Siswa</th>
                            <th>Alasan Pulang</th>
                            <th>Petugas Piket</th>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php if(count($data_laporan) > 0): ?>
                        
                        <?php if($jenis_laporan == 'absensi'): ?>
                            <!-- LOOPING KHUSUS ABSENSI DENGAN ROWSPAN -->
                            <?php 
                            $no = 1;
                            foreach($grouped_absensi as $tgl => $rombels): 
                                foreach($rombels as $rmb => $group):
                                    $rowspan = count($group);
                                    $first = true;
                                    
                                    $key = $tgl . '_' . $rmb;
                                    $tot_rombel = $total_siswa_per_rombel[$rmb] ?? 0;
                                    $tot_absen_riil = $jumlah_absen_per_tgl_rombel[$key] ?? 0;
                                    $jml_masuk_lap = max(0, $tot_rombel - $tot_absen_riil);
                                    
                                    foreach($group as $row):
                                        $display_nama = ($row['keterangan'] === 'NIHIL' || $row['nisn'] === 'NIHIL') ? 'SELURUH SISWA MASUK' : htmlspecialchars($row['nama_lengkap']);
                                        $display_nisn = ($row['keterangan'] === 'NIHIL' || $row['nisn'] === 'NIHIL') ? '' : htmlspecialchars($row['nisn']);
                            ?>
                                        <tr>
                                            <?php if($first): ?>
                                                <td rowspan="<?= $rowspan ?>" class="text-center align-middle"><?= $no++ ?></td>
                                                <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                                    <?= htmlspecialchars($row['hari']) ?>,<br><?= date('d/m/Y', strtotime($row['tanggal'])) ?>
                                                </td>
                                                <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                                    <strong><?= htmlspecialchars($row['rombel']) ?></strong>
                                                </td>
                                            <?php endif; ?>
                                            
                                            <td class="text-center align-middle">
                                                <?= $display_nama ?> 
                                                <?= $display_nisn ? "<br><small class='text-muted'>$display_nisn</small>" : "" ?>
                                            </td>
                                            <td class="text-center align-middle">
                                                <?php if ($row['keterangan'] === 'NIHIL'): ?>
                                                    <span class="badge bg-success"><?= $row['keterangan'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><?= htmlspecialchars($row['keterangan']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center align-middle"><?= htmlspecialchars($row['alasan'] ?: '-') ?></td>
                                            
                                            <?php if($first): ?>
                                                <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                                    <strong><?= $jml_masuk_lap ?> Siswa</strong>
                                                </td>
                                                <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                                    <?= htmlspecialchars($row['petugas']) ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                            <?php 
                                        $first = false;
                                    endforeach; 
                                endforeach; 
                            endforeach; 
                            ?>

                        <?php else: ?>
                            <!-- LOOPING STANDARD UNTUK LAPORAN NON-ABSENSI -->
                            <?php $no=1; foreach($data_laporan as $row): ?>
                                <?php if($jenis_laporan == 'mutasi'): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><span class="badge <?= $row['jenis_mutasi'] == 'Masuk' ? 'bg-success' : 'bg-danger' ?>"><?= $row['jenis_mutasi'] ?></span></td>
                                        <td><?= htmlspecialchars($row['no_surat_pindah']) ?></td>
                                        <td><?= htmlspecialchars($row['nisn']) ?></td>
                                        <td><?= htmlspecialchars($row['nama_lengkap']) ?></td>
                                        <td><?= htmlspecialchars($row['kelas']) ?></td>
                                        <td><?= htmlspecialchars($row['sekolah_tujuan_asal']) ?></td>
                                        <td><?= htmlspecialchars($row['alasan_pindah']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                                    </tr>
                                <?php elseif($jenis_laporan == 'surat_masuk'): ?>
                                    <tr>
                                        <td>#<?= $row['no_agenda'] ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['tgl_diterima'])) ?></td>
                                        <td><?= htmlspecialchars($row['nomor_surat']) ?></td>
                                        <td><?= htmlspecialchars($row['pengirim']) ?></td>
                                        <td><?= htmlspecialchars($row['perihal']) ?></td>
                                        <td><?= htmlspecialchars($row['status_disposisi']) ?></td>
                                    </tr>
                                <?php elseif($jenis_laporan == 'surat_keluar'): ?>
                                    <tr>
                                        <td>#<?= $row['no_agenda'] ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['tgl_dikirim'])) ?></td>
                                        <td><?= htmlspecialchars($row['nomor_surat']) ?></td>
                                        <td><?= htmlspecialchars($row['tujuan']) ?></td>
                                        <td><?= htmlspecialchars($row['perihal']) ?></td>
                                        <td><?= htmlspecialchars($row['status_disposisi']) ?></td>
                                    </tr>
                                <?php elseif($jenis_laporan == 'pulang_cepat'): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($row['hari']) ?>, <?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($row['rombel']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['nama_lengkap']) ?><br><small class="text-muted"><?= htmlspecialchars($row['nisn']) ?></small></td>
                                        <td><?= htmlspecialchars($row['alasan']) ?></td>
                                        <td><?= htmlspecialchars($row['petugas']) ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Tidak ada data untuk rentang tanggal dan kategori laporan yang dipilih.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- ============================================== -->
        <!-- AREA TANDA TANGAN (HANYA MUNCUL SAAT DI PRINT) -->
        <!-- ============================================== -->
        <div class="signature-wrapper">
            <div class="print-signature">
                <p style="margin-bottom: 2px;"><?= htmlspecialchars($ttd_settings['tempat']) ?>, <?= $tgl_cetak ?></p>
                <p style="margin-bottom: <?= file_exists('uploads/assets/ttd_kepsek.png') ? '10px' : '75px' ?>;">Kepala Sekolah</p>
                
                <?php if(file_exists('uploads/assets/ttd_kepsek.png')): ?>
                    <img src="uploads/assets/ttd_kepsek.png" style="height: 65px; margin-bottom: 10px;" alt="Tanda Tangan">
                <?php endif; ?>
                
                <p style="margin-bottom: 0;" class="fw-bold"><?= htmlspecialchars($ttd_settings['nama_kepsek']) ?></p>
                <p style="margin-bottom: 0;">NIP <?= htmlspecialchars($ttd_settings['nip_kepsek']) ?></p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // JS HIDE/UNHIDE Sidebar
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('main-content').classList.toggle('expanded');
    });

    // Alert Handling (SweetAlert2)
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