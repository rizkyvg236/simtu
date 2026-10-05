<?php
require 'koneksi.php';

// Cek autentikasi dan otorisasi hak akses[cite: 41]
if (!isset($_SESSION['user_nip']) || !in_array($_SESSION['role'], ['Administrator', 'TU_Persuratan', 'Kepala_Sekolah', 'Kepala_TU'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke modul Surat Keluar.'];
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];

// ==========================================
// LOGIKA PROSES (POST)
// ==========================================

// 1. Tambah Surat Keluar (No. Agenda Manual + Auto-Generate Nomor Surat + Boxname)
if (isset($_POST['tambah_surat_keluar'])) {
    $no_agenda   = $_POST['no_agenda']; // Input manual No. Agenda
    $tgl_dikirim = $_POST['tgl_dikirim'];
    $tujuan      = $_POST['tujuan'];
    $perihal     = $_POST['perihal'];
    $status_disp = $_POST['status_disposisi'];
    $boxname     = trim($_POST['boxname']); 

    try {
        if(empty($no_agenda)) {
            throw new Exception("Nomor Agenda tidak boleh kosong.");
        }
        if(empty($boxname)) {
            throw new Exception("Boxname / Kode Surat tidak boleh kosong.");
        }

        // Otomatis generate nomor surat dengan menyertakan boxname
        $nomor_surat = generateNomorSuratKeluar($pdo, $boxname);

        // Storage System: Struktur folder dinamis berbasis waktu (/arsip/tahun/bulan/...)
        $upload_dir = getDynamicUploadDir('surat_keluar');
        $file_name = time() . "_" . basename($_FILES["file_surat"]["name"]);
        $target_file = $upload_dir . $file_name;

        $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        if (!in_array($file_type, ['pdf', 'jpg', 'jpeg', 'png'])) {
            throw new Exception("Format file dokumen harus PDF, JPG, atau PNG.");
        }

        if (move_uploaded_file($_FILES["file_surat"]["tmp_name"], $target_file)) {
            $stmt = $pdo->prepare("INSERT INTO surat_keluar (no_agenda, tgl_dikirim, nomor_surat, tujuan, perihal, status_disposisi, file_surat) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$no_agenda, $tgl_dikirim, $nomor_surat, $tujuan, $perihal, $status_disp, $target_file]);
        // === TAMBAHKAN KODE NOTIFIKASI DI SINI ===
            addNotification($pdo, "Surat Keluar baru diterbitkan dengan No: " . $nomor_surat, "suratkeluar.php", "All");   
            
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Surat keluar dengan No. Agenda ' . $no_agenda . ' berhasil dibuat!'];
        } else {
            throw new Exception("Gagal mengunggah file surat keluar.");
        }
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Input', 'text' => $e->getMessage()];
    }
    header("Location: suratkeluar.php");
    exit;
}

// 2. Edit / Perbarui Surat Keluar
if (isset($_POST['edit_surat_keluar'])) {
    $no_agenda   = $_POST['no_agenda'];
    $tgl_dikirim = $_POST['tgl_dikirim'];
    $tujuan      = $_POST['tujuan'];
    $perihal     = $_POST['perihal'];
    $status_disp = $_POST['status_disposisi'];

    try {
        // Cek apakah ada file baru yang diunggah
        if (!empty($_FILES["file_surat"]["name"])) {
            $upload_dir = getDynamicUploadDir('surat_keluar');
            $file_name = time() . "_" . basename($_FILES["file_surat"]["name"]);
            $target_file = $upload_dir . $file_name;

            $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
            if (!in_array($file_type, ['pdf', 'jpg', 'jpeg', 'png'])) {
                throw new Exception("Format file dokumen baru harus PDF, JPG, atau PNG.");
            }

            if (move_uploaded_file($_FILES["file_surat"]["tmp_name"], $target_file)) {
                // Ambil file lama untuk dihapus dari server
                $stmt_old = $pdo->prepare("SELECT file_surat FROM surat_keluar WHERE no_agenda = ?");
                $stmt_old->execute([$no_agenda]);
                $old_file = $stmt_old->fetchColumn();
                if ($old_file && file_exists($old_file)) {
                    unlink($old_file);
                }

                $stmt = $pdo->prepare("UPDATE surat_keluar SET tgl_dikirim = ?, tujuan = ?, perihal = ?, status_disposisi = ?, file_surat = ? WHERE no_agenda = ?");
                $stmt->execute([$tgl_dikirim, $tujuan, $perihal, $status_disp, $target_file, $no_agenda]);
            } else {
                throw new Exception("Gagal mengunggah file baru.");
            }
        } else {
            // Update tanpa mengganti file
            $stmt = $pdo->prepare("UPDATE surat_keluar SET tgl_dikirim = ?, tujuan = ?, perihal = ?, status_disposisi = ? WHERE no_agenda = ?");
            $stmt->execute([$tgl_dikirim, $tujuan, $perihal, $status_disp, $no_agenda]);
        }

        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil Diperbarui', 'text' => 'Data surat keluar berhasil diubah.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Update', 'text' => $e->getMessage()];
    }
    header("Location: suratkeluar.php");
    exit;
}

// 3. Hapus Surat Keluar
if (isset($_POST['hapus_surat_keluar'])) {
    $no_agenda = $_POST['no_agenda'];

    try {
        // Ambil file fisik untuk dihapus dari server
        $stmt_file = $pdo->prepare("SELECT file_surat FROM surat_keluar WHERE no_agenda = ?");
        $stmt_file->execute([$no_agenda]);
        $file_path = $stmt_file->fetchColumn();

        if ($file_path && file_exists($file_path)) {
            unlink($file_path);
        }

        $stmt = $pdo->prepare("DELETE FROM surat_keluar WHERE no_agenda = ?");
        $stmt->execute([$no_agenda]);

        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Data surat keluar beserta berkasnya berhasil dihapus.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Hapus', 'text' => $e->getMessage()];
    }
    header("Location: suratkeluar.php");
    exit;
}

// 4. Update Status Disposisi Surat Keluar
if (isset($_POST['update_disposisi'])) {
    $no_agenda = $_POST['no_agenda'];
    $status_baru = $_POST['status_disposisi'];

    $stmt = $pdo->prepare("UPDATE surat_keluar SET status_disposisi = ? WHERE no_agenda = ?");
    $stmt->execute([$status_baru, $no_agenda]);

    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Status Diperbarui', 'text' => 'Status disposisi surat keluar berhasil diubah.'];
    header("Location: suratkeluar.php");
    exit;
}

// Pencarian Cerdas (Smart Search)
$search = isset($_GET['q']) ? '%' . $_GET['q'] . '%' : '%';
$stmt = $pdo->prepare("SELECT * FROM surat_keluar WHERE no_agenda LIKE ? OR nomor_surat LIKE ? OR tujuan LIKE ? OR perihal LIKE ? ORDER BY no_agenda DESC");
$stmt->execute([$search, $search, $search, $search]);
$surat_keluar_list = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manajemen Surat Keluar | SIMTU</title>
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
        <!-- Sub-menu 1. Mutasi -->
        <?php if(in_array($cek_role, ['Administrator', 'TU_Kesiswaan', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
        <a href="mutasi.php" class="<?= $current_page == 'mutasi.php' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> <span class="menu-text">Mutasi Siswa</span></a>
        <?php endif; ?>
        <!-- Sub-menu 2: Piket -->
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
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">SURAT KELUAR</h5>
        <div class="d-flex align-items-center">
        <img src="<?= $avatar_path ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
        <div>
        <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
        <small class="text-muted"><?= htmlspecialchars($role) ?></small>
    </div>
</div>
    </div>

    <!-- Konten Utama Surat Keluar -->
    <div class="card border-0 shadow-sm p-4">
        <div class="row align-items-center mb-4">
            <div class="col-md-6">
                <!-- Form Smart Search -->
                <form method="GET" class="input-group">
                    <input type="text" name="q" class="form-control" placeholder="Cari No. Agenda, nomor surat, tujuan, atau perihal..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                    <button class="btn" style="background: var(--navy-blue); color: var(--gold);" type="submit"><i class="fas fa-search"></i></button>
                </form>
            </div>
            <div class="col-md-6 text-end">
                <?php if(in_array($role, ['Administrator', 'TU_Persuratan'])): ?>
                <button class="btn" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahSuratKeluar">
                    <i class="fas fa-plus-circle"></i> Buat Surat Keluar
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead style="background-color: var(--bg-light);">
                    <tr>
                        <th>No. Agenda</th>
                        <th>Tgl Kirim</th>
                        <th>Nomor Surat</th>
                        <th>Tujuan</th>
                        <th>Perihal</th>
                        <th>Status Disposisi</th>
                        <th>e-Filing (Berkas)</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($surat_keluar_list) > 0): ?>
                        <?php foreach($surat_keluar_list as $row): ?>
                        <tr>
                            <td><strong>#<?= htmlspecialchars($row['no_agenda']) ?></strong></td>
                            <td><?= date('d/m/Y', strtotime($row['tgl_dikirim'])) ?></td>
                            <td><span class="fw-bold text-primary"><?= htmlspecialchars($row['nomor_surat']) ?></span></td>
                            <td><?= htmlspecialchars($row['tujuan']) ?></td>
                            <td><?= htmlspecialchars($row['perihal']) ?></td>
                            <td>
                                <?php 
                                    $badgeClass = 'badge-merah';
                                    if($row['status_disposisi'] == 'Kuning') $badgeClass = 'badge-kuning';
                                    if($row['status_disposisi'] == 'Hijau') $badgeClass = 'badge-hijau';
                                ?>
                                <span class="badge <?= $badgeClass ?> px-2 py-1"><?= $row['status_disposisi'] ?></span>
                            </td>
                            <td>
                                <?php if(!empty($row['file_surat'])): ?>
                                    <a href="<?= $row['file_surat'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Lihat Berkas"><i class="fas fa-file-pdf text-danger"></i> Lihat</a>
                                <?php else: ?>
                                    <span class="text-muted small">Tidak ada</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Tombol Disposisi -->
                                <?php if(in_array($role, ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])): ?>
                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modalDisposisi<?= $row['no_agenda'] ?>" title="Ubah Status Disposisi">
                                    <i class="fas fa-tasks"></i>
                                </button>
                                <?php endif; ?>

                                <!-- Tombol Edit & Hapus (Untuk Admin & Staf TU Persuratan) -->
                                <?php if(in_array($role, ['Administrator', 'TU_Persuratan'])): ?>
                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditSurat<?= $row['no_agenda'] ?>" title="Edit Surat">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus surat keluar dengan No. Agenda: <?= $row['no_agenda'] ?>?');">
                                    <input type="hidden" name="no_agenda" value="<?= $row['no_agenda'] ?>">
                                    <button type="submit" name="hapus_surat_keluar" class="btn btn-sm btn-danger" title="Hapus Surat"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- MODAL EDIT SURAT KELUAR PER BARIS -->
                        <div class="modal fade" id="modalEditSurat<?= $row['no_agenda'] ?>" tabindex="-1">
                          <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                              <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Surat Keluar: No. Agenda <?= htmlspecialchars($row['no_agenda']) ?></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                              </div>
                              <form method="POST" enctype="multipart/form-data">
                                  <div class="modal-body">
                                      <input type="hidden" name="no_agenda" value="<?= $row['no_agenda'] ?>">
                                      <div class="row">
                                          <div class="col-md-6 mb-3">
                                              <label class="form-label">No. Agenda (Manual)</label>
                                              <input type="number" class="form-control bg-light" value="<?= htmlspecialchars($row['no_agenda']) ?>" disabled>
                                              <small class="text-muted">Nomor agenda utama tidak dapat diubah.</small>
                                          </div>
                                          <div class="col-md-6 mb-3">
                                              <label class="form-label">Tanggal Dikirim</label>
                                              <input type="date" name="tgl_dikirim" class="form-control" value="<?= $row['tgl_dikirim'] ?>" required>
                                          </div>
                                      </div>
                                      <div class="mb-3">
                                          <label class="form-label">Tujuan Pihak / Instansi</label>
                                          <input type="text" name="tujuan" class="form-control" value="<?= htmlspecialchars($row['tujuan']) ?>" required>
                                      </div>
                                      <div class="mb-3">
                                          <label class="form-label">Perihal / Isi Ringkas Surat</label>
                                          <textarea name="perihal" class="form-control" rows="3" required><?= htmlspecialchars($row['perihal']) ?></textarea>
                                      </div>
                                      <div class="row">
                                          <div class="col-md-6 mb-3">
                                              <label class="form-label">Status Disposisi</label>
                                              <select name="status_disposisi" class="form-select" required>
                                                  <option value="Merah" <?= $row['status_disposisi'] == 'Merah' ? 'selected' : '' ?>>Merah (Penting/Segera)</option>
                                                  <option value="Kuning" <?= $row['status_disposisi'] == 'Kuning' ? 'selected' : '' ?>>Kuning (Perhatian Menengah)</option>
                                                  <option value="Hijau" <?= $row['status_disposisi'] == 'Hijau' ? 'selected' : '' ?>>Hijau (Informasi/Selesai)</option>
                                              </select>
                                          </div>
                                          <div class="col-md-6 mb-3">
                                              <label class="form-label">Ganti Berkas e-Filing (Opsional)</label>
                                              <input type="file" name="file_surat" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                              <small class="text-muted">Biarkan kosong jika tidak ingin mengubah file.</small>
                                          </div>
                                      </div>
                                  </div>
                                  <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" name="edit_surat_keluar" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Perubahan</button>
                                  </div>
                              </form>
                            </div>
                          </div>
                        </div>

                        <!-- MODAL UPDATE DISPOSISI PER BARIS -->
                        <div class="modal fade" id="modalDisposisi<?= $row['no_agenda'] ?>" tabindex="-1">
                          <div class="modal-dialog">
                            <div class="modal-content">
                              <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                <h5 class="modal-title">Update Status Disposisi Surat Keluar</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                              </div>
                              <form method="POST">
                                  <div class="modal-body">
                                      <input type="hidden" name="no_agenda" value="<?= $row['no_agenda'] ?>">
                                      <div class="mb-3">
                                          <label class="form-label">No. Agenda: <strong>#<?= htmlspecialchars($row['no_agenda']) ?></strong></label>
                                      </div>
                                      <div class="mb-3">
                                          <label class="form-label">Pilih Status Disposisi</label>
                                          <select name="status_disposisi" class="form-select" required>
                                              <option value="Merah" <?= $row['status_disposisi'] == 'Merah' ? 'selected' : '' ?>>Merah (Penting/Segera)</option>
                                              <option value="Kuning" <?= $row['status_disposisi'] == 'Kuning' ? 'selected' : '' ?>>Kuning (Perhatian Menengah)</option>
                                              <option value="Hijau" <?= $row['status_disposisi'] == 'Hijau' ? 'selected' : '' ?>>Hijau (Informasi/Selesai)</option>
                                          </select>
                                      </div>
                                  </div>
                                  <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" name="update_disposisi" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Status</button>
                                  </div>
                              </form>
                            </div>
                          </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Belum ada data surat keluar yang tercatat.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH SURAT KELUAR -->
<div class="modal fade" id="modalTambahSuratKeluar" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-paper-plane"></i> Form Pembuatan Surat Keluar</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Nomor Agenda (Input Manual)</label>
                      <input type="number" name="no_agenda" class="form-control" placeholder="Contoh: 101" required>
                      <small class="text-muted">Masukkan nomor agenda surat keluar secara manual.</small>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Tanggal Dikirim</label>
                      <input type="date" name="tgl_dikirim" class="form-control" value="<?= date('Y-m-d') ?>" required>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Box Name / Kode Klasifikasi (Bagian ke-2)</label>
                      <input type="text" name="boxname" class="form-control" value="" required placeholder="Contoh: PK 01.02">
                      <small class="text-muted">Disisipkan otomatis setelah 3 digit nomor urut awal.</small>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Tujuan Pihak / Instansi</label>
                      <input type="text" name="tujuan" class="form-control" placeholder="Contoh: Orang Tua Murid / Dinas Pendidikan" required>
                  </div>
              </div>
              <div class="mb-3">
                  <label class="form-label">Perihal / Isi Ringkas Surat</label>
                  <textarea name="perihal" class="form-control" rows="3" placeholder="Tuliskan perihal surat keluar..." required></textarea>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Status Disposisi Awal</label>
                      <select name="status_disposisi" class="form-select" required>
                          <option value="Hijau" selected>Hijau (Informasi/Selesai)</option>
                          <option value="Kuning">Kuning (Perhatian Menengah)</option>
                          <option value="Merah">Merah (Penting/Segera)</option>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Upload Berkas e-Filing (PDF / JPG)</label>
                      <input type="file" name="file_surat" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                      <small class="text-muted">Disimpan otomatis ke folder arsip waktu berjalan.</small>
                  </div>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="tambah_surat_keluar" class="btn" style="background: var(--navy-blue); color: var(--gold);">Generate & Simpan Surat</button>
          </div>
      </form>
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