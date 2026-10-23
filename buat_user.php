<?php
include 'config.php';

// Tentukan data akun baru yang ingin dibuat
$username_baru = 'operator1'; // Ganti dengan username yang diinginkan
$password_polos = 'Password123!'; // Ganti dengan password yang diinginkan
$role_baru = 'viewer'; // Pilih: 'admin' atau 'viewer'

// Enkripsi password dengan aman
$password_terenkripsi = password_hash($password_polos, PASSWORD_DEFAULT);

// Masukkan ke database
$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username_baru, $password_terenkripsi, $role_baru);

if ($stmt->execute()) {
    echo "<h3>Berhasil!</h3>";
    echo "Akun dengan username <b>$username_baru</b> dan role <b>$role_baru</b> berhasil dibuat.";
} else {
    echo "Gagal membuat akun (mungkin username sudah terdaftar): " . $conn->error;
}
?>