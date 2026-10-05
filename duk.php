<?php
require 'koneksi.php';

// Cek autentikasi dan otorisasi (Administrator, Kepala Sekolah, Kepala TU)
if (!isset($_SESSION['user_nip']) || !in_array($_SESSION['role'], ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke halaman ini.'];
    header("Location: dashboard.php");
    exit;
}

// Tangkap opsi urutan (default: purna)
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'purna';

// Fungsi helper untuk memberikan bobot peringkat golongan (1 tertinggi ke 20 terendah)
function getGolonganRank($pangkat) {
    // Bersihkan spasi berlebih
    $pangkat = trim($pangkat);
    
    // Mapping peringkat golongan sesuai permintaan
    $ranking = [
        'IV.e (Pembina Utama)' => 1,
        'IV.d (Pembina Utama Madya)' => 2,
        'IV.c (Pembina Utama Muda)' => 3,
        'IV.b (Pembina Tingkat I)' => 4,
        'IV.a (Pembina)' => 5,
        'III.d (Penata Tingkat I)' => 6,
        'III.c (Penata)' => 7,
        'III.b (Penata Muda Tingkat I)' => 8,
        'III.a (Penata Muda)' => 9,
        'II.d (Pengatur Tingkat I)' => 10,
        'II.c (Pengatur)' => 11,
        'II.b (Pengatur Muda Tingkat I)' => 12,
        'II.a (Pengatur Muda)' => 13,
        'I.d (Juru Tingkat I)' => 14,
        'I.c (Juru Muda)' => 15,
        'I.b (Juru Muda Tingkat I)' => 16,
        'I.a (Juru Muda)' => 17,
        'IX (Ahli Pertama)' => 18,
        'X (Ahli Pertama)' => 19,
        'Non ASN' => 20
    ];

    if (isset($ranking[$pangkat])) {
        return $ranking[$pangkat];
    }

    foreach ($ranking as $key => $val) {
        if (stripos($pangkat, substr($key, 0, 4)) !== false) {
            return $val;
        }
    }

    return 99;
}

// Logika Export Excel (.csv) DUK
if (isset($_GET['export_excel'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Data_DUK_Pegawai_SMPN236.csv');
    $output = fopen('php://output', 'w');
    
    // Header Export Excel
    fputcsv($output, ['No', 'Nama Pegawai', 'Jenis Pegawai', 'NIP/NIKKI/NIK', 'Golongan / Pangkat', 'TMT', 'TMT Pangkat Terakhir', 'Usia', 'Masa Kerja', 'Tanggal Purna']);

    $stmt_exp = $pdo->query("SELECT * FROM pegawai");
    $raw_exp = $stmt_exp->fetchAll(PDO::FETCH_ASSOC);
    $today_exp = new DateTime('today');
    $data_exp = [];

    foreach ($raw_exp as $p) {
        $batas_usia = (isset($p['jenis_pegawai']) && strtolower(trim($p['jenis_pegawai'])) === 'guru') ? 60 : 58;
        $tgl_purna_obj = null;
        $purna_str = '-';
        $usia_str = '-';
        $masa_kerja_str = '-';

        if (!empty($p['tanggal']) && $p['tanggal'] != '0000-00-00') {
            $tgl_lahir = new DateTime($p['tanggal']);
            $usia = $tgl_lahir->diff($today_exp);
            $usia_str = $usia->y . ' Thn';

            $tgl_purna_obj = clone $tgl_lahir;
            $tgl_purna_obj->modify("+{$batas_usia} years");
            $purna_str = $tgl_purna_obj->format('d/m/Y');
        }

        if (!empty($p['tmt']) && $p['tmt'] != '0000-00-00') {
            $tmt_date = new DateTime($p['tmt']);
            $masa_kerja = $tmt_date->diff($today_exp);
            $masa_kerja_str = $masa_kerja->y . ' Thn ' . $masa_kerja->m . ' Bln';
        }

        $p['calculated_purna_date'] = $tgl_purna_obj ? $tgl_purna_obj->format('Y-m-d') : '9999-12-31';
        // Menggunakan tmt_pangkat sebagai dasar pengurutan "Golongan Pangkat Terakhir"
        $p['calculated_tmt'] = (!empty($p['tmt_pangkat']) && $p['tmt_pangkat'] != '0000-00-00') ? date('Y-m-d', strtotime($p['tmt_pangkat'])) : ((!empty($p['tmt']) && $p['tmt'] != '0000-00-00') ? date('Y-m-d', strtotime($p['tmt'])) : '9999-12-31');
        
        $p['usia_Formatted'] = $usia_str;
        $p['masa_kerja_formatted'] = $masa_kerja_str;
        $p['purna_formatted'] = $purna_str;
        $p['gol_rank'] = getGolonganRank($p['pangkat_golongan']);

        $data_exp[] = $p;
    }

    if ($sort_by === 'golongan') {
        usort($data_exp, function($a, $b) {
            if ($a['gol_rank'] === $b['gol_rank']) {
                return strcmp($a['calculated_purna_date'], $b['calculated_purna_date']);
            }
            return $a['gol_rank'] - $b['gol_rank'];
        });
    } elseif ($sort_by === 'golongan_terakhir') {
        usort($data_exp, function($a, $b) {
            if ($a['gol_rank'] === $b['gol_rank']) {
                return strcmp($a['calculated_tmt'], $b['calculated_tmt']);
            }
            return $a['gol_rank'] - $b['gol_rank'];
        });
    } else {
        usort($data_exp, function($a, $b) {
            return strcmp($a['calculated_purna_date'], $b['calculated_purna_date']);
        });
    }

    $no_ex = 1;
    foreach ($data_exp as $row) {
        fputcsv($output, [
            $no_ex++,
            $row['nama_pegawai'],
            $row['jenis_pegawai'],
            '="' . $row['nip'] . '"',
            $row['pangkat_golongan'] ?: '-',
            (!empty($row['tmt']) && $row['tmt'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tmt'])) : '-',
            // Mengambil tmt_pangkat dari database untuk export excel
            (!empty($row['tmt_pangkat']) && $row['tmt_pangkat'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tmt_pangkat'])) : '-',
            $row['usia_Formatted'],
            $row['masa_kerja_formatted'],
            $row['purna_formatted']
        ]);
    }
    fclose($output);
    exit;
}

// Mengambil data pegawai untuk DUK Tampilan Web
try {
    $stmt = $pdo->query("SELECT * FROM pegawai");
    $raw_pegawai = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = new DateTime('today');
    $daftar_duk = [];

    foreach ($raw_pegawai as $p) {
        $batas_usia = (isset($p['jenis_pegawai']) && strtolower(trim($p['jenis_pegawai'])) === 'guru') ? 60 : 58;

        $tgl_purna_obj = null;
        $purna_str = '-';
        $usia_str = '-';
        $masa_kerja_str = '-';

        if (!empty($p['tanggal']) && $p['tanggal'] != '0000-00-00') {
            $tgl_lahir = new DateTime($p['tanggal']);
            $usia = $tgl_lahir->diff($today);
            $usia_str = $usia->y . ' Thn';

            $tgl_purna_obj = clone $tgl_lahir;
            $tgl_purna_obj->modify("+{$batas_usia} years");
            $purna_str = $tgl_purna_obj->format('d/m/Y');
        }

        if (!empty($p['tmt']) && $p['tmt'] != '0000-00-00') {
            $tmt_date = new DateTime($p['tmt']);
            $masa_kerja = $tmt_date->diff($today);
            $masa_kerja_str = $masa_kerja->y . ' Thn ' . $masa_kerja->m . ' Bln';
        }

        $p['calculated_purna_date'] = $tgl_purna_obj ? $tgl_purna_obj->format('Y-m-d') : '9999-12-31';
        // Menggunakan tmt_pangkat sebagai dasar pengurutan "Golongan Pangkat Terakhir"
        $p['calculated_tmt'] = (!empty($p['tmt_pangkat']) && $p['tmt_pangkat'] != '0000-00-00') ? date('Y-m-d', strtotime($p['tmt_pangkat'])) : ((!empty($p['tmt']) && $p['tmt'] != '0000-00-00') ? date('Y-m-d', strtotime($p['tmt'])) : '9999-12-31');
        
        $p['usia_Formatted'] = $usia_str;
        $p['masa_kerja_formatted'] = $masa_kerja_str;
        $p['purna_formatted'] = $purna_str;
        $p['gol_rank'] = getGolonganRank($p['pangkat_golongan']);

        $daftar_duk[] = $p;
    }

    if ($sort_by === 'golongan') {
        usort($daftar_duk, function($a, $b) {
            if ($a['gol_rank'] === $b['gol_rank']) {
                return strcmp($a['calculated_purna_date'], $b['calculated_purna_date']);
            }
            return $a['gol_rank'] - $b['gol_rank'];
        });
    } elseif ($sort_by === 'golongan_terakhir') {
        usort($daftar_duk, function($a, $b) {
            if ($a['gol_rank'] === $b['gol_rank']) {
                return strcmp($a['calculated_tmt'], $b['calculated_tmt']);
            }
            return $a['gol_rank'] - $b['gol_rank'];
        });
    } else {
        usort($daftar_duk, function($a, $b) {
            return strcmp($a['calculated_purna_date'], $b['calculated_purna_date']);
        });
    }

} catch (PDOException $e) {
    $daftar_duk = [];
}

// Deteksi file gambar kop surat
$kop_surat_file = "";
foreach(['png', 'jpg', 'jpeg'] as $ext) {
    if(file_exists("uploads/assets/kop-surat." . $ext)) {
        $kop_surat_file = "uploads/assets/kop-surat." . $ext;
        break;
    }
}

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
    <title>DUK Kepegawaian | SIMTU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* CSS KHUSUS UNTUK CETAK PDF/PRINT */
        @media print {
            @page {
                size: Legal portrait;
                margin: 8mm;
            }
            body { 
                background: white !important; 
                color: black !important; 
                font-size: 8.5pt;
            }
            #sidebar, #toggleSidebar, .top-header, form, .btn-success, .btn-outline-success {
                display: none !important;
            }
            #main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                width: 100% !important;
            }
            table {
                width: 100% !important;
                font-size: 8pt !important;
                margin-bottom: 15px !important;
                border-collapse: collapse !important;
            }
            /* PENYESUAIAN: Memastikan border tabel tercetak dengan warna hitam solid */
            table, table th, table td {
                border: 1px solid #000 !important;
            }
            table th, table td {
                padding: 4px 6px !important;
                word-wrap: break-word;
            }
            th {
                background-color: #f8f9fa !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact;
            }
            
            .d-flex.justify-content-between.align-items-center.mb-3.flex-wrap.gap-2 {
                display: none !important;
            }

            .print-header { display: block !important; text-align: center; margin-bottom: 10px; width: 100% !important; }
            .print-header img.kop-img { width: 100% !important; height: auto !important; max-height: none !important; object-fit: cover !important; display: block; margin: 0 auto 3mm auto; }
            
            .signature-wrapper {
                display: flex !important;
                justify-content: flex-end;
                width: 100%;
                margin-top: 25px;
                page-break-inside: avoid;
            }
            .print-signature {
                width: 220px;
                font-family: "Times New Roman", Times, serif;
                font-size: 10pt;
                text-align: left;
            }
        }
        
        .signature-wrapper, .print-header { display: none; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
    <div class="p-3 text-center border-bottom border-secondary mb-3">
        <h4 class="logo-text m-0"> <img src="uploads/assets/logo-login.png" alt="LOGO" style="height: 40px; vertical-align: middle; margin-right: 8px;"> <span class="menu-text">SIM-TU 236</span></h4>
    </div>
    
    <?php 
    $current_page = basename($_SERVER['PHP_SELF']); 
    $cek_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
    ?>
<!-- 1. Dashboard -->
    <a href="dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> <span class="menu-text">Dashboard</span></a>
<!-- 2. Manajemen User -->     
    <?php if(in_array($cek_role, ['Administrator'])): ?>
    <a href="admin.php" class="<?= $current_page == 'admin.php' ? 'active' : '' ?>"><i class="fas fa-users-cog"></i> <span class="menu-text">Manajemen User</span></a>
    <?php endif; ?>
<!-- 3. Menu Kepegawaian (Dropdown) -->
    <?php 
    $is_kepegawaian = in_array($current_page, ['duk.php', 'masakerja.php']);
    if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): 
    ?>
    <a data-bs-toggle="collapse" href="#menuKepegawaian" role="button" aria-expanded="<?= $is_kepegawaian ? 'true' : 'false' ?>" aria-controls="menuKepegawaian" class="<?= $is_kepegawaian ? 'active text-white' : '' ?>">
        <i class="fas fa-user-tie"></i> <span class="menu-text">Kepegawaian</span>
        <i class="fas fa-chevron-down ms-auto menu-text" style="font-size: 0.8rem;"></i>
    </a>
    <div class="collapse <?= $is_kepegawaian ? 'show' : '' ?>" id="menuKepegawaian" data-bs-parent="#sidebar">
         <!-- Sub-menu 1: DUK & Masa Bakti -->
        <?php if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="duk.php" class="<?= $current_page == 'duk.php' ? 'active' : '' ?>"><i class="fas fa-list-ol"></i> <span class="menu-text">DUK & Masa Bakti</span></a>
        <!-- Sub-menu 2: Arsip Kepegawaian --> 
        <a href="arsip_kepeg.php" class="<?= $current_page == 'arsipkepegawaian.php' ? 'active' : '' ?>"><i class="fa-regular fa-folder" style="color: rgb(255, 255, 255);"></i></i> <span class="menu-text">Arsip Kepegawaian</span></a>
        <?php endif; ?>
        <!-- Sub-menu 3: Beban ajar -->
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
       <!-- Sub-menu 1: Surat Masuk -->
        <a href="suratmasuk.php" class="<?= $current_page == 'suratmasuk.php' ? 'active' : '' ?>"><i class="fas fa-envelope-open-text"></i> <span class="menu-text">Surat Masuk</span></a>
        <!-- Sub-menu 2: Surat Keluar -->
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
        <!-- Sub-menu 1. Mutasi -->   
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
    <a href="laporan.php" class="<?= $current_page == 'laporan.php' ? 'active' : '' ?>"><i class="fas fa-file-export"></i> <span class="menu-text">Report</span></a>
    <?php endif; ?>
    <!-- 7. Logout -->    
    <a href="logout.php" class="mt-auto text-danger"><i class="fas fa-sign-out-alt"></i> <span class="menu-text">Logout</span></a>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="main-content">
    <div class="top-header mb-4">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">MANAJEMEN KARIR PTK</h5>
        <div class="d-flex align-items-center">
            <img src="<?= $avatar_path ?? 'uploads/profil/default.png' ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
            <div>
                <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($_SESSION['role']) ?></small>
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
        
        <h6 class="fw-bold mt-2">REKAPITULASI DAFTAR URUT KEPANGKATAN (DUK) PEGAWAI</h6>
        <p class="small text-muted" style="margin-bottom: 15px;">Dicetak pada: <?= date('d/m/Y') ?></p>
    </div>

    <!-- KONTEN TABEL DUK -->
    <div class="card border-0 shadow-sm p-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <!-- DROPDOWN OPSI URUTAN -->
                <form method="GET" action="duk.php" class="d-flex align-items-center gap-2 m-0">
                    <label class="small text-muted mb-0 fw-bold">Urutkan:</label>
                    <select name="sort" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto; cursor: pointer;">
                        <option value="purna" <?= $sort_by === 'purna' ? 'selected' : '' ?>>Tanggal Purna (Terdekat)</option>
                        <option value="golongan" <?= $sort_by === 'golongan' ? 'selected' : '' ?>>Golongan / Pangkat (IV.e s.d Non ASN)</option>
                        <option value="golongan_terakhir" <?= $sort_by === 'golongan_terakhir' ? 'selected' : '' ?>>Golongan Pangkat Terakhir</option>
                    </select>
                </form>

                <a href="duk.php?export_excel=true&sort=<?= $sort_by ?>" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Export Excel</a>
                <button class="btn btn-outline-success btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Cetak / PDF</button>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle" style="font-size: 0.9rem;">
                <thead style="background-color: var(--navy-blue); color: var(--gold); text-align: center;">
                    <tr>
                        <th class="py-3">No</th>
                        <th class="py-3">Nama Pegawai</th>
                        <th class="py-3">NIP/NIKKI/NIK</th>
                        <th class="py-3">Golongan / Pangkat</th>
                        <th class="py-3">TMT</th>
                        <!-- TMT Pangkat Terakhir diambil dari tmt_pangkat -->
                        <th class="py-3">TMT Pangkat Terakhir</th>
                        <th class="py-3">Usia</th>
                        <th class="py-3">Masa Kerja</th>
                        <th class="py-3">Tanggal Purna</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if(count($daftar_duk) > 0): 
                        $no = 1;
                        foreach($daftar_duk as $p): 
                    ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td>
                                <strong><?= htmlspecialchars($p['nama_pegawai']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($p['jenis_pegawai'] ?? '-') ?></small>
                            </td>
                            <td style="mso-number-format:'\@';"><?= htmlspecialchars($p['nip']) ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= htmlspecialchars($p['pangkat_golongan']) ?: '-' ?></span></td>
                            <td class="text-center"><?= !empty($p['tmt']) && $p['tmt'] != '0000-00-00' ? date('d/m/Y', strtotime($p['tmt'])) : '-' ?></td>
                            <!-- Menggunakan tmt_pangkat sesuai kolom tabel admin.php -->
                            <td class="text-center"><?= !empty($p['tmt_pangkat']) && $p['tmt_pangkat'] != '0000-00-00' ? date('d/m/Y', strtotime($p['tmt_pangkat'])) : '-' ?></td>
                            <td class="text-center"><?= $p['usia_Formatted'] ?></td>
                            <td class="text-center"><?= $p['masa_kerja_formatted'] ?></td>
                            <td class="text-center text-danger fw-bold"><?= $p['purna_formatted'] ?></td>
                        </tr>
                    <?php 
                        endforeach; 
                    else: 
                    ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Data kepegawaian belum tersedia.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    
        <!-- AREA TANDA TANGAN (HANYA MUNCUL SAAT DI PRINT) -->
        <div class="signature-wrapper">
            <div class="print-signature">
                <p style="margin-bottom: 2px;"><?= htmlspecialchars($ttd_settings['tempat']) ?>, <?= $tgl_cetak ?></p>
                <p style="margin-bottom: <?= file_exists('uploads/assets/ttd_kepsek.png') ? '8px' : '55px' ?>;">Kepala Sekolah</p>
                
                <?php if(file_exists('uploads/assets/ttd_kepsek.png')): ?>
                    <img src="uploads/assets/ttd_kepsek.png" style="height: 55px; margin-bottom: 8px;" alt="Tanda Tangan">
                <?php endif; ?>
                
                <p style="margin-bottom: 0;" class="fw-bold"><?= htmlspecialchars($ttd_settings['nama_kepsek']) ?></p>
                <p style="margin-bottom: 0;">NIP <?= htmlspecialchars($ttd_settings['nip_kepsek']) ?></p>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('main-content').classList.toggle('expanded');
    });

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