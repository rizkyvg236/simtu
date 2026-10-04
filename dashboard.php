<?php
require 'koneksi.php';
if (!isset($_SESSION['user_nip'])) { header("Location: login.php"); exit; }

$role = $_SESSION['role'];

// Ambil data statistik dari database siswa
$total_siswa = $pdo->query("SELECT COUNT(*) FROM siswa")->fetchColumn();
$total_surat_masuk = $pdo->query("SELECT COUNT(*) FROM surat_masuk")->fetchColumn();
$total_surat_keluar = $pdo->query("SELECT COUNT(*) FROM surat_keluar")->fetchColumn();
$total_mutasi = $pdo->query("SELECT COUNT(*) FROM mutasi")->fetchColumn();

// Filter Tanggal untuk Rekapitulasi Harian Piket
$filter_tanggal = $_GET['tanggal_rekap'] ?? date('Y-m-d');
// UPDATE: Ubah JOIN menjadi LEFT JOIN untuk support "NIHIL" dan ORDER BY rombel ASC untuk grouping rowspan
$stmt_rekap_harian = $pdo->prepare("SELECT pa.*, s.nama_lengkap FROM piket_absensi pa LEFT JOIN siswa s ON pa.nisn = s.nisn WHERE pa.tanggal = ? ORDER BY pa.rombel ASC, pa.id DESC");
$stmt_rekap_harian->execute([$filter_tanggal]);
$data_rekap_harian = $stmt_rekap_harian->fetchAll(PDO::FETCH_ASSOC);

// Daftar Rombel & Hitung Total Siswa per Rombel untuk Acuan Otomatis
$list_rombel = ['7A', '7B', '7C', '7D', '7E', '7F', '7G', '8A', '8B', '8C', '8D', '8E', '8F', '8G', '9A', '9B', '9C', '9D', '9E', '9F', '9G'];
$total_siswa_per_rombel = [];
foreach($list_rombel as $r) {
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM siswa WHERE rombel = ? AND status = 'Aktif'");
    $stmt_count->execute([$r]);
    $total_siswa_per_rombel[$r] = (int)$stmt_count->fetchColumn();
}

// UPDATE: Menghitung total siswa absen per rombel & grouping array untuk Rowspan UI
$jumlah_absen_per_rombel = [];
$grouped_absensi = [];
foreach ($data_rekap_harian as $row_absen) {
    $r = $row_absen['rombel'];
    if (!isset($jumlah_absen_per_rombel[$r])) { $jumlah_absen_per_rombel[$r] = 0; }
    if ($row_absen['nisn'] !== 'NIHIL' && $row_absen['keterangan'] !== 'NIHIL') {
        $jumlah_absen_per_rombel[$r] += 1;
    }
    if (!isset($grouped_absensi[$r])) { $grouped_absensi[$r] = []; }
    $grouped_absensi[$r][] = $row_absen;
}

// Rincian Keterangan Khusus / Inklusi Berdasarkan Rombel
$stmt_keterangan_rombel = $pdo->query("SELECT rombel, keterangan, COUNT(*) as total FROM siswa GROUP BY rombel, keterangan ORDER BY rombel ASC");
$data_keterangan_rombel = $stmt_keterangan_rombel->fetchAll(PDO::FETCH_ASSOC);

// Statistik Agama Berdasarkan Kelas
$stmt_agama_kelas = $pdo->query("SELECT kelas, LOWER(TRIM(agama)) as agama, COUNT(*) as total FROM siswa GROUP BY kelas, LOWER(TRIM(agama)) ORDER BY kelas ASC");
$raw_agama_kelas = $stmt_agama_kelas->fetchAll(PDO::FETCH_ASSOC);

$data_agama_kelas = [];
foreach($raw_agama_kelas as $row) {
    $kelas = $row['kelas'];
    $raw = $row['agama'];
    $total = (int)$row['total'];
    
    if (empty($raw)) {
        $kategori = 'Lainnya';
    } elseif (strpos($raw, 'katholik') !== false || strpos($raw, 'katolik') !== false) {
        $kategori = 'Katolik';
    } elseif (strpos($raw, 'kristen') !== false) {
        $kategori = 'Kristen';
    } elseif (strpos($raw, 'islam') !== false || strpos($raw, 'muslim') !== false) {
        $kategori = 'Islam';
    } elseif (strpos($raw, 'hindu') !== false) {
        $kategori = 'Hindu';
    } elseif (strpos($raw, 'buddha') !== false || strpos($raw, 'budha') !== false) {
        $kategori = 'Buddha';
    } elseif (strpos($raw, 'konghucu') !== false || strpos($raw, 'khonghucu') !== false) {
        $kategori = 'Konghucu';
    } else {
        $kategori = ucwords($raw);
    }
    
    if (!isset($data_agama_kelas[$kelas][$kategori])) {
        $data_agama_kelas[$kelas][$kategori] = 0;
    }
    $data_agama_kelas[$kelas][$kategori] += $total;
}

// Statistik Jenis Kelamin Berdasarkan Rombel
$stmt_gender_rombel = $pdo->query("SELECT kelas, rombel, gender, COUNT(*) as total FROM siswa GROUP BY kelas, rombel, gender ORDER BY kelas ASC, rombel ASC");
$raw_gender_rombel = $stmt_gender_rombel->fetchAll(PDO::FETCH_ASSOC);

$data_gender_rombel = [];
foreach($raw_gender_rombel as $row) {
    $key = "Kelas " . $row['kelas'] . " - Rombel " . $row['rombel'];
    $gender = ($row['gender'] == 'L') ? 'Laki-laki' : (($row['gender'] == 'P') ? 'Perempuan' : 'Tidak Diketahui');
    
    if (!isset($data_gender_rombel[$key])) {
        $data_gender_rombel[$key] = ['Laki-laki' => 0, 'Perempuan' => 0, 'Tidak Diketahui' => 0];
    }
    $data_gender_rombel[$key][$gender] += (int)$row['total'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | SIMTU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<!-- Sidebar Dinamis Berdasarkan Role -->
<div class="sidebar" id="sidebar">
    <div class="p-3 text-center border-bottom border-secondary mb-3">
        <h4 class="logo-text m-0"> <img src="uploads/assets/logo-login.png" alt="LOGO" style="height: 40px; vertical-align: middle; margin-right: 8px;"> <span class="menu-text">SIM-TU 236</span></h4>
    </div>
    
    <?php 
    // Deteksi halaman yang sedang dibuka untuk auto-active menu
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
    $is_kepegawaian = in_array($current_page, ['duk.php', 'masakerja.php']);
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
    
    <a href="logout.php" class="mt-auto text-danger"><i class="fas fa-sign-out-alt"></i> <span class="menu-text">Logout</span></a>
</div>

<!-- Main Content -->
<div class="main-content" id="main-content">
    <div class="top-header mb-4 d-flex justify-content-between align-items-center">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        
        <div class="d-flex align-items-center">
            
            <!-- Monitoring Bank Data Sampah -->
            <a href="monitoring_sampah.php" class="btn btn-sm fw-bold me-2 btn-success">
                <i class="fas fa-recycle"></i> Monitoring Bank Data Sampah
            </a>
            
            <a href="public_dashboard.php" target="_blank" class="btn btn-sm fw-bold me-3" style="background-color: var(--navy-blue); color: var(--gold);">
                <i class="fas fa-globe"></i> Lihat Dashboard Public
            </a>
            <img src="<?= $avatar_path ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
            <div>
                <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($role) ?></small>
            </div>
        </div>
    </div>

    <!-- Metric Cards Ringkasan -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="metric-card">
                <h6 class="text-muted">Total Siswa</h6>
                <h3><?= $total_siswa ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <h6 class="text-muted">Surat Masuk</h6>
                <h3><?= $total_surat_masuk ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <h6 class="text-muted">Surat Keluar</h6>
                <h3><?= $total_surat_keluar ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <h6 class="text-muted">Total Mutasi</h6>
                <h3><?= $total_mutasi ?></h3>
            </div>
        </div>
    </div>

    <!-- Tabel Rekapitulasi Absensi Harian Guru Piket (Update Rowspan) -->
    <div class="card border-0 shadow-sm p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-clipboard-user"></i> Rekapitulasi Absensi Harian Guru Piket</h5>
            <form method="GET" class="d-flex gap-2 align-items-center">
                <label class="small fw-bold text-muted">Pilih Tanggal:</label>
                <input type="date" name="tanggal_rekap" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_tanggal) ?>">
                <button type="submit" class="btn btn-sm btn-dark">Filter</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle">
                <thead class="text-center" style="background-color: var(--bg-light);">
                    <tr>
                        <th>Hari /<br>Tanggal</th>
                        <th>Rombel</th>
                        <th>NISN & Nama Siswa</th>
                        <th>Keterangan</th>
                        <th>Alasan</th>
                        <th>Jumlah Siswa<br>Masuk</th>
                        <th>Petugas Piket</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($grouped_absensi) > 0): ?>
                        <?php foreach($grouped_absensi as $rombel_key => $group): 
                            $rowspan = count($group);
                            $first = true;
                            
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
                                    <span class="badge bg-success"><?= $jml_masuk_rekap ?> Siswa</span>
                                </td>
                                <td rowspan="<?= $rowspan ?>" class="text-center align-middle">
                                    <?= htmlspecialchars($row['petugas']) ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php 
                                $first = false;
                            endforeach; 
                        ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">Belum ada data input absensi piket pada tanggal ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Rincian Statistik Agama, Inklusi, dan Jenis Kelamin -->
    <div class="row">
        
        <!-- Kolom Kiri: Agama Kelas & Keterangan Khusus/Inklusi -->
        <div class="col-md-6 mb-4">
            
            <!-- Statistik Agama Berdasarkan Kelas -->
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 class="mb-3" style="color: var(--navy-blue);"><i class="fas fa-table"></i> Statistik Agama Berdasarkan Kelas</h5>
                <div class="table-responsive">
                    <!-- CLASS TABLE-SM DAN FONT-SIZE DITAMBAHKAN DISINI -->
                    <table class="table table-sm table-striped table-hover align-middle" style="font-size: 0.85rem;">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>Kelas</th>
                                <th>Agama</th>
                                <th class="text-end">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_agama_kelas) > 0): ?>
                                <?php foreach($data_agama_kelas as $kelas => $agamas): ?>
                                    <?php $first = true; $count_agama = count($agamas); ?>
                                    <?php foreach($agamas as $agama => $jumlah): ?>
                                    <tr>
                                        <?php if($first): ?>
                                            <td rowspan="<?= $count_agama ?>" class="fw-bold align-middle">Kelas <?= htmlspecialchars($kelas) ?></td>
                                            <?php $first = false; ?>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($agama) ?></td>
                                        <td class="text-end fw-bold"><?= $jumlah ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-muted">Belum ada data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Rincian Data Berdasarkan Keterangan Khusus / Inklusi -->
            <div class="card border-0 shadow-sm p-4">
                <h5 class="mb-3" style="color: var(--navy-blue);"><i class="fas fa-table"></i> Rincian Data Keterangan Khusus / Inklusi</h5>
                <div class="table-responsive">
                    <!-- CLASS TABLE-SM DAN FONT-SIZE DITAMBAHKAN DISINI -->
                    <table class="table table-sm table-striped table-hover align-middle" style="font-size: 0.85rem;">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>Rombel</th>
                                <th>Keterangan / Jenis Inklusi</th>
                                <th class="text-end">Jumlah Siswa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_keterangan_rombel) > 0): ?>
                                <?php foreach($data_keterangan_rombel as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(!empty($row['rombel']) ? $row['rombel'] : '-') ?></td>
                                    <td><?= htmlspecialchars($row['keterangan']) ?></td>
                                    <td class="text-end fw-bold"><?= $row['total'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-muted">Belum ada data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div> <!-- Tutup Kolom Kiri -->

        <!-- Kolom Kanan: Jenis Kelamin Berdasarkan Rombel -->
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm p-4 h-100">
                <h5 class="mb-3" style="color: var(--navy-blue);"><i class="fas fa-table"></i> Statistik Jenis Kelamin Berdasarkan Rombel</h5>
                <div class="table-responsive">
                    <!-- CLASS TABLE-SM DAN FONT-SIZE DITAMBAHKAN DISINI -->
                    <table class="table table-sm table-striped table-hover align-middle" style="font-size: 0.85rem;">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>Kelas & Rombel</th>
                                <th class="text-center">Laki-laki</th>
                                <th class="text-center">Perempuan</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_gender_rombel) > 0): ?>
                                <?php foreach($data_gender_rombel as $rombel => $counts): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rombel) ?></td>
                                    <td class="text-center"><?= $counts['Laki-laki'] ?></td>
                                    <td class="text-center"><?= $counts['Perempuan'] ?></td>
                                    <td class="text-end fw-bold"><?= $counts['Laki-laki'] + $counts['Perempuan'] + $counts['Tidak Diketahui'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted">Belum ada data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div> <!-- Tutup Kolom Kanan -->
        
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
            text: '<?= $_SESSION['alert']['text'] ?>' 
        });
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>
</script>
</body>
</html>