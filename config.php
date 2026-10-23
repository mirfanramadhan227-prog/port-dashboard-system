<?php
// Tampilkan error secara langsung di layar
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host     = "sql202.infinityfree.com";
$user     = "if0_43231229";
$password = "Ramadhan10533"; 
$database = "if0_43231229_port_db";

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}
?>