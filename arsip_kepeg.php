<?php
require 'koneksi.php';

// Cek autentikasi dan otorisasi hak akses (Administrator, Kepala Sekolah, Kepala TU)[cite: 23]
if (!isset($_SESSION['user_nip']) || !in_array($_SESSION['role'], ['Administrator', 'Kepala_Sekolah', 'Kepala_TU'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke modul Arsip Kepegawaian.'];
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];

// [Ubah query untuk menarik kolom status_pegawai][cite: 23]
$stmt_pegawai = $pdo->query("SELECT nip, nama_pegawai, status_pegawai FROM pegawai ORDER BY nama_pegawai ASC");
$daftar_pegawai = $stmt_pegawai->fetchAll(PDO::FETCH_ASSOC);

// ======================================================================
// LOGIKA PROSES (POST) - TAB 1: ARSIP SKP[cite: 23]
// ======================================================================
if (isset($_POST['tambah_skp'])) {
    $nip = $_POST['nip'];
    $tahun = $_POST['tahun'];
    $nilai = $_POST['nilai'];
    
    try {
        $upload_dir = getDynamicUploadDir('arsip_skp');
        $file_name = time() . "_" . basename($_FILES["file_skp"]["name"]);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES["file_skp"]["tmp_name"], $target_file)) {
            $stmt = $pdo->prepare("INSERT INTO arsip_skp (nip, tahun, nilai, file_skp) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nip, $tahun, $nilai, $target_file]);
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Arsip SKP berhasil ditambahkan.'];
        } else {
            throw new Exception("Gagal mengunggah file SKP.");
        }
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Input', 'text' => $e->getMessage()];
    }
    header("Location: arsip_kepeg.php#tabSKP"); exit;
}

if (isset($_POST['edit_skp'])) {
    $id = $_POST['id'];
    $tahun = $_POST['tahun'];
    $nilai = $_POST['nilai'];

    try {
        if (!empty($_FILES["file_skp"]["name"])) {
            $upload_dir = getDynamicUploadDir('arsip_skp');
            $file_name = time() . "_" . basename($_FILES["file_skp"]["name"]);
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES["file_skp"]["tmp_name"], $target_file)) {
                $stmt_old = $pdo->prepare("SELECT file_skp FROM arsip_skp WHERE id = ?");
                $stmt_old->execute([$id]);
                $old_file = $stmt_old->fetchColumn();
                if ($old_file && file_exists($old_file)) unlink($old_file);

                $stmt = $pdo->prepare("UPDATE arsip_skp SET tahun = ?, nilai = ?, file_skp = ? WHERE id = ?");
                $stmt->execute([$tahun, $nilai, $target_file, $id]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE arsip_skp SET tahun = ?, nilai = ? WHERE id = ?");
            $stmt->execute([$tahun, $nilai, $id]);
        }
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data SKP diperbarui.'];
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Update', 'text' => $e->getMessage()];
    }
    header("Location: arsip_kepeg.php#tabSKP"); exit;
}

if (isset($_POST['hapus_skp'])) {
    $id = $_POST['id'];
    $stmt_old = $pdo->prepare("SELECT file_skp FROM arsip_skp WHERE id = ?");
    $stmt_old->execute([$id]);
    $old_file = $stmt_old->fetchColumn();
    if ($old_file && file_exists($old_file)) unlink($old_file);
    
    $pdo->prepare("DELETE FROM arsip_skp WHERE id = ?")->execute([$id]);
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Arsip SKP dihapus.'];
    header("Location: arsip_kepeg.php#tabSKP"); exit;
}

// ======================================================================
// LOGIKA PROSES (POST) - TAB 2: ARSIP SK KENAIKAN GOLONGAN[cite: 23]
// ======================================================================
if (isset($_POST['tambah_golongan'])) {
    $nip = $_POST['nip'];
    $no_sk = $_POST['no_sk']; 
    $gol_baru = $_POST['golongan_baru'];
    $tmt = $_POST['tmt_golongan'];
    
    try {
        $upload_dir = "uploads/arsip_golongan/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = time() . "_" . basename($_FILES["file_sk_gol"]["name"]);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES["file_sk_gol"]["tmp_name"], $target_file)) {
            $stmt = $pdo->prepare("INSERT INTO arsip_sk_golongan (nip, no_sk, golongan_baru, tmt_golongan, file_sk) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nip, $no_sk, $gol_baru, $tmt, $target_file]);
            
            $stmt_update = $pdo->prepare("UPDATE pegawai SET pangkat_golongan = ?, tmt_pangkat = ? WHERE nip = ?");
            $stmt_update->execute([$gol_baru, $tmt, $nip]);
            
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Arsip SK Golongan ditambahkan & profil pegawai diperbarui.'];
        } else {
            throw new Exception("Gagal mengunggah file SK.");
        }
    } catch (Exception $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location='arsip_kepeg.php#tabGolongan';</script>";
        exit;
    }
}

if (isset($_POST['hapus_golongan'])) {
    $id = $_POST['id'];
    $stmt_old = $pdo->prepare("SELECT file_sk FROM arsip_sk_golongan WHERE id = ?");
    $stmt_old->execute([$id]);
    $old_file = $stmt_old->fetchColumn();
    if ($old_file && file_exists($old_file)) unlink($old_file);
    
    $pdo->prepare("DELETE FROM arsip_sk_golongan WHERE id = ?")->execute([$id]);
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Arsip SK Golongan dihapus.'];
    header("Location: arsip_kepeg.php#tabGolongan"); exit;
}

// ======================================================================
// LOGIKA PROSES (POST) - TAB 3: ARSIP SK PENSIUN (DIPERBARUI DENGAN NO SK)
// ======================================================================
if (isset($_POST['tambah_pensiun'])) {
    $nip = $_POST['nip'];
    $tgl_pensiun = $_POST['tgl_pensiun'];
    $no_sk = $_POST['no_sk']; // Tangkap input No SK Pensiun
    $keterangan = $_POST['keterangan'];
    
    try {
        $upload_dir = getDynamicUploadDir('arsip_pensiun');
        $file_name = time() . "_" . basename($_FILES["file_sk_pens"]["name"]);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES["file_sk_pens"]["tmp_name"], $target_file)) {
            // Disimpan dengan kolom no_sk
            $stmt = $pdo->prepare("INSERT INTO arsip_sk_pensiun (nip, tgl_pensiun, no_sk, keterangan, file_sk) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nip, $tgl_pensiun, $no_sk, $keterangan, $target_file]);
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Arsip SK Pensiun berhasil ditambahkan.'];
        }
    } catch (Exception $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Input', 'text' => $e->getMessage()];
    }
    header("Location: arsip_kepeg.php#tabPensiun"); exit;
}

if (isset($_POST['hapus_pensiun'])) {
    $id = $_POST['id'];
    $stmt_old = $pdo->prepare("SELECT file_sk FROM arsip_sk_pensiun WHERE id = ?");
    $stmt_old->execute([$id]);
    $old_file = $stmt_old->fetchColumn();
    if ($old_file && file_exists($old_file)) unlink($old_file);
    
    $pdo->prepare("DELETE FROM arsip_sk_pensiun WHERE id = ?")->execute([$id]);
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Arsip SK Pensiun dihapus.'];
    header("Location: arsip_kepeg.php#tabPensiun"); exit;
}

// ======================================================================
// PENGAMBILAN DATA UNTUK DITAMPILKAN DI TABEL[cite: 23]
// ======================================================================
try {
    $data_skp = $pdo->query("SELECT a.*, p.nama_pegawai FROM arsip_skp a LEFT JOIN pegawai p ON a.nip = p.nip ORDER BY a.tahun DESC, p.nama_pegawai ASC")->fetchAll();
    $data_golongan = $pdo->query("SELECT a.*, p.nama_pegawai FROM arsip_sk_golongan a LEFT JOIN pegawai p ON a.nip = p.nip ORDER BY a.tmt_golongan DESC")->fetchAll();
    $data_pensiun = $pdo->query("SELECT a.*, p.nama_pegawai FROM arsip_sk_pensiun a LEFT JOIN pegawai p ON a.nip = p.nip ORDER BY a.tgl_pensiun DESC")->fetchAll();
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error Database Query: " . $e->getMessage() . "</div>";
    $data_skp = $data_golongan = $data_pensiun = [];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Arsip Kepegawaian | SIMTU</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .nav-tabs .nav-link { color: var(--navy-blue); font-weight: 600; }
        .nav-tabs .nav-link.active { color: var(--gold); background-color: var(--navy-blue); border-color: var(--navy-blue); }
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

    <a href="dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> <span class="menu-text">Dashboard</span></a>
    
    <?php if(in_array($cek_role, ['Administrator'])): ?>
    <a href="admin.php" class="<?= $current_page == 'admin.php' ? 'active' : '' ?>"><i class="fas fa-users-cog"></i> <span class="menu-text">Manajemen User</span></a>
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
        <a href="duk.php" class="<?= $current_page == 'duk.php' ? 'active' : '' ?>"><i class="fas fa-list-ol"></i> <span class="menu-text">DUK</span></a>
        <a href="arsip_kepeg.php" class="<?= $current_page == 'arsip_kepeg.php' ? 'active' : '' ?>"><i class="fa-regular fa-folder" style="color: rgb(255, 255, 255);"></i> <span class="menu-text">Arsip Kepegawaian</span></a>
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
    <a href="laporan.php" class="<?= $current_page == 'laporan.php' ? 'active' : '' ?>"><i class="fas fa-file-export"></i> <span class="menu-text">Report</span></a>
    <?php endif; ?>
    
    <a href="logout.php" class="mt-auto text-danger"><i class="fas fa-sign-out-alt"></i> <span class="menu-text">Logout</span></a>
</div>

<!-- MAIN CONTENT -->
<div class="main-content" id="main-content">
    <div class="top-header mb-4">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">Dokumen Kepegawaian</h5>
        <div class="d-flex align-items-center">
            <img src="<?= $avatar_path ?? 'uploads/profil/default.png' ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
            <div>
                <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($_SESSION['role']) ?></small>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGASI -->
    <ul class="nav nav-tabs mb-4" id="arsipTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="skp-tab" data-bs-toggle="tab" href="#tabSKP" role="tab"><i class="fas fa-file-contract"></i> SKP</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="golongan-tab" data-bs-toggle="tab" href="#tabGolongan" role="tab"><i class="fas fa-chart-line"></i> SK Kenaikan Golongan</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="pensiun-tab" data-bs-toggle="tab" href="#tabPensiun" role="tab"><i class="fas fa-user-clock"></i> SK Pensiun</a>
        </li>
    </ul>

    <div class="tab-content" id="arsipTabsContent">
        
        <!-- TAB 1: ARSIP SKP -->
        <div class="tab-pane fade show active" id="tabSKP" role="tabpanel">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-file-contract"></i> Daftar Arsip SKP Pegawai</h5>
                    <button class="btn btn-sm" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahSKP">
                        <i class="fas fa-plus"></i> Tambah SKP
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>No</th>
                                <th>NIP/NIK</th>
                                <th>Nama Pegawai</th>
                                <th>Tahun SKP</th>
                                <th>Nilai / Predikat</th>
                                <th>Berkas PDF</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_skp) > 0): $no=1; foreach($data_skp as $row): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><strong><?= htmlspecialchars($row['nip']) ?></strong></td>
                                <td><?= htmlspecialchars($row['nama_pegawai']) ?></td>
                                <td><?= htmlspecialchars($row['tahun']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($row['nilai']) ?></span></td>
                                <td>
                                    <?php if(!empty($row['file_skp'])): ?>
                                        <a href="<?= $row['file_skp'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Lihat Berkas"><i class="fas fa-file-pdf text-danger"></i> Lihat</a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditSKP<?= $row['id'] ?>"><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Hapus arsip SKP ini?');">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="hapus_skp" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            
                            <!-- MODAL EDIT SKP -->
                            <div class="modal fade" id="modalEditSKP<?= $row['id'] ?>" tabindex="-1">
                              <div class="modal-dialog">
                                <div class="modal-content">
                                  <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Arsip SKP</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                  </div>
                                  <form method="POST" enctype="multipart/form-data">
                                      <div class="modal-body">
                                          <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                          <div class="mb-3">
                                              <label>Nama Pegawai</label>
                                              <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($row['nama_pegawai']) ?>" readonly>
                                          </div>
                                          <div class="row">
                                              <div class="col-md-6 mb-3">
                                                  <label>Tahun SKP</label>
                                                  <input type="number" name="tahun" class="form-control" value="<?= htmlspecialchars($row['tahun']) ?>" required>
                                              </div>
                                              <div class="col-md-6 mb-3">
                                                  <label>Nilai / Predikat</label>
                                                  <input type="text" name="nilai" class="form-control" value="<?= htmlspecialchars($row['nilai']) ?>" required>
                                              </div>
                                          </div>
                                          <div class="mb-3">
                                              <label>Ganti File (Opsional, PDF/JPG)</label>
                                              <input type="file" name="file_skp" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                          </div>
                                      </div>
                                      <div class="modal-footer">
                                        <button type="submit" name="edit_skp" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan</button>
                                      </div>
                                  </form>
                                </div>
                              </div>
                            </div>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data Arsip SKP.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: ARSIP GOLONGAN -->
        <div class="tab-pane fade" id="tabGolongan" role="tabpanel">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-chart-line"></i> Daftar Arsip SK Kenaikan Golongan</h5>
                    <button class="btn btn-sm" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahGolongan">
                        <i class="fas fa-plus"></i> Tambah SK Golongan
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>No</th>
                                <th>NIP/NIK</th>
                                <th>Nama Pegawai</th>
                                <th>No SK</th>
                                <th>Golongan Baru</th>
                                <th>TMT Golongan</th>
                                <th>Berkas SK</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_golongan) > 0): $no=1; foreach($data_golongan as $row): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><strong><?= htmlspecialchars($row['nip']) ?></strong></td>
                                <td><?= htmlspecialchars($row['nama_pegawai']) ?></td>
                                <td><?= htmlspecialchars($row['no_sk'] ?? '-') ?></td>
                                <td><span class="badge bg-success"><?= htmlspecialchars($row['golongan_baru']) ?></span></td>
                                <td><?= date('d/m/Y', strtotime($row['tmt_golongan'])) ?></td>
                                <td>
                                    <?php if(!empty($row['file_sk'])): ?>
                                        <a href="<?= $row['file_sk'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Lihat Berkas"><i class="fas fa-file-pdf text-danger"></i> Lihat</a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Hapus arsip SK Kenaikan Golongan ini?');">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="hapus_golongan" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada data Arsip SK Kenaikan Golongan.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 3: ARSIP PENSIUN -->
        <div class="tab-pane fade" id="tabPensiun" role="tabpanel">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-user-clock"></i> Daftar Arsip SK Pensiun / Purna Tugas</h5>
                    <button class="btn btn-sm" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahPensiun">
                        <i class="fas fa-plus"></i> Tambah SK Pensiun
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>No</th>
                                <th>NIP/NIK</th>
                                <th>Nama Pegawai</th>
                                <th>Tanggal Pensiun</th>
                                <th>No SK</th> <!-- KOLOM NO SK SESUAI PERMINTAAN -->
                                <th>Keterangan</th>
                                <th>Berkas SK</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_pensiun) > 0): $no=1; foreach($data_pensiun as $row): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><strong><?= htmlspecialchars($row['nip']) ?></strong></td>
                                <td><?= htmlspecialchars($row['nama_pegawai']) ?></td>
                                <td class="text-danger fw-bold"><?= date('d/m/Y', strtotime($row['tgl_pensiun'])) ?></td>
                                <td><?= htmlspecialchars($row['no_sk'] ?? '-') ?></td> <!-- MENAMPILKAN NO SK -->
                                <td><?= htmlspecialchars($row['keterangan']) ?></td>
                                <td>
                                    <?php if(!empty($row['file_sk'])): ?>
                                        <a href="<?= $row['file_sk'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Lihat Berkas"><i class="fas fa-file-pdf text-danger"></i> Lihat</a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Hapus arsip SK Pensiun ini?');">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="hapus_pensiun" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada data Arsip SK Pensiun.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH ARSIP SKP -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahSKP" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-file-contract"></i> Tambah Arsip SKP</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="mb-3">
                  <label>Pilih Pegawai</label>
                  <select name="nip" class="form-select" required>
                      <option value="">-- Pilih Pegawai --</option>
                      <?php foreach($daftar_pegawai as $p): ?>
                        <option value="<?= $p['nip'] ?>"><?= htmlspecialchars($p['nama_pegawai']) ?> (NIP: <?= $p['nip'] ?>)</option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label>Tahun SKP</label>
                      <input type="number" name="tahun" class="form-control" placeholder="Cth: <?= date('Y') ?>" required>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label>Nilai / Predikat</label>
                      <input type="text" name="nilai" class="form-control" placeholder="Cth: Sangat Baik / 90.5" required>
                  </div>
              </div>
              <div class="mb-3">
                  <label>Upload File SKP (PDF/JPG)</label>
                  <input type="file" name="file_skp" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
              </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="tambah_skp" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan SKP</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH GOLONGAN -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahGolongan" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-chart-line"></i> Tambah Arsip Kenaikan Golongan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="mb-3">
                  <label>Pilih Pegawai</label>
                  <select name="nip" class="form-select select-nip-golongan" required>
                      <option value="">-- Pilih Pegawai --</option>
                      <?php foreach($daftar_pegawai as $p): ?>
                      <option value="<?= $p['nip'] ?>" data-status="<?= htmlspecialchars($p['status_pegawai'] ?? '') ?>">
                          <?= htmlspecialchars($p['nama_pegawai']) ?>
                      </option>
                      <?php endforeach; ?>
                  </select>
              </div>
              
              <div class="mb-3">
                  <label>No SK</label>
                  <input type="text" name="no_sk" class="form-control" placeholder="Masukkan Nomor Surat Keputusan..." required>
              </div>
              
              <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Golongan Baru</label>
                    <select name="golongan_baru" class="form-select" required>
                        <option value="" disabled selected>-- Pilih Golongan Baru --</option>
                        <?php
                        $golongans = ['I.a (Juru Muda)','I.b (Juru Muda Tingkat I)','I.c (Juru Muda)','I.d (Juru Tingkat I)','II.a (Pengatur Muda)','II.b (Pengatur Muda Tingkat I)','II.c (Pengatur)','II.d (Pengatur Tingkat I)','III.a (Penata Muda)','III.b (Penata Muda Tingkat I)','III.c (Penata)','III.d (Penata Tingkat I)','IV.a (Pembina)','IV.b (Pembina Tingkat I)','IV.c (Pembina Utama Muda)','IV.d (Pembina Utama Madya)','IV.e (Pembina Utama)','IX (Ahli Pertama)','X (Ahli Pertama)','Non ASN'];
                        foreach($golongans as $gol) {
                            echo "<option value=\"$gol\">$gol</option>";
                        }
                        ?>
                    </select>
                  </div> 
                  
                  <div class="col-md-6 mb-3"> 
                      <label>TMT Golongan</label>
                      <input type="date" name="tmt_golongan" class="form-control" required>
                  </div>
              </div>
              <div class="mb-3">
                  <label>Upload File SK Golongan (PDF/JPG)</label>
                  <input type="file" name="file_sk_gol" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
              </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="tambah_golongan" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan SK Golongan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH PENSIUN -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahPensiun" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-user-clock"></i> Tambah Arsip SK Pensiun</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="mb-3">
                  <label>Pilih Pegawai</label>
                  <select name="nip" class="form-select" required>
                      <option value="">-- Pilih Pegawai --</option>
                      <?php foreach($daftar_pegawai as $p): ?>
                        <option value="<?= $p['nip'] ?>"><?= htmlspecialchars($p['nama_pegawai']) ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="mb-3">
                  <label>Tanggal Pensiun (TMT)</label>
                  <input type="date" name="tgl_pensiun" class="form-control" required>
              </div>
              <!-- INPUT NO SK PENSIUN (POSISI SESUAI PERMINTAAN) -->
              <div class="mb-3">
                  <label>No SK</label>
                  <input type="text" name="no_sk" class="form-control" placeholder="Masukkan Nomor Surat Keputusan Pensiun..." required>
              </div>
              <div class="mb-3">
                  <label>Keterangan Tambahan</label>
                  <input type="text" name="keterangan" class="form-control" placeholder="Cth: BUP / Pensiun Dini">
              </div>
              <div class="mb-3">
                  <label>Upload File SK Pensiun (PDF/JPG)</label>
                  <input type="file" name="file_sk_pens" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
              </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="tambah_pensiun" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan SK Pensiun</button>
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

    // Handle Tabs Persistence (Tetap di tab terakhir setelah refresh/submit)
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        let targetTabId = window.location.hash;
        
        if (!targetTabId) {
            targetTabId = localStorage.getItem('arsip_last_tab');
        }

        if (targetTabId) {
            var tabEl = document.querySelector('a[href="' + targetTabId + '"]');
            if (tabEl) {
                var tab = new bootstrap.Tab(tabEl);
                tab.show();
            }
        }
    });

    var triggerTabList = [].slice.call(document.querySelectorAll('#arsipTabs a'));
    triggerTabList.forEach(function (triggerEl) {
      triggerEl.addEventListener('click', function (event) {
        localStorage.setItem('arsip_last_tab', triggerEl.getAttribute('href'));
      });
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

    document.addEventListener("DOMContentLoaded", function() {
        const golonganPNS = ['IV.e (Pembina Utama)','IV.d (Pembina Utama Madya)','IV.c (Pembina Utama Muda)','IV.b (Pembina Tingkat I)','IV.a (Pembina)','III.d (Penata Tingkat I)','III.c (Penata)','III.b (Penata Muda Tingkat I)','III.a (Penata Muda)','II.d (Pengatur Tingkat I)','II.c (Pengatur)','II.b (Pengatur Muda Tingkat I)','II.a (Pengatur Muda)','I.d (Juru Tingkat I)','I.c (Juru Muda)','I.b (Juru Muda Tingkat I)','I.a (Juru Muda)'];
        const golonganPPPK = ['IX (Ahli Pertama)', 'X (Ahli Pertama)'];
        const golonganNonASN = ['Non ASN'];

        const nipSelect = document.querySelector('.select-nip-golongan');
        const golonganBaruSelect = document.querySelector('select[name="golongan_baru"]');
        
        if (nipSelect && golonganBaruSelect) {
            nipSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const status = selectedOption.getAttribute('data-status');
                
                let options = [];
                if (status === 'PNS') options = golonganPNS;
                else if (status === 'PPPK') options = golonganPPPK;
                else if (status === 'PPPK Paruh Waktu' || status === 'KKI' || status === 'HONORER') options = golonganNonASN;
                
                golonganBaruSelect.innerHTML = '<option value="" disabled selected>-- Pilih Golongan Baru --</option>';
                options.forEach(opt => {
                    golonganBaruSelect.innerHTML += `<option value="${opt}">${opt}</option>`;
                });
            });
        }
    }); 
</script>
</body>
</html>