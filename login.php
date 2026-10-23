<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: /");
    exit();
}
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - TBI Port Monitoring System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --bs-body-bg: #1a1a1a; --primary-orange: #fd7e14; }
        body { background-color: #1a1a1a; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-card { border: none; border-radius: 12px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3); background: #ffffff; width: 100%; max-width: 400px; border-top: 5px solid #fd7e14; }
        .form-control { color: #212529 !important; background-color: #fff !important; }
        .form-control:focus { color: #212529 !important; background-color: #fff !important; border-color: #fd7e14; box-shadow: 0 0 0 0.25rem rgba(253, 126, 20, 0.25); }
        .btn-orange { background-color: #fd7e14; color: #fff; font-weight: 600; border: none; }
        .btn-orange:hover { background-color: #e86d0d; color: #fff; }
        .text-orange { color: #fd7e14 !important; }
    </style>
</head>
<body>

<div class="container px-3">
    <div class="card login-card p-4 mx-auto">
        <div class="text-center mb-4">
            <i class="fa-solid fa-ship text-orange fs-1 mb-2"></i>
            <h4 class="fw-bold text-dark">TBI PORT MONITORING</h4>
            <p class="text-muted small">Silakan masuk menggunakan akun Anda</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger py-2 small" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $error; ?>
            </div>
        <?php endif; ?>

        <form action="process_login" method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold small text-secondary">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-user"></i></span>
                    <input type="text" name="username" class="form-control" required placeholder="Masukkan username">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" class="form-control" required placeholder="Masukkan password">
                </div>
            </div>
            <button type="submit" class="btn btn-orange w-100 py-2 shadow-sm"><i class="fa-solid fa-right-to-bracket me-2"></i>Masuk Sistem</button>
        </form>
    </div>
    <div class="text-center mt-3 text-secondary small">
        &copy; 2026 Tribhakti Port Monitoring System
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>