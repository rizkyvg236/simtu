<?php
require 'koneksi.php';

// Cek autentikasi dan otorisasi (Hanya Administrator)
if (!isset($_SESSION['user_nip']) || $_SESSION['role'] !== 'Administrator') {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Akses Ditolak', 'text' => 'Anda tidak memiliki hak akses ke halaman ini.'];
    header("Location: dashboard.php");
    exit;
}

// Daftar Pilihan Keterangan Inklusi / Khusus
$pilihan_keterangan = [
    'Non Inklusi', 'Netra(A)', 'Rungu(B)', 'Grahita Ringan(C)', 'Grahita Sedang(C1)',
    'Daksa Ringan(D)', 'Daksa Sendang(D1)', 'Laras(E)', 'Wicara(F)', 'Hyperaktif(H)',
    'Cerdas Istimewah(I)', 'Bakat Istimewah(J)', 'Kesulitan Belajar(K)', 'Narkoba(N)',
    'Indigo(O)', 'Down Syndrome(P)', 'Autis(Q)'
];

// =========================================================================================
// INTEGRASI DATA GOOGLE SPREADSHEET (CSV) REALTIME UNTUK TABEL BANK DATA SAMPAH
// =========================================================================================
$url_spreadsheet = "https://docs.google.com/spreadsheets/d/e/2PACX-1vRNKlX8s5ClZizpqR1GQhAdFNwNcYXMPViJYKwDT0WB_gpbGy2dL7MUP0yq6L69-cSJ1elPMtpoWZFM/pub?output=csv";
$data_spreadsheet_sampah = [];
if (!empty($url_spreadsheet)) {
    if (($handle = fopen($url_spreadsheet, "r")) !== FALSE) {
        $headers_ss = fgetcsv($handle, 1000, ",");
        if ($headers_ss !== FALSE) {
            $headers_ss = array_map(function($h) { return strtolower(trim($h)); }, $headers_ss);
            while (($data_ss = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data_ss) === count($headers_ss)) {
                    $data_spreadsheet_sampah[] = array_combine($headers_ss, $data_ss);
                }
            }
        }
        fclose($handle);
    }
}

// 1. Tambah User
if (isset($_POST['tambah_user'])) {
    $nip = $_POST['nip'];
    $nama = $_POST['nama_lengkap'];
    $jabatan = $_POST['jabatan'];
    $status_pegawai = $_POST['status_kepegawaian'];
    $role = $_POST['role'];
    $password_default = password_hash($nip, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO users (nip, nama_lengkap, jabatan, status_kepegawaian, password, role) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nip, $nama, $jabatan, $status_pegawai, $password_default, $role]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'User baru berhasil ditambahkan!'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'NIP mungkin sudah terdaftar. Error: ' . $e->getMessage()];
    }
    header("Location: admin.php"); exit;
}

// 1.1 Edit User
if (isset($_POST['edit_user'])) {
    $nip_lama = $_POST['nip_lama'];
    $nip_baru = $_POST['nip'];
    $nama = $_POST['nama_lengkap'];
    $jabatan = $_POST['jabatan'];
    $status_pegawai = $_POST['status_kepegawaian'];
    $role = $_POST['role'];

    try {
        $stmt = $pdo->prepare("UPDATE users SET nip = ?, nama_lengkap = ?, jabatan = ?, status_kepegawaian = ?, role = ? WHERE nip = ?");
        $stmt->execute([$nip_baru, $nama, $jabatan, $status_pegawai, $role, $nip_lama]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data user berhasil diperbarui!'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal memperbarui user: ' . $e->getMessage()];
    }
    header("Location: admin.php"); exit;
}

// 2. Upload/Ganti Foto Profil User
if (isset($_POST['upload_foto_user'])) {
    $nip_target = $_POST['nip_target'];
    $upload_dir = "uploads/profil/";
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
    
    if (!empty($_FILES["foto_profil"]["name"])) {
        $ext = strtolower(pathinfo($_FILES["foto_profil"]["name"], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $target_file = $upload_dir . $nip_target . "_" . time() . "." . $ext;
            if (move_uploaded_file($_FILES["foto_profil"]["tmp_name"], $target_file)) {
                $stmt_old = $pdo->prepare("SELECT logo_profil FROM users WHERE nip = ?");
                $stmt_old->execute([$nip_target]);
                $old_foto = $stmt_old->fetchColumn();
                if ($old_foto && $old_foto != 'uploads/profil/default.png' && file_exists($old_foto)) unlink($old_foto);
                
                $stmt = $pdo->prepare("UPDATE users SET logo_profil = ? WHERE nip = ?");
                $stmt->execute([$target_file, $nip_target]);
                $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Foto profil berhasil diperbarui!'];
            } else {
                $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal mengunggah foto.'];
            }
        } else {
            $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Format file wajib JPG atau PNG.'];
        }
    }
    header("Location: admin.php"); exit;
}

// 3. Hapus User
if (isset($_POST['hapus_user'])) {
    $nip = $_POST['nip'];
    if ($nip !== $_SESSION['user_nip']) { 
        $stmt = $pdo->prepare("DELETE FROM users WHERE nip = ?");
        $stmt->execute([$nip]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Dihapus', 'text' => 'Data user berhasil dihapus.'];
    } else {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Anda tidak dapat menghapus akun Anda sendiri.'];
    }
    header("Location: admin.php"); exit;
}

// 4. Reset Password User
if (isset($_POST['reset_password'])) {
    $nip = $_POST['nip'];
    $password_reset = password_hash($nip, PASSWORD_DEFAULT); 
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE nip = ?");
    $stmt->execute([$password_reset, $nip]);
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Reset Berhasil', 'text' => 'Password user berhasil direset.'];
    header("Location: admin.php"); exit;
}

// 5. Simpan Pengaturan Tanda Tangan Cetak
if (isset($_POST['simpan_ttd'])) {
    $upload_dir = "uploads/assets/";
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

    $ttd_data = ['tempat' => $_POST['tempat'], 'nama_kepsek' => $_POST['nama_kepsek'], 'nip_kepsek'  => $_POST['nip_kepsek']];
    file_put_contents($upload_dir . "ttd_settings.json", json_encode($ttd_data));

    if (!empty($_FILES["file_ttd"]["name"])) {
        $ext = strtolower(pathinfo($_FILES["file_ttd"]["name"], PATHINFO_EXTENSION));
        if ($ext === 'png') move_uploaded_file($_FILES["file_ttd"]["tmp_name"], $upload_dir . "ttd_kepsek.png");
    }
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Format kolom tanda tangan cetak berhasil disimpan.'];
    header("Location: admin.php"); exit;
}

// 6. Simpan Personalisasi Tampilan Login
if (isset($_POST['simpan_tampilan'])) {
    $upload_dir = "uploads/assets/";
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
    if (!empty($_FILES["logo_login"]["name"])) move_uploaded_file($_FILES["logo_login"]["tmp_name"], $upload_dir . "logo-login.png");
    if (!empty($_FILES["bg_login"]["name"])) move_uploaded_file($_FILES["bg_login"]["tmp_name"], $upload_dir . "bg-login.jpg");
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Tampilan Diperbarui', 'text' => 'Logo dan Background halaman login berhasil diubah.'];
    header("Location: admin.php"); exit;
}

// 7. Export Template Excel Siswa (.csv)
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Template_Import_Siswa_SMPN236.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['nisn', 'nama_lengkap', 'kelas', 'rombel', 'agama', 'gender', 'keterangan', 'status']);
    fputcsv($output, ['3108923411', 'Ahmad Rizki', 'VII', 'A', 'Islam', 'L', 'Non Inklusi', 'Aktif']);
    fclose($output);
    exit;
}

// ==============================================
// FITUR BARU: JADWAL BANK SAMPAH (DISIMPAN KE JSON)
// ==============================================
$file_jadwal_sampah = 'uploads/assets/jadwal_sampah.json';
$jadwal_kegiatan = [];
if (file_exists($file_jadwal_sampah)) {
    $jadwal_kegiatan = json_decode(file_get_contents($file_jadwal_sampah), true) ?? [];
}

// Tambah Jadwal Baru
if (isset($_POST['tambah_jadwal_sampah'])) {
    $uploaded_photos = [];
    if (!empty($_FILES['foto_galeri']['name'][0])) {
        $upload_dir = "uploads/galeri_sampah/";
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $total_files = count($_FILES['foto_galeri']['name']);
        for ($i = 0; $i < $total_files; $i++) {
            $ext = strtolower(pathinfo($_FILES['foto_galeri']['name'][$i], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $target_file = $upload_dir . uniqid() . "_" . time() . "_" . $i . "." . $ext;
                if (move_uploaded_file($_FILES['foto_galeri']['tmp_name'][$i], $target_file)) {
                    $uploaded_photos[] = $target_file;
                }
            }
        }
    }

    $jadwal_baru = [
        'id' => uniqid(),
        'kategori' => $_POST['kategori_sampah'],
        'judul' => $_POST['judul_kegiatan'],
        'deskripsi' => $_POST['deskripsi_kegiatan'],
        'waktu' => $_POST['waktu_kegiatan'],
        'galeri' => $uploaded_photos
    ];
    $data_jadwal = file_exists($file_jadwal_sampah) ? json_decode(file_get_contents($file_jadwal_sampah), true) : [];
    $data_jadwal[] = $jadwal_baru;
    file_put_contents($file_jadwal_sampah, json_encode($data_jadwal));
    
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Jadwal kegiatan bank sampah berhasil ditambahkan.'];
    header("Location: admin.php?tab=jadwalBankSampah#jadwalBankSampah"); exit;
}

// Hapus Jadwal
if (isset($_POST['hapus_jadwal_sampah'])) {
    $id_hapus = $_POST['id_jadwal'];
    if (file_exists($file_jadwal_sampah)) {
        $data_jadwal = json_decode(file_get_contents($file_jadwal_sampah), true) ?? [];
        
        foreach ($data_jadwal as $j) {
            if ($j['id'] === $id_hapus && !empty($j['galeri'])) {
                foreach ($j['galeri'] as $foto) {
                    if (file_exists($foto)) unlink($foto);
                }
            }
        }

        $data_jadwal = array_filter($data_jadwal, function($j) use ($id_hapus) { return $j['id'] !== $id_hapus; });
        file_put_contents($file_jadwal_sampah, json_encode(array_values($data_jadwal)));
    }
    $_SESSION['alert'] = ['type' => 'success', 'title' => 'Dihapus', 'text' => 'Jadwal kegiatan berhasil dihapus.'];
    header("Location: admin.php?tab=jadwalBankSampah#jadwalBankSampah"); exit;
}

$daftar_jadwal_sampah = file_exists($file_jadwal_sampah) ? json_decode(file_get_contents($file_jadwal_sampah), true) : [];


// ==============================================
// 7.1 Export Template Excel Pendidik (.csv)
// ==============================================
if (isset($_GET['download_template_pendidik'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Template_Import_Pendidik_SMPN236.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['nip', 'nrk', 'nuptk', 'nama_pegawai', 'tempat', 'tanggal_lahir(dd/mm/yyyy)', 'jenis_pegawai', 'status_pegawai', 'jabatan', 'mapel', 'tugas_tambahan', 'pangkat_golongan', 'tmt(dd/mm/yyyy)', 'tmt_pangkat(dd/mm/yyyy)']);
    fputcsv($output, ['198001012010011001', '12345', '1234567890123456', 'Nama Guru, S.Pd', 'Jakarta', '01/01/1980', 'Guru', 'PNS', 'Guru Kelas', 'Matematika', 'Wali Kelas', 'III.a (Penata Muda)', '01/01/2010', '01/04/2022']);
    fclose($output);
    exit;
}

// ==============================================
// 7.2 Export Template Excel Tendik (.csv)
// ==============================================
if (isset($_GET['download_template_tendik'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Template_Import_Tendik_SMPN236.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['nip', 'nrk', 'nuptk', 'nama_pegawai', 'tempat', 'tanggal_lahir(dd/mm/yyyy)', 'jenis_pegawai', 'status_pegawai', 'jabatan', 'pangkat_golongan', 'tmt(dd/mm/yyyy)', 'tmt_pangkat(dd/mm/yyyy)']);
    fputcsv($output, ['198502022015022002', '54321', '0987654321098765', 'Nama Tendik, S.Kom', 'Jakarta', '02/02/1985', 'Tenaga Kependidikan', 'PPPK', 'Tenaga Administrasi', 'IX (Ahli Pertama)', '02/02/2015', '01/04/2023']);
    fclose($output);
    exit;
}

// ==============================================
// 7.3 Export / Download Data Master Pendidik (.csv)
// ==============================================
if (isset($_GET['export_pendidik'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Data_Master_Tenaga_Pendidik_SMPN236.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'NIP/NIKKI/NIK', 'NRK', 'NUPTK', 'Nama Pegawai', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Pegawai', 'Status Pegawai', 'Jabatan', 'Mapel', 'Tugas Tambahan', 'Golongan / Pangkat', 'TMT', 'TMT Pangkat Terakhir']);
    
    $stmt_exp = $pdo->prepare("SELECT * FROM pegawai WHERE jenis_pegawai = 'Guru' ORDER BY nama_pegawai ASC");
    $stmt_exp->execute();
    $no_ex = 1;
    while ($row = $stmt_exp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $no_ex++,
            '="' . $row['nip'] . '"',
            $row['nrk'] ?: '-',
            '="' . ($row['nuptk'] ?? '') . '"',
            $row['nama_pegawai'],
            $row['tempat'] ?: '-',
            (!empty($row['tanggal']) && $row['tanggal'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tanggal'])) : '-',
            $row['jenis_pegawai'],
            $row['status_pegawai'],
            $row['jabatan'],
            $row['mapel'] ?: '-',
            $row['tugas_tambahan'] ?: '-',
            $row['pangkat_golongan'] ?: '-',
            (!empty($row['tmt']) && $row['tmt'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tmt'])) : '-',
            (!empty($row['tmt_pangkat']) && $row['tmt_pangkat'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tmt_pangkat'])) : '-'
        ]);
    }
    fclose($output);
    exit;
}

// ==============================================
// 7.4 Export / Download Data Master Tendik (.csv)
// ==============================================
if (isset($_GET['export_tendik'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Data_Master_Tenaga_Kependidikan_SMPN236.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'NIP/NIKKI/NIK', 'NRK', 'NUPTK', 'Nama Pegawai', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Pegawai', 'Status Pegawai', 'Jabatan', 'Golongan / Pangkat', 'TMT', 'TMT Pangkat Terakhir']);
    
    $stmt_exp = $pdo->prepare("SELECT * FROM pegawai WHERE jenis_pegawai = 'Tenaga Kependidikan' ORDER BY nama_pegawai ASC");
    $stmt_exp->execute();
    $no_ex = 1;
    while ($row = $stmt_exp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $no_ex++,
            '="' . $row['nip'] . '"',
            $row['nrk'] ?: '-',
            '="' . ($row['nuptk'] ?? '') . '"',
            $row['nama_pegawai'],
            $row['tempat'] ?: '-',
            (!empty($row['tanggal']) && $row['tanggal'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tanggal'])) : '-',
            $row['jenis_pegawai'],
            $row['status_pegawai'],
            $row['jabatan'],
            $row['pangkat_golongan'] ?: '-',
            (!empty($row['tmt']) && $row['tmt'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tmt'])) : '-',
            (!empty($row['tmt_pangkat']) && $row['tmt_pangkat'] != '0000-00-00') ? date('d/m/Y', strtotime($row['tmt_pangkat'])) : '-'
        ]);
    }
    fclose($output);
    exit;
}

// 8. Proses Import Data Siswa via Excel / CSV
if (isset($_POST['import_siswa'])) {
    if (isset($_FILES['file_excel']['name']) && !empty($_FILES['file_excel']['name'])) {
        $filename = $_FILES['file_excel']['tmp_name'];
        $handle = fopen($filename, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); 
            $sukses = 0;
            $stmt = $pdo->prepare("INSERT INTO siswa (nisn, nama_lengkap, kelas, rombel, agama, gender, keterangan, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama_lengkap=VALUES(nama_lengkap), kelas=VALUES(kelas), rombel=VALUES(rombel), agama=VALUES(agama), gender=VALUES(gender), keterangan=VALUES(keterangan), status=VALUES(status)");
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (isset($data[0]) && !empty(trim($data[0]))) {
                    $nisn = trim($data[0]); $nama = trim($data[1]); $kelas = trim($data[2]); $rombel = trim($data[3]);
                    $agama = trim($data[4]); $gender = trim($data[5]);
                    $keterangan = !empty(trim($data[6])) ? trim($data[6]) : 'Non Inklusi';
                    $status = !empty(trim($data[7])) ? trim($data[7]) : 'Aktif';
                    $stmt->execute([$nisn, $nama, $kelas, $rombel, $agama, $gender, $keterangan, $status]);
                    $sukses++;
                }
            }
            fclose($handle);
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Import Berhasil', 'text' => "Sebanyak $sukses data siswa berhasil diimpor & disinkronkan ke database."];
        }
    }
    header("Location: admin.php#importSiswa"); exit;
}

// 9. Tambah Siswa Manual
if (isset($_POST['tambah_siswa_manual'])) {
    $nisn = trim($_POST['nisn']); $nama = trim($_POST['nama_lengkap']); $kelas = trim($_POST['kelas']);
    $rombel = trim($_POST['rombel']); $agama = trim($_POST['agama']); $gender = trim($_POST['gender']);
    $keterangan = trim($_POST['keterangan']); $status = trim($_POST['status']);
    try {
        $stmt = $pdo->prepare("INSERT INTO siswa (nisn, nama_lengkap, kelas, rombel, agama, gender, keterangan, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama_lengkap=VALUES(nama_lengkap), kelas=VALUES(kelas), rombel=VALUES(rombel), agama=VALUES(agama), gender=VALUES(gender), keterangan=VALUES(keterangan), status=VALUES(status)");
        $stmt->execute([$nisn, $nama, $kelas, $rombel, $agama, $gender, $keterangan, $status]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data siswa berhasil ditambahkan!'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal menambah siswa: ' . $e->getMessage()];
    }
    header("Location: admin.php#importSiswa"); exit;
}

// 10. Edit Siswa
if (isset($_POST['edit_siswa'])) {
    $nisn_lama = $_POST['nisn_lama']; $nisn_baru = trim($_POST['nisn']); $nama = trim($_POST['nama_lengkap']);
    $kelas = trim($_POST['kelas']); $rombel = trim($_POST['rombel']); $agama = trim($_POST['agama']);
    $gender = trim($_POST['gender']); $keterangan = trim($_POST['keterangan']); $status = trim($_POST['status']);
    try {
        $stmt = $pdo->prepare("UPDATE siswa SET nisn = ?, nama_lengkap = ?, kelas = ?, rombel = ?, agama = ?, gender = ?, keterangan = ?, status = ? WHERE nisn = ?");
        $stmt->execute([$nisn_baru, $nama, $kelas, $rombel, $agama, $gender, $keterangan, $status, $nisn_lama]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data siswa berhasil diperbarui!'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal memperbarui siswa: ' . $e->getMessage()];
    }
    header("Location: admin.php#importSiswa"); exit;
}

// 11. Hapus Siswa
if (isset($_POST['hapus_siswa'])) {
    $nisn = $_POST['nisn'];
    try {
        $stmt = $pdo->prepare("DELETE FROM siswa WHERE nisn = ?");
        $stmt->execute([$nisn]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Data siswa berhasil dihapus.'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal menghapus siswa.'];
    }
    header("Location: admin.php#importSiswa"); exit;
}

// 12. Hapus Semua Siswa
if (isset($_POST['hapus_semua_siswa'])) {
    try {
        $pdo->exec("DELETE FROM siswa");
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Seluruh data siswa dihapus bersih.'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal menghapus.'];
    }
    header("Location: admin.php#importSiswa"); exit;
}

// ==============================================
// 13. TAMBAH PEGAWAI MANUAL 
// ==============================================
if (isset($_POST['tambah_pegawai_manual'])) {
    $nip = trim($_POST['nip']); 
    $nrk = trim($_POST['nrk']); 
    $nuptk = trim($_POST['nuptk']); 
    $nama_pegawai = trim($_POST['nama_pegawai']);
    $tempat = trim($_POST['tempat']); 
    $tanggal = trim($_POST['tanggal']); 
    $jenis_pegawai = trim($_POST['jenis_pegawai']); 
    $status_pegawai = trim($_POST['status_pegawai']); 
    $jabatan = trim($_POST['jabatan']);
    $mapel = isset($_POST['mapel']) ? trim($_POST['mapel']) : '-'; 
    $tugas_tambahan = isset($_POST['tugas_tambahan']) ? trim($_POST['tugas_tambahan']) : '-'; 
    $pangkat_golongan = trim($_POST['pangkat_golongan']); 
    $tmt = trim($_POST['tmt']);
    $tmt_pangkat = !empty($_POST['tmt_pangkat']) ? trim($_POST['tmt_pangkat']) : null;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO pegawai (nip, nrk, nuptk, nama_pegawai, tempat, tanggal, jenis_pegawai, status_pegawai, jabatan, mapel, tugas_tambahan, pangkat_golongan, tmt, tmt_pangkat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nrk=VALUES(nrk), nuptk=VALUES(nuptk), nama_pegawai=VALUES(nama_pegawai), tempat=VALUES(tempat), tanggal=VALUES(tanggal), jenis_pegawai=VALUES(jenis_pegawai), status_pegawai=VALUES(status_pegawai), jabatan=VALUES(jabatan), mapel=VALUES(mapel), tugas_tambahan=VALUES(tugas_tambahan), pangkat_golongan=VALUES(pangkat_golongan), tmt=VALUES(tmt), tmt_pangkat=VALUES(tmt_pangkat)");
        $stmt->execute([$nip, $nrk, $nuptk, $nama_pegawai, $tempat, $tanggal, $jenis_pegawai, $status_pegawai, $jabatan, $mapel, $tugas_tambahan, $pangkat_golongan, $tmt, $tmt_pangkat]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data kepegawaian berhasil ditambahkan!'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal menambah pegawai: ' . $e->getMessage()];
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); exit;
}

// ==============================================
// 14.1 HAPUS SEMUA TENAGA PENDIDIK
// ==============================================
if (isset($_POST['hapus_semua_pendidik'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM pegawai WHERE jenis_pegawai = 'Guru'");
        $stmt->execute();
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Seluruh data tenaga pendidik dihapus bersih.'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal menghapus data tenaga pendidik.'];
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); exit;
}

// ==============================================
// 14.2 HAPUS SEMUA TENAGA KEPENDIDIK
// ==============================================
if (isset($_POST['hapus_semua_tendik'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM pegawai WHERE jenis_pegawai = 'Tenaga Kependidikan'");
        $stmt->execute();
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Seluruh data tenaga kependidikan dihapus bersih.'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal menghapus data tenaga kependidikan.'];
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); exit;
}

// ==============================================
// 15. Import Data Pendidik via Excel/CSV
// ==============================================
if (isset($_POST['import_pendidik'])) {
    if (isset($_FILES['file_excel_pendidik']['name']) && !empty($_FILES['file_excel_pendidik']['name'])) {
        $filename = $_FILES['file_excel_pendidik']['tmp_name'];
        $handle = fopen($filename, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); 
            $sukses = 0;
            $stmt = $pdo->prepare("INSERT INTO pegawai (nip, nrk, nuptk, nama_pegawai, tempat, tanggal, jenis_pegawai, status_pegawai, jabatan, mapel, tugas_tambahan, pangkat_golongan, tmt, tmt_pangkat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nrk=VALUES(nrk), nuptk=VALUES(nuptk), nama_pegawai=VALUES(nama_pegawai), tempat=VALUES(tempat), tanggal=VALUES(tanggal), jenis_pegawai=VALUES(jenis_pegawai), status_pegawai=VALUES(status_pegawai), jabatan=VALUES(jabatan), mapel=VALUES(mapel), tugas_tambahan=VALUES(tugas_tambahan), pangkat_golongan=VALUES(pangkat_golongan), tmt=VALUES(tmt), tmt_pangkat=VALUES(tmt_pangkat)");
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (isset($data[0]) && !empty(trim($data[0]))) {
                    $nip = trim($data[0]); $nrk = trim($data[1]); $nuptk = trim($data[2]);
                    $nama = trim($data[3]); $tempat = trim($data[4]); 
                    
                    $tgl_raw = trim($data[5]);
                    $tanggal = (strpos($tgl_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tgl_raw))) : $tgl_raw;
                    
                    $jenis = 'Guru'; 
                    $status = trim($data[7]); $jabatan = trim($data[8]);
                    $mapel = trim($data[9] ?? '-'); $tugas = trim($data[10] ?? '-');
                    $golongan = trim($data[11] ?? '-'); 
                    
                    $tmt_raw = trim($data[12] ?? '');
                    $tmt = (strpos($tmt_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tmt_raw))) : $tmt_raw;

                    $tmt_pangkat_raw = trim($data[13] ?? '');
                    $tmt_pangkat = (!empty($tmt_pangkat_raw)) ? ((strpos($tmt_pangkat_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tmt_pangkat_raw))) : $tmt_pangkat_raw) : null;
                    
                    $stmt->execute([$nip, $nrk, $nuptk, $nama, $tempat, $tanggal, $jenis, $status, $jabatan, $mapel, $tugas, $golongan, $tmt, $tmt_pangkat]);
                    $sukses++;
                }
            }
            fclose($handle);
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Import Berhasil', 'text' => "Sebanyak $sukses data Tenaga Pendidik berhasil diimpor."];
        }
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); exit;
}

// ==============================================
// 15. Import Data Pendidik via Excel/CSV
// ==============================================
if (isset($_POST['import_pendidik'])) {
    if (isset($_FILES['file_excel_pendidik']['name']) && !empty($_FILES['file_excel_pendidik']['name'])) {
        $filename = $_FILES['file_excel_pendidik']['tmp_name'];
        $handle = fopen($filename, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); 
            $sukses = 0;
            
            // Perbaikan: Membungkus query import ke dalam Try-Catch
            try {
                $stmt = $pdo->prepare("INSERT INTO pegawai (nip, nrk, nuptk, nama_pegawai, tempat, tanggal, jenis_pegawai, status_pegawai, jabatan, mapel, tugas_tambahan, pangkat_golongan, tmt, tmt_pangkat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nrk=VALUES(nrk), nuptk=VALUES(nuptk), nama_pegawai=VALUES(nama_pegawai), tempat=VALUES(tempat), tanggal=VALUES(tanggal), jenis_pegawai=VALUES(jenis_pegawai), status_pegawai=VALUES(status_pegawai), jabatan=VALUES(jabatan), mapel=VALUES(mapel), tugas_tambahan=VALUES(tugas_tambahan), pangkat_golongan=VALUES(pangkat_golongan), tmt=VALUES(tmt), tmt_pangkat=VALUES(tmt_pangkat)");
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (isset($data[0]) && !empty(trim($data[0]))) {
                        $nip = trim($data[0]); $nrk = trim($data[1]); $nuptk = trim($data[2]);
                        $nama = trim($data[3]); $tempat = trim($data[4]); 
                        
                        $tgl_raw = trim($data[5]);
                        $tanggal = (strpos($tgl_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tgl_raw))) : $tgl_raw;
                        
                        $jenis = 'Guru'; 
                        $status = trim($data[7]); $jabatan = trim($data[8]);
                        $mapel = trim($data[9] ?? '-'); $tugas = trim($data[10] ?? '-');
                        $golongan = trim($data[11] ?? '-'); 
                        
                        $tmt_raw = trim($data[12] ?? '');
                        $tmt = (strpos($tmt_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tmt_raw))) : $tmt_raw;

                        $tmt_pangkat_raw = trim($data[13] ?? '');
                        $tmt_pangkat = (!empty($tmt_pangkat_raw)) ? ((strpos($tmt_pangkat_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tmt_pangkat_raw))) : $tmt_pangkat_raw) : null;
                        
                        $stmt->execute([$nip, $nrk, $nuptk, $nama, $tempat, $tanggal, $jenis, $status, $jabatan, $mapel, $tugas, $golongan, $tmt, $tmt_pangkat]);
                        $sukses++;
                    }
                }
                $_SESSION['alert'] = ['type' => 'success', 'title' => 'Import Berhasil', 'text' => "Sebanyak $sukses data Tenaga Pendidik berhasil diimpor."];
            } catch (PDOException $e) {
                // Tangkap error jika kolom DB tidak sinkron, tampilkan via SweetAlert
                $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Import', 'text' => 'Terjadi kesalahan pada struktur database: ' . $e->getMessage()];
            }
            
            fclose($handle);
        }
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); exit;
}

// ==============================================
// 16. Import Data Tendik via Excel/CSV
// ==============================================
if (isset($_POST['import_tendik'])) {
    if (isset($_FILES['file_excel_tendik']['name']) && !empty($_FILES['file_excel_tendik']['name'])) {
        $filename = $_FILES['file_excel_tendik']['tmp_name'];
        $handle = fopen($filename, "r");
        if ($handle !== FALSE) {
            fgetcsv($handle, 1000, ","); 
            $sukses = 0;
            
            // Perbaikan: Membungkus query import ke dalam Try-Catch
            try {
                $stmt = $pdo->prepare("INSERT INTO pegawai (nip, nrk, nuptk, nama_pegawai, tempat, tanggal, jenis_pegawai, status_pegawai, jabatan, mapel, tugas_tambahan, pangkat_golongan, tmt, tmt_pangkat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nrk=VALUES(nrk), nuptk=VALUES(nuptk), nama_pegawai=VALUES(nama_pegawai), tempat=VALUES(tempat), tanggal=VALUES(tanggal), jenis_pegawai=VALUES(jenis_pegawai), status_pegawai=VALUES(status_pegawai), jabatan=VALUES(jabatan), mapel=VALUES(mapel), tugas_tambahan=VALUES(tugas_tambahan), pangkat_golongan=VALUES(pangkat_golongan), tmt=VALUES(tmt), tmt_pangkat=VALUES(tmt_pangkat)");
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (isset($data[0]) && !empty(trim($data[0]))) {
                        $nip = trim($data[0]); $nrk = trim($data[1]); $nuptk = trim($data[2]);
                        $nama = trim($data[3]); $tempat = trim($data[4]); 
                        
                        $tgl_raw = trim($data[5] ?? '');
                        $tanggal = (strpos($tgl_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tgl_raw))) : $tgl_raw;
                        
                        $jenis = 'Tenaga Kependidikan'; 
                        $status = trim($data[7]); $jabatan = trim($data[8]);
                        $mapel = '-'; $tugas = '-'; 
                        $golongan = trim($data[9] ?? '-'); 
                        
                        $tmt_raw = trim($data[10] ?? '');
                        $tmt = (strpos($tmt_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tmt_raw))) : $tmt_raw;

                        $tmt_pangkat_raw = trim($data[11] ?? '');
                        $tmt_pangkat = (!empty($tmt_pangkat_raw)) ? ((strpos($tmt_pangkat_raw, '/') !== false) ? implode('-', array_reverse(explode('/', $tmt_pangkat_raw))) : $tmt_pangkat_raw) : null;
                        
                        $stmt->execute([$nip, $nrk, $nuptk, $nama, $tempat, $tanggal, $jenis, $status, $jabatan, $mapel, $tugas, $golongan, $tmt, $tmt_pangkat]);
                        $sukses++;
                    }
                }
                $_SESSION['alert'] = ['type' => 'success', 'title' => 'Import Berhasil', 'text' => "Sebanyak $sukses data Tenaga Kependidikan berhasil diimpor."];
            } catch (PDOException $e) {
                // Tangkap error jika kolom DB tidak sinkron, tampilkan via SweetAlert
                $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal Import', 'text' => 'Terjadi kesalahan pada struktur database: ' . $e->getMessage()];
            }
            
            fclose($handle);
        }
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); exit;
}

// ==============================================
// 17. Hapus Pegawai (Satuan / Per Baris)
// ==============================================
if (isset($_POST['hapus_pegawai'])) {
    $nip = $_POST['nip'];
    try {
        $stmt = $pdo->prepare("DELETE FROM pegawai WHERE nip = ?");
        $stmt->execute([$nip]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Terhapus', 'text' => 'Data pegawai berhasil dihapus.'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal menghapus pegawai: ' . $e->getMessage()];
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); 
    exit;
}

// ==============================================
// 18 EDIT PEGAWAI (Pendidik & Tendik)
// ==============================================
if (isset($_POST['edit_pegawai'])) {
    $nip_lama = $_POST['nip_lama'];
    $nip = trim($_POST['nip']); 
    $nrk = trim($_POST['nrk']); 
    $nuptk = trim($_POST['nuptk']); 
    $nama_pegawai = trim($_POST['nama_pegawai']);
    $tempat = trim($_POST['tempat']); 
    $tanggal = trim($_POST['tanggal']); 
    $status_pegawai = trim($_POST['status_pegawai']); 
    $jabatan = trim($_POST['jabatan']);
    $mapel = isset($_POST['mapel']) ? trim($_POST['mapel']) : '-'; 
    $tugas_tambahan = isset($_POST['tugas_tambahan']) ? trim($_POST['tugas_tambahan']) : '-'; 
    $pangkat_golongan = trim($_POST['pangkat_golongan']); 
    $tmt = trim($_POST['tmt']);
    // PERBAIKAN: Hapus trim() dari dalam empty() agar variabel POST terbaca dengan benar
    $tmt_pangkat = !empty($_POST['tmt_pangkat']) ? trim($_POST['tmt_pangkat']) : null;
    
    try {
        $stmt = $pdo->prepare("UPDATE pegawai SET nip = ?, nrk = ?, nuptk = ?, nama_pegawai = ?, tempat = ?, tanggal = ?, status_pegawai = ?, jabatan = ?, mapel = ?, tugas_tambahan = ?, pangkat_golongan = ?, tmt = ?, tmt_pangkat = ? WHERE nip = ?");
        $stmt->execute([$nip, $nrk, $nuptk, $nama_pegawai, $tempat, $tanggal, $status_pegawai, $jabatan, $mapel, $tugas_tambahan, $pangkat_golongan, $tmt, $tmt_pangkat, $nip_lama]);
        $_SESSION['alert'] = ['type' => 'success', 'title' => 'Berhasil', 'text' => 'Data pegawai berhasil diperbarui!'];
    } catch (PDOException $e) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Gagal memperbarui pegawai: ' . $e->getMessage()];
    }
    header("Location: admin.php?tab=masterKepegawaian#masterKepegawaian"); 
    exit;
}

// 19. PENGAMBILAN DATA USERS --- //
$users = $pdo->query("SELECT * FROM users ORDER BY nama_lengkap ASC")->fetchAll();

// ==========================================
// 20. LOGIKA PENCARIAN & PENGURUTAN PEGAWAI
// ==========================================
$search_pegawai = isset($_GET['cari_pegawai']) ? trim($_GET['cari_pegawai']) : '';
$sort_pegawai = isset($_GET['sort_pegawai']) ? $_GET['sort_pegawai'] : 'nama_asc';

switch ($sort_pegawai) {
    case 'nama_desc': $order_peg_sql = "nama_pegawai DESC"; break;
    case 'tgl_asc':   $order_peg_sql = "tanggal ASC, nama_pegawai ASC"; break; 
    case 'tgl_desc':  $order_peg_sql = "tanggal DESC, nama_pegawai ASC"; break; 
    case 'golongan':  
        $order_peg_sql = "CASE pangkat_golongan 
            WHEN 'IV.e (Pembina Utama)' THEN 1 
            WHEN 'IV.d (Pembina Utama Madya)' THEN 2 
            WHEN 'IV.c (Pembina Utama Muda)' THEN 3 
            WHEN 'IV.b (Pembina Tingkat I)' THEN 4 
            WHEN 'IV.a (Pembina)' THEN 5 
            WHEN 'III.d (Penata Tingkat I)' THEN 6 
            WHEN 'III.c (Penata)' THEN 7 
            WHEN 'III.b (Penata Muda Tingkat I)' THEN 8 
            WHEN 'III.a (Penata Muda)' THEN 9 
            WHEN 'II.d (Pengatur Tingkat I)' THEN 10 
            WHEN 'II.c (Pengatur)' THEN 11 
            WHEN 'II.b (Pengatur Muda Tingkat I)' THEN 12 
            WHEN 'II.a (Pengatur Muda)' THEN 13 
            WHEN 'I.d (Juru Tingkat I)' THEN 14 
            WHEN 'I.c (Juru Muda)' THEN 15 
            WHEN 'I.b (Juru Muda Tingkat I)' THEN 16 
            WHEN 'I.a (Juru Muda)' THEN 17 
            WHEN 'IX (Ahli Pertama)' THEN 18 
            WHEN 'X (Ahli Pertama)' THEN 19 
            WHEN 'Non ASN' THEN 20 
            ELSE 21 END ASC, nama_pegawai ASC"; 
        break;
    case 'nama_asc': 
    default:          
        $order_peg_sql = "nama_pegawai ASC"; 
        break;
}

$daftar_pegawai = [];
try {
    if (!empty($search_pegawai)) {
        $stmt_pegawai = $pdo->prepare("SELECT * FROM pegawai WHERE nama_pegawai LIKE ? ORDER BY $order_peg_sql");
        $stmt_pegawai->execute(["%$search_pegawai%"]);
        $daftar_pegawai = $stmt_pegawai->fetchAll();
    } else {
        $daftar_pegawai = $pdo->query("SELECT * FROM pegawai ORDER BY $order_peg_sql")->fetchAll();
    }
} catch (PDOException $e) {
}

// ==========================================
// 21.LOGIKA PENCARIAN & PENGURUTAN SISWA 
// ==========================================
$search = isset($_GET['cari_siswa']) ? trim($_GET['cari_siswa']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'nama_asc';

switch ($sort) {
    case 'nama_desc': $order_sql = "nama_lengkap DESC"; break;
    case 'kelas_asc': $order_sql = "kelas ASC, nama_lengkap ASC"; break;
    case 'rombel_asc': $order_sql = "rombel ASC, nama_lengkap ASC"; break;
    case 'agama_asc': $order_sql = "agama ASC, nama_lengkap ASC"; break;
    case 'keterangan_asc': $order_sql = "keterangan ASC, nama_lengkap ASC"; break;
    case 'nisn_asc':  $order_sql = "nisn ASC"; break;
    case 'nisn_desc': $order_sql = "nisn DESC"; break;
    case 'nama_asc': default: $order_sql = "nama_lengkap ASC"; break;
}

if (!empty($search)) {
    $stmt_siswa = $pdo->prepare("SELECT * FROM siswa WHERE nama_lengkap LIKE ? OR nisn LIKE ? OR kelas LIKE ? OR rombel LIKE ? OR keterangan LIKE ? OR agama LIKE ? ORDER BY $order_sql");
    $keyword = "%$search%";
    $stmt_siswa->execute([$keyword, $keyword, $keyword, $keyword, $keyword, $keyword]);
    $daftar_siswa = $stmt_siswa->fetchAll();
} else {
    $daftar_siswa = $pdo->query("SELECT * FROM siswa ORDER BY $order_sql")->fetchAll();
}

$ttd_settings = ['tempat' => 'Jakarta', 'nama_kepsek' => '', 'nip_kepsek' => ''];
if(file_exists('uploads/assets/ttd_settings.json')) $ttd_settings = json_decode(file_get_contents('uploads/assets/ttd_settings.json'), true);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Administrator | SIMTU</title>
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

<!-- Main Content -->
<div class="main-content" id="main-content">
    <div class="top-header mb-4">
        <button class="btn btn-light shadow-sm" id="toggleSidebar"><i class="fas fa-bars"></i></button>
        <h5 class="m-0" style="color: var(--navy-blue); font-weight: 700;">Halaman Administrator</h5>
        <div class="d-flex align-items-center">
            <img src="<?= $avatar_path ?? 'uploads/profil/default.png' ?>" class="rounded-circle me-2" width="40" height="40" style="object-fit: cover;" alt="Profil">
            <div>
                <h6 class="m-0"><?= htmlspecialchars($_SESSION['nama']) ?></h6>
                <small class="text-muted"><?= htmlspecialchars($_SESSION['role']) ?></small>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGASI -->
    <ul class="nav nav-tabs mb-4" id="adminTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="user-tab" data-bs-toggle="tab" href="#manajemenUser" role="tab">Manajemen User</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="siswa-tab" data-bs-toggle="tab" href="#importSiswa" role="tab">Master Data Siswa</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="pegawai-tab" data-bs-toggle="tab" href="#masterKepegawaian" role="tab">Master Data Kepegawaian</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="ttd-tab" data-bs-toggle="tab" href="#pengaturanTtd" role="tab">Tanda Tangan Laporan</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="tampilan-tab" data-bs-toggle="tab" href="#pengaturanTampilan" role="tab">Tampilan Login</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="jadwal-sampah-tab" data-bs-toggle="tab" href="#jadwalBankSampah" role="tab">Bank Data Sampah</a>
        </li>
    </ul>

    <div class="tab-content" id="adminTabsContent">
        <!-- TAB 1: MANAJEMEN USER -->
        <div class="tab-pane fade show active" id="manajemenUser" role="tabpanel">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex justify-content-between mb-3">
                    <h5 style="color: var(--navy-blue);"><i class="fas fa-users"></i> Daftar Pengguna SIMTU</h5>
                    <button class="btn" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahUser">
                        <i class="fas fa-plus"></i> Tambah User
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>Profil</th>
                                <th>NIP</th>
                                <th>Nama Lengkap</th>
                                <th>Jabatan & Status</th>
                                <th>Role Akses</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $u): ?>
                            <tr>
                                <td>
                                    <?php $foto_user = (!empty($u['logo_profil']) && file_exists($u['logo_profil'])) ? $u['logo_profil'] : 'uploads/profil/default.png'; ?>
                                    <img src="<?= $foto_user ?>" class="rounded-circle shadow-sm" width="45" height="45" style="object-fit: cover;" alt="Foto">
                                </td>
                                <td><?= htmlspecialchars($u['nip']) ?></td>
                                <td><?= htmlspecialchars($u['nama_lengkap']) ?></td>
                                <td>
                                    <?= htmlspecialchars($u['jabatan']) ?> <br>
                                    <span class="badge bg-secondary text-white" style="font-size: 10px;"><?= htmlspecialchars($u['status_kepegawaian']) ?></span>
                                </td>
                                <td>
                                    <span class="badge" style="background-color: var(--navy-blue); color: var(--gold);">
                                        <?= htmlspecialchars(str_replace('_', ' ', $u['role'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditUser<?= htmlspecialchars($u['nip']) ?>" title="Edit User"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#modalFoto<?= htmlspecialchars($u['nip']) ?>" title="Ganti Foto Profil"><i class="fas fa-camera"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Reset password user ini menjadi NIP?');">
                                        <input type="hidden" name="nip" value="<?= $u['nip'] ?>">
                                        <button type="submit" name="reset_password" class="btn btn-sm btn-warning" title="Reset Password"><i class="fas fa-key"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus user ini?');">
                                        <input type="hidden" name="nip" value="<?= $u['nip'] ?>">
                                        <button type="submit" name="hapus_user" class="btn btn-sm btn-danger" title="Hapus User"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>

                            <!-- MODAL EDIT USER -->
                            <div class="modal fade" id="modalEditUser<?= htmlspecialchars($u['nip']) ?>" tabindex="-1">
                              <div class="modal-dialog">
                                <div class="modal-content">
                                  <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                    <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit User: <?= htmlspecialchars($u['nama_lengkap']) ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                  </div>
                                  <form method="POST">
                                      <div class="modal-body">
                                          <input type="hidden" name="nip_lama" value="<?= htmlspecialchars($u['nip']) ?>">
                                          <div class="mb-3">
                                              <label>NIP Pengguna</label>
                                              <input type="number" name="nip" class="form-control" value="<?= htmlspecialchars($u['nip']) ?>" required>
                                          </div>
                                          <div class="mb-3">
                                              <label>Nama Lengkap</label>
                                              <input type="text" name="nama_lengkap" class="form-control" value="<?= htmlspecialchars($u['nama_lengkap']) ?>" required>
                                          </div>
                                          <div class="mb-3">
                                              <label>Jabatan</label>
                                              <input type="text" name="jabatan" class="form-control" value="<?= htmlspecialchars($u['jabatan']) ?>" required>
                                          </div>
                                          <div class="row">
                                              <div class="col-md-6 mb-3">
                                                  <label>Status</label>
                                                  <select name="status_kepegawaian" class="form-select" required>
                                                      <option value="ASN" <?= $u['status_kepegawaian'] == 'ASN' ? 'selected' : '' ?>>ASN</option>
                                                      <option value="KKI" <?= $u['status_kepegawaian'] == 'KKI' ? 'selected' : '' ?>>KKI</option>
                                                      <option value="HONORER" <?= $u['status_kepegawaian'] == 'HONORER' ? 'selected' : '' ?>>HONORER</option>
                                                  </select>
                                              </div>
                                              <div class="col-md-6 mb-3">
                                                  <label>Role Akses</label>
                                                  <select name="role" class="form-select" required>
                                                      <option value="Administrator" <?= $u['role'] == 'Administrator' ? 'selected' : '' ?>>Administrator</option>
                                                      <option value="Kepala_Sekolah" <?= $u['role'] == 'Kepala_Sekolah' ? 'selected' : '' ?>>Kepala Sekolah</option>
                                                      <option value="Kepala_TU" <?= $u['role'] == 'Kepala_TU' ? 'selected' : '' ?>>Kepala Tata Usaha</option>
                                                      <option value="TU_Persuratan" <?= $u['role'] == 'TU_Persuratan' ? 'selected' : '' ?>>Staf TU Persuratan</option>
                                                      <option value="TU_Kesiswaan" <?= $u['role'] == 'TU_Kesiswaan' ? 'selected' : '' ?>>Staf TU Kesiswaan</option>
                                                      <option value="Guru_Piket" <?= $u['role'] == 'Guru_Piket' ? 'selected' : '' ?>>Guru Piket</option>
                                                  </select>
                                              </div>
                                          </div>
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" name="edit_user" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Perubahan</button>
                                      </div>
                                  </form>
                                </div>
                              </div>
                            </div>

                            <!-- MODAL GANTI FOTO PROFIL -->
                            <div class="modal fade" id="modalFoto<?= htmlspecialchars($u['nip']) ?>" tabindex="-1">
                              <div class="modal-dialog">
                                <div class="modal-content">
                                  <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                    <h5 class="modal-title"><i class="fas fa-camera"></i> Ganti Foto Profil: <?= htmlspecialchars($u['nama_lengkap']) ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                  </div>
                                  <form method="POST" enctype="multipart/form-data">
                                      <div class="modal-body text-center">
                                          <input type="hidden" name="nip_target" value="<?= htmlspecialchars($u['nip']) ?>">
                                          <div class="mb-3">
                                              <img src="<?= $foto_user ?>" class="rounded-circle shadow mb-3" width="100" height="100" style="object-fit: cover;" alt="Preview Foto">
                                          </div>
                                          <div class="mb-3 text-start">
                                              <label class="form-label">Pilih Foto Baru (Format: JPG / PNG)</label>
                                              <input type="file" name="foto_profil" class="form-control" accept=".jpg,.jpeg,.png" required>
                                          </div>
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" name="upload_foto_user" class="btn" style="background: var(--navy-blue); color: var(--gold);">Upload Foto</button>
                                      </div>
                                  </form>
                                </div>
                              </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: MASTER DATA SISWA & IMPORT -->
        <div class="tab-pane fade" id="importSiswa" role="tabpanel">
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 style="color: var(--navy-blue);"><i class="fas fa-file-excel"></i> Import & Export Format Data Siswa</h5>
                <hr>
                <div class="row mt-4">
                    <div class="col-md-6 border-end">
                        <h6 class="fw-bold mb-3">Langkah 1: Download Format Excel</h6>
                        <a href="admin.php?download_template=true" class="btn btn-outline-success"><i class="fas fa-download"></i> Download Format Excel (CSV)</a>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold mb-3">Langkah 2: Upload File Excel Siswa</h6>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <input type="file" name="file_excel" class="form-control" accept=".csv,.xlsx" required>
                            </div>
                            <button type="submit" name="import_siswa" class="btn" style="background: var(--navy-blue); color: var(--gold);"><i class="fas fa-upload"></i> Upload & Sinkronkan Data</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- TABEL DAFTAR DATA SISWA -->
            <div class="card border-0 shadow-sm p-4">
                
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-user-graduate"></i> Daftar Master Data Siswa</h5>
                    
                    <div class="d-flex gap-2 flex-wrap">
                        <form method="GET" action="admin.php" class="d-flex gap-2 align-items-center">
                            <input type="hidden" name="tab" value="importSiswa">
                            <input type="text" name="cari_siswa" class="form-control form-control-sm" placeholder="Cari Nama, Kelas, Agama..." value="<?= htmlspecialchars($search) ?>">
                            <select name="sort" class="form-select form-select-sm" style="width: auto;">
                                <option value="nama_asc" <?= $sort == 'nama_asc' ? 'selected' : '' ?>>Nama (A-Z)</option>
                                <option value="nama_desc" <?= $sort == 'nama_desc' ? 'selected' : '' ?>>Nama (Z-A)</option>
                                <option value="kelas_asc" <?= $sort == 'kelas_asc' ? 'selected' : '' ?>>Kelas</option>
                                <option value="rombel_asc" <?= $sort == 'rombel_asc' ? 'selected' : '' ?>>Rombel</option>
                                <option value="agama_asc" <?= $sort == 'agama_asc' ? 'selected' : '' ?>>Agama</option>
                                <option value="keterangan_asc" <?= $sort == 'keterangan_asc' ? 'selected' : '' ?>>Keterangan</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Cari & Urutkan"><i class="fas fa-search"></i></button>
                        </form>

                        <button class="btn btn-sm" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahSiswaManual">
                            <i class="fas fa-plus"></i> Tambah Siswa
                        </button>

                        <form method="POST" class="d-inline" onsubmit="return confirm('PERINGATAN KERAS: Apakah Anda yakin ingin MENGHAPUS SELURUH data siswa? Tindakan ini tidak dapat dibatalkan!');">
                            <button type="submit" name="hapus_semua_siswa" class="btn btn-sm btn-danger" title="Hapus Seluruh Data Siswa">
                                <i class="fas fa-trash-alt"></i> Hapus Semua
                            </button>
                        </form>
                    </div>
                </div>

                <div class="d-flex align-items-center mb-2">
                    <label class="me-2 mb-0 small text-muted">Tampilkan</label>
                    <select id="limitSiswa" class="form-select form-select-sm shadow-none border-secondary" style="width: auto; cursor: pointer;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="all">Semua</option>
                    </select>
                    <label class="ms-2 mb-0 small text-muted">data</label>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="tableSiswa">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>NISN</th><th>Nama Lengkap</th><th>Kelas</th><th>Rombel</th><th>Agama</th><th>Keterangan</th><th>Status</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbodySiswa">
                            <?php if(count($daftar_siswa) > 0): ?>
                                <?php foreach($daftar_siswa as $s): ?>
                                <tr>
                                    <td><?= htmlspecialchars($s['nisn']) ?></td>
                                    <td><strong><?= htmlspecialchars($s['nama_lengkap']) ?></strong> <br> <small class="text-muted"><?= $s['gender'] == 'L' ? 'Laki-laki' : 'Perempuan' ?></small></td>
                                    <td><?= htmlspecialchars($s['kelas']) ?></td>
                                    <td><?= htmlspecialchars($s['rombel']) ?></td>
                                    <td><?= htmlspecialchars($s['agama']) ?></td>
                                    <td><?= htmlspecialchars($s['keterangan']) ?></td>
                                    <td><?= htmlspecialchars($s['status']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditSiswa<?= htmlspecialchars($s['nisn']) ?>" title="Edit Siswa"><i class="fas fa-edit"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data siswa ini?');">
                                            <input type="hidden" name="nisn" value="<?= htmlspecialchars($s['nisn']) ?>">
                                            <button type="submit" name="hapus_siswa" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>

                                <!-- MODAL EDIT SISWA -->
                                <div class="modal fade" id="modalEditSiswa<?= htmlspecialchars($s['nisn']) ?>" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Siswa: <?= htmlspecialchars($s['nama_lengkap']) ?></h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                      </div>
                                      <form method="POST">
                                          <div class="modal-body">
                                              <input type="hidden" name="nisn_lama" value="<?= htmlspecialchars($s['nisn']) ?>">
                                              <div class="mb-3">
                                                  <label class="form-label">NISN</label>
                                                  <input type="text" name="nisn" class="form-control" value="<?= htmlspecialchars($s['nisn']) ?>" required>
                                              </div>
                                              <div class="mb-3">
                                                  <label class="form-label">Nama Lengkap</label>
                                                  <input type="text" name="nama_lengkap" class="form-control" value="<?= htmlspecialchars($s['nama_lengkap']) ?>" required>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Kelas</label>
                                                      <input type="text" name="kelas" class="form-control" value="<?= htmlspecialchars($s['kelas']) ?>" required>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Rombel</label>
                                                      <input type="text" name="rombel" class="form-control" value="<?= htmlspecialchars($s['rombel']) ?>" required>
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Gender</label>
                                                      <select name="gender" class="form-select" required>
                                                          <option value="L" <?= $s['gender'] == 'L' ? 'selected' : '' ?>>Laki-laki</option>
                                                          <option value="P" <?= $s['gender'] == 'P' ? 'selected' : '' ?>>Perempuan</option>
                                                      </select>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Agama</label>
                                                      <input type="text" name="agama" class="form-control" value="<?= htmlspecialchars($s['agama']) ?>">
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Keterangan / Jenis Inklusi</label>
                                                      <select name="keterangan" class="form-select" required>
                                                          <?php foreach($pilihan_keterangan as $ket): ?>
                                                              <option value="<?= htmlspecialchars($ket) ?>" <?= $s['keterangan'] == $ket ? 'selected' : '' ?>><?= htmlspecialchars($ket) ?></option>
                                                          <?php endforeach; ?>
                                                      </select>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Status</label>
                                                      <select name="status" class="form-select" required>
                                                          <option value="Aktif" <?= $s['status'] == 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                                          <option value="Pindah" <?= $s['status'] == 'Pindah' ? 'selected' : '' ?>>Pindah</option>
                                                          <option value="Lulus" <?= $s['status'] == 'Lulus' ? 'selected' : '' ?>>Lulus</option>
                                                      </select>
                                                  </div>
                                              </div>
                                          </div>
                                          <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" name="edit_siswa" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Perubahan</button>
                                          </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>

                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">Data siswa tidak ditemukan.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="paginationSiswa" class="d-flex justify-content-end mt-3"></div>
            </div>
        </div>


<!-- TAB 2.5: MASTER DATA KEPEGAWAIAN -->
        <div class="tab-pane fade" id="masterKepegawaian" role="tabpanel">
            
<!-- FORM IMPORT/EXPORT EXCEL -->
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h5 style="color: var(--navy-blue);"><i class="fas fa-file-excel"></i> Import & Export Master Data Kepegawaian</h5>
                <hr>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <h6 class="fw-bold mb-3">Langkah 1: Download Format Excel</h6>
                        
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-download"></i> Download Format Excel (CSV)
                            </button>
                            <ul class="dropdown-menu shadow-sm">
                                <li>
                                    <a class="dropdown-item py-2" href="admin.php?download_template_pendidik=true">
                                        <i class="fas fa-chalkboard-teacher text-primary me-2"></i> Format Tenaga Pendidik
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item py-2" href="admin.php?download_template_tendik=true">
                                        <i class="fas fa-user-cog text-success me-2"></i> Format Tenaga Kependidikan
                                    </a>
                                </li>
                            </ul>
                        </div>
                        
                        <p class="text-muted mt-3 mb-0"><i class="fas fa-info-circle"></i> <strong>Langkah 2:</strong> Setelah data diisi, gunakan tombol <strong>"Upload Excel"</strong> pada masing-masing tabel (Tenaga Pendidik / Kependidikan) di bawah ini untuk memasukkan data.</p>
                    </div>
                </div>
            </div>

            <!-- TABEL DAFTAR DATA PEGAWAI - TENAGA PENDIDIK -->
            <div class="card border-0 shadow-sm p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-chalkboard-teacher"></i> Daftar Master Data Kepegawaian - Tenaga Pendidik</h5>
                    
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <form method="GET" action="admin.php" class="d-flex gap-2 align-items-center m-0">
                            <input type="hidden" name="tab" value="masterKepegawaian">
                            <input type="text" name="cari_pegawai" class="form-control form-control-sm" placeholder="Cari Nama Pegawai..." value="<?= htmlspecialchars($search_pegawai) ?>">
                            <select name="sort_pegawai" class="form-select form-select-sm" style="width: auto;">
                                <option value="nama_asc" <?= $sort_pegawai == 'nama_asc' ? 'selected' : '' ?>>Nama (A-Z)</option>
                                <option value="nama_desc" <?= $sort_pegawai == 'nama_desc' ? 'selected' : '' ?>>Nama (Z-A)</option>
                                <option value="tgl_asc" <?= $sort_pegawai == 'tgl_asc' ? 'selected' : '' ?>>Lahir (Tua ke Muda)</option>
                                <option value="tgl_desc" <?= $sort_pegawai == 'tgl_desc' ? 'selected' : '' ?>>Lahir (Muda ke Tua)</option>
                                <option value="golongan" <?= $sort_pegawai == 'golongan' ? 'selected' : '' ?>>Golongan / Pangkat</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Cari & Urutkan"><i class="fas fa-search"></i></button>
                        </form>

                        <a href="admin.php?export_pendidik=true" class="btn btn-outline-success btn-sm"><i class="fas fa-file-excel"></i> Export Excel</a>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalUploadPendidik">
                            <i class="fas fa-file-excel"></i> Upload Excel
                        </button>
                        <button class="btn btn-sm" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahPendidik">
                            <i class="fas fa-plus"></i> Tambah Pegawai
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('PERINGATAN KERAS: Apakah Anda yakin ingin MENGHAPUS SELURUH data tenaga pendidik? Tindakan ini tidak dapat dibatalkan!');">
                            <button type="submit" name="hapus_semua_pendidik" class="btn btn-danger btn-sm" title="Hapus Seluruh Data Tenaga Pendidik">
                                <i class="fas fa-trash-alt"></i> Hapus Semua
                            </button>
                        </form>
                    </div>
                </div>

                <div class="d-flex align-items-center mb-2">
                    <label class="me-2 mb-0 small text-muted">Tampilkan</label>
                    <select id="limitPendidik" class="form-select form-select-sm shadow-none border-secondary" style="width: auto; cursor: pointer;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="all">Semua</option>
                    </select>
                    <label class="ms-2 mb-0 small text-muted">data</label>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="tablePendidik">
                       <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>No</th>
                                <th>NIP/NIKKI/NIK</th>
                                <th>NRK</th>
                                <th>NUPTK</th>
                                <th>Nama Pegawai</th>
                                <th>Tempat</th>
                                <th>Tanggal</th>
                                <th>Jenis Pegawai</th>
                                <th>Status Pegawai</th>
                                <th>Jabatan</th>
                                <th>Mapel</th> 
                                <th>Tugas Tambahan</th>
                                <th>Golongan / Pangkat</th>
                                <th>TMT</th>
                                <th>TMT Pangkat Terakhir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyPendidik">

                            <?php 
                            $ada_pendidik = false;
                            $no_pendidik = 1; 
                            if(count($daftar_pegawai) > 0): 
                                foreach($daftar_pegawai as $p): 
                                    if($p['jenis_pegawai'] == 'Guru'):
                                        $ada_pendidik = true;
                            ?>
                                <tr>
                                    <td><?= $no_pendidik++ ?></td>
                                    <td><strong><?= htmlspecialchars($p['nip']) ?></strong></td>
                                    <td><?= htmlspecialchars($p['nrk']) ?: '-' ?></td>
                                    <td><?= htmlspecialchars($p['nuptk'] ?? '') ?: '-' ?></td>
                                    <td><?= htmlspecialchars($p['nama_pegawai']) ?></td>
                                    <td><?= htmlspecialchars($p['tempat'] ?? '') ?: '-' ?></td>
                                    <td><?= (!empty($p['tanggal']) && $p['tanggal'] != '0000-00-00') ? date('d/m/Y', strtotime($p['tanggal'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($p['jenis_pegawai']) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($p['status_pegawai']) ?></span></td>
                                    <td><?= htmlspecialchars($p['jabatan']) ?></td>
                                    <td><?= htmlspecialchars($p['mapel'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($p['tugas_tambahan'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($p['pangkat_golongan']) ?: '-' ?></td>
                                    <td><?= (!empty($p['tmt']) && $p['tmt'] != '0000-00-00') ? date('d/m/Y', strtotime($p['tmt'])) : '-' ?></td>
                                    <td><?= (!empty($p['tmt_pangkat']) && $p['tmt_pangkat'] != '0000-00-00') ? date('d/m/Y', strtotime($p['tmt_pangkat'])) : '-' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditPendidik<?= htmlspecialchars($p['nip']) ?>" title="Edit Pegawai"><i class="fas fa-edit"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data pegawai ini?');">
                                            <input type="hidden" name="nip" value="<?= htmlspecialchars($p['nip']) ?>">
                                            <button type="submit" name="hapus_pegawai" class="btn btn-sm btn-danger" title="Hapus Pegawai"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                
                                <!-- MODAL EDIT PENDIDIK -->
                                <div class="modal fade" id="modalEditPendidik<?= htmlspecialchars($p['nip']) ?>" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Tenaga Pendidik</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                      </div>
                                      <form method="POST">
                                          <div class="modal-body">
                                              <input type="hidden" name="nip_lama" value="<?= htmlspecialchars($p['nip']) ?>">
                                              <div class="mb-3">
                                                  <label class="form-label">NIP / NIKKI / NIK</label>
                                                  <input type="text" name="nip" class="form-control" value="<?= htmlspecialchars($p['nip']) ?>" required>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">NRK (Opsional)</label>
                                                      <input type="text" name="nrk" class="form-control" value="<?= htmlspecialchars($p['nrk']) ?>">
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">NUPTK (Opsional)</label>
                                                      <input type="text" name="nuptk" class="form-control" maxlength="18" pattern="\d*" value="<?= htmlspecialchars($p['nuptk']) ?>">
                                                  </div>
                                              </div>
                                              <div class="mb-3">
                                                  <label class="form-label">Nama Pegawai</label>
                                                  <input type="text" name="nama_pegawai" class="form-control" value="<?= htmlspecialchars($p['nama_pegawai']) ?>" required>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Tempat Lahir</label>
                                                      <input type="text" name="tempat" class="form-control" value="<?= htmlspecialchars($p['tempat']) ?>" required>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Tanggal Lahir</label>
                                                      <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($p['tanggal']) ?>" required>
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-12 mb-3">
                                                      <label class="form-label">Jabatan</label>
                                                      <input type="text" name="jabatan" class="form-control" value="<?= htmlspecialchars($p['jabatan']) ?>" required>
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Mapel</label>
                                                      <input type="text" name="mapel" class="form-control" value="<?= htmlspecialchars($p['mapel']) ?>" required>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Tugas Tambahan</label>
                                                      <select name="tugas_tambahan" class="form-select">
                                                          <option value="-" <?= $p['tugas_tambahan'] == '-' ? 'selected' : '' ?>>-- Tidak Ada --</option>
                                                          <option value="Kepala Sekolah" <?= $p['tugas_tambahan'] == 'Kepala Sekolah' ? 'selected' : '' ?>>Kepala Sekolah</option>
                                                          <option value="Wakil Kepala Sekolah Bidang Kesiswaan & Humas" <?= $p['tugas_tambahan'] == 'Wakil Kepala Sekolah Bidang Kesiswaan & Humas' ? 'selected' : '' ?>>Wakil Kepala Sekolah Bidang Kesiswaan & Humas</option>
                                                          <option value="Wakil Kepala Sekolah Bidang Akademik & Perpustakaan" <?= $p['tugas_tambahan'] == 'Wakil Kepala Sekolah Bidang Akademik & Perpustakaan' ? 'selected' : '' ?>>Wakil Kepala Sekolah Bidang Akademik & Perpustakaan</option>
                                                          <option value="Wakil Kepala Sekolah Bidang Sarana Prasarana & Administrasi" <?= $p['tugas_tambahan'] == 'Wakil Kepala Sekolah Bidang Sarana Prasarana & Administrasi' ? 'selected' : '' ?>>Wakil Kepala Sekolah Bidang Sarana Prasarana & Administrasi</option>
                                                          <option value="Kepala Laboratorium" <?= $p['tugas_tambahan'] == 'Kepala Laboratorium' ? 'selected' : '' ?>>Kepala Laboratorium</option>
                                                          <option value="Kepala Perpustakaan" <?= $p['tugas_tambahan'] == 'Kepala Perpustakaan' ? 'selected' : '' ?>>Kepala Perpustakaan</option>
                                                          <option value="Wali Kelas" <?= $p['tugas_tambahan'] == 'Wali Kelas' ? 'selected' : '' ?>>Wali Kelas</option>
                                                      </select>
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Status Pegawai</label>
                                                      <select name="status_pegawai" class="form-select" required>
                                                          <option value="PNS" <?= $p['status_pegawai'] == 'PNS' ? 'selected' : '' ?>>PNS</option>
                                                          <option value="PPPK" <?= $p['status_pegawai'] == 'PPPK' ? 'selected' : '' ?>>PPPK</option>
                                                          <option value="PPPK Paruh Waktu" <?= $p['status_pegawai'] == 'PPPK Paruh Waktu' ? 'selected' : '' ?>>PPPK Paruh Waktu</option>
                                                          <option value="KKI" <?= $p['status_pegawai'] == 'KKI' ? 'selected' : '' ?>>KKI</option>
                                                          <option value="HONORER" <?= $p['status_pegawai'] == 'HONORER' ? 'selected' : '' ?>>HONORER</option>
                                                      </select>
                                                  </div>
                                                    <div class="col-md-6 mb-3">
                                                      <label class="form-label">Golongan / Pangkat</label>
                                                      <select name="pangkat_golongan" class="form-select" data-initial="<?= htmlspecialchars($p['pangkat_golongan']) ?>" required>
                                                          <option value="" disabled>-- Pilih Golongan / Pangkat --</option>
                                                          <?php 
                                                          $stat = $p['status_pegawai'];
                                                          $gol_opts = [];
                                                          if($stat == 'PNS') {
                                                              $gol_opts = ['IV.e (Pembina Utama)','IV.d (Pembina Utama Madya)','IV.c (Pembina Utama Muda)','IV.b (Pembina Tingkat I)','IV.a (Pembina)','III.d (Penata Tingkat I)','III.c (Penata)','III.b (Penata Muda Tingkat I)','III.a (Penata Muda)','II.d (Pengatur Tingkat I)','II.c (Pengatur)','II.b (Pengatur Muda Tingkat I)','II.a (Pengatur Muda)','I.d (Juru Tingkat I)','I.c (Juru Muda)','I.b (Juru Muda Tingkat I)','I.a (Juru Muda)'];
                                                          } elseif($stat == 'PPPK') {
                                                              $gol_opts = ['IX (Ahli Pertama)', 'X (Ahli Pertama)'];
                                                          } else {
                                                              $gol_opts = ['Non ASN'];
                                                          }
                                                          
                                                          foreach($gol_opts as $g_opt) {
                                                              $sel = ($p['pangkat_golongan'] == $g_opt) ? 'selected' : '';
                                                              echo "<option value=\"$g_opt\" $sel>$g_opt</option>";
                                                          }
                                                          ?>
                                                      </select>
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">TMT</label>
                                                      <input type="date" name="tmt" class="form-control" value="<?= htmlspecialchars($p['tmt']) ?>" required>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">TMT Pangkat Terakhir</label>
                                                      <input type="date" name="tmt_pangkat" class="form-control" value="<?= (!empty($p['tmt_pangkat']) && $p['tmt_pangkat'] != '0000-00-00') ? htmlspecialchars($p['tmt_pangkat']) : '' ?>">
                                                  </div>
                                              </div>
                                          </div>
                                          <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" name="edit_pegawai" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Perubahan</button>
                                          </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>
                                <?php 
                                    endif;
                                endforeach; 
                            endif; 
                            
                            if(!$ada_pendidik): 
                            ?>
                                <tr>
                                    <td colspan="15" class="text-center text-muted py-4">Data tenaga pendidik belum tersedia. Tambahkan data baru.</td>
                                </tr>
                           <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="paginationPendidik" class="d-flex justify-content-end mt-3"></div>
            </div>

            <!-- TABEL DAFTAR DATA PEGAWAI - TENAGA KEPENDIDIKAN -->
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-user-cog"></i> Daftar Master Data Kepegawaian - Tenaga Kependidikan</h5>
                    
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <form method="GET" action="admin.php" class="d-flex gap-2 align-items-center m-0">
                            <input type="hidden" name="tab" value="masterKepegawaian">
                            <input type="text" name="cari_pegawai" class="form-control form-control-sm" placeholder="Cari Nama Pegawai..." value="<?= htmlspecialchars($search_pegawai) ?>">
                            <select name="sort_pegawai" class="form-select form-select-sm" style="width: auto;">
                                <option value="nama_asc" <?= $sort_pegawai == 'nama_asc' ? 'selected' : '' ?>>Nama (A-Z)</option>
                                <option value="nama_desc" <?= $sort_pegawai == 'nama_desc' ? 'selected' : '' ?>>Nama (Z-A)</option>
                                <option value="tgl_asc" <?= $sort_pegawai == 'tgl_asc' ? 'selected' : '' ?>>Lahir (Tua ke Muda)</option>
                                <option value="tgl_desc" <?= $sort_pegawai == 'tgl_desc' ? 'selected' : '' ?>>Lahir (Muda ke Tua)</option>
                                <option value="golongan" <?= $sort_pegawai == 'golongan' ? 'selected' : '' ?>>Golongan / Pangkat</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Cari & Urutkan"><i class="fas fa-search"></i></button>
                        </form>

                        <a href="admin.php?export_tendik=true" class="btn btn-outline-success btn-sm"><i class="fas fa-file-excel"></i> Export Excel</a>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalUploadTendik">
                            <i class="fas fa-file-excel"></i> Upload Excel
                        </button>
                        <button class="btn btn-sm" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahTendik">
                            <i class="fas fa-plus"></i> Tambah Pegawai
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('PERINGATAN KERAS: Apakah Anda yakin ingin MENGHAPUS SELURUH data tenaga kependidikan? Tindakan ini tidak dapat dibatalkan!');">
                            <button type="submit" name="hapus_semua_tendik" class="btn btn-danger btn-sm" title="Hapus Seluruh Data Tenaga Kependidikan">
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
                                <th>NIP/NIKKI/NIK</th>
                                <th>NRK</th>
                                <th>NUPTK</th>
                                <th>Nama Pegawai</th>
                                <th>Tempat</th>
                                <th>Tanggal</th>
                                <th>Jenis Pegawai</th>
                                <th>Status Pegawai</th>
                                <th>Jabatan</th>
                                <th>Golongan / Pangkat</th>
                                <th>TMT (Mulai Tugas)</th>
                                <th>TMT Pangkat Terakhir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $ada_tendik = false;
                            $no_tendik = 1; 
                            if(count($daftar_pegawai) > 0): 
                                foreach($daftar_pegawai as $p): 
                                    if($p['jenis_pegawai'] == 'Tenaga Kependidikan'):
                                        $ada_tendik = true;
                            ?>
                                <tr>
                                    <td><?= $no_tendik++ ?></td>
                                    <td><strong><?= htmlspecialchars($p['nip']) ?></strong></td>
                                    <td><?= htmlspecialchars($p['nrk']) ?: '-' ?></td>
                                    <td><?= htmlspecialchars($p['nuptk'] ?? '') ?: '-' ?></td>
                                    <td><?= htmlspecialchars($p['nama_pegawai']) ?></td>
                                    <td><?= htmlspecialchars($p['tempat'] ?? '') ?: '-' ?></td>
                                    <td><?= (!empty($p['tanggal']) && $p['tanggal'] != '0000-00-00') ? date('d/m/Y', strtotime($p['tanggal'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($p['jenis_pegawai']) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($p['status_pegawai']) ?></span></td>
                                    <td><?= htmlspecialchars($p['jabatan']) ?></td>
                                    <td><?= htmlspecialchars($p['pangkat_golongan']) ?: '-' ?></td>
                                    <td><?= (!empty($p['tmt']) && $p['tmt'] != '0000-00-00') ? date('d/m/Y', strtotime($p['tmt'])) : '-' ?></td>
                                    <td><?= (!empty($p['tmt_pangkat']) && $p['tmt_pangkat'] != '0000-00-00') ? date('d/m/Y', strtotime($p['tmt_pangkat'])) : '-' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEditTendik<?= htmlspecialchars($p['nip']) ?>" title="Edit Pegawai"><i class="fas fa-edit"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data pegawai ini?');">
                                            <input type="hidden" name="nip" value="<?= htmlspecialchars($p['nip']) ?>">
                                            <button type="submit" name="hapus_pegawai" class="btn btn-sm btn-danger" title="Hapus Pegawai"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                
                                <!-- MODAL EDIT TENDIK -->
                                <div class="modal fade" id="modalEditTendik<?= htmlspecialchars($p['nip']) ?>" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
                                        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Tenaga Kependidikan</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                      </div>
                                      <form method="POST">
                                          <div class="modal-body">
                                              <input type="hidden" name="nip_lama" value="<?= htmlspecialchars($p['nip']) ?>">
                                              <div class="mb-3">
                                                  <label class="form-label">NIP / NIKKI / NIK</label>
                                                  <input type="text" name="nip" class="form-control" value="<?= htmlspecialchars($p['nip']) ?>" required>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">NRK (Opsional)</label>
                                                      <input type="text" name="nrk" class="form-control" value="<?= htmlspecialchars($p['nrk']) ?>">
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">NUPTK (Opsional)</label>
                                                      <input type="text" name="nuptk" class="form-control" maxlength="18" pattern="\d*" value="<?= htmlspecialchars($p['nuptk']) ?>">
                                                  </div>
                                              </div>
                                              <div class="mb-3">
                                                  <label class="form-label">Nama Pegawai</label>
                                                  <input type="text" name="nama_pegawai" class="form-control" value="<?= htmlspecialchars($p['nama_pegawai']) ?>" required>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Tempat Lahir</label>
                                                      <input type="text" name="tempat" class="form-control" value="<?= htmlspecialchars($p['tempat']) ?>" required>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Tanggal Lahir</label>
                                                      <input type="date" name="tanggal" class="form-control" value="<?= htmlspecialchars($p['tanggal']) ?>" required>
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-6 mb-3">
                                                      <label class="form-label">Status Pegawai</label>
                                                      <select name="status_pegawai" id="status_pegawai_tendik" class="form-select" required>
                                                        <option value="PNS" <?= $p['status_pegawai'] == 'PNS' ? 'selected' : '' ?>>PNS</option>
                                                        <option value="PPPK" <?= $p['status_pegawai'] == 'PPPK' ? 'selected' : '' ?>>PPPK</option>
                                                        <option value="PPPK Paruh Waktu" <?= $p['status_pegawai'] == 'PPPK Paruh Waktu' ? 'selected' : '' ?>>PPPK Paruh Waktu</option>
                                                        <option value="KKI" <?= $p['status_pegawai'] == 'KKI' ? 'selected' : '' ?>>KKI</option>
                                                        <option value="HONORER" <?= $p['status_pegawai'] == 'HONORER' ? 'selected' : '' ?>>HONORER</option>
                                                    </select>
                                                  </div>
                                                      <div class="col-md-6 mb-3">
                                                      <label class="form-label">Status Pegawai</label>
                                                      <!-- Hapus ID yang bentrok, biarkan pemanggilan name="status_pegawai" yang menghandle JS -->
                                                      <select name="status_pegawai" class="form-select" required>
                                                        <option value="PNS" <?= $p['status_pegawai'] == 'PNS' ? 'selected' : '' ?>>PNS</option>
                                                        <option value="PPPK" <?= $p['status_pegawai'] == 'PPPK' ? 'selected' : '' ?>>PPPK</option>
                                                        <option value="PPPK Paruh Waktu" <?= $p['status_pegawai'] == 'PPPK Paruh Waktu' ? 'selected' : '' ?>>PPPK Paruh Waktu</option>
                                                        <option value="KKI" <?= $p['status_pegawai'] == 'KKI' ? 'selected' : '' ?>>KKI</option>
                                                        <option value="HONORER" <?= $p['status_pegawai'] == 'HONORER' ? 'selected' : '' ?>>HONORER</option>
                                                    </select>
                                                  </div>
                                                  <div class="col-md-6 mb-3">
                                                    <label class="form-label">Golongan / Pangkat</label>
                                                    <select name="pangkat_golongan" class="form-select" data-initial="<?= htmlspecialchars($p['pangkat_golongan']) ?>" required>
                                                        <option value="" disabled>-- Pilih Golongan / Pangkat --</option>
                                                        <?php 
                                                        $stat = $p['status_pegawai'];
                                                        $gol_opts = [];
                                                        if($stat == 'PNS') {
                                                            $gol_opts = ['IV.e (Pembina Utama)','IV.d (Pembina Utama Madya)','IV.c (Pembina Utama Muda)','IV.b (Pembina Tingkat I)','IV.a (Pembina)','III.d (Penata Tingkat I)','III.c (Penata)','III.b (Penata Muda Tingkat I)','III.a (Penata Muda)','II.d (Pengatur Tingkat I)','II.c (Pengatur)','II.b (Pengatur Muda Tingkat I)','II.a (Pengatur Muda)','I.d (Juru Tingkat I)','I.c (Juru Muda)','I.b (Juru Muda Tingkat I)','I.a (Juru Muda)'];
                                                        } elseif($stat == 'PPPK') {
                                                            $gol_opts = ['IX (Ahli Pertama)', 'X (Ahli Pertama)'];
                                                        } else {
                                                            $gol_opts = ['Non ASN'];
                                                        }
                                                        
                                                        foreach($gol_opts as $g_opt) {
                                                            $sel = ($p['pangkat_golongan'] == $g_opt) ? 'selected' : '';
                                                            echo "<option value=\"$g_opt\" $sel>$g_opt</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                  </div>
                                              </div>
                                              <div class="row">
                                                  <div class="col-md-4 mb-3">
                                                      <label class="form-label">Jabatan</label>
                                                      <select name="jabatan" class="form-select" required>
                                                          <option value="" disabled selected>-- Pilih Jabatan --</option>
                                                          <option value="Kepala Satuan Pelaksana" <?= $p['jabatan'] == 'Kepala Satuan Pelaksana' ? 'selected' : '' ?>>Kepala Satuan Pelaksana</option>
                                                          <option value="Tenaga Administrasi" <?= $p['jabatan'] == 'Tenaga Administrasi' ? 'selected' : '' ?>>Tenaga Administrasi</option>
                                                          <option value="Tenaga Kebersihan" <?= $p['jabatan'] == 'Tenaga Kebersihan' ? 'selected' : '' ?>>Tenaga Kebersihan</option>
                                                          <option value="Tenaga Keamanan" <?= $p['jabatan'] == 'Tenaga Keamanan' ? 'selected' : '' ?>>Tenaga Keamanan</option>
                                                          <option value="Penjaga Sekolah" <?= $p['jabatan'] == 'Penjaga Sekolah' ? 'selected' : '' ?>>Penjaga Sekolah</option>
                                                      </select>
                                                  </div>
                                                  <div class="col-md-4 mb-3">
                                                      <label class="form-label">TMT</label>
                                                      <input type="date" name="tmt" class="form-control" value="<?= htmlspecialchars($p['tmt']) ?>" required>
                                                  </div>
                                                  <div class="col-md-4 mb-3">
                                                      <label class="form-label">TMT Pangkat Terakhir</label>
                                                      <input type="date" name="tmt_pangkat" class="form-control" value="<?= (!empty($p['tmt_pangkat']) && $p['tmt_pangkat'] != '0000-00-00') ? htmlspecialchars($p['tmt_pangkat']) : '' ?>">
                                                  </div>
                                              </div>
                                          </div>
                                          <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" name="edit_pegawai" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Perubahan</button>
                                          </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>
                                <?php 
                                    endif;
                                endforeach; 
                            endif; 
                            
                            if(!$ada_tendik): 
                            ?>
                                <tr>
                                    <td colspan="14" class="text-center text-muted py-4">Data tenaga kependidikan belum tersedia. Tambahkan data baru.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div> <!-- PENUTUP TAB MASTER KEPEGAWAIAN -->
        

        <!-- TAB 3: PENGATURAN TANDA TANGAN -->
        <div class="tab-pane fade" id="pengaturanTtd" role="tabpanel">
            <div class="card border-0 shadow-sm p-4">
                <h5 style="color: var(--navy-blue);"><i class="fas fa-signature"></i> Setup Tanda Tangan Cetak Laporan (PDF)</h5>
                <hr>
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">1. Tempat / Kota Pembuatan</label>
                            <input type="text" name="tempat" class="form-control" value="<?= htmlspecialchars($ttd_settings['tempat']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">2. Upload TTD Kepala Sekolah (PNG)</label>
                            <input type="file" name="file_ttd" class="form-control" accept=".png">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">3. Nama Kepala Sekolah</label>
                            <input type="text" name="nama_kepsek" class="form-control" value="<?= htmlspecialchars($ttd_settings['nama_kepsek']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">4. NIP Kepala Sekolah</label>
                            <input type="number" name="nip_kepsek" class="form-control" value="<?= htmlspecialchars($ttd_settings['nip_kepsek']) ?>" required>
                        </div>
                    </div>
                    <button type="submit" name="simpan_ttd" class="btn mt-2" style="background: var(--navy-blue); color: var(--gold);"><i class="fas fa-save"></i> Simpan Format</button>
                </form>
            </div>
        </div>

        <!-- TAB 4: PENGATURAN TAMPILAN LOGIN -->
        <div class="tab-pane fade" id="pengaturanTampilan" role="tabpanel">
            <div class="card border-0 shadow-sm p-4">
                <h5 style="color: var(--navy-blue);"><i class="fas fa-paint-roller"></i> Personalisasi Tampilan Halaman Login & Public Dashboard</h5>
                <hr>
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label fw-bold">1. Upload Logo (PNG)</label><input class="form-control" type="file" name="logo_login" accept=".png,.jpg,.jpeg"></div>
                        <div class="col-md-6 mb-3"><label class="form-label fw-bold">2. Upload Background Gambar</label><input class="form-control" type="file" name="bg_login" accept=".jpg,.jpeg,.png"></div>
                    </div>
                    <button type="submit" name="simpan_tampilan" class="btn mt-2" style="background: var(--navy-blue); color: var(--gold);"><i class="fas fa-save"></i> Simpan Tampilan</button>
                </form>
            </div>
        </div>

      <!-- TAB 5: JADWAL BANK SAMPAH & DATA HASIL INPUT -->
        <div class="tab-pane fade" id="jadwalBankSampah" role="tabpanel">
            <div class="card border-0 shadow-sm p-4 mb-4">
                <div class="row">
                    
                    <!-- Tabel Daftar Jadwal (Sekarang menjadi Full Width) -->
                    <div class="col-md-12 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-list"></i> Daftar Aktivitas & Jadwal Terbaru</h5>
                            <!-- Tombol ini akan memanggil Pop Up Modal -->
                            <button class="btn btn-sm" style="background: var(--navy-blue); color: var(--gold);" data-bs-toggle="modal" data-bs-target="#modalTambahAktivitas">
                                <i class="fas fa-plus"></i> Tambah Aktivitas
                            </button>
                        </div>
                        <hr>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead style="background-color: var(--bg-light);">
                                    <tr>
                                        <th>Kategori</th>
                                        <th>Judul Kegiatan</th>
                                        <th>Deskripsi</th>
                                        <th>Waktu</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($daftar_jadwal_sampah)): ?>
                                        <?php foreach($daftar_jadwal_sampah as $j): ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?= $j['kategori']=='Organik'?'bg-success':($j['kategori']=='Plastik'?'bg-warning text-dark':'bg-secondary') ?>">
                                                    <?= htmlspecialchars($j['kategori']) ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold"><?= htmlspecialchars($j['judul']) ?>
                                            </td>

                                        <td>
                                        <small><?= htmlspecialchars($j['deskripsi']) ?></small>
                                        <?php if(!empty($j['galeri'])): ?>
                                            <div class="mt-2 d-flex flex-wrap gap-1">
                                                <?php foreach($j['galeri'] as $foto): ?>
                                                    <a href="<?= $foto ?>" target="_blank" title="Klik untuk perbesar">
                                                        <img src="<?= $foto ?>" class="rounded border" width="35" height="35" style="object-fit: cover;">
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                            <td>
                                                <small><i class="far fa-clock"></i> <?= htmlspecialchars($j['waktu']) ?></small></td>
                                            <td>

                                                <form method="POST" onsubmit="return confirm('Hapus aktivitas ini?');">
                                                    <input type="hidden" name="id_jadwal" value="<?= $j['id'] ?>">
                                                    <button type="submit" name="hapus_jadwal_sampah" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada jadwal kegiatan bank sampah.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

           <!-- TABEL TAMBAHAN: DATA HASIL INPUT BANK DATA SAMPAH REALTIME DARI SPREADSHEET -->
            <div class="card border-0 shadow-sm p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 style="color: var(--navy-blue);" class="m-0"><i class="fas fa-table"></i> Data Hasil Input Bank Data Sampah (Realtime Spreadsheet)</h5>
                    <span class="badge bg-success">Live Sinkronisasi</span>
                </div>
                <hr>
                
                <!-- TAMBAHAN: Fitur Show (Limit Data) -->
                <div class="d-flex align-items-center mb-3">
                    <label class="me-2 mb-0 small text-muted">Tampilkan</label>
                    <select id="limitSpreadsheet" class="form-select form-select-sm shadow-none border-secondary" style="width: auto; cursor: pointer;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="all">Semua</option>
                    </select>
                    <label class="ms-2 mb-0 small text-muted">data</label>
                </div>

                <div class="table-responsive">
                    <!-- TAMBAHAN: id="tableSpreadsheet" -->
                    <table class="table table-hover align-middle" id="tableSpreadsheet">
                        <thead style="background-color: var(--bg-light);">
                            <tr>
                                <th>No</th>
                                <?php if(!empty($data_spreadsheet_sampah)): ?>
                                    <?php foreach(array_keys($data_spreadsheet_sampah[0]) as $col_name): ?>
                                        <th class="text-uppercase"><?= htmlspecialchars($col_name) ?></th>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <th>Informasi</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <!-- TAMBAHAN: id="tbodySpreadsheet" -->
                        <tbody id="tbodySpreadsheet">
                            <?php if(!empty($data_spreadsheet_sampah)): ?>
                                <?php $no_ss = 1; foreach($data_spreadsheet_sampah as $row_ss): ?>
                                <tr>
                                    <td><?= $no_ss++ ?></td>
                                    <?php foreach($row_ss as $val_ss): ?>
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
                <!-- TAMBAHAN: Wadah untuk tombol navigasi pagination -->
                <div id="paginationSpreadsheet" class="d-flex justify-content-end mt-3"></div>
            </div>

            <hr class="my-4 border-2 border-secondary opacity-25">
            <h5 style="color: var(--navy-blue);" class="mb-4"><i class="fas fa-images"></i> Galeri Kegiatan Pemilahan</h5>
            
            <div class="row">
                <?php 
                $ada_galeri = false;
                if(!empty($daftar_jadwal_sampah)): 
                    foreach($daftar_jadwal_sampah as $j): 
                        if(!empty($j['galeri'])): 
                            $ada_galeri = true;
                ?>
                    <div class="col-12 mb-3">
                        <div class="p-3 border rounded bg-light shadow-sm">
                            <h6 class="fw-bold mb-3 text-dark border-bottom pb-2">
                                <i class="far fa-calendar-alt text-primary"></i> <?= htmlspecialchars($j['waktu']) ?> - <?= htmlspecialchars($j['judul']) ?>
                            </h6>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach($j['galeri'] as $foto): ?>
                                    <a href="<?= $foto ?>" target="_blank" title="Klik untuk memperbesar">
                                        <img src="<?= $foto ?>" class="rounded shadow-sm" style="width: 150px; height: 110px; object-fit: cover; border: 2px solid #fff;">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php 
                        endif;
                    endforeach; 
                endif; 
                
                if(!$ada_galeri): 
                ?>
                    <div class="col-12 text-center text-muted py-4">
                        <i class="fas fa-image fa-2x mb-2 text-light"></i><br>
                        Belum ada galeri kegiatan pemilahan yang diunggah.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    </div> 
</div> 


<!-- ========================================== -->
<!-- MODAL TAMBAH USER -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahUser" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-user-plus"></i> Tambah User Baru</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <div class="modal-body">
              <div class="mb-3">
                  <label>NIP Pengguna</label>
                  <input type="number" name="nip" class="form-control" required>
              </div>
              <div class="mb-3">
                  <label>Nama Lengkap</label>
                  <input type="text" name="nama_lengkap" class="form-control" required>
              </div>
              <div class="mb-3">
                  <label>Jabatan</label>
                  <input type="text" name="jabatan" class="form-control" required>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label>Status</label>
                      <select name="status_kepegawaian" class="form-select" required>
                          <option value="ASN">ASN</option>
                          <option value="KKI">KKI</option>
                          <option value="HONORER">HONORER</option>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label>Role Akses</label>
                      <select name="role" class="form-select" required>
                          <option value="Administrator">Administrator</option>
                          <option value="Kepala_Sekolah">Kepala Sekolah</option>
                          <option value="Kepala_TU">Kepala Tata Usaha</option>
                          <option value="TU_Persuratan">Staf TU Persuratan</option>
                          <option value="TU_Kesiswaan">Staf TU Kesiswaan</option>
                          <option value="Guru_Piket">Guru Piket</option>
                      </select>
                  </div>
              </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="tambah_user" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH SISWA MANUAL -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahSiswaManual" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-user-plus"></i> Tambah Siswa Manual</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label">NISN</label>
                  <input type="text" name="nisn" class="form-control" required placeholder="Contoh: 3108923411">
              </div>
              <div class="mb-3">
                  <label class="form-label">Nama Lengkap</label>
                  <input type="text" name="nama_lengkap" class="form-control" required placeholder="Nama lengkap siswa">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Kelas</label>
                      <input type="text" name="kelas" class="form-control" required placeholder="Contoh: VII, VIII, IX">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Rombel</label>
                      <input type="text" name="rombel" class="form-control" required placeholder="Contoh: A, B">
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Gender</label>
                      <select name="gender" class="form-select" required>
                          <option value="L">Laki-laki</option>
                          <option value="P">Perempuan</option>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Agama</label>
                      <input type="text" name="agama" class="form-control" placeholder="Islam/Kristen/dll">
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Keterangan / Jenis Inklusi</label>
                      <select name="keterangan" class="form-select" required>
                          <?php foreach($pilihan_keterangan as $ket): ?>
                              <option value="<?= htmlspecialchars($ket) ?>"><?= htmlspecialchars($ket) ?></option>
                          <?php endforeach; ?>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Status</label>
                      <select name="status" class="form-select" required>
                          <option value="Aktif" selected>Aktif</option>
                          <option value="Pindah">Pindah</option>
                          <option value="Lulus">Lulus</option>
                      </select>
                  </div>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="tambah_siswa_manual" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Siswa</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH TENAGA PENDIDIK -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahPendidik" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-chalkboard-teacher"></i> Tambah Tenaga Pendidik</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <div class="modal-body">
              <input type="hidden" name="jenis_pegawai" value="Guru">
              
              <div class="mb-3">
                  <label class="form-label">NIP / NIKKI / NIK</label>
                  <input type="text" name="nip" class="form-control" required placeholder="Masukkan NIP/NIK">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">NRK (Opsional)</label>
                      <input type="text" name="nrk" class="form-control" placeholder="Masukkan NRK">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">NUPTK (Opsional)</label>
                      <input type="text" name="nuptk" class="form-control" maxlength="18" pattern="\d*" title="Hanya angka" placeholder="Masukkan NUPTK">
                  </div>
              </div>
              <div class="mb-3">
                  <label class="form-label">Nama Pegawai</label>
                  <input type="text" name="nama_pegawai" class="form-control" required placeholder="Nama lengkap pegawai">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Tempat Lahir</label>
                      <input type="text" name="tempat" class="form-control" required>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Tanggal Lahir</label>
                      <input type="date" name="tanggal" class="form-control" required>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-12 mb-3">
                      <label class="form-label">Jabatan</label>
                      <input type="text" name="jabatan" class="form-control" required placeholder="Contoh: Guru Kelas">
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Mapel (Mata Pelajaran)</label>
                      <input type="text" name="mapel" class="form-control" required placeholder="Contoh: Matematika">
                  </div>
                <div class="col-md-6 mb-3">
                      <label class="form-label">Tugas Tambahan</label>
                      <select name="tugas_tambahan" class="form-select">
                          <option value="-">-- Tidak Ada / Pilih Tugas Tambahan --</option>
                          <option value="Kepala Sekolah">Kepala Sekolah</option>
                          <option value="Wakil Kepala Sekolah Bidang Kesiswaan & Humas">Wakil Kepala Sekolah Bidang Kesiswaan & Humas</option>
                          <option value="Wakil Kepala Sekolah Bidang Akademik & Perpustakaan">Wakil Kepala Sekolah Bidang Akademik & Perpustakaan</option>
                          <option value="Wakil Kepala Sekolah Bidang Sarana Prasarana & Administrasi">Wakil Kepala Sekolah Bidang Sarana Prasarana & Administrasi</option>
                          <option value="Kepala Laboratorium">Kepala Laboratorium</option>
                          <option value="Kepala Perpustakaan">Kepala Perpustakaan</option>
                          <option value="Wali Kelas">Wali Kelas</option>
                      </select>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Status Pegawai</label>
                      <select name="status_pegawai" id="status_pegawai_pendidik" class="form-select" required>
                        <option value="PNS">PNS</option>
                        <option value="PPPK">PPPK</option>
                        <option value="PPPK Paruh Waktu">PPPK Paruh Waktu</option>
                        <option value="KKI">KKI</option>
                        <option value="HONORER">HONORER</option>
                    </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Golongan / Pangkat</label>
                      <select name="pangkat_golongan" id="pangkat_golongan_pendidik" class="form-select" required>
                          <option value="I.a (Juru Muda)">I.a (Juru Muda)</option>
                          <option value="I.b (Juru Muda Tingkat I)">I.b (Juru Muda Tingkat I)</option>
                          <option value="I.c (Juru Muda)">I.c (Juru)</option>
                          <option value="I.d (Juru Tingkat I)">I.d (Juru Tingkat I)</option>
                          <option value="II.a (Pengatur Muda)">II.a (Pengatur Muda)</option>
                          <option value="II.b (Pengatur Muda Tingkat I)">II.b (Pengatur Muda Tingkat I)</option>
                          <option value="II.c (Pengatur)">II.c (Pengatur)</option>
                          <option value="II.d (Pengatur Tingkat I)">II.d (Pengatur Tingkat I)</option>
                          <option value="III.a (Penata Muda)">III.a (Penata Muda)</option>
                          <option value="III.b (Penata Muda Tingkat I)">III.b (Penata Muda Tingkat I)</option>
                          <option value="III.c (Penata)">III.c (Penata)</option>
                          <option value="III.d (Penata Tingkat I)">III.d (Penata Tingkat I)</option>
                          <option value="IV.a (Pembina)">IV.a (Pembina)</option>
                          <option value="IV.b (Pembina Tingkat I)">IV.b (Pembina Tingkat I)</option>
                          <option value="IV.c (Pembina Utama Muda)">IV.c (Pembina Utama Muda)</option>
                          <option value="IV.d (Pembina Utama Madya)">IV.d (Pembina Utama Madya)</option>
                          <option value="IV.e (Pembina Utama)">IV.e (Pembina Utama)</option>
                          <option value="IX (Ahli Pertama)">IX (Ahli Pertama)</option>
                          <option value="X (Ahli Pertama)">X (Ahli Pertama)</option>
                          <option value="Non ASN">Non ASN</option>
                      </select>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">TMT (Tanggal Mulai Tugas)</label>
                      <input type="date" name="tmt" class="form-control" required>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">TMT Pangkat Terakhir</label>
                      <input type="date" name="tmt_pangkat" class="form-control">
                  </div>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="tambah_pegawai_manual" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Pendidik</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH TENAGA KEPENDIDIKAN -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahTendik" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-user-cog"></i> Tambah Tenaga Kependidikan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <div class="modal-body">
              <input type="hidden" name="jenis_pegawai" value="Tenaga Kependidikan">
              
              <div class="mb-3">
                  <label class="form-label">NIP / NIKKI / NIK</label>
                  <input type="text" name="nip" class="form-control" required placeholder="Masukkan NIP/NIK">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">NRK (Opsional)</label>
                      <input type="text" name="nrk" class="form-control" placeholder="Masukkan NRK">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">NUPTK (Opsional)</label>
                      <input type="text" name="nuptk" class="form-control" maxlength="18" pattern="\d*" title="Hanya angka" placeholder="Masukkan NUPTK">
                  </div>
              </div>
              <div class="mb-3">
                  <label class="form-label">Nama Pegawai</label>
                  <input type="text" name="nama_pegawai" class="form-control" required placeholder="Nama lengkap pegawai">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Tempat Lahir</label>
                      <input type="text" name="tempat" class="form-control" required>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Tanggal Lahir</label>
                      <input type="date" name="tanggal" class="form-control" required>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Status Pegawai</label>
                      <select name="status_pegawai" id="status_pegawai_tendik" class="form-select" required>
                        <option value="PNS">PNS</option>
                        <option value="PPPK">PPPK</option>
                        <option value="PPPK Paruh Waktu">PPPK Paruh Waktu</option>
                        <option value="KKI">KKI</option>
                        <option value="HONORER">HONORER</option>
                    </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Golongan / Pangkat</label>
                      <select name="pangkat_golongan" id="pangkat_golongan_tendik" class="form-select" required>
                          <option value="I.a (Juru Muda)">I.a (Juru Muda)</option>
                          <option value="I.b (Juru Muda Tingkat I)">I.b (Juru Muda Tingkat I)</option>
                          <option value="I.c (Juru Muda)">I.c (Juru)</option>
                          <option value="I.d (Juru Tingkat I)">I.d (Juru Tingkat I)</option>
                          <option value="II.a (Pengatur Muda)">II.a (Pengatur Muda)</option>
                          <option value="II.b (Pengatur Muda Tingkat I)">II.b (Pengatur Muda Tingkat I)</option>
                          <option value="II.c (Pengatur)">II.c (Pengatur)</option>
                          <option value="II.d (Pengatur Tingkat I)">II.d (Pengatur Tingkat I)</option>
                          <option value="III.a (Penata Muda)">III.a (Penata Muda)</option>
                          <option value="III.b (Penata Muda Tingkat I)">III.b (Penata Muda Tingkat I)</option>
                          <option value="III.c (Penata)">III.c (Penata)</option>
                          <option value="III.d (Penata Tingkat I)">III.d (Penata Tingkat I)</option>
                          <option value="IV.a (Pembina)">IV.a (Pembina)</option>
                          <option value="IV.b (Pembina Tingkat I)">IV.b (Pembina Tingkat I)</option>
                          <option value="IV.c (Pembina Utama Muda)">IV.c (Pembina Utama Muda)</option>
                          <option value="IV.d (Pembina Utama Madya)">IV.d (Pembina Utama Madya)</option>
                          <option value="IV.e (Pembina Utama)">IV.e (Pembina Utama)</option>
                          <option value="IX (Ahli Pertama)">IX (Ahli Pertama)</option>
                          <option value="X (Ahli Pertama)">X (Ahli Pertama)</option>
                          <option value="Non ASN">Non ASN</option>
                      </select>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-4 mb-3">
                      <label class="form-label">Jabatan</label>
                      <select name="jabatan" class="form-select" required>
                          <option value="" disabled selected>-- Pilih Jabatan --</option>
                          <option value="Kepala Satuan Pelaksana">Kepala Satuan Pelaksana</option>
                          <option value="Tenaga Administrasi">Tenaga Administrasi</option>
                          <option value="Tenaga Kebersihan">Tenaga Kebersihan</option>
                          <option value="Tenaga Keamanan">Tenaga Keamanan</option>
                          <option value="Penjaga Sekolah">Penjaga Sekolah</option>
                      </select>
                  </div>
                  <div class="col-md-4 mb-3">
                      <label class="form-label">TMT</label>
                      <input type="date" name="tmt" class="form-control" required>
                  </div>
                  <div class="col-md-4 mb-3">
                      <label class="form-label">TMT Pangkat Terakhir</label>
                      <input type="date" name="tmt_pangkat" class="form-control">
                  </div>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="tambah_pegawai_manual" class="btn" style="background: var(--navy-blue); color: var(--gold);">Simpan Tendik</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL UPLOAD EXCEL PENDIDIK -->
<!-- ========================================== -->
<div class="modal fade" id="modalUploadPendidik" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-upload"></i> Upload Data Tenaga Pendidik</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label">Pilih File Excel/CSV (Format Pendidik)</label>
                  <input type="file" name="file_excel_pendidik" class="form-control" accept=".csv" required>
                  <small class="text-muted">Pastikan format kolom (.csv) sesuai dengan template Tenaga Pendidik.</small>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="import_pendidik" class="btn" style="background: var(--navy-blue); color: var(--gold);">Upload & Sinkronkan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL UPLOAD EXCEL TENDIK -->
<!-- ========================================== -->
<div class="modal fade" id="modalUploadTendik" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-upload"></i> Upload Data Tenaga Kependidikan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label">Pilih File Excel/CSV (Format Tendik)</label>
                  <input type="file" name="file_excel_tendik" class="form-control" accept=".csv" required>
                  <small class="text-muted">Pastikan format kolom (.csv) sesuai dengan template Tenaga Kependidikan.</small>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="import_tendik" class="btn" style="background: var(--navy-blue); color: var(--gold);">Upload & Sinkronkan</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH AKTIVITAS BANK SAMPAH -->
<!-- ========================================== -->
<div class="modal fade" id="modalTambahAktivitas" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background: var(--navy-blue); color: var(--gold);">
        <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Tambah Aktivitas Bank Sampah</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <!-- Pastikan enctype tetap ada agar fungsi upload gambar tidak rusak -->
      <form method="POST" enctype="multipart/form-data">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label fw-bold small">Kategori Sampah</label>
                  <select name="kategori_sampah" class="form-select" required>
                      <option value="Organik">Organik (Hijau)</option>
                      <option value="Plastik">Plastik/Anorganik (Oranye)</option>
                      <option value="Residu">Residu/Pengepul (Kuning)</option>
                  </select>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Judul Kegiatan</label>
                  <input type="text" name="judul_kegiatan" class="form-control" placeholder="Cth: Sortir Botol PET" required>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Deskripsi Singkat</label>
                  <textarea name="deskripsi_kegiatan" class="form-control" rows="2" placeholder="Jelaskan detail kegiatan..." required></textarea>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Waktu / Jadwal</label>
                  <input type="text" name="waktu_kegiatan" class="form-control" placeholder="Cth: Jumat, 13:00 - 15:00" required>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Upload Galeri Kegiatan</label>
                  <input type="file" name="foto_galeri[]" class="form-control" accept="image/jpeg, image/png, image/jpg" multiple>
                  <small class="text-muted" style="font-size: 11px;">*Bisa upload lebih dari 1 foto. Tahan tombol <b>CTRL</b> saat memilih gambar.</small>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" name="tambah_jadwal_sampah" class="btn" style="background: var(--navy-blue); color: var(--gold);">
                <i class="fas fa-save"></i> Simpan Aktivitas
            </button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('main-content').classList.toggle('expanded');
    });

// GANTIKAN FUNGSI setup Golongan Auto Fill LAMA DENGAN KODE INI:
document.addEventListener("DOMContentLoaded", function() {
    const golonganPNS = ['IV.e (Pembina Utama)','IV.d (Pembina Utama Madya)','IV.c (Pembina Utama Muda)','IV.b (Pembina Tingkat I)','IV.a (Pembina)','III.d (Penata Tingkat I)','III.c (Penata)','III.b (Penata Muda Tingkat I)','III.a (Penata Muda)','II.d (Pengatur Tingkat I)','II.c (Pengatur)','II.b (Pengatur Muda Tingkat I)','II.a (Pengatur Muda)','I.d (Juru Tingkat I)','I.c (Juru Muda)','I.b (Juru Muda Tingkat I)','I.a (Juru Muda)'];
    const golonganPPPK = ['IX (Ahli Pertama)', 'X (Ahli Pertama)'];
    const golonganNonASN = ['Non ASN'];

    // Terapkan ke semua dropdown status pegawai (Pendidik & Tendik, form Tambah & Edit)
    const statusSelects = document.querySelectorAll('select[name="status_pegawai"]');
    
    statusSelects.forEach(function(statusEl) {
        const form = statusEl.closest('form');
        if (form) {
            const golonganEl = form.querySelector('select[name="pangkat_golongan"]');
            
            if (golonganEl) {
                function updateOptions() {
                    // Gunakan trim() untuk menghindari error jika ada spasi berlebih pada data dari database
                    const status = statusEl.value.trim();
                    let options = [];
                    
                    if (status === 'PNS') options = golonganPNS;
                    else if (status === 'PPPK') options = golonganPPPK;
                    else if (status === 'PPPK Paruh Waktu' || status === 'KKI' || status === 'HONORER') options = golonganNonASN;
                    
                    // Pindahkan definisi initialValue ke dalam fungsi agar selalu dinamis saat dipanggil
                    const currentInitial = golonganEl.getAttribute('data-initial') || golonganEl.value;
                    
                    // PERBAIKAN: Bangun string HTML secara utuh terlebih dahulu, JANGAN gunakan innerHTML += di dalam loop
                    let htmlOptions = '<option value="" disabled selected>-- Pilih Golongan / Pangkat --</option>';
                    options.forEach(opt => {
                        const isSelected = (opt === currentInitial) ? 'selected' : '';
                        htmlOptions += `<option value="${opt}" ${isSelected}>${opt}</option>`;
                    });
                    
                    // Terapkan string yang sudah jadi ke dalam select (Mencegah bug dropdown kosong di browser)
                    golonganEl.innerHTML = htmlOptions;
                }

                // Panggil saat modal pertama kali dibuka/halaman diload
                updateOptions();

                // Panggil setiap kali opsi status diganti oleh pengguna
                statusEl.addEventListener('change', function() {
                    golonganEl.removeAttribute('data-initial'); // Hapus state awal agar bisa mengikuti status baru murni
                    updateOptions();
                });
            }
        }
    });
});

    var triggerTabList = [].slice.call(document.querySelectorAll('#adminTabs a'));
    triggerTabList.forEach(function (triggerEl) {
      var tabTrigger = new bootstrap.Tab(triggerEl);
      triggerEl.addEventListener('click', function (event) {
        event.preventDefault();
        tabTrigger.show();
        localStorage.setItem('admin_last_tab', '#' + triggerEl.id);
      });
    });

    <?php if(isset($_SESSION['alert'])): ?>
        Swal.fire({ icon: '<?= $_SESSION['alert']['type'] ?>', title: '<?= $_SESSION['alert']['title'] ?>', text: '<?= $_SESSION['alert']['text'] ?>', confirmButtonColor: '#0A192F' });
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    // SCRIPT PAGINASI & SHOW DATA SISWA (DIPERSINGKAT DENGAN ELLIPSIS / WINDOWING)
    const tbodyS = document.getElementById('tbodySiswa');
    if (tbodyS) {
        const rowsS = Array.from(tbodyS.querySelectorAll('tr')); 
        const limitSelectS = document.getElementById('limitSiswa');
        const paginationContainerS = document.getElementById('paginationSiswa');
        
        let currentPageS = 1;
        let rowsPerPageS = parseInt(limitSelectS.value);

        function displayRowsS() {
            if (limitSelectS.value === 'all') {
                rowsPerPageS = rowsS.length;
                currentPageS = 1;
            } else {
                rowsPerPageS = parseInt(limitSelectS.value);
            }

            const totalPages = Math.ceil(rowsS.length / rowsPerPageS) || 1;
            if (currentPageS > totalPages) currentPageS = totalPages;

            const start = (currentPageS - 1) * rowsPerPageS;
            const end = start + rowsPerPageS;

            rowsS.forEach((row, index) => {
                if (row.cells.length === 1 && row.cells[0].colSpan > 5) {
                    row.style.display = '';
                    return;
                }
                
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            renderPaginationS(totalPages);
        }

        function renderPaginationS(totalPages) {
            paginationContainerS.innerHTML = '';
            if (totalPages <= 1) return;

            const nav = document.createElement('nav');
            const ul = document.createElement('ul');
            ul.className = 'pagination pagination-sm mb-0';

            // Tombol Prev («)
            const liPrev = document.createElement('li');
            liPrev.className = `page-item ${currentPageS === 1 ? 'disabled' : ''}`;
            liPrev.innerHTML = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&laquo;</a>`;
            liPrev.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageS > 1) { currentPageS--; displayRowsS(); }
            });
            ul.appendChild(liPrev);

            // Helper tambah tombol halaman
            function addPageButton(i) {
                const li = document.createElement('li');
                li.className = `page-item ${currentPageS === i ? 'active' : ''}`;
                li.innerHTML = `<a class="page-link" href="javascript:void(0)" style="${currentPageS === i ? 'background-color: var(--navy-blue); border-color: var(--navy-blue); color: var(--gold);' : 'color: var(--navy-blue);'}">${i}</a>`;
                li.addEventListener('click', (e) => {
                    e.preventDefault();
                    currentPageS = i;
                    displayRowsS();
                });
                ul.appendChild(li);
            }

            // Helper tambah titik-titik (...)
            function addEllipsis() {
                const li = document.createElement('li');
                li.className = 'page-item disabled';
                li.innerHTML = `<span class="page-link" style="color: var(--navy-blue);">...</span>`;
                ul.appendChild(li);
            }

            // Logika Jendela Halaman (Windowing) yang Dipersingkat
            let maxVisible = 5;
            let startPage = Math.max(1, currentPageS - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);

            if (endPage - startPage + 1 < maxVisible) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }

            if (startPage > 1) {
                addPageButton(1);
                if (startPage > 2) {
                    addEllipsis();
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                addPageButton(i);
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    addEllipsis();
                }
                addPageButton(totalPages);
            }

            // Tombol Next (»)
            const liNext = document.createElement('li');
            liNext.className = `page-item ${currentPageS === totalPages ? 'disabled' : ''}`;
            liNext.innerHTML = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&raquo;</a>`;
            liNext.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageS < totalPages) { currentPageS++; displayRowsS(); }
            });
            ul.appendChild(liNext);

            nav.appendChild(ul);
            paginationContainerS.appendChild(nav);
        }

        limitSelectS.addEventListener('change', () => {
            currentPageS = 1;
            displayRowsS();
        });

        displayRowsS();
    }

    const tbodyP = document.getElementById('tbodyPendidik');
    if (tbodyP) {
        const rowsP = Array.from(tbodyP.querySelectorAll('tr')); 
        const limitSelectP = document.getElementById('limitPendidik');
        const paginationContainerP = document.getElementById('paginationPendidik');
        
        let currentPageP = 1;
        let rowsPerPageP = parseInt(limitSelectP.value);

        function displayRowsP() {
            if (limitSelectP.value === 'all') {
                rowsPerPageP = rowsP.length;
                currentPageP = 1;
            } else {
                rowsPerPageP = parseInt(limitSelectP.value);
            }

            const totalPages = Math.ceil(rowsP.length / rowsPerPageP) || 1;
            if (currentPageP > totalPages) currentPageP = totalPages;

            const start = (currentPageP - 1) * rowsPerPageP;
            const end = start + rowsPerPageP;

            rowsP.forEach((row, index) => {
                if (row.cells.length === 1 && row.cells[0].colSpan > 5) {
                    row.style.display = '';
                    return;
                }
                
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            renderPaginationP(totalPages);
        }

        function renderPaginationP(totalPages) {
            paginationContainerP.innerHTML = '';
            if (totalPages <= 1) return;

            const nav = document.createElement('nav');
            const ul = document.createElement('ul');
            ul.className = 'pagination pagination-sm mb-0';

            const liPrev = document.createElement('li');
            liPrev.className = `page-item ${currentPageP === 1 ? 'disabled' : ''}`;
            liPrev.innerHTML = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&laquo;</a>`;
            liPrev.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageP > 1) { currentPageP--; displayRowsP(); }
            });
            ul.appendChild(liPrev);

            for (let i = 1; i <= totalPages; i++) {
                const li = document.createElement('li');
                li.className = `page-item ${currentPageP === i ? 'active' : ''}`;
                li.innerHTML = `<a class="page-link" href="javascript:void(0)" style="${currentPageP === i ? 'background-color: var(--navy-blue); border-color: var(--navy-blue); color: var(--gold);' : 'color: var(--navy-blue);'}">${i}</a>`;
                li.addEventListener('click', (e) => {
                    e.preventDefault();
                    currentPageP = i;
                    displayRowsP();
                });
                ul.appendChild(li);
            }

            const liNext = document.createElement('li');
            liNext.className = `page-item ${currentPageP === totalPages ? 'disabled' : ''}`;
            liNext.html = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&raquo;</a>`;
            liNext.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageP < totalPages) { currentPageP++; displayRowsP(); }
            });
            ul.appendChild(liNext);

            nav.appendChild(ul);
            paginationContainerP.appendChild(nav);
        }

        limitSelectP.addEventListener('change', () => {
            currentPageP = 1;
            displayRowsP();
        });

        displayRowsP();
    }

    // =========================================================================
    // SCRIPT PAGINASI & SHOW DATA SPREADSHEET BANK SAMPAH
    // =========================================================================
    const tbodySS = document.getElementById('tbodySpreadsheet');
    if (tbodySS) {
        const rowsSS = Array.from(tbodySS.querySelectorAll('tr')); 
        const limitSelectSS = document.getElementById('limitSpreadsheet');
        const paginationContainerSS = document.getElementById('paginationSpreadsheet');
        
        let currentPageSS = 1;
        let rowsPerPageSS = parseInt(limitSelectSS.value);

        function displayRowsSS() {
            if (limitSelectSS.value === 'all') {
                rowsPerPageSS = rowsSS.length;
                currentPageSS = 1;
            } else {
                rowsPerPageSS = parseInt(limitSelectSS.value);
            }

            const totalPages = Math.ceil(rowsSS.length / rowsPerPageSS) || 1;
            if (currentPageSS > totalPages) currentPageSS = totalPages;

            const start = (currentPageSS - 1) * rowsPerPageSS;
            const end = start + rowsPerPageSS;

            rowsSS.forEach((row, index) => {
                // Biarkan baris pesan kosong (colspan > 5) tetap tampil
                if (row.cells.length === 1 && row.cells[0].colSpan > 5) {
                    row.style.display = '';
                    return;
                }
                
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            renderPaginationSS(totalPages);
        }

        function renderPaginationSS(totalPages) {
            paginationContainerSS.innerHTML = '';
            if (totalPages <= 1) return;

            const nav = document.createElement('nav');
            const ul = document.createElement('ul');
            ul.className = 'pagination pagination-sm mb-0';

            // Tombol Prev («)
            const liPrev = document.createElement('li');
            liPrev.className = `page-item ${currentPageSS === 1 ? 'disabled' : ''}`;
            liPrev.innerHTML = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&laquo;</a>`;
            liPrev.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageSS > 1) { currentPageSS--; displayRowsSS(); }
            });
            ul.appendChild(liPrev);

            // Helper tambah tombol halaman
            function addPageButton(i) {
                const li = document.createElement('li');
                li.className = `page-item ${currentPageSS === i ? 'active' : ''}`;
                li.innerHTML = `<a class="page-link" href="javascript:void(0)" style="${currentPageSS === i ? 'background-color: var(--navy-blue); border-color: var(--navy-blue); color: var(--gold);' : 'color: var(--navy-blue);'}">${i}</a>`;
                li.addEventListener('click', (e) => {
                    e.preventDefault();
                    currentPageSS = i;
                    displayRowsSS();
                });
                ul.appendChild(li);
            }

            // Helper tambah titik-titik (...)
            function addEllipsis() {
                const li = document.createElement('li');
                li.className = 'page-item disabled';
                li.innerHTML = `<span class="page-link" style="color: var(--navy-blue);">...</span>`;
                ul.appendChild(li);
            }

            // Logika Jendela Halaman (Windowing)
            let maxVisible = 5;
            let startPage = Math.max(1, currentPageSS - Math.floor(maxVisible / 2));
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
            liNext.className = `page-item ${currentPageSS === totalPages ? 'disabled' : ''}`;
            liNext.innerHTML = `<a class="page-link" href="javascript:void(0)" style="color: var(--navy-blue);">&raquo;</a>`;
            liNext.addEventListener('click', (e) => {
                e.preventDefault();
                if (currentPageSS < totalPages) { currentPageSS++; displayRowsSS(); }
            });
            ul.appendChild(liNext);

            nav.appendChild(ul);
            paginationContainerSS.appendChild(nav);
        }

        // Jalankan saat dropdown "Show" diubah
        limitSelectSS.addEventListener('change', () => {
            currentPageSS = 1;
            displayRowsSS();
        });

        // Inisialisasi saat pertama kali dimuat
        displayRowsSS();
    }
</script>
</body>
</html>