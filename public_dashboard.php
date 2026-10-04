<?php
require 'koneksi.php';

// Ambil data statistik dari database siswa secara realtime
$total_siswa = 0;
try {
    $total_siswa = $pdo->query("SELECT COUNT(*) FROM siswa")->fetchColumn();
} catch (Exception $e) {
    $total_siswa = 0;
}

// Daftar Rombel & Hitung Total Siswa per Rombel untuk Acuan Otomatis
$list_rombel_ref = ['7A', '7B', '7C', '7D', '7E', '7F', '7G', '8A', '8B', '8C', '8D', '8E', '8F', '8G', '9A', '9B', '9C', '9D', '9E', '9F', '9G'];
$total_siswa_per_rombel = [];
foreach($list_rombel_ref as $r) {
    try {
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM siswa WHERE rombel = ? AND status = 'Aktif'");
        $stmt_count->execute([$r]);
        $total_siswa_per_rombel[$r] = (int)$stmt_count->fetchColumn();
    } catch (Exception $e) {
        $total_siswa_per_rombel[$r] = 0;
    }
}

// UPDATE: Ambil Data Rekapitulasi Absensi Hari Ini menggunakan LEFT JOIN & ORDER BY rombel
$filter_tanggal_pub = date('Y-m-d');
$data_rekap_pub = [];
try {
    $stmt_rekap_pub = $pdo->prepare("SELECT pa.*, s.nama_lengkap FROM piket_absensi pa LEFT JOIN siswa s ON pa.nisn = s.nisn WHERE pa.tanggal = ? ORDER BY pa.rombel ASC, pa.id DESC");
    $stmt_rekap_pub->execute([$filter_tanggal_pub]);
    $data_rekap_pub = $stmt_rekap_pub->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $data_rekap_pub = [];
}

// UPDATE: Grouping dan kalkulasi untuk rowspan tabel
$jumlah_absen_per_rombel_pub = [];
$grouped_absensi_pub = [];
foreach ($data_rekap_pub as $row_absen) {
    $r = $row_absen['rombel'];
    if (!isset($jumlah_absen_per_rombel_pub[$r])) { $jumlah_absen_per_rombel_pub[$r] = 0; }
    if ($row_absen['nisn'] !== 'NIHIL' && $row_absen['keterangan'] !== 'NIHIL') {
        $jumlah_absen_per_rombel_pub[$r] += 1;
    }
    if (!isset($grouped_absensi_pub[$r])) { $grouped_absensi_pub[$r] = []; }
    $grouped_absensi_pub[$r][] = $row_absen;
}

// Data Agama Berdasarkan Kelas
$data_agama_kelas = [];
$list_kelas = [];
$list_agama = [];
try {
    $stmt_agama_kelas = $pdo->query("SELECT kelas, LOWER(TRIM(agama)) as agama, COUNT(*) as total FROM siswa GROUP BY kelas, LOWER(TRIM(agama)) ORDER BY kelas ASC");
    $raw_agama_kelas = $stmt_agama_kelas->fetchAll(PDO::FETCH_ASSOC);

    foreach($raw_agama_kelas as $row) {
        $kls = $row['kelas'];
        $raw_ag = $row['agama'];
        
        if (empty($raw_ag)) { $ag = 'Lainnya'; }
        elseif (strpos($raw_ag, 'katholik') !== false || strpos($raw_ag, 'katolik') !== false) { $ag = 'Katolik'; }
        elseif (strpos($raw_ag, 'kristen') !== false) { $ag = 'Kristen'; }
        elseif (strpos($raw_ag, 'islam') !== false || strpos($raw_ag, 'muslim') !== false) { $ag = 'Islam'; }
        elseif (strpos($raw_ag, 'hindu') !== false) { $ag = 'Hindu'; }
        elseif (strpos($raw_ag, 'buddha') !== false || strpos($raw_ag, 'budha') !== false) { $ag = 'Buddha'; }
        elseif (strpos($raw_ag, 'konghucu') !== false || strpos($raw_ag, 'khonghucu') !== false) { $ag = 'Konghucu'; }
        else { $ag = ucwords($raw_ag); }
        
        if(!in_array($kls, $list_kelas)) { $list_kelas[] = $kls; }
        if(!in_array($ag, $list_agama)) { $list_agama[] = $ag; }
        
        $data_agama_kelas[$kls][$ag] = (int)$row['total'];
    }
    sort($list_kelas);
} catch (Exception $e) {}

// Data Gender Berdasarkan Rombel
$data_gender_rombel = [];
$list_rombel = [];
try {
    $stmt_gender_rombel = $pdo->query("SELECT rombel, gender, COUNT(*) as total FROM siswa GROUP BY rombel, gender ORDER BY rombel ASC");
    $raw_gender_rombel = $stmt_gender_rombel->fetchAll(PDO::FETCH_ASSOC);

    foreach($raw_gender_rombel as $row) {
        $rmb = !empty($row['rombel']) ? $row['rombel'] : '-';
        $gnd = $row['gender'] == 'L' ? 'Laki-laki' : ($row['gender'] == 'P' ? 'Perempuan' : 'Tidak Diketahui');
        
        if(!in_array($rmb, $list_rombel)) { $list_rombel[] = $rmb; }
        $data_gender_rombel[$rmb][$gnd] = (int)$row['total'];
    }
    sort($list_rombel);
} catch (Exception $e) {}

// Rincian Keterangan Khusus / Inklusi Berdasarkan Rombel
$data_keterangan_rombel = [];
try {
    $stmt_ket_rombel = $pdo->query("SELECT rombel, keterangan, COUNT(*) as total FROM siswa GROUP BY rombel, keterangan ORDER BY rombel ASC");
    $data_keterangan_rombel = $stmt_ket_rombel->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Cek Keberadaan Aset Gambar secara Aman
$bg_public = file_exists("uploads/assets/bg-public.jpg") ? "uploads/assets/bg-public.jpg" : "";
$logo_public = file_exists("uploads/assets/logo-public.png") ? "uploads/assets/logo-public.png" : "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Portal Statistik Siswa | SIMTU 236</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-size: 13px; }
        .dashboard-public-card {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            border-radius: 10px;
            max-width: 980px;
            margin: 10px auto 40px auto; 
        }
        .header-title { font-size: 12px; }
        .logo-img { max-height: 50px; }
        .chart-container { height: 130px; }

        @media (max-width: 768px) {
            .dashboard-public-card {
                margin: 5px auto 40px auto;
                padding: 8px !important;
                max-width: 100%;
            }
            .header-title { font-size: 11px !important; }
            .logo-img { max-height: 40px !important; margin-bottom: 4px !important; }
            .total-siswa-text { font-size: 1.5rem !important; }
            .chart-container { height: 120px !important; }
            .table-sm { font-size: 10px !important; }
        }
    </style>
</head>
<body class="login-body" style="<?= $bg_public ? "background-image: url('$bg_public'); background-size: cover; background-position: center; background-attachment: fixed;" : "background-color: var(--navy-blue);" ?>">
    
    <div class="container d-flex justify-content-center align-items-center" style="min-height: 100vh;">
        <div class="row justify-content-center w-100 m-0">
            <div class="col-12 px-1">
                <div class="card p-3 shadow-lg border-0 login-card dashboard-public-card">
                    
                    <!-- Header Card -->
                    <div class="text-center mb-2 border-bottom pb-2">
                        <?php if($logo_public): ?>
                            <img src="<?= $logo_public ?>" alt="Logo SIMTU" class="logo-img" style="max-width: 100%; width: auto; object-fit: contain; margin-bottom: 4px;">
                        <?php else: ?>
                            <h6 style="color: var(--navy-blue); font-weight: 700;"><i class="fas fa-school"></i> SIM-TU</h6>
                        <?php endif; ?>
                        <p class="text-muted fw-bold mb-0 header-title">PORTAL SISTEM INFORMASI MANAJEMEN TATA USAHA (SIMTU)</p>
                        <p class="text-muted fw-bold mb-0 header-title">SMP NEGERI 236 JAKARTA</p>
                    </div>

                    <!-- Ringkasan Total Siswa -->
                    <div class="text-center mb-3">
                        <span class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Total Siswa Aktif:</span>
                        <h4 class="fw-bold mb-0 total-siswa-text" style="color: var(--navy-blue); display: inline-block; margin-left: 6px;"><?= $total_siswa ?> <span class="fs-6 text-muted">Siswa</span></h4>
                    </div>

                    <!-- Tabel Rekapitulasi Absensi Harian Guru Piket -->
                    <div class="card border shadow-sm p-2 mb-3 bg-white rounded-3">
                        <h6 class="fw-bold mb-2" style="color: var(--navy-blue); font-size: 11px;"><i class="fas fa-clipboard-user"></i> Rekapitulasi Absensi Harian Guru Piket (<?= date('d/m/Y') ?>)</h6>
                        <div class="table-responsive" style="max-height: 130px; overflow-y: auto;">
                            <table class="table table-sm table-striped table-bordered text-center align-middle bg-white mb-0" style="font-size: 11px;">
                                <thead style="background-color: var(--navy-blue); color: white; position: sticky; top: 0;">
                                    <tr>
                                        <th>Rombel</th>
                                        <th>Nama Siswa</th>
                                        <th>Keterangan</th>
                                        <th>Alasan</th>
                                        <th>Siswa Masuk</th>
                                        <th>Petugas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($grouped_absensi_pub) > 0): ?>
                                        <?php foreach($grouped_absensi_pub as $rombel_key => $group): 
                                            $rowspan = count($group);
                                            $first = true;
                                            
                                            $tot_rombel = $total_siswa_per_rombel[$rombel_key] ?? 0;
                                            $tot_absen_riil = $jumlah_absen_per_rombel_pub[$rombel_key] ?? 0;
                                            $masuk_pub = max(0, $tot_rombel - $tot_absen_riil);

                                            foreach($group as $row):
                                                $display_nama = ($row['keterangan'] === 'NIHIL' || $row['nisn'] === 'NIHIL') ? 'SELURUH SISWA MASUK' : htmlspecialchars($row['nama_lengkap']);
                                        ?>
                                        <tr>
                                            <?php if($first): ?>
                                                <td rowspan="<?= $rowspan ?>" class="align-middle"><strong><?= htmlspecialchars($row['rombel']) ?></strong></td>
                                            <?php endif; ?>
                                            
                                            <td class="text-center align-middle"><?= $display_nama ?></td>
                                            
                                            <td class="align-middle">
                                                <?php if ($row['keterangan'] === 'NIHIL'): ?>
                                                    <span class="badge bg-success" style="font-size: 9px;"><?= $row['keterangan'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger" style="font-size: 9px;"><?= htmlspecialchars($row['keterangan']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="align-middle"><?= htmlspecialchars($row['alasan'] ?: '-') ?></td>
                                            
                                            <?php if($first): ?>
                                                <td rowspan="<?= $rowspan ?>" class="align-middle"><span class="badge bg-success" style="font-size: 9px;"><?= $masuk_pub ?> Siswa</span></td>
                                                <td rowspan="<?= $rowspan ?>" class="align-middle"><?= htmlspecialchars($row['petugas']) ?></td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php 
                                                $first = false;
                                            endforeach; 
                                        ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-muted py-2">Belum ada data input absensi guru piket hari ini.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Baris Grid Statistik 1: Agama Berdasarkan Kelas -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <div class="card border shadow-sm p-2 bg-white rounded-3 h-100">
                                <h6 class="mb-1 text-center fw-bold" style="color: var(--navy-blue); font-size: 11px;"><i class="fas fa-chart-bar"></i> Statistik Agama Berdasarkan Kelas</h6>
                                <div class="chart-container" style="position: relative;">
                                    <canvas id="agamaKelasChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border shadow-sm p-2 bg-white rounded-3 h-100">
                                <h6 class="fw-bold mb-1" style="color: var(--navy-blue); font-size: 11px;"><i class="fas fa-table"></i> Rincian Agama Berdasarkan Kelas</h6>
                                <div class="table-responsive" style="max-height: 125px; overflow-y: auto;">
                                    <table class="table table-sm table-striped table-bordered text-center align-middle bg-white mb-0" style="font-size: 11px;">
                                        <thead style="background-color: var(--navy-blue); color: white; position: sticky; top: 0;">
                                            <tr>
                                                <th>Kelas</th>
                                                <th>Agama</th>
                                                <th>Jumlah</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(count($list_kelas) > 0): ?>
                                                <?php foreach($list_kelas as $kls): ?>
                                                    <?php foreach($list_agama as $ag): ?>
                                                        <?php if(isset($data_agama_kelas[$kls][$ag])): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($kls) ?></td>
                                                            <td><?= htmlspecialchars($ag) ?></td>
                                                            <td class="fw-bold"><?= $data_agama_kelas[$kls][$ag] ?></td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-muted">Kosong</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Baris Grid Statistik 2: Jenis Kelamin Berdasarkan Rombel -->
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <div class="card border shadow-sm p-2 bg-white rounded-3 h-100">
                                <h6 class="mb-1 text-center fw-bold" style="color: var(--navy-blue); font-size: 11px;"><i class="fas fa-chart-bar"></i> Statistik Jenis Kelamin Berdasarkan Rombel</h6>
                                <div class="chart-container" style="position: relative;">
                                    <canvas id="genderRombelChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border shadow-sm p-2 bg-white rounded-3 h-100">
                                <h6 class="fw-bold mb-1" style="color: var(--navy-blue); font-size: 11px;"><i class="fas fa-table"></i> Rincian Jenis Kelamin Berdasarkan Rombel</h6>
                                <div class="table-responsive" style="max-height: 125px; overflow-y: auto;">
                                    <table class="table table-sm table-striped table-bordered text-center align-middle bg-white mb-0" style="font-size: 11px;">
                                        <thead style="background-color: var(--navy-blue); color: white; position: sticky; top: 0;">
                                            <tr>
                                                <th>Rombel</th>
                                                <th>Gender</th>
                                                <th>Jumlah</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(count($list_rombel) > 0): ?>
                                                <?php foreach($list_rombel as $rmb): ?>
                                                    <?php foreach(['Laki-laki', 'Perempuan'] as $gnd): ?>
                                                        <?php if(isset($data_gender_rombel[$rmb][$gnd])): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($rmb) ?></td>
                                                            <td><?= htmlspecialchars($gnd) ?></td>
                                                            <td class="fw-bold"><?= $data_gender_rombel[$rmb][$gnd] ?></td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-muted">Kosong</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Rincian Tabel Keterangan Khusus / Inklusi Berdasarkan Rombel -->
                    <div class="row g-2 mb-2">
                        <div class="col-12">
                            <div class="card border shadow-sm p-2 bg-white rounded-3">
                                <h6 class="fw-bold mb-1" style="color: var(--navy-blue); font-size: 11px;"><i class="fas fa-table"></i> Rincian Keterangan Khusus / Inklusi Berdasarkan Rombel</h6>
                                <div class="table-responsive" style="max-height: 115px; overflow-y: auto;">
                                    <table class="table table-sm table-striped table-bordered text-center align-middle bg-white mb-0" style="font-size: 11px;">
                                        <thead style="background-color: var(--navy-blue); color: white; position: sticky; top: 0;">
                                            <tr>
                                                <th>Rombel</th>
                                                <th>Keterangan / Jenis Inklusi</th>
                                                <th>Jumlah</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(count($data_keterangan_rombel) > 0): ?>
                                                <?php foreach($data_keterangan_rombel as $row): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars(!empty($row['rombel']) ? $row['rombel'] : '-') ?></td>
                                                    <td><?= htmlspecialchars($row['keterangan']) ?></td>
                                                    <td class="fw-bold"><?= $row['total'] ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-muted">Kosong</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol Navigasi Halaman Login & Update Kurasi Prestasi -->
                    <div class="mt-1 pt-2 border-top text-center d-flex justify-content-center gap-2 flex-wrap">
                        <a href="../kurasi-sertifikat/index.php" target="_blank" class="btn btn-sm py-1 shadow-sm fw-bold" style="background: var(--navy-blue); color: var(--gold); font-size: 0.8rem; width: 100%; max-width: 230px;">
                            <i class="fas fa-trophy"></i> UPDATE KURASI PRESTASI
                        </a>
                          <a href="login.php" class="btn btn-sm py-1 shadow-sm fw-bold" style="background: var(--gold); color: var(--navy-blue); font-size: 0.8rem; width: 100%; max-width: 230px;">
                            <i class="fas fa-sign-in-alt"></i> HALAMAN LOGIN
                        </a>
                        <a href="../comingsoon/announcement_dashboard.html" target="_blank" class="btn btn-sm py-1 shadow-sm fw-bold" style="background: var(--navy-blue); color: var(--gold); font-size: 0.8rem; width: 100%; max-width: 230px;">
                            <i class="fa-solid fa-user-graduate" style="color: rgb(255, 255, 255);"></i> COMING SOON
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Running Text Footer -->
    <div class="login-footer">
        <div class="marquee-container">
            <span>CREATED by Rizky Agustian, S.Kom &mdash; PORTAL SISTEM INFORMASI MANAJEMEN TATA USAHA (SIMTU) SMP NEGERI 236 JAKARTA</span>
        </div>
    </div>

    <script>
        // Chart Agama Berdasarkan Kelas (Public)
        const ctxAgamaKelas = document.getElementById('agamaKelasChart').getContext('2d');
        new Chart(ctxAgamaKelas, {
            type: 'bar',
            data: {
                labels: <?= json_encode($list_kelas) ?>,
                datasets: [
                    <?php 
                    $colors = ['#0A192F', '#FFD700', '#198754', '#dc3545', '#0dcaf0', '#6c757d'];
                    $idx = 0;
                    foreach($list_agama as $ag): 
                        $arr_val = [];
                        foreach($list_kelas as $kls) {
                            $arr_val[] = isset($data_agama_kelas[$kls][$ag]) ? $data_agama_kelas[$kls][$ag] : 0;
                        }
                        $color = $colors[$idx % count($colors)];
                        $idx++;
                    ?>
                    {
                        label: '<?= $ag ?>',
                        data: <?= json_encode($arr_val) ?>,
                        backgroundColor: '<?= $color ?>'
                    },
                    <?php endforeach; ?>
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 8 } } },
                    x: { ticks: { font: { size: 8 } } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 6, font: { size: 8 }, padding: 2 } }
                }
            }
        });

        // Chart Gender Berdasarkan Rombel (Public)
        const ctxGenderRombel = document.getElementById('genderRombelChart').getContext('2d');
        new Chart(ctxGenderRombel, {
            type: 'bar',
            data: {
                labels: <?= json_encode($list_rombel) ?>,
                datasets: [
                    {
                        label: 'Laki-laki',
                        data: <?= json_encode(array_map(function($rmb) use ($data_gender_rombel) { return isset($data_gender_rombel[$rmb]['Laki-laki']) ? $data_gender_rombel[$rmb]['Laki-laki'] : 0; }, $list_rombel)) ?>,
                        backgroundColor: '#0A192F'
                    },
                    {
                        label: 'Perempuan',
                        data: <?= json_encode(array_map(function($rmb) use ($data_gender_rombel) { return isset($data_gender_rombel[$rmb]['Perempuan']) ? $data_gender_rombel[$rmb]['Perempuan'] : 0; }, $list_rombel)) ?>,
                        backgroundColor: '#FFD700'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 8 } } },
                    x: { ticks: { font: { size: 8 } } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 6, font: { size: 8 }, padding: 2 } }
                }
            }
        });
    </script>
</body>
</html>