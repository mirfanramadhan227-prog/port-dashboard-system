<?php
session_start();
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        header("Location: login?error=Username dan password tidak boleh kosong.");
        exit();
    }

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            
            // 1. Buat session ID baru yang aman untuk perangkat ini
            session_regenerate_id(true);
            $current_session_id = session_id();

            // 2. Simpan session_id baru ke database
            $update_ses = $conn->prepare("UPDATE users SET session_id = ? WHERE id = ?");
            $update_ses->bind_param("si", $current_session_id, $row['id']);
            $update_ses->execute();

            // 3. Set session pengguna
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['last_activity'] = time();

            header("Location: /");
            exit();
        } else {
            header("Location: login?error=Password yang Anda masukkan salah.");
            exit();
        }
    } else {
        header("Location: login?error=Username tidak ditemukan.");
        exit();
    }
} else {
    header("Location: login");
    exit();
}
?>