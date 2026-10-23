<?php
include 'config.php';

// Masukkan username dan password baru yang Anda inginkan di sini:
$username = 'master';
$password_polos = 'password123'; 
$role = 'master';

// Enkripsi otomatis sesuai dengan server lokal Anda
$password_terenkripsi = password_hash($password_polos, PASSWORD_DEFAULT);

// Masukkan atau perbarui ke database lokal
$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password = ?, role = ?");
$stmt->bind_param("sssss", $username, $password_terenkripsi, $role, $password_terenkripsi, $role);

if ($stmt->execute()) {
    echo "<h3 style='color:green;'>Berhasil! Akun <b>$username</b> dengan password <b>$password_polos</b> telah di-generate dan di-update ke database.</h3>";
    echo "<p>Silakan <a href='login'>kembali ke halaman login</a>.</p>";
} else {
    echo "Gagal: " . $stmt->error;
}
?>