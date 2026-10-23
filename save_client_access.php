<?php
session_start();
include 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Validasi hak akses (hanya admin/master)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'master'])) {
    die("Akses ditolak.");
}

$operation_id = isset($_GET['operation_id']) ? intval($_GET['operation_id']) : 0;
$selected_clients = isset($_GET['client_ids']) && is_array($_GET['client_ids']) ? $_GET['client_ids'] : [];

$port_id = isset($_GET['port_id']) ? intval($_GET['port_id']) : 0;
$month = isset($_GET['month']) ? intval($_GET['month']) : '';
$year = isset($_GET['year']) ? intval($_GET['year']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : '';

if ($operation_id > 0) {
    // Ambil nama kapal berdasarkan operation_id
    $v_stmt = $conn->prepare("SELECT v.name FROM vessel_operations vo JOIN vessels v ON vo.vessel_id = v.id WHERE vo.id = ?");
    $v_stmt->bind_param("i", $operation_id);
    $v_stmt->execute();
    $v_res = $v_stmt->get_result()->fetch_assoc();
    $vessel_name = $v_res ? $v_res['name'] : 'Kapal';
    $v_stmt->close();

    // 1. Hapus semua hak akses lama
    $stmt_del = $conn->prepare("DELETE FROM client_vessel_access WHERE operation_id = ?");
    $stmt_del->bind_param("i", $operation_id);
    $stmt_del->execute();
    $stmt_del->close();

    $client_usernames = [];

    // 2. Masukkan hak akses baru & kumpulkan username client
    if (!empty($selected_clients)) {
        $stmt_ins = $conn->prepare("INSERT INTO client_vessel_access (user_id, operation_id) VALUES (?, ?)");
        $u_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");

        foreach ($selected_clients as $client_id) {
            $c_id = intval($client_id);
            if ($c_id > 0) {
                $stmt_ins->bind_param("ii", $c_id, $operation_id);
                $stmt_ins->execute();

                // Ambil username client
                $u_stmt->bind_param("i", $c_id);
                $u_stmt->execute();
                $u_res = $u_stmt->get_result()->fetch_assoc();
                if ($u_res) {
                    $client_usernames[] = $u_res['username'];
                }
            }
        }
        $stmt_ins->close();
        $u_stmt->close();
    }

    // Buat string nama client yang diberi akses (misal: "PTABC" atau "PTABC, Client2")
    $client_str = !empty($client_usernames) ? implode(', ', $client_usernames) : 'Tidak ada';

    // Susun URL redirect dengan membawa pesan detail
    $redirect_url = "index.php?status=success_access&vessel=" . urlencode($vessel_name) . "&clients=" . urlencode($client_str);
    if ($port_id > 0) $redirect_url .= "&port_id=" . $port_id;
    if ($month !== '') $redirect_url .= "&month=" . $month;
    if ($year !== '') $redirect_url .= "&year=" . $year;
    if ($sort !== '') $redirect_url .= "&sort=" . urlencode($sort);

    header("Location: " . $redirect_url . "#vesselsTable");
    exit();
} else {
    die("Error: ID Operasi Kapal tidak valid.");
}
?>