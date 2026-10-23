<?php
session_start();
include 'config.php';

echo "<h2>Session Info:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Daftar Users (Role Client):</h2>";
$res_u = $conn->query("SELECT id, username, role FROM users WHERE role = 'client'");
echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Username</th><th>Role</th></tr>";
while($u = $res_u->fetch_assoc()) {
    echo "<tr><td>{$u['id']}</td><td>{$u['username']}</td><td>{$u['role']}</td></tr>";
}
echo "</table>";

echo "<h2>Isi Tabel client_vessel_access:</h2>";
$res_a = $conn->query("SELECT * FROM client_vessel_access");
if ($res_a && $res_a->num_rows > 0) {
    echo "<table border='1' cellpadding='5'><tr><th>User ID</th><th>Operation ID</th></tr>";
    while($a = $res_a->fetch_assoc()) {
        echo "<tr><td>{$a['user_id']}</td><td>{$a['operation_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>Tabel client_vessel_access kosong!</p>";
}
?>