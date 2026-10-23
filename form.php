<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'config.php';

$success_msg = isset($_GET['success']) ? "Data berhasil disimpan / diimpor!" : '';
$error_msg = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_manual'])) {
    $name = trim($_POST['name']);
    $vessel_type = $_POST['vessel_type'];
    if ($vessel_type === 'Lainnya' && !empty(trim($_POST['custom_vessel_type']))) {
        $vessel_type = trim($_POST['custom_vessel_type']);
    }

    $operator = trim($_POST['operator']);
    $agent_pbm = trim($_POST['agent_pbm']);
    $loa_lbp = trim($_POST['loa_lbp']);
    $gt_nt = trim($_POST['gt_nt']);
    $draft = trim($_POST['draft']);
    $flag = trim($_POST['flag']);
    
    $port_id = $_POST['port_id'];
    if ($port_id === 'Lainnya' && !empty(trim($_POST['custom_port']))) {
        $new_port_name = trim($_POST['custom_port']);
        $stmt_p = $conn->prepare("INSERT INTO ports (name) VALUES (?)");
        $stmt_p->bind_param("s", $new_port_name);
        $stmt_p->execute();
        $port_id = $stmt_p->insert_id;
    } else {
        $port_id = intval($port_id);
    }

    // Penanganan Jetty & Status
    $jetty_input = $_POST['jetty_id'];
    $db_jetty_id = NULL;
    $main_status = "BERTHING";

    if ($jetty_input === 'WAITING_LIST') {
        $main_status = "WAITING_LIST";
        $db_jetty_id = NULL;
    } else {
        $jetty_name_val = $jetty_input;
        if ($jetty_input === 'Lainnya' && !empty(trim($_POST['custom_jetty']))) {
            $jetty_name_val = trim($_POST['custom_jetty']);
        }

        $stmt_j = $conn->prepare("SELECT id FROM jetties WHERE name = ?");
        $stmt_j->bind_param("s", $jetty_name_val);
        $stmt_j->execute();
        $res_j = $stmt_j->get_result();
        if ($row_j = $res_j->fetch_assoc()) {
            $db_jetty_id = $row_j['id'];
        } else {
            $stmt_ins_j = $conn->prepare("INSERT INTO jetties (name) VALUES (?)");
            $stmt_ins_j->bind_param("s", $jetty_name_val);
            $stmt_ins_j->execute();
            $db_jetty_id = $stmt_ins_j->insert_id;
        }
    }

    $operation_type = $_POST['operation_type'];
    $cargo_type = $_POST['cargo_type'];
    if ($cargo_type === 'Lainnya' && !empty(trim($_POST['custom_cargo']))) {
        $cargo_type = trim($_POST['custom_cargo']);
    }

    $target_volume = ($main_status === 'WAITING_LIST') ? 0 : ($_POST['target_volume'] ?: 0);
    
    // Waktu Maritim
    $eta = !empty($_POST['eta']) ? $_POST['eta'] : NULL;
    $etb = !empty($_POST['etb']) ? $_POST['etb'] : NULL;
    $ata_atb = ($main_status === 'WAITING_LIST') ? NULL : (!empty($_POST['ata_atb']) ? $_POST['ata_atb'] : NULL);
    $etd = !empty($_POST['etd']) ? $_POST['etd'] : NULL;

    // Simpan ke tabel vessels
    $stmt = $conn->prepare("INSERT INTO vessels (name, vessel_type, operator, agent_pbm, loa_lbp, gt_nt, draft, flag) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $name, $vessel_type, $operator, $agent_pbm, $loa_lbp, $gt_nt, $draft, $flag);
    
    if ($stmt->execute()) {
        $vessel_id = $stmt->insert_id;
        
        // Simpan ke tabel vessel_operations
        $stmt_op = $conn->prepare("INSERT INTO vessel_operations (vessel_id, port_id, jetty_id, main_status, operation_type, cargo_type, target_volume_tons, eta, etb, ata_atb, etd, berthing_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_op->bind_param("iiisssdsssss", $vessel_id, $port_id, $db_jetty_id, $main_status, $operation_type, $cargo_type, $target_volume, $eta, $etb, $ata_atb, $etd, $ata_atb);
        $stmt_op->execute();

        header("Location: form.php?success=1");
        exit();
    } else {
        $error_msg = "Gagal menyimpan data: " . $conn->error;
    }
}

$ports = $conn->query("SELECT * FROM ports ORDER BY name ASC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Form Input & Import Operasional Kapal - TBI Port Monitoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --bs-body-bg: #f8f9fa; --primary-orange: #fd7e14; }
        .bg-orange-dark { background-color: #1a1a1a !important; border-bottom: 3px solid #fd7e14; }
        .btn-orange { background-color: #fd7e14; color: #fff; font-weight: 600; border: none; }
        .btn-orange:hover { background-color: #e86d0d; color: #fff; }
        .text-orange { color: #fd7e14 !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-orange-dark navbar-dark py-2 shadow-sm">
    <div class="container px-4">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fa-solid fa-ship text-orange me-2"></i>TBI PORT MONITORING</a>
        <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Dashboard</a>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <?php if($success_msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i><?php echo $success_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if($error_msg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 p-4 mb-4" style="border-radius: 12px; border-top: 5px solid #fd7e14 !important;">
                
                <!-- TAB NAVIGASI -->
                <ul class="nav nav-pills mb-4 justify-content-center gap-2" id="pills-tab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold px-4" id="pills-manual-tab" data-bs-toggle="pill" data-bs-target="#pills-manual" type="button" role="tab">
                            <i class="fa-solid fa-pen-to-square me-2"></i>Form Input Manual
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold px-4" id="pills-excel-tab" data-bs-toggle="pill" data-bs-target="#pills-excel" type="button" role="tab">
                            <i class="fa-solid fa-file-excel me-2 text-success"></i>Import via Excel / CSV
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="pills-tabContent">
                    
                    <!-- TAB 1: FORM MANUAL -->
                    <div class="tab-pane fade show active" id="pills-manual" role="tabpanel">
                        <h4 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-pen-to-square text-orange me-2"></i>Form Input Data & Jadwal Kapal Maritim</h4>
                        <p class="text-muted small mb-4">Lengkapi spesifikasi kapal, agen, operator, serta jadwal waktu operasional pelabuhan secara lengkap.</p>

                        <form method="POST" action="form.php">
                            <h5 class="fw-bold text-secondary border-bottom pb-2 mb-3">1. Spesifikasi & Identitas Kapal</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Nama Kapal</label>
                                    <input type="text" name="name" class="form-control" required placeholder="Contoh: MV Nusantara Satu">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Jenis Kapal</label>
                                    <select name="vessel_type" id="vesselTypeSelect" class="form-select" required onchange="toggleCustomVessel()">
                                        <option value="">-- Pilih Jenis Kapal --</option>
                                        <optgroup label="Barge">
                                            <option value="Oil Barge">Oil Barge</option>
                                            <option value="Dry Cargo Barge">Dry Cargo Barge</option>
                                            <option value="Deck Cargo Barge">Deck Cargo Barge</option>
                                            <option value="Crane Barge">Crane Barge</option>
                                            <option value="Hopper Barge">Hopper Barge</option>
                                        </optgroup>
                                        <optgroup label="Vessel">
                                            <option value="SPOB">SPOB</option>
                                            <option value="Bulk Carrier">Bulk Carrier</option>
                                            <option value="MT">MT</option>
                                            <option value="MV">MV</option>
                                            <option value="Chemical Carrier">Chemical Carrier</option>
                                            <option value="LPG Carrier">LPG Carrier</option>
                                            <option value="LNG Carrier">LNG Carrier</option>
                                            <option value="Bitumen Carrier">Bitumen Carrier</option>
                                            <option value="Asphalt Carrier">Asphalt Carrier</option>
                                            <option value="Bunker Vessel">Bunker Vessel</option>
                                            <option value="General Cargo Ship">General Cargo Ship</option>
                                            <option value="Container Ship">Container Ship</option>
                                            <option value="Reefer Ship">Reefer Ship</option>
                                            <option value="Ro-Ro Ship">Ro-Ro Ship</option>
                                            <option value="Livestock Carrier">Livestock Carrier</option>
                                            <option value="Anchor Handling Tug Supply">Anchor Handling Tug Supply</option>
                                            <option value="Platform Supply Vessel">Platform Supply Vessel</option>
                                            <option value="Diving Support Vessel">Diving Support Vessel</option>
                                            <option value="Crew Boat">Crew Boat</option>
                                            <option value="Fast Crew Boat">Fast Crew Boat</option>
                                            <option value="Offshore Support Vessel">Offshore Support Vessel</option>
                                            <option value="Multipurpose Support Vessel">Multipurpose Support Vessel</option>
                                            <option value="Tugboat">Tugboat</option>
                                            <option value="Trailing Suction Hopper Dredger">Trailing Suction Hopper Dredger</option>
                                            <option value="Dredger">Dredger</option>
                                            <option value="Crane Vessel">Crane Vessel</option>
                                            <option value="Floating Crane">Floating Crane</option>
                                            <option value="Pilot Boat">Pilot Boat</option>
                                        </optgroup>
                                        <option value="Lainnya">Lainnya</option>
                                    </select>
                                    <input type="text" name="custom_vessel_type" id="customVesselInput" class="form-control mt-2 d-none" placeholder="Masukkan Jenis Kapal Lainnya">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Operator Kapal</label>
                                    <input type="text" name="operator" class="form-control" placeholder="Contoh: PT Pelayaran Nasional">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Agen / PBM</label>
                                    <input type="text" name="agent_pbm" class="form-control" placeholder="Contoh: PT Keagenan Makmur">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">LOA / LBP</label>
                                    <input type="text" name="loa_lbp" class="form-control" placeholder="Contoh: 120m">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">GT / NT</label>
                                    <input type="text" name="gt_nt" class="form-control" placeholder="Contoh: 5000 / 2500">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Draft</label>
                                    <input type="text" name="draft" class="form-control" placeholder="Contoh: 6.5m">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Bendera (Flag)</label>
                                    <input type="text" name="flag" class="form-control" placeholder="Contoh: Indonesia">
                                </div>
                            </div>

                            <h5 class="fw-bold text-secondary border-bottom pb-2 mb-3">2. Lokasi Pelabuhan & Jadwal Waktu Maritim</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Pelabuhan Tujuan</label>
                                    <select name="port_id" id="portSelect" class="form-select" required onchange="toggleCustomPort()">
                                        <option value="">-- Pilih Pelabuhan --</option>
                                        <option value="Lainnya" class="fw-bold text-orange">➕ Tambah Pelabuhan Lainnya</option>
                                        <?php 
                                        if ($ports && $ports->num_rows > 0) {
                                            while($p = $ports->fetch_assoc()) {
                                                $pid = isset($p['id']) ? $p['id'] : $p['port_id'];
                                                echo '<option value="' . $pid . '">' . htmlspecialchars($p['name']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                    <input type="text" name="custom_port" id="customPortInput" class="form-control mt-2 d-none" placeholder="Masukkan Nama Pelabuhan Baru">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Jetty / Dermaga</label>
                                    <select name="jetty_id" id="jettySelect" class="form-select" required onchange="toggleWaitingList()">
                                        <option value="">-- Pilih Jetty --</option>
                                        <option value="WAITING_LIST" class="fw-bold text-danger">⏳ WAITING LIST (Antri)</option>
                                        <option value="Jetty 1">Jetty 1</option>
                                        <option value="Jetty 2">Jetty 2</option>
                                        <option value="Jetty 3">Jetty 3</option>
                                        <option value="Jetty 4">Jetty 4</option>
                                        <option value="Jetty 5">Jetty 5</option>
                                        <option value="Jetty 6">Jetty 6</option>
                                        <option value="Lainnya" class="fw-bold text-orange">➕ Lainnya</option>
                                    </select>
                                    <input type="text" name="custom_jetty" id="customJettyInput" class="form-control mt-2 d-none" placeholder="Masukkan Nama Jetty Lainnya">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">ETA (Estimated Time of Arrival)</label>
                                    <input type="datetime-local" name="eta" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">ETB (Estimated Time of Berthing)</label>
                                    <input type="datetime-local" name="etb" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">ATA / ATB (Actual Berthing)</label>
                                    <input type="datetime-local" name="ata_atb" id="ataAtbTime" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">ETD (Estimated Time of Departure)</label>
                                    <input type="datetime-local" name="etd" class="form-control">
                                </div>
                            </div>

                            <h5 class="fw-bold text-secondary border-bottom pb-2 mb-3">3. Aktivitas & Muatan (Cargo)</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Tipe Aktivitas</label>
                                    <select name="operation_type" class="form-select">
                                        <option value="LOADING">Loading (Muat)</option>
                                        <option value="UNLOADING">Unloading (Bongkar)</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Komoditas / Cargo</label>
                                    <select name="cargo_type" id="cargoSelect" class="form-select" onchange="toggleCustomCargo()">
                                        <option value="">-- Pilih Komoditas --</option>
                                        <option value="Biosolar">Biosolar</option>
                                        <option value="B40">B40</option>
                                        <option value="B50">B50</option>
                                        <option value="Oli">Oli</option>
                                        <option value="CPO">CPO</option>
                                        <option value="FAME">FAME</option>
                                        <option value="Lainnya">Lainnya</option>
                                    </select>
                                    <input type="text" name="custom_cargo" id="customCargoInput" class="form-control mt-2 d-none" placeholder="Masukkan Komoditas Lainnya">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Target Volume (Ton)</label>
                                    <input type="number" step="0.01" name="target_volume" id="targetVolume" class="form-control" placeholder="0.00">
                                </div>
                            </div>

                            <button type="submit" name="submit_manual" class="btn btn-orange w-100 py-2 shadow-sm"><i class="fa-solid fa-floppy-disk me-2"></i>Simpan Data Operasional Kapal</button>
                        </form>
                    </div>

                    <!-- TAB 2: IMPORT EXCEL / CSV -->
                    <div class="tab-pane fade" id="pills-excel" role="tabpanel">
                        <h4 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-file-excel text-success me-2"></i>Import Data via File CSV</h4>
                        <p class="text-muted small mb-4">Unggah file berformat `.csv` yang berisi data operasional kapal secara massal sesuai template maritim terbaru.</p>

                        <form method="POST" action="process_excel.php" enctype="multipart/form-data">
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Pilih File CSV</label>
                                <input type="file" name="excel_file" class="form-control" accept=".csv" required>
                                <div class="form-text">Gunakan file template berformat CSV standar.</div>
                            </div>
                            <button type="submit" class="btn btn-success w-100 py-2 shadow-sm fw-bold"><i class="fa-solid fa-upload me-2"></i>Proses & Import Data</button>
                        </form>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
function toggleWaitingList() {
    let jettyVal = document.getElementById('jettySelect').value;
    let isWaiting = (jettyVal === 'WAITING_LIST');
    let isOther = (jettyVal === 'Lainnya');
    
    let customJettyInput = document.getElementById('customJettyInput');
    if (isOther) {
        customJettyInput.classList.remove('d-none');
        customJettyInput.required = true;
    } else {
        customJettyInput.classList.add('d-none');
        customJettyInput.required = false;
        customJettyInput.value = '';
    }

    let ataInput = document.getElementById('ataAtbTime');
    let targetVolInput = document.getElementById('targetVolume');

    ataInput.disabled = isWaiting;
    targetVolInput.disabled = isWaiting;

    if (isWaiting) {
        ataInput.value = ''; 
        targetVolInput.value = '';
        ataInput.classList.add('bg-light'); 
        targetVolInput.classList.add('bg-light');
    } else {
        ataInput.classList.remove('bg-light'); 
        targetVolInput.classList.remove('bg-light');
    }
}

function toggleCustomVessel() {
    let val = document.getElementById('vesselTypeSelect').value;
    let customInput = document.getElementById('customVesselInput');
    if (val === 'Lainnya') { customInput.classList.remove('d-none'); customInput.required = true; } 
    else { customInput.classList.add('d-none'); customInput.required = false; customInput.value = ''; }
}

function toggleCustomPort() {
    let val = document.getElementById('portSelect').value;
    let customInput = document.getElementById('customPortInput');
    if (val === 'Lainnya') { customInput.classList.remove('d-none'); customInput.required = true; } 
    else { customInput.classList.add('d-none'); customInput.required = false; customInput.value = ''; }
}

function toggleCustomCargo() {
    let val = document.getElementById('cargoSelect').value;
    let customInput = document.getElementById('customCargoInput');
    if (val === 'Lainnya') { customInput.classList.remove('d-none'); customInput.required = true; } 
    else { customInput.classList.add('d-none'); customInput.required = false; customInput.value = ''; }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>