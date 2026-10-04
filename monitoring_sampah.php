<?php
require 'koneksi.php';
if (!isset($_SESSION['user_nip'])) { header("Location: login.php"); exit; }

$role =$_SESSION['role'];

// =========================================================================================
// INTEGRASI DATA GOOGLE SPREADSHEET (CSV) REALTIME UNTUK DONUT CHART & TABEL
// =========================================================================================
$url_spreadsheet = "https://docs.google.com/spreadsheets/d/e/2PACX-1vRNKlX8s5ClZizpqR1GQhAdFNwNcYXMPViJYKwDT0WB_gpbGy2dL7MUP0yq6L69-cSJ1elPMtpoWZFM/pub?output=csv";

$total_organik = 0; 
$total_anorganik = 0; 
$total_b3 = 0;
$total_residu = 0;

$data_spreadsheet_sampah = []; // Array untuk menampung baris data tabel

if (!empty($url_spreadsheet)) {
    if (($handle = fopen($url_spreadsheet, "r")) !== FALSE) {
        $headers_ss = fgetcsv($handle, 1000, ",");
        if ($headers_ss !== FALSE) {
            $headers_ss = array_map(function($h) { return strtolower(trim($h)); },$headers_ss);
            while (($data_ss = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data_ss) === count($headers_ss)) {$row = array_combine($headers_ss,$data_ss);
                    
                    // Simpan data array utuh untuk ditampilkan pada Tabel Realtime
                    $data_spreadsheet_sampah[] =$row;
                    
                    // Deteksi kolom dinamis kategori
                    $kategori = '';
                    foreach (['kategori', 'jenis', 'sampah'] as $key) {
                        if (isset($row[$key])) {$kategori = trim($row[$key]); break; }
                    }

                    // Deteksi kolom dinamis berat
                    $berat = 0;
                    foreach (['berat', 'kg', 'jumlah', 'volume'] as $key) {
                        if (isset($row[$key])) {$berat = floatval(str_replace(',', '.', $row[$key])); break; }
                    }

                    // Akumulasi Total Keseluruhan untuk Grafik Donat
                    if (stripos($kategori, 'organik') !== false) {
                        $total_organik +=$berat;
                    } elseif (stripos($kategori, 'anorganik') !== false || stripos($kategori, 'plastik') !== false) {
                        $total_anorganik +=$berat;
                    } elseif (stripos($kategori, 'b3') !== false) {
                        $total_b3 +=$berat;
                    } elseif (stripos($kategori, 'residu') !== false) {
                        $total_residu +=$berat;
                    }
                }
            }
        }
        fclose($handle);
    }
}

// Fallback ke data default jika spreadsheet kosong
if ($total_organik == 0 && $total_anorganik == 0 &&$total_residu == 0 && $total_b3 == 0) {$total_organik = 223.5; 
    $total_anorganik = 123; 
    $total_residu = 0; 
    $total_b3 = 0;
}

$total_keseluruhan = $total_organik +$total_anorganik + $total_residu +$total_b3;

// =========================================================================================
// AMBIL DATA JADWAL & GALERI DARI JSON (YANG DI-INPUT DARI ADMIN.PHP)
// =========================================================================================
$file_jadwal = 'uploads/assets/jadwal_sampah.json';$jadwal_kegiatan = [];
if (file_exists($file_jadwal)) {
    $jadwal_kegiatan = json_decode(file_get_contents($file_jadwal), true) ?? [];
}
if (empty($jadwal_kegiatan)) {$jadwal_kegiatan[] = [
        'id' => '1', 'kategori' => 'Organik', 'judul' => 'Belum Ada Jadwal', 
        'deskripsi' => 'Tambahkan jadwal baru melalui menu Admin.', 'waktu' => '00:00'
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monitoring Bank Sampah | SIMTU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        body { background-color: #f4f7f6; } 
        .waste-widget {
            background-color: #ffffff; border-radius: 1.25rem; padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: none; height: 100%;
        }
        .widget-title {
            font-size: 0.95rem; font-weight: 700; color: #2c3e50;
            margin-bottom: 1.2rem; display: flex; justify-content: space-between; align-items: center;
        }
        .activity-card { border-radius: 1rem; padding: 1.2rem; margin-bottom: 1rem; border: none; position: relative; }
        .activity-card h6 { font-weight: 700; margin-bottom: 5px; }
        .activity-card p { font-size: 0.85rem; margin-bottom: 0; }
        .act-green { background-color: #d1f4e0; color: #0f5132; }
        .act-orange { background-color: #ffe5d0; color: #842029; }
        .act-yellow { background-color: #fff3cd; color: #664d03; }
        .act-icon { position: absolute; right: 15px; top: 15px; opacity: 0.5; font-size: 1.2rem; }
    </style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="p-3 text-center border-bottom border-secondary mb-3">
        <h4 class="logo-text m-0"> <img src="uploads/assets/logo-login.png" alt="LOGO" style="height: 40px; vertical-align: middle; margin-right: 8px;"> <span class="menu-text">SIM-TU 236</span></h4>
    </div>
    
    <?php $current_page = basename($_SERVER['PHP_SELF']);$cek_role = isset($_SESSION['role']) ?$_SESSION['role'] : ''; ?>
    <a href="logout.php" class="mt-auto text-danger"><i class="fas fa-sign-out-alt"></i> <span class="menu-text">Logout</span></a>
</div>

<div class="main-content" id="main-content">
    <div class="top-header mb-4 d-flex justify-content-between align-items-center bg-white shadow-sm p-3 rounded-4">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
            <h4 class="m-0 fw-bold" style="color: var(--navy-blue);"><i class="fas fa-leaf text-success me-2"></i> Dashboard Bank Data Sampah</h4>
        </div>
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary me-3 rounded-pill px-3"><i class="fas fa-arrow-left"></i> Kembali ke SIMTU</a>
            <img src="<?= $avatar_path ?? 'uploads/profil/default.png' ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;">
            <div><h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6><small class="text-muted"><?= htmlspecialchars($role) ?></small></div>
        </div>
    </div>

    <!-- Ringkasan Angka / Kartu Kategori Sesuai Gambar Referensi -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="waste-widget bg-white p-4 shadow-sm rounded-4 border">
                <span class="text-muted small">Total Sampah Keseluruhan</span>
                <h2 class="fw-bold display-6 mt-2 mb-0" style="color: #2c3e50;"><?= number_format($total_keseluruhan, 1, ',', '.') ?> <span class="fs-5 text-muted">Kg</span></h2>
            </div>
        </div>
        <div class="col-md-6">
            <div class="row g-3">
                <div class="col-6">
                    <div class="waste-widget p-3 shadow-sm rounded-4 bg-white border">
                        <span class="text-muted small fw-bold">ANORGANIK</span>
                        <h4 class="fw-bold mt-1 mb-0 text-warning"><?= number_format($total_anorganik, 1, ',', '.') ?></h4>
                    </div>
                </div>
                <div class="col-6">
                    <div class="waste-widget p-3 shadow-sm rounded-4 bg-white border">
                        <span class="text-muted small fw-bold">ORGANIK</span>
                        <h4 class="fw-bold mt-1 mb-0 text-success"><?= number_format($total_organik, 1, ',', '.') ?></h4>
                    </div>
                </div>
                <div class="col-6">
                    <div class="waste-widget p-3 shadow-sm rounded-4 bg-white border">
                        <span class="text-muted small fw-bold">B3</span>
                        <h4 class="fw-bold mt-1 mb-0 text-danger"><?= number_format($total_b3, 1, ',', '.') ?></h4>
                    </div>
                </div>
                <div class="col-6">
                    <div class="waste-widget p-3 shadow-sm rounded-4 bg-white border">
                        <span class="text-muted small fw-bold">RESIDU</span>
                        <h4 class="fw-bold mt-1 mb-0 text-dark"><?= number_format($total_residu, 1, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Baris 1: Grafik Donat Proporsi Komposisi Sampah -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="waste-widget">
                <div class="widget-title">
                    <span><i class="fas fa-chart-pie text-success me-2"></i> Komposisi Proporsi Jenis Sampah (Realtime Spreadsheet)</span>
                    <span class="badge bg-success">Live Data</span>
                </div>
                <div class="row align-items-center">
                    <div class="col-md-6" style="height: 280px;">
                        <canvas id="donutChartSampah"></canvas>
                    </div>
                    <div class="col-md-6">
                        <p class="text-muted small mb-3">Grafik donat ini menampilkan proporsi rincian kategori sampah (Anorganik, Organik, B3, dan Residu) dari total keseluruhan sampah masuk secara *real-time*.</p>
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2"><i class="fas fa-circle text-success me-2"></i> <strong>Organik:</strong> <?= $total_organik ?> Kg</li>
                            <li class="mb-2"><i class="fas fa-circle text-warning me-2"></i> <strong>Anorganik:</strong> <?= $total_anorganik ?> Kg</li>
                            <li class="mb-2"><i class="fas fa-circle text-danger me-2"></i> <strong>B3:</strong> <?= $total_b3 ?> Kg</li>
                            <li class="mb-0"><i class="fas fa-circle text-dark me-2"></i> <strong>Residu:</strong> <?= $total_residu ?> Kg</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Baris 2: Tabel Data Hasil Input Bank Data Sampah Realtime (Dengan Pagination) -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <div class="waste-widget p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                    <div class="widget-title mb-0">
                        <span><i class="fas fa-table text-primary me-2"></i> Data Hasil Input Bank Data Sampah (Realtime Spreadsheet)</span>
                        <span class="badge bg-success ms-2">Live Sinkronisasi</span>
                    </div>
                    
                    <div class="d-flex align-items-center">
                        <label class="me-2 mb-0 small text-muted">Tampilkan</label>
                        <select id="limitSampah" class="form-select form-select-sm shadow-none border-secondary" style="width: auto; cursor: pointer;">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="all">Semua</option>
                        </select>
                        <label class="ms-2 mb-0 small text-muted">data</label>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="tableSampah">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>No</th>
                                <?php if(!empty($data_spreadsheet_sampah)): ?>
                                    <?php foreach(array_keys($data_spreadsheet_sampah[0]) as$col_name): ?>
                                        <th class="text-uppercase"><?= htmlspecialchars($col_name) ?></th>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <th>Informasi</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="tbodySampah">
                            <?php if(!empty($data_spreadsheet_sampah)): ?>
                                <?php $no_ss = 1; foreach($data_spreadsheet_sampah as$row_ss): ?>
                                <tr>
                                    <td><?= $no_ss++ ?></td>
                                    <?php foreach($row_ss as$val_ss): ?>
                                        <td><?= htmlspecialchars($val_ss) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">Belum ada data atau koneksi spreadsheet belum memuat baris data.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Container Pagination -->
                <div id="paginationSampah" class="d-flex justify-content-end mt-3"></div>
            </div>
        </div>
    </div>

    <!-- Baris Utama: Galeri Kegiatan & Aktivitas / Jadwal Terbaru -->
    <div class="row g-3 mb-4">
        <!-- Galeri Kegiatan -->
        <div class="col-md-8">
            <div class="waste-widget p-3 overflow-hidden d-flex flex-column" style="background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border-radius: 1.25rem;">
                <div class="widget-title px-1 mb-2">Galeri Kegiatan <i class="fas fa-camera text-muted"></i></div>
                <div class="flex-grow-1 d-flex flex-column gap-2 pb-1" style="max-height: 450px; overflow-y: auto;">
                    <?php 
                    $ada_galeri_dashboard = false;
                    if(!empty($jadwal_kegiatan)):
                        foreach($jadwal_kegiatan as$j):
                            if(!empty($j['galeri'])):$ada_galeri_dashboard = true;
                    ?>
                        <div class="p-3 border rounded-3 bg-light shadow-sm mb-2">
                            <small class="fw-bold text-dark d-block mb-2" style="font-size: 0.85rem;">
                                <i class="far fa-calendar-alt text-primary"></i> <?= htmlspecialchars($j['waktu']) ?> - <?= htmlspecialchars($j['judul']) ?>
                            </small>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach($j['galeri'] as$foto): ?>
                                    <a href="<?= $foto ?>" target="_blank" title="Klik untuk memperbesar">
                                        <img src="<?= $foto ?>" class="rounded border shadow-sm" style="width: 140px; height: 100px; object-fit: cover;">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php 
                            endif;
                        endforeach;
                    endif;
                    
                    if(!$ada_galeri_dashboard):
                    ?>
                        <div class="text-center text-muted py-5 small">
                            <i class="fas fa-images fa-3x mb-2 text-light"></i><br>
                            Belum ada galeri kegiatan yang diunggah dari menu Admin.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Aktivitas & Jadwal Terbaru -->
        <div class="col-md-4">
            <div class="waste-widget" style="background-color: transparent; box-shadow: none; padding: 0;">
                <div class="widget-title mb-3 px-2">Aktivitas & Jadwal Terbaru <i class="fas fa-calendar-alt text-muted"></i></div>
                
                <div style="max-height: 450px; overflow-y: auto; padding-right: 5px;">
                    <?php foreach($jadwal_kegiatan as$jadwal): 
                        $bg_class = 'act-yellow';$icon = 'fas fa-truck';
                        if ($jadwal['kategori'] == 'Organik') { $bg_class = 'act-green';$icon = 'fas fa-leaf'; }
                        else if ($jadwal['kategori'] == 'Plastik' OR $jadwal['kategori'] == 'Anorganik') { $bg_class = 'act-orange'; $icon = 'fas fa-bottle-water'; }
                    ?>
                    <div class="activity-card <?= $bg_class ?> shadow-sm">
                        <i class="<?= $icon ?> act-icon"></i>
                        <h6><?= htmlspecialchars($jadwal['judul']) ?></h6>
                        <p><?= htmlspecialchars($jadwal['deskripsi']) ?></p>
                        <hr style="opacity: 0.2; margin: 10px 0;">
                        <small><i class="far fa-clock"></i> <?= htmlspecialchars($jadwal['waktu']) ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('main-content').classList.toggle('expanded');
    });

    // Inisialisasi Donut Chart Komposisi Sampah
    const ctxDonut = document.getElementById('donutChartSampah').getContext('2d');
    new Chart(ctxDonut, {
        type: 'doughnut',
        data: {
            labels: ['Organik', 'Anorganik', 'B3', 'Residu'],
            datasets: [{
                data: [<?= $total_organik ?>, <?= $total_anorganik ?>, <?= $total_b3 ?>, <?= $total_residu ?>],
                backgroundColor: ['#2ecc71', '#ff9f43', '#e74c3c', '#2d3436'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 } }
                }
            }
        }
    });

    // SCRIPT PAGINASI TABEL BANK SAMPAH REALTIME (Dipendekkan per 10 Data)
    const tbodySmp = document.getElementById('tbodySampah');
    if (tbodySmp) {
        const rowsSmp = Array.from(tbodySmp.querySelectorAll('tr')); 
        const limitSelectSmp = document.getElementById('limitSampah');
        const paginationContainerSmp = document.getElementById('paginationSampah');
        
        let currentPageSmp = 1;
        let rowsPerPageSmp = parseInt(limitSelectSmp.value);

        function displayRowsSmp() {
            if (limitSelectSmp.value === 'all') {
                rowsPerPageSmp = rowsSmp.length;
                currentPageSmp = 1;
            } else {
                rowsPerPageSmp = parseInt(limitSelectSmp.value);
            }

            const totalPages = Math.ceil(rowsSmp.length / rowsPerPageSmp) || 1;
            if (currentPageSmp > totalPages) currentPageSmp = totalPages;

            const start = (currentPageSmp - 1) * rowsPerPageSmp;
            const end = start + rowsPerPageSmp;

            rowsSmp.forEach((row, index) => {
                // Abaikan baris informasi/peringatan (misal "Belum ada data")
                if (row.cells.length === 1 && row.cells[0].colSpan > 2) {
                    row.style.display = '';
                    return;
                }
                
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            renderPaginationSmp(totalPages);
        }

        function renderPaginationSmp(totalPages) {
            paginationContainerSmp.innerHTML = '';
            if (totalPages <= 1) return;

            const nav = document.createElement('nav');
            const ul = document.createElement('ul');
            ul.className = 'pagination pagination-sm mb-0';

            // Tombol Prev («)
            const liPrev = document.createElement('li');
            liPrev.className = `page-item ${currentPageSmp === 1 ? 'disabled' : ''}`;
            liPrev.innerHTML = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&laquo;</a>`;
            liPrev.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageSmp > 1) { currentPageSmp--; displayRowsSmp(); }
            });
            ul.appendChild(liPrev);

            // Fungsi Bantuan untuk Halaman
            function addPageButton(i) {
                const li = document.createElement('li');
                li.className = `page-item ${currentPageSmp === i ? 'active' : ''}`;
                li.innerHTML = `<a class="page-link" href="javascript:void(0)" style="${currentPageSmp === i ? 'background-color: var(--navy-blue); border-color: var(--navy-blue); color: var(--gold);' : 'color: var(--navy-blue);'}">${i}</a>`;
                li.addEventListener('click', (e) => {
                    e.preventDefault();
                    currentPageSmp = i;
                    displayRowsSmp();
                });
                ul.appendChild(li);
            }

            function addEllipsis() {
                const li = document.createElement('li');
                li.className = 'page-item disabled';
                li.innerHTML = `<span class="page-link" style="color: var(--navy-blue);">...</span>`;
                ul.appendChild(li);
            }

            // Windowing / Jendela halaman
            let maxVisible = 5;
            let startPage = Math.max(1, currentPageSmp - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);

            if (endPage - startPage + 1 < maxVisible) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }

            if (startPage > 1) {
                addPageButton(1);
                if (startPage > 2) addEllipsis();
            }

            for (let i = startPage; i <= endPage; i++) {
                addPageButton(i);
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addEllipsis();
                addPageButton(totalPages);
            }

            // Tombol Next (»)
            const liNext = document.createElement('li');
            liNext.className = `page-item ${currentPageSmp === totalPages ? 'disabled' : ''}`;
            liNext.innerHTML = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&raquo;</a>`;
            liNext.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageSmp < totalPages) { currentPageSmp++; displayRowsSmp(); }
            });
            ul.appendChild(liNext);

            nav.appendChild(ul);
            paginationContainerSmp.appendChild(nav);
        }

        limitSelectSmp.addEventListener('change', () => {
            currentPageSmp = 1;
            displayRowsSmp();
        });

        // Tampilkan data saat load pertama
        displayRowsSmp();
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>