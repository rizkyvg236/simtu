<?php
require 'koneksi.php';

// Cek autentikasi dan otorisasi hak akses (Administrator, TU Kesiswaan, Kepala Sekolah, Kepala TU)[cite: 40]
if (!isset($_SESSION['user_nip']) || !in_array($_SESSION['role'], ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke modul Mutasi Siswa.'];
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];

// ==========================================
// LOGIKA PROSES (POST)
// ==========================================

// 1. Tambah Mutasi (Masuk / Keluar) & Auto-Sinkronisasi Status Siswa
if (isset($_POST['tambah_mutasi'])) {
    $jenis_mutasi        = $_POST['jenis_mutasi'];
    $no_surat_pindah     = $_POST['no_surat_pindah'];
    
    // PERBAIKAN: Pisahkan NISN agar tidak bentrok antara form Masuk dan Keluar
    $nisn = ($jenis_mutasi == 'Keluar') ? ($_POST['nisn_keluar'] ?? '') : ($_POST['nisn_masuk'] ?? '');
    $nama_lengkap        = $_POST['nama_lengkap'] ?? '';

    // KODE ASLI DI BAWAHNYA TETAP SAMA
    $kelas               = $_POST['kelas'] ?? '-';
    $rombel              = $_POST['rombel'] ?? '-';
    $keterangan          = $_POST['keterangan'] ?? 'Non Inklusi';
    $agama               = $_POST['agama'] ?? '-';
    $gender              = $_POST['gender'] ?? 'L';
    $sekolah_tujuan_asal = $_POST['sekolah_tujuan_asal'];
    $alasan_pindah       = $_POST['alasan_pindah'];

    try {
        $pdo->beginTransaction();

        $stmt_cek = $pdo->prepare("SELECT * FROM siswa WHERE nisn = ?");
        $stmt_cek->execute([$nisn]);
        $siswa_data = $stmt_cek->fetch();

        if ($jenis_mutasi == 'Masuk') {
            if (!$siswa_data) {
                // Jika mutasi masuk dan siswa belum ada di database, daftarkan dulu
                $stmt_ins_siswa = $pdo->prepare("INSERT INTO siswa (nisn, nama_lengkap, kelas, rombel, agama, gender, keterangan, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktif')");
                $stmt_ins_siswa->execute([$nisn, $nama_lengkap, $kelas, $rombel, $agama, $gender, $keterangan]);
            } else {
                // Update status siswa menjadi Aktif kembali jika sebelumnya Pindah
                $stmt_up_siswa = $pdo->prepare("UPDATE siswa SET status = 'Aktif' WHERE nisn = ?");
                $stmt_up_siswa->execute([$nisn]);
            }
        } else { // Mutasi Keluar
            if (!$siswa_data) {
                throw new Exception("NISN tidak ditemukan di data master siswa aktif.");
            }
            // Update status siswa menjadi Pindah
            $stmt_up_siswa = $pdo->prepare("UPDATE siswa SET status = 'Pindah' WHERE nisn = ?");
            $stmt_up_siswa->execute([$nisn]);
        }

        // Storage System: Struktur folder dinamis berbasis waktu (/arsip/tahun/bulan/...)
        $folder_modul = ($jenis_mutasi == 'Masuk') ? 'mutasi_masuk' : 'mutasi_keluar';
        $upload_dir = getDynamicUploadDir($folder_modul);
        $file_name = time() . "_" . basename($_FILES["file_sk"]["name"]);
        $target_file = $upload_dir . $file_name;

        $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        if (!in_array($file_type, ['pdf', 'jpg', 'jpeg', 'png'])) {
            throw new Exception("Format dokumen SK harus PDF, JPG, atau PNG.");
        }

        if (move_uploaded_file($_FILES["file_sk"]["tmp_name"], $target_file)) {
            $stmt = $pdo->prepare("INSERT INTO mutasi (jenis_mutasi, no_surat_pindah, nisn, sekolah_tujuan_asal, alasan_pindah, file_sk_pindah) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$jenis_mutasi, $no_surat_pindah, $nisn, $sekolah_tujuan_asal, $alasan_pindah, $target_file]);
            
            // === TAMBAHKAN KODE NOTIFIKASI DI SINI ===
            addNotification($pdo, "Ada Mutasi Siswa Baru ($jenis_mutasi) dengan No Surat: $no_surat_pindah", "mutasi.php", "All");

            $pdo->commit();
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data mutasi berhasil direkam!'];
        } else {
            throw new Exception("Gagal mengunggah file SK Pindah.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Input', 'text' => $e->getMessage()];
    }
    header("Location: mutasi.php");
    exit;
}

// 2. Edit Mutasi
if (isset($_POST['edit_mutasi'])) {
    $id                  = $_POST['id'];
    $no_surat_pindah     = $_POST['no_surat_pindah'];
    $sekolah_tujuan_asal = $_POST['sekolah_tujuan_asal'];
    $alasan_pindah       = $_POST['alasan_pindah'];

    try {
        if (!empty($_FILES["file_sk"]["name"])) {
            $stmt_m = $pdo->prepare("SELECT jenis_mutasi, file_sk_pindah FROM mutasi WHERE id = ?");
            $stmt_m->execute([$id]);
            $m_data = $stmt_m->fetch();

            $folder_modul = ($m_data['jenis_mutasi'] == 'Masuk') ? 'mutasi_masuk' : 'mutasi_keluar';
            $upload_dir = getDynamicUploadDir($folder_modul);
            $file_name = time() . "_" . basename($_FILES["file_sk"]["name"]);
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES["file_sk"]["tmp_name"], $target_file)) {
                if ($m_data['file_sk_pindah'] && file_exists($m_data['file_sk_pindah'])) {
                    unlink($m_data['file_sk_pindah']);
                }
                $stmt = $pdo->prepare("UPDATE mutasi SET no_surat_pindah = ?, sekolah_tujuan_asal = ?, alasan_pindah = ?, file_sk_pindah = ? WHERE id = ?");
                $stmt->execute([$no_surat_pindah, $sekolah_tujuan_asal, $alasan_pindah, $target_file, $id]);
            } else {
                throw new Exception("Gagal mengunggah dokumen baru.");
            }
        } else {
            $stmt = $pdo->prepare("UPDATE mutasi SET no_surat_pindah = ?, sekolah_tujuan_asal = ?, alasan_pindah = ? WHERE id = ?");
            $stmt->execute([$no_surat_pindah, $sekolah_tujuan_asal, $alasan_pindah, $id]);
        }

        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Diperbarui', 'text' => 'Data mutasi berhasil diperbarui.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Update', 'text' => $e->getMessage()];
    }
    header("Location: mutasi.php");
    exit;
}

// 3. Hapus Mutasi
if (isset($_POST['hapus_mutasi'])) {
    $id = $_POST['id'];
    $nisn = $_POST['nisn'];
    $jenis = $_POST['jenis_mutasi'];

    try {
        $pdo->beginTransaction();

        $stmt_f = $pdo->prepare("SELECT file_sk_pindah FROM mutasi WHERE id = ?");
        $stmt_f->execute([$id]);
        $file_path = $stmt_f->fetchColumn();

        if ($file_path && file_exists($file_path)) {
            unlink($file_path);
        }

        $stmt = $pdo->prepare("DELETE FROM mutasi WHERE id = ?");
        $stmt->execute([$id]);

        // Revert status siswa jika diperlukan (jika keluar dihapus, kembalikan ke aktif)
        if ($jenis == 'Keluar') {
            $pdo->prepare("UPDATE siswa SET status = 'Aktif' WHERE nisn = ?")->execute([$nisn]);
        }

        $pdo->commit();
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Data mutasi berhasil dihapus.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Hapus', 'text' => $e->getMessage()];
    }
    header("Location: mutasi.php");
    exit;
}

// ==========================================
// EXPORT LAPORAN (EXCEL/CSV)
// ==========================================
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    if ($format == 'excel') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Laporan_Mutasi_SMPN236_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID Mutasi', 'Jenis Mutasi', 'No Surat Pindah', 'NISN', 'Nama Siswa', 'Sekolah Tujuan/Asal', 'Alasan Pindah', 'Tanggal Rekam']);
        
        $stmt_exp = $pdo->query("SELECT m.*, s.nama_lengkap FROM mutasi m JOIN siswa s ON m.nisn = s.nisn ORDER BY m.created_at DESC");
        while ($row = $stmt_exp->fetch()) {
            fputcsv($output, [$row['id'], $row['jenis_mutasi'], $row['no_surat_pindah'], $row['nisn'], $row['nama_lengkap'], $row['sekolah_tujuan_asal'], $row['alasan_pindah'], $row['created_at']]);
        }
        fclose($output);
        exit;
    }
}

// Pencarian Cerdas & Fetch Data
$search = isset($_GET['q']) ? '%' . $_GET['q'] . '%' : '%';
$stmt = $pdo->prepare("SELECT m.*, s.nama_lengkap, s.kelas FROM mutasi m JOIN siswa s ON m.nisn = s.nisn WHERE m.no_surat_pindah LIKE ? OR m.nisn LIKE ? OR s.nama_lengkap LIKE ? OR m.sekolah_tujuan_asal LIKE ? ORDER BY m.id DESC");
$stmt->execute([$search, $search, $search, $search]);
$mutasi_list = $stmt->fetchAll();

// Ambil juga data siswa untuk pilihan dropdown mutasi keluar
$siswa_aktif = $pdo->query("SELECT * FROM siswa WHERE status = 'Aktif' ORDER BY nama_lengkap ASC")->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manajemen Mutasi Siswa | SIMTU</title>
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
    $is_kepegawaian = in_array($current_page, ['duk.php', 'arsip_kepeg.php']);
    // Hak Akses Menu Induk Kepegawaian
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
        <?php endif; ?>
        <!-- Sub-menu 2: Arsip Kepegawaian -->
        <?php if(in_array($cek_role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="arsip_kepeg.php" class="<?= $current_page == 'arsip_kepeg.php' ? 'active' : '' ?>"><i class="fas fa-user-clock"></i> <span class="menu-text">Arsip Kepegawaian</span></a>
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
        <!-- Sub-menu 1: Surat Masuk -->
        <?php if(in_array($cek_role, ['Administrator', 'TU_Persuratan', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="suratmasuk.php" class="<?= $current_page == 'suratmasuk.php' ? 'active' : '' ?>"><i class="fas fa-envelope-open-text"></i> <span class="menu-text">Surat Masuk</span></a>
        <?php endif; ?>
        <!-- Sub-menu 2: Surat Keluar -->
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
        <!-- Sub-menu 1: Mutasi Siswa -->
        <?php if(in_array($cek_role, ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="mutasi.php" class="<?= $current_page == 'mutasi.php' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> <span class="menu-text">Mutasi Siswa</span></a>
        <?php endif; ?>
        <!-- Sub-menu 2: Piket Pintar -->
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

<!-- Main Content -->
<div class="main-content" id="main-content">
    <div class="top-header mb-4">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">MUTASI SISWA MASUK & KELUAR</h5>
        <div class="d-flex align-items-center">
        <img src="<?= $avatar_path ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
        <div>
        <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
        <small class="text-muted"><?= htmlspecialchars($role) ?></small>
    </div>
</div>
    </div>

    <!-- Konten Utama Mutasi -->
    <div class="card border-0 shadow-sm p-4">
        <div class="row align-items-center mb-4">
            <div class="col-md-5">
                <!-- Form Smart Search -->
                <form method="GET" class="input-group">
                    <input type="text" name="q" class="form-control" placeholder="Cari NISN, nama siswa, atau surat pindah..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                    <button class="btn" style="background: var(--navy-blue); color: var(--gold);" type="submit"><i class="fas fa-search"></i></button>
                </form>
            </div>
            <div class="col-md-7 text-end">
                <a href="mutasi.php?export=excel" class="btn btn-success me-2"><i class="fas fa-file-excel"></i> Export Excel</a>
                <?php if(in_array($role, ['Administrator', 'TU_Kesiswaan'])): ?>
                <button class="btn" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahMutasi">
                    <i class="fas fa-plus-circle"></i> Input Mutasi
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead style="background-color: var(--bg-light);">
                    <tr>
                        <th>ID</th>
                        <th>Jenis Mutasi</th>
                        <th>No. Surat Pindah</th>
                        <th>NISN & Nama Siswa</th>
                        <th>Kelas</th>
                        <th>Sekolah Asal / Tujuan</th>
                        <th>Alasan</th>
                        <th>SK Pindah</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($mutasi_list) > 0): ?>
                        <?php foreach($mutasi_list as $row): ?>
                        <tr>
                            <td><strong>#<?= $row['id'] ?></strong></td>
                            <td>
                                <span class="badge <?= $row['jenis_mutasi'] == 'Masuk' ? 'bg-success' : 'bg-danger' ?>">
                                    Mutasi <?= $row['jenis_mutasi'] ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($row['no_surat_pindah']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['nama_lengkap']) ?></strong><br>
                                <small class="text-muted">NISN: <?= htmlspecialchars($row['nisn']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($row['kelas']) ?></td>
                            <td><?= htmlspecialchars($row['sekolah_tujuan_asal']) ?></td>
                            <td><?= htmlspecialchars($row['alasan_pindah']) ?></td>
                            <td>
                                <?php if(!empty($row['file_sk_pindah'])): ?>
                                    <a href="<?= $row['file_sk_pindah'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Lihat SK"><i class="fas fa-file-pdf text-danger"></i> Lihat</a>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(in_array($role, ['Administrator', 'TU_Kesiswaan'])): ?>
                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditMutasi<?= $row['id'] ?>" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data mutasi ini? Status siswa akan disesuaikan kembali.');">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="nisn" value="<?= $row['nisn'] ?>">
                                    <input type="hidden" name="jenis_mutasi" value="<?= $row['jenis_mutasi'] ?>">
                                    <button type="submit" name="hapus_mutasi" class="btn btn-sm btn-danger" title="Hapus"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- MODAL EDIT MUTASI -->
                        <div class="modal fade" id="modalEditMutasi<?= $row['id'] ?>" tabindex="-1">
                          <div class="modal-dialog">
                            <div class="modal-content">
                              <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Mutasi #<?= $row['id'] ?></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                              </div>
                              <form method="POST" enctype="multipart/form-data">
                                  <div class="modal-body">
                                      <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                      <div class="mb-3">
                                          <label class="form-label">Nomor Surat Pindah</label>
                                          <input type="text" name="no_surat_pindah" class="form-control" value="<?= htmlspecialchars($row['no_surat_pindah']) ?>" required>
                                      </div>
                                      <div class="mb-3">
                                          <label class="form-label">Sekolah Asal / Tujuan</label>
                                          <input type="text" name="sekolah_tujuan_asal" class="form-control" value="<?= htmlspecialchars($row['sekolah_tujuan_asal']) ?>" required>
                                      </div>
                                      <div class="mb-3">
                                          <label class="form-label">Alasan Pindah</label>
                                          <textarea name="alasan_pindah" class="form-control" rows="2" required><?= htmlspecialchars($row['alasan_pindah']) ?></textarea>
                                      </div>
                                      <div class="mb-3">
                                          <label class="form-label">Ganti Berkas SK (Opsional)</label>
                                          <input type="file" name="file_sk" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                      </div>
                                  </div>
                                  <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" name="edit_mutasi" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Perubahan</button>
                                  </div>
                              </form>
                            </div>
                          </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Belum ada data mutasi siswa yang tercatat.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH MUTASI -->
<div class="modal fade" id="modalTambahMutasi" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Form Input Mutasi Siswa</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data" id="formMutasi">
          <div class="modal-body">
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Jenis Mutasi</label>
                      <select name="jenis_mutasi" id="jenis_mutasi" class="form-select" required onchange="toggleMutasiMode()">
                          <option value="Keluar">Mutasi Keluar</option>
                          <option value="Masuk">Mutasi Masuk</option>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Nomor Surat Pindah</label>
                      <input type="text" name="no_surat_pindah" class="form-control" placeholder="Contoh: 421.9/SKP/2026" required>
                  </div>
              </div>

              <!-- JIKA KELUAR: Pilih dari Siswa Aktif -->
              <div class="mb-3" id="groupKeluar">
                  <label class="form-label">Pilih Siswa Aktif</label>
                  <select name="nisn_keluar" id="select_nisn_keluar" class="form-select" onchange="fillSiswaData(this)">
                 <option value="">-- Pilih Siswa --</option>
                <?php foreach($siswa_aktif as $s): ?>
                <option value="<?= $s['nisn'] ?>" data-nama="<?= $s['nama_lengkap'] ?>" data-kelas="<?= $s['kelas'] ?>">
                [<?= $s['nisn'] ?>] <?= $s['nama_lengkap'] ?> (Kelas <?= $s['kelas'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
              </div>

              <!-- JIKA MASUK: Input Manual Data Siswa Baru/Pindahan -->
                  <div id="groupMasuk" style="display: none;">
                  <div class="row">
                      <div class="col-md-4 mb-3">
                          <label class="form-label">NISN Siswa</label>
                          <input type="number" name="nisn_masuk" id="input_nisn_masuk" class="form-control" placeholder="Maks 10 digit">
                      </div>
                      <div class="col-md-8 mb-3">
                          <label class="form-label">Nama Lengkap Siswa</label>
                          <input type="text" name="nama_lengkap" id="input_nama_masuk" class="form-control" placeholder="Nama lengkap & gelar">
                      </div>
                  </div>
                  <div class="row">
                      <div class="col-md-3 mb-3">
                          <label class="form-label">Kelas</label>
                          <input type="text" name="kelas" class="form-control" placeholder="Contoh: VII">
                      </div>
                      <div class="col-md-3 mb-3">
                          <label class="form-label">Rombel</label>
                          <input type="text" name="rombel" class="form-control" placeholder="Contoh: A">
                      </div>
                      <div class="col-md-3 mb-3">
                          <label class="form-label">Gender</label>
                          <select name="gender" class="form-select">
                              <option value="L">Laki-laki</option>
                              <option value="P">Perempuan</option>
                          </select>
                      </div>
                    <div class="col-md-3 mb-3">
    <label class="form-label">Keterangan / Jenis</label>
    <select name="keterangan" class="form-select">
        <option value="NON INKLUSI">NON INKLUSI</option>
        <option value="RUNGU(B)">RUNGU(B)</option>
        <option value="GRAHITA RINGAN(C)">GRAHITA RINGAN(C)</option>
        <option value="GRAHITA SEDANG(C1)">GRAHITA SEDANG(C1)</option>
        <option value="DAKSA RINGAN(D)">DAKSA RINGAN(D)</option>
        <option value="DAKSA SENDANG(D1)">DAKSA SENDANG(D1)</option>
        <option value="LARAS(E)">LARAS(E)</option>
        <option value="WICARA(F)">WICARA(F)</option>
        <option value="HYPERAKTIF(H)">HYPERAKTIF(H)</option>
        <option value="CERDAS ISTIMEWAH(I)">CERDAS ISTIMEWAH(I)</option>
        <option value="BAKAT ISTIMEWAH(J)">BAKAT ISTIMEWAH(J)</option>
        <option value="KESULITAN BELAJAR(K)">KESULITAN BELAJAR(K)</option>
        <option value="NARKOBA(N)">NARKOBA(N)</option>
        <option value="INDIGO(O)">INDIGO(O)</option>
        <option value="DOWN SYNDROME(P)">DOWN SYNDROME(P)</option>
        <option value="AUTIS (Q)">AUTIS (Q)</option>
    </select>
</div>
                  </div>
              </div>

                <div class="mb-3">
                    <label class="form-label" id="labelTujuanAsal">Sekolah Tujuan Pindah</label>
                    <input type="text" name="sekolah_tujuan_asal" class="form-control" placeholder="Contoh: SMPN 100 Jakarta" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Alasan Pindah</label>
                    <textarea name="alasan_pindah" class="form-control" rows="2" placeholder="Alasan perpindahan siswa..." required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Unggah SK Pindah (PDF / JPG)</label>
                    <input type="file" name="file_sk" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" name="tambah_mutasi" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan & Sinkronkan</button>
            </div>
        </form>
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

    // Toggle Form Mutasi Masuk / Keluar
    function toggleMutasiMode() {
        let val = document.getElementById('jenis_mutasi').value;
        let groupKeluar = document.getElementById('groupKeluar');
        let groupMasuk = document.getElementById('groupMasuk');
        let labelTujuanAsal = document.getElementById('labelTujuanAsal');
        let selectNisn = document.getElementById('select_nisn_keluar');
        let inputNisnMasuk = document.getElementById('input_nisn_masuk');
        let inputNamaMasuk = document.getElementById('input_nama_masuk');

        if(val === 'Masuk') {
            groupKeluar.style.display = 'none';
            groupMasuk.style.display = 'block';
            selectNisn.removeAttribute('required');
            inputNisnMasuk.setAttribute('required', 'true');
            inputNamaMasuk.setAttribute('required', 'true');
            labelTujuanAsal.innerText = 'Sekolah Asal Siswa';
        } else {
            groupKeluar.style.display = 'block';
            groupMasuk.style.display = 'none';
            selectNisn.setAttribute('required', 'true');
            inputNisnMasuk.removeAttribute('required');
            inputNamaMasuk.removeAttribute('required');
            labelTujuanAsal.innerText = 'Sekolah Tujuan Pindah';
        }
    }

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