<?php
session_start();

// Debugging sementara: Aktifkan error reporting agar terlihat jika ada masalah
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'config.php';

$success_msg = '';
$error_msg = '';

// Cek apakah user sudah login sebagai master
$current_user = $_SESSION['username'] ?? 'Belum Login';
if ($current_user !== 'master') {
    // Jika bukan master, beritahu di layar agar jelas
    $error_msg = "Akses ditolak! Anda sedang login sebagai <b>" . htmlspecialchars($current_user) . "</b>. Hanya akun dengan username <b>'master'</b> yang dapat menambah user.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $current_user === 'master') {
    $username = trim($_POST['username'] ?? '');
    $password_polos = trim($_POST['password'] ?? ''); 
    $role = trim($_POST['role'] ?? ''); 

    if (empty($username) || empty($password_polos) || empty($role)) {
        $error_msg = "Semua kolom wajib diisi!";
    } else {
        // Enkripsi password
        $password_terenkripsi = password_hash($password_polos, PASSWORD_DEFAULT);

        // Eksekusi query insert
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sss", $username, $password_terenkripsi, $role);

            if ($stmt->execute()) {
                $success_msg = "Akun baru <b>" . htmlspecialchars($username) . "</b> dengan role <b>" . ucfirst($role) . "</b> berhasil dibuat!";
            } else {
                $error_msg = "Gagal menyimpan ke database: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_msg = "Gagal menyiapkan database statement: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Akun Pengguna - TBI Port Monitoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --bs-body-bg: #f8f9fa; --primary-orange: #fd7e14; }
        .bg-orange-dark { background-color: #1a1a1a !important; border-bottom: 3px solid #fd7e14; }
        .btn-orange { background-color: #fd7e14; color: #fff; font-weight: 600; border: none; }
        .btn-orange:hover { background-color: #e86d0d; color: #fff; }
        .text-orange { color: #fd7e14 !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-orange-dark navbar-dark py-2 shadow-sm">
    <div class="container px-4">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fa-solid fa-ship text-orange me-2"></i>TBI PORT MONITORING</a>
        <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Dashboard</a>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            
            <?php if($success_msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if($error_msg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 p-4" style="border-radius: 12px; border-top: 5px solid #fd7e14 !important;">
                <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-user-plus text-orange me-2"></i>Buat Akun Baru</h4>
                <p class="text-muted small mb-3">Status Login Anda saat ini: <span class="badge bg-dark"><?= htmlspecialchars($current_user); ?></span></p>

                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" required placeholder="Contoh: PTRSE1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password (Teks Biasa)</label>
                        <input type="text" name="password" class="form-control" required placeholder="Contoh: sandiAman123">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Hak Akses (Role)</label>
                        <select name="role" class="form-select" required>
                            <option value="client">Client (Akses terbatas khusus kapal yang diizinkan)</option>
                            <option value="viewer">Viewer (Hanya bisa melihat dashboard & download Excel)</option>
                            <option value="admin">Admin (Akses penuh: input, edit, submit, & buat akun)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-orange w-100 py-2 shadow-sm"><i class="fa-solid fa-floppy-disk me-2"></i>Simpan & Enkripsi Akun</button>
                </form>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>