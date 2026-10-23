<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'master')) {
    header("Location: login");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vessel_id = intval($_POST['vessel_id']);
    $operation_id = intval($_POST['operation_id']);
    $port_id = intval($_POST['port_id']);

    // Data Ship Information
    $name = $_POST['name'];
    $port_of_registry = $_POST['port_of_registry'];
    $call_sign = $_POST['call_sign'];
    $official_number = $_POST['official_number'];
    $class_id = $_POST['class_id'];
    $vessel_type = $_POST['vessel_type'];
    $classification = $_POST['classification'];
    $imo_number = $_POST['imo_number'];
    $operator = $_POST['operator'];
    $owners = $_POST['owners'];
    $loa_lbp = $_POST['loa_lbp'];
    $gt_nt = $_POST['gt_nt'];

    // Data Cargo Information baru
    $shipper = $_POST['shipper'] ?? '';
    $consignee = $_POST['consignee'] ?? '';
    $notify_address = $_POST['notify_address'] ?? '';
    $port_of_loading = $_POST['port_of_loading'] ?? '';
    $port_of_discharging = $_POST['port_of_discharging'] ?? '';
    $quantity_on_bl = floatval($_POST['quantity_on_bl'] ?? 0);
    $bl_no = $_POST['bl_no'] ?? '';

    // Draft
    $draft_initial_fwd = $_POST['draft_initial_fwd'];
    $draft_initial_mid = $_POST['draft_initial_mid'];
    $draft_initial_aft = $_POST['draft_initial_aft'];
    $draft_initial_trim = $_POST['draft_initial_trim'];
    
    $draft_final_fwd = $_POST['draft_final_fwd'];
    $draft_final_mid = $_POST['draft_final_mid'];
    $draft_final_aft = $_POST['draft_final_aft'];
    $draft_final_trim = $_POST['draft_final_trim'];

    // Update tabel vessels
    $stmt = $conn->prepare("UPDATE vessels SET name=?, port_of_registry=?, call_sign=?, official_number=?, class_id=?, vessel_type=?, classification=?, imo_number=?, operator=?, owners=?, loa_lbp=?, gt_nt=?, shipper=?, consignee=?, notify_address=?, port_of_loading=?, port_of_discharging=?, quantity_on_bl=?, bl_no=? WHERE id=?");
    $stmt->bind_param("sssssssssssssssssdSI", $name, $port_of_registry, $call_sign, $official_number, $class_id, $vessel_type, $classification, $imo_number, $operator, $owners, $loa_lbp, $gt_nt, $shipper, $consignee, $notify_address, $port_of_loading, $port_of_discharging, $quantity_on_bl, $bl_no, $vessel_id);
    $stmt->execute();

    // Update draft di vessel_operations
    $stmt_op = $conn->prepare("UPDATE vessel_operations SET draft_initial_fwd=?, draft_initial_mid=?, draft_initial_aft=?, draft_initial_trim=?, draft_final_fwd=?, draft_final_mid=?, draft_final_aft=?, draft_final_trim=? WHERE id=?");
    $stmt_op->bind_param("ssssssssi", $draft_initial_fwd, $draft_initial_mid, $draft_initial_aft, $draft_initial_trim, $draft_final_fwd, $draft_final_mid, $draft_final_aft, $draft_final_trim, $operation_id);
    $stmt_op->execute();

    header("Location: index?port_id=" . $port_id);
    exit();
}