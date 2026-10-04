<?php
require 'koneksi.php';
if (!isset($_SESSION['user_nip']) || !in_array($_SESSION['role'], ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU', 'Guru_Piket'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke Piket Pintar.'];
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];
$list_rombel = ['7A', '7B', '7C', '7D', '7E', '7F', '7G', '8A', '8B', '8C', '8D', '8E', '8F', '8G', '9A', '9B', '9C', '9D', '9E', '9F', '9G'];

// Ambil data siswa untuk JSON Autocomplete & Perhitungan Total per Rombel
$stmt_siswa =$pdo->query("SELECT nisn, nama_lengkap, kelas, rombel FROM siswa WHERE status = 'Aktif' ORDER BY rombel ASC, nama_lengkap ASC");
$semua_siswa =$stmt_siswa->fetchAll(PDO::FETCH_ASSOC);

// Hitung jumlah total siswa per rombel untuk acuan otomatis
$total_siswa_per_rombel = [];
foreach($list_rombel as$r) {
    $stmt_count =$pdo->prepare("SELECT COUNT(*) FROM siswa WHERE rombel = ? AND status = 'Aktif'");
    $stmt_count->execute([$r]);
    $total_siswa_per_rombel[$r] = (int)$stmt_count->fetchColumn();
}

// 1. PROSES INPUT ABSENSI HARIAN (UPDATE: MULTIPLE SISWA)
if (isset($_POST['simpan_absensi'])) {
    $hari    = trim($_POST['hari']);
    $tanggal = $_POST['tanggal'];
    $rombel  = trim($_POST['rombel']);
    $petugas = trim($_POST['petugas']);
    
    $nisn_array   = $_POST['nisn'] ?? [];
    $ket_array    = $_POST['keterangan'] ?? [];
    $alasan_array = $_POST['alasan'] ?? [];

    if (empty($nisn_array)) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Harap pilih minimal satu siswa atau ketik NIHIL.'];
        header("Location: piket.php?tab=absensi"); exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO piket_absensi (hari, tanggal, rombel, nisn, keterangan, alasan, petugas) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        for ($i = 0; $i < count($nisn_array); $i++) {
            $nisn   = trim($nisn_array[$i]);
            $ket    = trim($ket_array[$i]);
            $alasan = trim($alasan_array[$i]);
            $stmt->execute([$hari, $tanggal, $rombel, $nisn, $ket, $alasan, $petugas]);
        }
        
        $pdo->commit();
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Absensi harian siswa berhasil disimpan.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => $e->getMessage()];
    }
    header("Location: piket.php?tab=absensi");
    exit;
}

// 2. PROSES INPUT PULANG CEPAT
if (isset($_POST['simpan_pulang_cepat'])) {
    $hari    = trim($_POST['hari']);
    $tanggal =$_POST['tanggal'];
    $rombel  = trim($_POST['rombel']);
    $nisn    = trim($_POST['nisn']);
    $alasan  = trim($_POST['alasan']);
    $petugas = trim($_POST['petugas']);

    try {
        $stmt =$pdo->prepare("INSERT INTO piket_pulang_cepat (hari, tanggal, rombel, nisn, alasan, petugas) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$hari, $tanggal,$rombel, $nisn,$alasan, $petugas]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data izin pulang cepat berhasil disimpan.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' =>$e->getMessage()];
    }
    header("Location: piket.php?tab=pulang");
    exit;
}

// Hapus Data Absensi
if (isset($_POST['hapus_absensi'])) {
    $id = $_POST['id'];
    $pdo->prepare("DELETE FROM piket_absensi WHERE id = ?")->execute([$id]);
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Data absensi dihapus.'];
    header("Location: piket.php?tab=absensi"); exit;
}

// Hapus Data Pulang Cepat
if (isset($_POST['hapus_pulang'])) {
    $id = $_POST['id'];
    $pdo->prepare("DELETE FROM piket_pulang_cepat WHERE id = ?")->execute([$id]);
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Data pulang cepat dihapus.'];
    header("Location: piket.php?tab=pulang"); exit;
}

// Fetch Rekap Data
$filter_tanggal =$_GET['tanggal'] ?? date('Y-m-d');
// ORDER BY diubah ke rombel ASC terlebih dahulu untuk keperluan rowspan / grouping tampilan
$rekap_absensi =$pdo->prepare("SELECT pa.*, s.nama_lengkap FROM piket_absensi pa LEFT JOIN siswa s ON pa.nisn = s.nisn WHERE pa.tanggal = ? ORDER BY pa.rombel ASC, pa.id DESC");
$rekap_absensi->execute([$filter_tanggal]);
$data_absensi =$rekap_absensi->fetchAll();

// MENGHITUNG TOTAL SISWA ABSEN PER ROMBEL SEKALIGUS MELAKUKAN GROUPING DATA UNTUK ROWSPAN TAMPILAN
$jumlah_absen_per_rombel = [];
$grouped_absensi = [];

foreach ($data_absensi as $row_absen) {
    $r = $row_absen['rombel'];
    
    // Perhitungan Akumulasi Jumlah
    if (!isset($jumlah_absen_per_rombel[$r])) {
        $jumlah_absen_per_rombel[$r] = 0;
    }
    if ($row_absen['nisn'] !== 'NIHIL' && $row_absen['keterangan'] !== 'NIHIL') {
        $jumlah_absen_per_rombel[$r] += 1;
    }
    
    // Grouping array untuk Tampilan Rowspan
    if (!isset($grouped_absensi[$r])) {
        $grouped_absensi[$r] = [];
    }
    $grouped_absensi[$r][] = $row_absen;
}

$rekap_pulang =$pdo->prepare("SELECT pp.*, s.nama_lengkap FROM piket_pulang_cepat pp JOIN siswa s ON pp.nisn = s.nisn WHERE pp.tanggal = ? ORDER BY pp.id DESC");
$rekap_pulang->execute([$filter_tanggal]);
$data_pulang =$rekap_pulang->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Piket Pintar | SIMTU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .nav-tabs .nav-link { color: var(--navy-blue); font-weight: 600; }
        .nav-tabs .nav-link.active { color: var(--gold); background-color: var(--navy-blue); border-color: var(--navy-blue); }
        .autocomplete-suggestions {
            border: 1px solid #ced4da;
            max-height: 180px;
            overflow-y: auto;
            background: #fff;
            position: absolute;
            z-index: 1000;
            width: 100%;
            border-radius: 0 0 0.375rem 0.375rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .autocomplete-suggestion {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
        }
        .autocomplete-suggestion:hover {
            background-color: #f8f9fa;
            color: var(--navy-blue);
            font-weight: 600;
        }
        .table-absensi th { font-size: 0.9rem; background-color: #f8f9fa; }
        .table-absensi td { vertical-align: middle; }
    </style>
</head>
<body>

<!-- Sidebar -->
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
        <!-- Sub-menu 2: Arsip Kepegawaian -->
        <?php if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="arsip_kepeg.php" class="<?= $current_page == 'arsip_kepeg.php' ? 'active' : '' ?>"><i class="fas fa-user-clock"></i> <span class="menu-text">Arsip Kepegawaian</span></a>
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
    <div class="top-header mb-4 d-flex justify-content-between align-items-center">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">PIKET PINTAR - MONITORING ABSENSI & PULANG CEPAT</h5>
        <div class="d-flex align-items-center">
            <img src="<?= $avatar_path ?? 'uploads/profil/default.png' ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
            <div>
                <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($role) ?></small>
            </div>
        </div>
    </div>

    <!-- Navigasi Tombol Terpisah -->
    <ul class="nav nav-tabs mb-4" id="piketTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="absensi-tab" data-bs-toggle="tab" href="#tabAbsensi" role="tab"><i class="fas fa-user-times"></i> Input & Rekap Absensi Siswa</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="pulang-tab" data-bs-toggle="tab" href="#tabPulang" role="tab"><i class="fas fa-running"></i> Input & Rekap Siswa Pulang Cepat</a>
        </li>
    </ul>

    <div class="tab-content" id="piketTabsContent">
        
        <!-- TAB 1: ABSENSI HARIAN -->
        <div class="tab-pane fade show active" id="tabAbsensi" role="tabpanel">
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="mb-3" style="color: var(--navy-blue);"><i class="fas fa-edit"></i> Form Input Absensi Harian Siswa</h5>
                <form method="POST" id="formAbsensi">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Hari</label>
                            <input type="text" name="hari" id="hari_absensi" class="form-control bg-light" placeholder="Otomatis dari tanggal..." readonly required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Tanggal</label>
                            <input type="date" name="tanggal" id="tanggal_absensi" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Rombel</label>
                            <select name="rombel" id="pilih_rombel_absensi" class="form-select" required onchange="updateJumlahMasuk()">
                                <option value="">-- Pilih Rombel --</option>
                                <?php foreach($list_rombel as$r): ?>
                                    <option value="<?= $r ?>"><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Nama Petugas Piket</label>
                            <input type="text" name="petugas" class="form-control" value="<?= htmlspecialchars($_SESSION['nama']) ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-10 mb-3 position-relative">
                            <label class="form-label">Cari Nama / NISN Siswa Tidak Masuk <small class="text-danger">(Maks 36 Siswa. Pilih rombel terlebih dahulu)</small></label>
                            <input type="text" id="search_siswa_absensi" class="form-control border-primary" placeholder="Ketik nama, NISN, atau ketik 'NIHIL'..." autocomplete="off">
                            <div id="suggestions_absensi" class="autocomplete-suggestions d-none"></div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label fw-bold text-success">Jml Siswa Masuk</label>
                            <input type="text" id="jumlah_siswa_masuk" class="form-control fw-bold text-center bg-light text-success" readonly value="0">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm table-absensi mb-0">
                                    <thead>
                                        <tr>
                                            <th>Nama Siswa (NISN)</th>
                                            <th width="20%">Keterangan</th>
                                            <th width="35%">Alasan (Opsional)</th>
                                            <th width="10%" class="text-center">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="selected_students_container">
                                        <tr id="row_empty"><td colspan="4" class="text-center text-muted py-3">Belum ada siswa dipilih. Ketik/cari nama siswa atau ketik 'NIHIL' jika semua masuk.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="simpan_absensi" class="btn mt-2" style="background: var(--navy-blue); color: var(--gold);"><i class="fas fa-save"></i> Simpan Absensi Harian</button>
                </form>
            </div>

            <!-- Tabel Rekap Absensi -->
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="color: var(--navy-blue);"><i class="fas fa-table"></i> Rekapitulasi Absensi Tanggal: <?= $filter_tanggal ?></h5>
                    <form method="GET" class="d-flex gap-2">
                        <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= $filter_tanggal ?>">
                        <button type="submit" class="btn btn-sm btn-dark">Filter</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <!-- Penambahan class table-bordered untuk garis batas tabel menyesuaikan UI gambar referensi -->
                    <table class="table table-hover table-bordered align-middle">
                        <thead class="text-center" style="background-color: var(--bg-light);">
                            <tr>
                                <th>Hari /<br>Tanggal</th>
                                <th>Rombel</th>
                                <th>NISN & Nama Siswa</th>
                                <th>Keterangan</th>
                                <th>Alasan</th>
                                <th>Jumlah Siswa<br>Masuk</th>
                                <th>Petugas</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($grouped_absensi) > 0): ?>
                                <?php foreach($grouped_absensi as $rombel_key => $group): 
                                    $rowspan = count($group);
                                    $first = true;
                                    
                                    // PENGHITUNGAN AKURAT: Total di kelas dikurangi Total keseluruhan anak yg absen di rombel tersebut hari ini
                                    $total_rombel = $total_siswa_per_rombel[$rombel_key] ?? 0;
                                    $total_absen_riil = $jumlah_absen_per_rombel[$rombel_key] ?? 0;
                                    $jml_masuk_rekap = max(0, $total_rombel - $total_absen_riil);

                                    foreach($group as $row):
                                        $display_nama = ($row['keterangan'] === 'NIHIL' || $row['nisn'] === 'NIHIL') ? 'SELURUH SISWA MASUK' : htmlspecialchars($row['nama_lengkap']);
                                        $display_nisn = ($row['keterangan'] === 'NIHIL' || $row['nisn'] === 'NIHIL') ? '' : htmlspecialchars($row['nisn']);
                                ?>
                                <tr>
                                    <?php if($first): ?>
                                        <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                            <?= $row['hari'] ?>,<br><?= date('d/m/Y', strtotime($row['tanggal'])) ?>
                                        </td>
                                        <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                            <strong><?= $row['rombel'] ?></strong>
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
                                            <strong><?= $row['keterangan'] ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center align-middle"><?= htmlspecialchars($row['alasan'] ?: '-') ?></td>
                                    
                                    <?php if($first): ?>
                                        <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                            <strong><?= $jml_masuk_rekap ?> Siswa</strong>
                                        </td>
                                        <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                            <?= htmlspecialchars($row['petugas']) ?>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <td class="text-center align-middle">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data absensi ini?');">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <button type="submit" name="hapus_absensi" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php 
                                        $first = false;
                                    endforeach; 
                                ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-3">Belum ada input absensi pada tanggal ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: IZIN PULANG CEPAT -->
        <div class="tab-pane fade" id="tabPulang" role="tabpanel">
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="mb-3" style="color: var(--navy-blue);"><i class="fas fa-edit"></i> Form Input Siswa Izin Pulang Cepat</h5>
                <form method="POST">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Hari</label>
                            <input type="text" name="hari" id="hari_pulang" class="form-control bg-light" placeholder="Otomatis..." readonly required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Tanggal</label>
                            <input type="date" name="tanggal" id="tanggal_pulang" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Rombel</label>
                            <select name="rombel" class="form-select" required>
                                <option value="">-- Pilih Rombel --</option>
                                <?php foreach($list_rombel as$r): ?>
                                    <option value="<?= $r ?>"><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Nama Petugas Piket</label>
                            <input type="text" name="petugas" class="form-control" value="<?= htmlspecialchars($_SESSION['nama']) ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3 position-relative">
                            <label class="form-label">Cari Nama / NISN Siswa Pulang Cepat</label>
                            <input type="text" id="search_siswa_pulang" class="form-control" placeholder="Ketik nama atau NISN siswa..." autocomplete="off" required>
                            <input type="hidden" name="nisn" id="nisn_pulang" required>
                            <div id="suggestions_pulang" class="autocomplete-suggestions d-none"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Alasan Pulang Cepat</label>
                            <input type="text" name="alasan" class="form-control" placeholder="Contoh: Sakit / Dijemput Orang Tua..." required>
                        </div>
                    </div>
                    <button type="submit" name="simpan_pulang_cepat" class="btn" style="background: var(--navy-blue); color: var(--gold);"><i class="fas fa-save"></i> Simpan Data Pulang Cepat</button>
                </form>
            </div>

            <!-- Tabel Rekap Pulang Cepat -->
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="color: var(--navy-blue);"><i class="fas fa-table"></i> Rekapitulasi Pulang Cepat Tanggal: <?= $filter_tanggal ?></h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>Hari / Tanggal</th>
                                <th>Rombel</th>
                                <th>NISN & Nama Siswa</th>
                                <th>Alasan Pulang</th>
                                <th>Petugas</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_pulang) > 0): ?>
                                <?php foreach($data_pulang as$row): ?>
                                <tr>
                                    <td><?= $row['hari'] ?>, <?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                    <td><strong><?= $row['rombel'] ?></strong></td>
                                    <td><?= htmlspecialchars($row['nama_lengkap']) ?> <br><small class="text-muted"><?= $row['nisn'] ?></small></td>
                                    <td><?= htmlspecialchars($row['alasan']) ?></td>
                                    <td><?= htmlspecialchars($row['petugas']) ?></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data ini?');">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <button type="submit" name="hapus_pulang" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Belum ada data pulang cepat pada tanggal ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
             </div>
        </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('main-content').classList.toggle('expanded');
    });

    const daftarSiswa = <?= json_encode($semua_siswa) ?>;
    const totalSiswaRombel = <?= json_encode($total_siswa_per_rombel) ?>;

    // --- FUNGSI HARI OTOMATIS ---
    function updateHariOtomatis(inputId, outputId) {
        const inputTanggal = document.getElementById(inputId);
        const inputHari = document.getElementById(outputId);

        if (!inputTanggal || !inputHari) return;
        if (!inputTanggal.value) { inputHari.value = ''; return; }

        const dateObj = new Date(inputTanggal.value);
        const hariList = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        inputHari.value = hariList[dateObj.getDay()];
    }

    document.getElementById('tanggal_absensi').addEventListener('change', () => updateHariOtomatis('tanggal_absensi', 'hari_absensi'));
    document.getElementById('tanggal_pulang').addEventListener('change', () => updateHariOtomatis('tanggal_pulang', 'hari_pulang'));

    // --- FUNGSI LOGIKA MULTIPLE SISWA (ABSENSI) ---
    let selectedStudentsAbsensi = [];
    const MAX_ABSENSI = 36;

    function renderTableAbsensi() {
        const container = document.getElementById('selected_students_container');
        container.innerHTML = '';

        if (selectedStudentsAbsensi.length === 0) {
            container.innerHTML = `<tr id="row_empty"><td colspan="4" class="text-center text-muted py-3">Belum ada siswa dipilih. Ketik/cari nama siswa atau ketik 'NIHIL' jika semua masuk.</td></tr>`;
            return;
        }

        selectedStudentsAbsensi.forEach(s => {
            if (s.nisn === 'NIHIL') {
                container.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-success fw-bold py-3 bg-light">
                            <input type="hidden" name="nisn[]" value="NIHIL">
                            <input type="hidden" name="keterangan[]" value="NIHIL">
                            <input type="hidden" name="alasan[]" value="">
                            <i class="fas fa-check-circle"></i> SELURUH SISWA MASUK (NIHIL) 
                            <button type="button" class="btn btn-sm btn-danger ms-3" onclick="removeStudent('NIHIL')"><i class="fas fa-times"></i> Batal</button>
                        </td>
                    </tr>
                `;
            } else {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <input type="hidden" name="nisn[]" value="${s.nisn}">
                        <span class="fw-bold">${s.nama}</span> <br><small class="text-muted">${s.nisn} - Rombel ${s.rombel}</small>
                    </td>
                    <td>
                        <select name="keterangan[]" class="form-select form-select-sm" required>
                            <option value="Alfa">Alfa</option>
                            <option value="Sakit">Sakit</option>
                            <option value="Izin">Izin</option>
                            <option value="Dispensasi">Dispensasi</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="alasan[]" class="form-control form-control-sm" placeholder="Contoh: Sakit demam / Acara keluarga...">
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeStudent('${s.nisn}')"><i class="fas fa-trash"></i></button>
                    </td>
                `;
                container.appendChild(tr);
            }
        });
        updateJumlahMasuk();
    }

    function addStudent(nama, nisn, rombel) {
        if (selectedStudentsAbsensi.length >= MAX_ABSENSI && nisn !== 'NIHIL') {
            alert('Maksimal ' + MAX_ABSENSI + ' siswa yang tidak masuk dapat diinput sekaligus.');
            return;
        }

        if (selectedStudentsAbsensi.length === 1 && selectedStudentsAbsensi[0].nisn === 'NIHIL' && nisn !== 'NIHIL') {
            selectedStudentsAbsensi = [];
        }

        if (selectedStudentsAbsensi.find(s => s.nisn === nisn)) return;

        if (nisn === 'NIHIL') {
            selectedStudentsAbsensi = [];
        }

        selectedStudentsAbsensi.push({nama, nisn, rombel});
        
        if (selectedStudentsAbsensi.length === 1 && nisn !== 'NIHIL') {
            document.getElementById('pilih_rombel_absensi').value = rombel;
        }

        renderTableAbsensi();
    }

    function removeStudent(nisn) {
        selectedStudentsAbsensi = selectedStudentsAbsensi.filter(s => s.nisn !== nisn);
        renderTableAbsensi();
    }

    // --- FUNGSI UPDATE JUMLAH SISWA MASUK (Absensi) ---
    function updateJumlahMasuk() {
        const selectRombel = document.getElementById('pilih_rombel_absensi').value;
        const inputJumlahMasuk = document.getElementById('jumlah_siswa_masuk');

        if (!selectRombel) {
            inputJumlahMasuk.value = 0;
            return;
        }

        let totalSiswa = totalSiswaRombel[selectRombel] || 0;
        
        let isNihil = selectedStudentsAbsensi.length === 1 && selectedStudentsAbsensi[0].nisn === 'NIHIL';
        let jumlahTidakMasuk = isNihil ? 0 : selectedStudentsAbsensi.length;
        
        let hasilMasuk = totalSiswa - jumlahTidakMasuk;

        inputJumlahMasuk.value = hasilMasuk >= 0 ? hasilMasuk : 0;
    }

    document.getElementById('formAbsensi').addEventListener('submit', function(e) {
        if (selectedStudentsAbsensi.length === 0) {
            e.preventDefault();
            alert('Harap masukkan setidaknya satu siswa yang tidak masuk, atau ketik NIHIL.');
        }
    });

    // --- FUNGSI AUTOCOMPLETE PENCARIAN SISWA ---
    function setupAutocomplete(inputId, hiddenId, suggestionsId, isAbsensiForm = false) {
        const input = document.getElementById(inputId);
        const hidden = hiddenId ? document.getElementById(hiddenId) : null;
        const suggestionsBox = document.getElementById(suggestionsId);

        input.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            if (hidden) hidden.value = ''; 
            suggestionsBox.innerHTML = '';
            
            const filterRombelAbsensi = document.getElementById('pilih_rombel_absensi').value;

            if (query.length === 0) {
                suggestionsBox.classList.add('d-none');
                return;
            }

            if (isAbsensiForm && query === 'nihil') {
                suggestionsBox.classList.remove('d-none');
                const divNihil = document.createElement('div');
                divNihil.className = 'autocomplete-suggestion text-success fw-bold';
                divNihil.innerHTML = `<i class="fas fa-check-circle"></i> NIHIL (Seluruh Siswa Hadir)`;
                divNihil.addEventListener('click', function() {
                    addStudent('NIHIL', 'NIHIL', '');
                    input.value = '';
                    suggestionsBox.classList.add('d-none');
                });
                suggestionsBox.appendChild(divNihil);
                return; 
            }

            let filtered = daftarSiswa.filter(s => s.nama_lengkap.toLowerCase().includes(query) || s.nisn.toLowerCase().includes(query));
            
            if (isAbsensiForm && filterRombelAbsensi) {
                filtered = filtered.filter(s => s.rombel === filterRombelAbsensi);
            }

            if (filtered.length > 0) {
                suggestionsBox.classList.remove('d-none');
                filtered.forEach(s => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';
                    div.innerHTML = `<strong>${s.nama_lengkap}</strong> <small class="text-muted">(NISN: ${s.nisn} - Rombel ${s.rombel})</small>`;
                    div.addEventListener('click', function() {
                        if (isAbsensiForm) {
                            addStudent(s.nama_lengkap, s.nisn, s.rombel);
                            input.value = ''; 
                        } else {
                            input.value = `${s.nama_lengkap} (Rombel ${s.rombel} - NISN: ${s.nisn})`;
                            hidden.value = s.nisn;
                        }
                        suggestionsBox.classList.add('d-none');
                    });
                    suggestionsBox.appendChild(div);
                });
            } else {
                suggestionsBox.classList.add('d-none');
            }
        });

        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !suggestionsBox.contains(e.target)) {
                suggestionsBox.classList.add('d-none');
            }
        });
    }

    setupAutocomplete('search_siswa_absensi', null, 'suggestions_absensi', true);
    setupAutocomplete('search_siswa_pulang', 'nisn_pulang', 'suggestions_pulang', false);

    window.addEventListener('DOMContentLoaded', () => {
        updateHariOtomatis('tanggal_absensi', 'hari_absensi');
        updateHariOtomatis('tanggal_pulang', 'hari_pulang');
        renderTableAbsensi();
    });

    <?php if(isset($_SESSION['alert'])): ?>
        Swal.fire({ icon: '<?= $_SESSION['alert']['type'] ?>', title: '<?= $_SESSION['alert']['title'] ?>', text: '<?=$_SESSION['alert']['text'] ?>', confirmButtonColor: '#0A192F' });
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>
</script>
</body>
</html>