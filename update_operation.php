<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op_id = intval($_POST['operation_id']);
    $action = $_POST['action'] ?? ''; // 'edit' atau 'submit'

    if ($action === 'edit') {
        $jetty_id = !empty($_POST['jetty_id']) ? intval($_POST['jetty_id']) : NULL;
        $cargo_type = trim($_POST['cargo_type']);
        $eta = !empty($_POST['eta']) ? $_POST['eta'] : NULL;
        $etb = !empty($_POST['etb']) ? $_POST['etb'] : NULL;
        $ata_atb = !empty($_POST['ata_atb']) ? $_POST['ata_atb'] : NULL;
        $etd = !empty($_POST['etd']) ? $_POST['etd'] : NULL;

        $stmt_check = $conn->prepare("SELECT main_status FROM vessel_operations WHERE id = ?");
        $stmt_check->bind_param("i", $op_id);
        $stmt_check->execute();
        $curr_status = $stmt_check->get_result()->fetch_assoc()['main_status'] ?? '';

        if ($jetty_id && $curr_status === 'WAITING_LIST') {
            $stmt = $conn->prepare("UPDATE vessel_operations SET jetty_id = ?, cargo_type = ?, eta = ?, etb = ?, ata_atb = ?, etd = ?, berthing_time = ?, main_status = 'BERTHING' WHERE id = ?");
            $stmt->bind_param("issssssi", $jetty_id, $cargo_type, $eta, $etb, $ata_atb, $etd, $ata_atb, $op_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("UPDATE vessel_operations SET jetty_id = ?, cargo_type = ?, eta = ?, etb = ?, ata_atb = ?, etd = ?, berthing_time = ? WHERE id = ?");
            $stmt->bind_param("issssssi", $jetty_id, $cargo_type, $eta, $etb, $ata_atb, $etd, $ata_atb, $op_id);
            $stmt->execute();
        }

    } elseif ($action === 'submit') {
        $etd_now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE vessel_operations SET main_status = 'COMPLETED', departed_time = ?, etd = ? WHERE id = ?");
        $stmt->bind_param("ssi", $etd_now, $etd_now, $op_id);
        $stmt->execute();
    }

    $port_id = intval($_POST['port_id'] ?? 0);
    header("Location: index.php?port_id=" . $port_id);
    exit();
}
?>