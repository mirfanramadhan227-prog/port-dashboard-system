<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'config.php';

// Fungsi konversi format tanggal ke YYYY-MM-DD HH:MM:SS untuk MySQL
function parseToMysqlDatetime($dateStr) {
    if (empty($dateStr)) return NULL;
    $time = strtotime($dateStr);
    if ($time === false) return NULL;
    return date('Y-m-d H:i:s', $time);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    if (!empty($_FILES['excel_file']['name'])) {
        $csv_file = $_FILES['excel_file']['tmp_name'];
        
        $handle = fopen($csv_file, "r");
        if ($handle !== FALSE) {
            // Lewati header
            fgetcsv($handle, 1000, ",");
            
            $imported_count = 0;
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) < 10) continue;

                $name         = trim($data[0] ?? '');
                $so_number    = trim($data[1] ?? '');
                $vessel_type  = trim($data[2] ?? '');
                $operator     = trim($data[3] ?? '');
                $agent_pbm    = trim($data[4] ?? '');
                $loa_lbp      = trim($data[5] ?? '');
                $gt_nt        = trim($data[6] ?? '');
                $draft        = trim($data[7] ?? '');
                $flag         = trim($data[8] ?? '');
                $port_name    = trim($data[9] ?? '');
                $jetty_name   = trim($data[10] ?? '');
                
                // Konversi tanggal agar sesuai standar MySQL
                $eta          = parseToMysqlDatetime($data[11] ?? '');
                $etb          = parseToMysqlDatetime($data[12] ?? '');
                $ata_atb      = parseToMysqlDatetime($data[13] ?? '');
                $etd          = parseToMysqlDatetime($data[14] ?? '');
                
                $op_type      = trim($data[15] ?? 'LOADING');
                $cargo_type   = trim($data[16] ?? '');
                $target_vol   = floatval($data[17] ?? 0);

                if (empty($name) || empty($port_name)) continue;

                // 1. Tangani Pelabuhan
                $stmt_p = $conn->prepare("SELECT id FROM ports WHERE name = ?");
                $stmt_p->bind_param("s", $port_name);
                $stmt_p->execute();
                $res_p = $stmt_p->get_result();
                if ($row_p = $res_p->fetch_assoc()) {
                    $port_id = $row_p['id'];
                } else {
                    $stmt_ins_p = $conn->prepare("INSERT INTO ports (name) VALUES (?)");
                    $stmt_ins_p->bind_param("s", $port_name);
                    $stmt_ins_p->execute();
                    $port_id = $stmt_ins_p->insert_id;
                }

                // 2. Tangani Jetty
                $db_jetty_id = NULL;
                $main_status = "BERTHING";
                if (strcasecmp($jetty_name, 'WAITING LIST') === 0 || empty($jetty_name)) {
                    $main_status = "WAITING_LIST";
                    $db_jetty_id = NULL;
                } else {
                    $stmt_j = $conn->prepare("SELECT id FROM jetties WHERE name = ?");
                    $stmt_j->bind_param("s", $jetty_name);
                    $stmt_j->execute();
                    $res_j = $stmt_j->get_result();
                    if ($row_j = $res_j->fetch_assoc()) {
                        $db_jetty_id = $row_j['id'];
                    } else {
                        $stmt_ins_j = $conn->prepare("INSERT INTO jetties (name) VALUES (?)");
                        $stmt_ins_j->bind_param("s", $jetty_name);
                        $stmt_ins_j->execute();
                        $db_jetty_id = $stmt_ins_j->insert_id;
                    }
                }

                // 3. Simpan ke tabel vessels
                $stmt_v = $conn->prepare("INSERT INTO vessels (name, vessel_type, operator, agent_pbm, loa_lbp, gt_nt, draft, flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_v->bind_param("ssssssss", $name, $vessel_type, $operator, $agent_pbm, $loa_lbp, $gt_nt, $draft, $flag);
                if ($stmt_v->execute()) {
                    $vessel_id = $stmt_v->insert_id;

                    // 4. Simpan ke tabel vessel_operations dengan berthing_time yang valid
                    $stmt_op = $conn->prepare("INSERT INTO vessel_operations (vessel_id, port_id, jetty_id, main_status, operation_type, cargo_type, target_volume_tons, eta, etb, ata_atb, etd, berthing_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt_op->bind_param("iiisssdsssss", $vessel_id, $port_id, $db_jetty_id, $main_status, $op_type, $cargo_type, $target_vol, $eta, $etb, $ata_atb, $etd, $ata_atb);
                    $stmt_op->execute();
                    
                    $imported_count++;
                }
            }
            fclose($handle);
            
            header("Location: form.php?success=1");
            exit();
        }
    }
}
header("Location: form.php");
exit();
?>