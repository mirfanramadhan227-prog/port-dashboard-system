<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vessel_name   = $_POST['vessel_name'];
    $imo_number    = $_POST['imo_number'];
    $status        = $_POST['status'];
    $operation_type= $_POST['operation_type'] ?? '';
    $cargo_type    = $_POST['cargo_type'] ?? '';
    $target_volume = $_POST['target_volume'] ?? 0;

    // 1. Cek / Tangani Pelabuhan
    $port_id = $_POST['port_id'];
    if ($port_id === 'other' && !empty($_POST['other_port_name'])) {
        $other_port = trim($_POST['other_port_name']);
        $stmt = $conn->prepare("SELECT port_id FROM ports WHERE name = ?");
        $stmt->bind_param("s", $other_port);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $port_id = $row['port_id'];
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO ports (name) VALUES (?)");
            $stmt_ins->bind_param("s", $other_port);
            $stmt_ins->execute();
            $port_id = $stmt_ins->insert_id;
        }
    }

    // 2. Cek / Tangani Jetty
    $jetty_id = $_POST['jetty_id'];
    if ($jetty_id === 'other' && !empty($_POST['other_jetty_name'])) {
        $other_jetty = trim($_POST['other_jetty_name']);
        $stmt = $conn->prepare("SELECT jetty_id FROM jetties WHERE name = ?");
        $stmt->bind_param("s", $other_jetty);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $jetty_id = $row['jetty_id'];
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO jetties (port_id, name) VALUES (?, ?)");
            $stmt_ins->bind_param("is", $port_id, $other_jetty);
            $stmt_ins->execute();
            $jetty_id = $stmt_ins->insert_id;
        }
    }

    $jetty_id_db = !empty($jetty_id) ? $jetty_id : NULL;

    // 3. Simpan Ke Tabel Vessels
    $stmt_v = $conn->prepare("INSERT INTO vessels (vessel_name, imo_number, port_id, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE port_id=?, status=?");
    $stmt_v->bind_param("ssisis", $vessel_name, $imo_number, $port_id, $status, $port_id, $status);
    $stmt_v->execute();
    $vessel_id = $stmt_v->insert_id ?: $conn->query("SELECT vessel_id FROM vessels WHERE imo_number='$imo_number'")->fetch_assoc()['vessel_id'];

    // 4. Simpan Operational Record
    if ($status === 'BERTHING') {
        $stmt_o = $conn->prepare("INSERT INTO vessel_operations (vessel_id, jetty_id, operation_type, cargo_type, target_volume) VALUES (?, ?, ?, ?, ?)");
        $stmt_o->bind_param("iissd", $vessel_id, $jetty_id_db, $operation_type, $cargo_type, $target_volume);
        $stmt_o->execute();
    }

    header("Location: index.php?status=success");
    exit();
}
?>