<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

include 'config.php';

$stmt_check_ses = $conn->prepare("SELECT session_id FROM users WHERE id = ?");
$stmt_check_ses->bind_param("i", $_SESSION['user_id']);
$stmt_check_ses->execute();
$res_ses = $stmt_check_ses->get_result()->fetch_assoc();

if ($res_ses['session_id'] !== session_id()) {
    session_unset();
    session_destroy();
    header("Location: login?error=Akun Anda telah login di perangkat lain.");
    exit();
}

$timeout_duration = 900; 
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login?error=Sesi Anda telah habis karena tidak ada aktivitas selama 15 menit. Silakan login kembali.");
    exit();
}
$_SESSION['last_activity'] = time();    

$is_admin = (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'master'));
$is_master = (isset($_SESSION['username']) && $_SESSION['username'] === 'master');
$is_client = (isset($_SESSION['role']) && $_SESSION['role'] === 'client');
$current_user_id = intval($_SESSION['user_id']);

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Semua role (termasuk client) bisa melihat daftar pelabuhan yang memiliki aktivitas kapal
$result_ports = $conn->query("SELECT DISTINCT p.* FROM ports p JOIN vessel_operations vo ON p.id = vo.port_id ORDER BY p.name ASC");

$selected_port_id = isset($_GET['port_id']) ? intval($_GET['port_id']) : 0;
$selected_month = isset($_GET['month']) && $_GET['month'] !== '' ? $_GET['month'] : date('n'); 
$selected_year = isset($_GET['year']) && $_GET['year'] !== '' ? $_GET['year'] : date('Y'); 

$selected_port_name = "";
$result_vessels = null;
$vessels_data = []; 
$active_jetties = [];
$jetties_list = $conn->query("SELECT * FROM jetties ORDER BY name ASC");
$all_jetties = [];
while($j = $jetties_list->fetch_assoc()) {
    $all_jetties[] = $j;
}

$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'id_desc';

$dom_vessel = 0; $dom_barge = 0;
$int_vessel = 0; $int_barge = 0;

$commodity_volumes = [];
$total_monthly_volume = 0;

$annual_loading = array_fill(1, 12, 0);
$annual_discharging = array_fill(1, 12, 0);

$chart_year = !empty($selected_year) ? intval($selected_year) : intval(date('Y'));
$chart_where = ["vo.main_status != 'WAITING_LIST'", "YEAR(vo.berthing_time) = ?"];
$chart_params = [$chart_year];
$chart_types = "i";

if ($selected_port_id > 0) {
    $stmt_p = $conn->prepare("SELECT name FROM ports WHERE id = ?");
    $stmt_p->bind_param("i", $selected_port_id);
    $stmt_p->execute();
    $res_p = $stmt_p->get_result();
    if ($row_p = $res_p->fetch_assoc()) {
        $selected_port_name = $row_p['name'];
    }
    $chart_where[] = "vo.port_id = ?";
    $chart_params[] = $selected_port_id;
    $chart_types .= "i";
}

$chart_sql = "SELECT vo.*, v.vessel_type, v.flag FROM vessel_operations vo JOIN vessels v ON vo.vessel_id = v.id WHERE " . implode(" AND ", $chart_where);
$stmt_c = $conn->prepare($chart_sql);
$stmt_c->bind_param($chart_types, ...$chart_params);
$stmt_c->execute();
$chart_res = $stmt_c->get_result();

while($c_row = $chart_res->fetch_assoc()) {
    $op = strtoupper($c_row['operation_type'] ?? '');
    $b_time = $c_row['berthing_time'];
    if (!$b_time) continue;
    $m = intval(date('n', strtotime($b_time)));
    $y = intval(date('Y', strtotime($b_time)));

    if ($m >= 1 && $m <= 12) {
        if ($op === 'LOADING') {
            $annual_loading[$m]++;
        } elseif ($op === 'UNLOADING' || $op === 'DISCHARGING') {
            $annual_discharging[$m]++;
        }
    }

    if ($m == intval($selected_month) && $y == intval($chart_year)) {
        $v_type = strtolower($c_row['vessel_type'] ?? '');
        $flag = strtolower($c_row['flag'] ?? 'indonesia');
        $is_barge = (strpos($v_type, 'barge') !== false || strpos($v_type, 'bg.') !== false);
        $is_domestic = (strpos($flag, 'indonesia') !== false || empty($flag));

        if ($is_domestic) {
            if ($is_barge) $dom_barge++; else $dom_vessel++;
        } else {
            if ($is_barge) $int_barge++; else $int_vessel++;
        }

        $cargo = !empty($c_row['cargo_type']) ? $c_row['cargo_type'] : 'Lainnya';
        $vol = floatval($c_row['target_volume_tons'] ?? 0);
        if (!isset($commodity_volumes[$cargo])) {
            $commodity_volumes[$cargo] = 0;
        }
        $commodity_volumes[$cargo] += $vol;
        $total_monthly_volume += $vol;
    }
}

if ($selected_port_id > 0) {
    $order_sql = "ORDER BY vo.id DESC";
    if ($sort_by === 'jetty_asc') $order_sql = "ORDER BY j.name IS NULL, j.name ASC, vo.id DESC";
    elseif ($sort_by === 'jetty_desc') $order_sql = "ORDER BY j.name IS NULL, j.name DESC, vo.id DESC";
    elseif ($sort_by === 'activity_asc') $order_sql = "ORDER BY vo.operation_type ASC, vo.id DESC";
    elseif ($sort_by === 'activity_desc') $order_sql = "ORDER BY vo.operation_type DESC, vo.id DESC";

    $table_where = ["vo.port_id = ?"];
    $table_params = [$selected_port_id];
    $table_types = "i";

    if (!empty($selected_month) && !empty($selected_year)) {
        $table_where[] = "((vo.main_status = 'WAITING_LIST' AND MONTH(vo.created_at) = ? AND YEAR(vo.created_at) = ?) OR (vo.main_status != 'WAITING_LIST' AND vo.berthing_time IS NOT NULL AND MONTH(vo.berthing_time) = ? AND YEAR(vo.berthing_time) = ?))";
        array_push($table_params, intval($selected_month), intval($selected_year), intval($selected_month), intval($selected_year));
        $table_types .= "iiii";
    } elseif (!empty($selected_month)) {
        $table_where[] = "((vo.main_status = 'WAITING_LIST' AND MONTH(vo.created_at) = ?) OR (vo.main_status != 'WAITING_LIST' AND vo.berthing_time IS NOT NULL AND MONTH(vo.berthing_time) = ?))";
        array_push($table_params, intval($selected_month), intval($selected_month));
        $table_types .= "ii";
    } elseif (!empty($selected_year)) {
        $table_where[] = "((vo.main_status = 'WAITING_LIST' AND YEAR(vo.created_at) = ?) OR (vo.main_status != 'WAITING_LIST' AND vo.berthing_time IS NOT NULL AND YEAR(vo.berthing_time) = ?))";
        array_push($table_params, intval($selected_year), intval($selected_year));
        $table_types .= "ii";
    }

    $query = "SELECT v.*, 
                     vo.id as op_id, vo.berthing_time, vo.departed_time, 
                     vo.operation_type, vo.cargo_type, vo.operation_start, vo.operation_end,
                     vo.target_volume_tons, vo.main_status, vo.created_at,
                     vo.eta, vo.etb, vo.ata_atb, vo.etd,
                     vo.draft_initial_fwd, vo.draft_initial_mid, vo.draft_initial_aft, vo.draft_initial_trim,
                     vo.draft_final_fwd, vo.draft_final_mid, vo.draft_final_aft, vo.draft_final_trim,
                     j.name as jetty_name, j.id as jetty_id
              FROM vessel_operations vo
              JOIN vessels v ON vo.vessel_id = v.id
              LEFT JOIN jetties j ON vo.jetty_id = j.id
              WHERE " . implode(" AND ", $table_where) . " 
              $order_sql";
              
    $stmt_v = $conn->prepare($query);
    $stmt_v->bind_param($table_types, ...$table_params);
    $stmt_v->execute();
    $result_vessels = $stmt_v->get_result();

    while($v_row = $result_vessels->fetch_assoc()) {
        // Cek apakah client ini memiliki akses ke operasi kapal ini
        $has_access = true;
        if ($is_client) {
            $check_acc = $conn->prepare("SELECT 1 FROM client_vessel_access WHERE user_id = ? AND operation_id = ?");
            $check_acc->bind_param("ii", $current_user_id, $v_row['op_id']);
            $check_acc->execute();
            $has_access = ($check_acc->get_result()->num_rows > 0);
        }
        $v_row['has_access'] = $has_access;

        $vessels_data[] = $v_row;
        if(!empty($v_row['jetty_name']) && $v_row['main_status'] !== 'WAITING_LIST') {
            $active_jetties[$v_row['jetty_name']] = $v_row;
        }
    }
}

function getJettyColor($jetty_name) {
    switch(trim($jetty_name)) {
        case 'Jetty 1': return '#0dcaf0';
        case 'Jetty 2': return '#ffc107';
        case 'Jetty 3': return '#d63384';
        case 'Jetty 4': return '#6f42c1';
        case 'Jetty 5': return '#fd7e14';
        case 'Jetty 6': return '#20c997';
        default: return '#6c757d';
    }
}

// Ambil daftar user ber-role 'client' untuk fitur assign hak akses
$client_users_result = $conn->query("SELECT id, username FROM users WHERE role = 'client' ORDER BY username ASC");
$client_users_list = [];
while($cu = $client_users_result->fetch_assoc()) {
    $client_users_list[] = $cu;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Monitoring Loading-Discharging Pelabuhan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --bs-body-bg: #f4f6f9; --primary-orange: #fd7e14; --dark-black: #212529; }
        body { position: relative; background-color: var(--bs-body-bg); min-height: 100vh; font-size: 0.85rem; }
        .navbar-brand-text { font-weight: 700; letter-spacing: 0.5px; color: #fff; }
        .bg-orange-dark { background-color: #1a1a1a !important; border-bottom: 3px solid #fd7e14; }
        .btn-orange { background-color: #fd7e14; color: #fff; font-weight: 600; border: none; }
        .btn-orange:hover { background-color: #e86d0d; color: #fff; }
        .text-orange { color: #fd7e14 !important; }
        .dash-card { border: none; border-radius: 12px; box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.05); background: #ffffff; }
        .jetty-box { border-radius: 8px; color: #fff; text-align: center; padding: 12px; font-weight: bold; }
        .pulse-indicator { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background-color: #198754; animation: pulse 2s infinite; }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7); }
            70% { box-shadow: 0 0 0 8px rgba(25, 135, 84, 0); }
            100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
        }
        .ship-visit-card { 
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
        }
        .ship-visit-title {
            font-size: 0.9rem;
            font-weight: 700;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 6px;
            margin-bottom: 12px;
            color: #212529;
            text-align: center;
        }
        .ship-section-title {
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: underline;
            color: #0d6efd;
            margin-bottom: 3px;
            text-align: center;
        }
        .ship-item-text {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 4px;
            text-align: center;
        }
        .weather-badge {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            padding: 4px 10px;
            color: #fff;
        }
        .ship-link {
            color: #0d6efd;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }
        .ship-link:hover {
            text-decoration: underline;
            color: #0a58ca;
        }
        .jetty-ship-link {
            color: #ffffff;
            text-decoration: none;
            cursor: pointer;
        }
        .jetty-ship-link:hover {
            color: #f8f9fa;
            text-decoration: underline;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-orange-dark sticky-top shadow-sm py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="">
            <i class="fa-solid fa-ship text-orange fs-4"></i>
            <span class="navbar-brand-text">TRIBHAKTI PORT MONITORING SYSTEM</span>
        </a>
        <div class="d-flex align-items-center gap-3 ms-auto">
            <span class="badge bg-dark text-light border border-secondary d-none d-md-inline-flex align-items-center gap-2 px-3 py-2">
                <span class="pulse-indicator"></span> Online (<?= htmlspecialchars($_SESSION['username']); ?> - <?= ucfirst($_SESSION['role']); ?>)
            </span>

            <span class="weather-badge small d-none d-xl-inline-flex align-items-center gap-2" id="ipip-weather">
                <i class="fa-solid fa-cloud-sun text-warning"></i> Memuat Cuaca IPIP...
            </span>

            <span class="text-light small d-none d-lg-inline" id="realtime-clock"><i class="fa-regular fa-clock me-1 text-orange"></i> Memuat Waktu...</span>
            
            <?php if ($is_master): ?>
                <a href="tambah_user" class="btn btn-warning btn-sm px-3 shadow-sm fw-bold text-dark"><i class="fa-solid fa-user-plus me-1"></i> Tambah Akun</a>
            <?php endif; ?>

            <?php if ($is_admin): ?>
                <a href="form" class="btn btn-orange px-3 shadow-sm"><i class="fa-solid fa-plus-circle me-1"></i> Form Input / Import</a>
            <?php endif; ?>

            <a href="logout" class="btn btn-outline-light btn-sm px-3"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <div class="card dash-card p-4 mb-4 border-start border-4 border-orange" style="border-left-color: #fd7e14 !important;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-3">
            <div>
                <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-anchor text-orange me-2"></i>Dashboard Monitoring Loading & Discharging</h4>
                <p class="text-muted small mb-0">Statistik aktivitas operasional pelabuhan secara global atau berdasarkan filter waktu yang dipilih.</p>
            </div>

            <form method="GET" action="" class="row g-2 align-items-center" id="filterForm">
                <?php if ($selected_port_id > 0): ?><input type="hidden" name="port_id" value="<?= $selected_port_id; ?>"><?php endif; ?>
                <?php if ($sort_by !== 'id_desc'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by); ?>"><?php endif; ?>

                <div class="col-auto">
                    <select name="month" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                        <?php 
                        $months = [
                            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 
                            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 
                            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                        ];
                        foreach($months as $m_num => $m_name) {
                            $sel = ($selected_month == $m_num) ? 'selected' : '';
                            echo "<option value=\"$m_num\" $sel>$m_name</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-auto">
                    <select name="year" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                        <?php 
                        $current_year = intval(date('Y'));
                        for($y = $current_year; $y >= $current_year - 5; $y--) {
                            $sel = ($selected_year == $y) ? 'selected' : '';
                            echo "<option value=\"$y\" $sel>$y</option>";
                        }
                        ?>
                    </select>
                </div>
                <?php if(isset($_GET['month']) || isset($_GET['year'])): ?>
                    <div class="col-auto">
                        <a href="<?= ($selected_port_id > 0) ? '?port_id='.$selected_port_id : ''; ?>" class="btn btn-outline-secondary btn-sm" title="Reset ke Bulan Berjalan"><i class="fa-solid fa-xmark"></i></a>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- BARIS ATAS: GRAFIK & SHIPS VISIT -->
        <div class="row g-3 my-2 align-items-stretch">
            <div class="col-lg-10">
                <div class="p-3 border rounded bg-light shadow-sm h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-line text-orange me-2"></i>Data Aktivitas Kunjungan Kapal Bulanan (Tahun <?= $chart_year; ?>)</h6>
                    </div>
                    <div style="height: 220px;"><canvas id="annualLineChart"></canvas></div>
                </div>
            </div>

            <div class="col-lg-2">
                <div class="p-3 border rounded bg-light shadow-sm h-100 ship-visit-card">
                    <div>
                        <div class="ship-visit-title"><i class="fa-solid fa-ship me-1 text-orange"></i> Ships Visit</div>
                        
                        <div class="mb-3">
                            <div class="ship-section-title">Domestic</div>
                            <div class="ship-item-text text-muted">Vessel : <strong class="text-dark"><?= $dom_vessel; ?></strong></div>
                            <div class="ship-item-text text-muted">Barge : <strong class="text-dark"><?= $dom_barge; ?></strong></div>
                        </div>

                        <div>
                            <div class="ship-section-title">International</div>
                            <div class="ship-item-text text-muted">Vessel : <strong class="text-dark"><?= $int_vessel; ?></strong></div>
                            <div class="ship-item-text text-muted">Barge : <strong class="text-dark"><?= $int_barge; ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BARIS BAWAH: GRAFIK KOMODITAS BERJEJER SEJAJAR LURUS -->
        <div class="row g-3 my-2">
            <div class="col-12">
                <div class="p-3 border rounded bg-light shadow-sm">
                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-chart-pie text-orange me-2"></i>% Quantity Loading/Discharging dalam 1 Bulan Berdasarkan Komoditas</h6>
                    
                    <?php if (!empty($commodity_volumes)): ?>
                        <div class="row g-3 text-center">
                            <?php 
                            $idx = 0;
                            foreach($commodity_volumes as $comm => $vol):
                                $pct = ($total_monthly_volume > 0) ? round(($vol / $total_monthly_volume) * 100, 1) : 0;
                                $rem_pct = round(100 - $pct, 1);
                                if ($rem_pct < 0) $rem_pct = 0;
                            ?>
                                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
                                    <div class="p-2 bg-white rounded border shadow-sm h-100 d-flex flex-column justify-content-between">
                                        <div style="height: 100px; position: relative;">
                                            <canvas id="commPie<?= $idx; ?>"></canvas>
                                        </div>
                                        <div class="mt-2">
                                            <strong class="d-block text-dark small mb-0"><?= htmlspecialchars($comm); ?></strong>
                                            <span class="text-muted" style="font-size: 0.75rem;"><?= $pct; ?>% (<?= number_format($vol, 0); ?> Ton)</span>
                                        </div>
                                    </div>
                                </div>
                                <script>
                                    window.addEventListener('DOMContentLoaded', (event) => {
                                        const ctx = document.getElementById('commPie<?= $idx; ?>').getContext('2d');
                                        new Chart(ctx, {
                                            type: 'doughnut',
                                            data: {
                                                labels: ['<?= htmlspecialchars($comm); ?>', 'Lainnya'],
                                                datasets: [{
                                                    data: [<?= $pct; ?>, <?= $rem_pct; ?>],
                                                    backgroundColor: ['#0d6efd', '#e9ecef'],
                                                    borderWidth: 1
                                                }]
                                            },
                                            options: {
                                                responsive: true,
                                                maintainAspectRatio: false,
                                                plugins: {
                                                    legend: { display: false },
                                                    tooltip: {
                                                        callbacks: {
                                                            label: function(context) {
                                                                return context.label + ': ' + context.raw + '% (' + '<?= number_format($vol, 2); ?> Ton)';
                                                            }
                                                        }
                                                    }
                                                },
                                                cutout: '65%'
                                            }
                                        });
                                    });
                                </script>
                            <?php 
                                $idx++;
                            endforeach; 
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">Belum ada data muatan komoditas pada bulan ini.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row pt-3 border-top mt-2">
            <div class="col-md-6 col-lg-5 ms-auto">
                <label class="form-label fw-bold text-dark small mb-1"><i class="fa-solid fa-filter text-orange me-1"></i> Pilih Pelabuhan / Terminal Tujuan:</label>
                <form method="GET" action="">
                    <?php if(!empty($selected_month)): ?><input type="hidden" name="month" value="<?= $selected_month; ?>"><?php endif; ?>
                    <?php if(!empty($selected_year)): ?><input type="hidden" name="year" value="<?= $selected_year; ?>"><?php endif; ?>
                    <?php if($sort_by !== 'id_desc'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by); ?>"><?php endif; ?>
                    
                    <select name="port_id" class="form-select border-secondary-subtle fw-semibold" required onchange="this.form.submit()">
                        <option value="">-- Pilih Pelabuhan / Terminal --</option>
                        <?php 
                        if ($result_ports && $result_ports->num_rows > 0) {
                            while($port = $result_ports->fetch_assoc()) {
                                $pid = isset($port['id']) ? $port['id'] : $port['port_id'];
                                $pname = htmlspecialchars($port['name']);
                                $is_selected = ($selected_port_id == $pid) ? 'selected' : '';
                                echo '<option value="' . $pid . '" ' . $is_selected . '>' . $pname . '</option>';
                            }
                        }
                        ?>
                    </select>
                </form>
            </div>
        </div>
    </div>

    <?php if ($selected_port_id > 0): ?>
        
        <!-- ALERT / NOTICE HIJAU DIPINDAHKAN KE ATAS TABEL KAPAL -->
        <div id="alertContainer">
            <?php if (isset($_GET['status']) && $_GET['status'] === 'success_access'): 
                $msg_vessel = isset($_GET['vessel']) ? htmlspecialchars($_GET['vessel']) : 'Kapal';
                $msg_clients = isset($_GET['clients']) ? htmlspecialchars($_GET['clients']) : 'Client';
            ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert" id="autoCloseAlert">
                    <i class="fa-solid fa-circle-check me-2"></i><strong>Berhasil!</strong> Hak akses untuk <strong><?= $msg_clients; ?></strong> pada kapal <strong><?= $msg_vessel; ?></strong> telah diperbarui.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>

        <div class="card dash-card p-4 mb-4">
            <div class="row align-items-center mb-3">
                <div class="col-md-5">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-ship text-orange me-2"></i>Daftar Kapal di Terminal: <span class="text-orange"><?= htmlspecialchars($selected_port_name); ?></span></h5>
                </div>
                <div class="col-md-7 d-flex justify-content-md-end align-items-center gap-2 flex-wrap">
                    <?php 
                    $export_url = "export_excel.php?port_id=" . $selected_port_id;
                    if(!empty($selected_month)) $export_url .= "&month=" . $selected_month;
                    if(!empty($selected_year)) $export_url .= "&year=" . $selected_year;
                    ?>
                    <a href="<?= $export_url; ?>" class="btn btn-success btn-sm fw-bold px-3 shadow-sm d-flex align-items-center gap-1">
                        <i class="fa-solid fa-file-excel"></i> Download Excel
                    </a>

                    <div class="input-group shadow-sm" style="max-width: 280px;">
                        <span class="input-group-text bg-white text-orange border-end-0"><i class="fa-solid fa-search"></i></span>
                        <input type="text" id="tableSearchInput" class="form-control border-start-0 ps-0" placeholder="Cari Nama Kapal..." onkeyup="filterTable()">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle mb-0 text-nowrap" id="vesselsTable">
                    <thead class="table-dark text-uppercase small text-center align-middle">
                        <tr>
                            <th class="ps-3">No</th>
                            <th>Nama Kapal</th>
                            <th>Tipe Kapal</th>
                            <th>Operator Kapal</th>
                            <th>Agen/PBM</th>
                            <th>LOA/LBP</th>
                            <th>GT/NT</th>
                            <th>ETA</th>
                            <th>ETB</th>
                            <th>ATA/ATB</th>
                            <th>ETD</th>
                            <th>Draft</th>
                            <th>Kargo</th>
                            <th>Quantity</th>
                            <th>Jetty</th>
                            <th>Status</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($vessels_data)): ?>
                            <?php $no = 1; foreach($vessels_data as $row): 
                                $is_completed = (strtoupper($row['main_status'] ?? '') === 'COMPLETED');
                                $is_waiting = (strtoupper($row['main_status'] ?? '') === 'WAITING_LIST');
                                
                                $status_text = $is_completed ? 'Completed' : 'On-Going';
                                $status_badge = $is_completed ? 'bg-success' : 'bg-warning text-dark';
                                
                                $jetty_name_val = $row['jetty_name'] ?? '';
                                $jetty_color = getJettyColor($jetty_name_val);

                                if ($is_waiting) {
                                    $jetty_display = '<span class="badge bg-danger">Waiting List</span>';
                                } else {
                                    $display_text = $is_completed ? 'Depart' : ($jetty_name_val !== '' ? $jetty_name_val : '-');
                                    $jetty_display = '<span class="badge text-white" style="background-color: ' . $jetty_color . '; font-weight: 600; padding: 5px 8px;">' . htmlspecialchars($display_text) . '</span>';
                                }
                            ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-muted text-center"><?= $no++; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <?php if ($row['has_access'] || $is_admin): ?>
                                                <a href="javascript:void(0);" class="ship-link" data-bs-toggle="modal" data-bs-target="#shipModal<?= $row['op_id']; ?>">
                                                    <i class="fa-solid fa-ship me-1 text-orange"></i><?= htmlspecialchars($row['name'] ?? '-'); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-secondary">
                                                    <i class="fa-solid fa-lock me-1 text-muted"></i><?= htmlspecialchars($row['name'] ?? '-'); ?> 
                                                    <span class="badge bg-light text-muted border ms-1" style="font-size: 0.65rem;">Terkunci</span>
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($is_admin): ?>
                                            <div class="dropdown ms-2">
                                                <button class="btn btn-sm btn-link text-dark p-0 text-decoration-none" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fa-solid fa-ellipsis-vertical fs-6"></i>
                                                </button>
                                                <ul class="dropdown-menu shadow">
                                                    <li>
                                                        <a class="dropdown-item small" href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#assignClientModal<?= $row['op_id']; ?>">
                                                            <i class="fa-solid fa-user-shield me-2 text-primary"></i>Atur Akses Client
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($row['vessel_type'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($row['operator'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($row['agent_pbm'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($row['loa_lbp'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($row['gt_nt'] ?? '-'); ?></td>
                                    <td class="small text-muted"><?= !empty($row['eta']) ? $row['eta'] : '-'; ?></td>
                                    <td class="small text-muted"><?= !empty($row['etb']) ? $row['etb'] : '-'; ?></td>
                                    <td class="small text-muted"><?= !empty($row['ata_atb']) ? $row['ata_atb'] : (!empty($row['berthing_time']) ? $row['berthing_time'] : '-'); ?></td>
                                    <td class="small text-muted"><?= !empty($row['etd']) ? $row['etd'] : (!empty($row['departed_time']) ? $row['departed_time'] : '-'); ?></td>
                                    <td><?= htmlspecialchars($row['draft'] ?? '-'); ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($row['cargo_type'] ?? '-'); ?></span></td>
                                    <td class="fw-semibold"><?= number_format($row['target_volume_tons'] ?? 0, 2); ?> Ton</td>
                                    <td class="text-center"><?= $jetty_display; ?></td>
                                    <td class="text-center"><span class="badge <?= $status_badge; ?>"><?= $status_text; ?></span></td>
                                    <td class="text-center">
                                        <?php if(!$is_completed): ?>
                                            <?php if ($is_admin): ?>
                                                <form method="POST" action="update_operation" class="d-inline" onsubmit="return confirm('Yakin ingin menyelesaikan operasi kapal ini? Status akan menjadi Completed.');">
                                                    <input type="hidden" name="operation_id" value="<?= $row['op_id']; ?>">
                                                    <input type="hidden" name="port_id" value="<?= $selected_port_id; ?>">
                                                    <input type="hidden" name="action" value="submit">
                                                    <button type="submit" class="btn btn-sm btn-success px-2 py-1" title="Submit">
                                                        <i class="fa-solid fa-check"></i> Submit
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Selesai</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="17" class="text-center text-muted py-4">Belum ada data kapal terdaftar di pelabuhan ini sesuai filter yang dipilih.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODAL ASSIGN CLIENT ACCESS -->
        <?php if ($is_admin && !empty($vessels_data)): ?>
            <?php foreach($vessels_data as $row): 
                $acc_query = $conn->prepare("SELECT user_id FROM client_vessel_access WHERE operation_id = ?");
                $acc_query->bind_param("i", $row['op_id']);
                $acc_query->execute();
                $acc_res = $acc_query->get_result();
                $assigned_clients = [];
                while($ac = $acc_res->fetch_assoc()) {
                    $assigned_clients[] = $ac['user_id'];
                }
            ?>
                <div class="modal fade" id="assignClientModal<?= $row['op_id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-orange-dark text-white">
                                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-shield text-orange me-2"></i>Atur Akses Client: <?= htmlspecialchars($row['name']); ?></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            
                            <form action="save_client_access.php" method="GET">
                                <input type="hidden" name="operation_id" value="<?= $row['op_id']; ?>">
                                
                                <!-- MENYIMPAN FILTER PELABUHAN & WAKTU AGAR TIDAK TERESET -->
                                <?php if ($selected_port_id > 0): ?><input type="hidden" name="port_id" value="<?= $selected_port_id; ?>"><?php endif; ?>
                                <?php if (!empty($selected_month)): ?><input type="hidden" name="month" value="<?= $selected_month; ?>"><?php endif; ?>
                                <?php if (!empty($selected_year)): ?><input type="hidden" name="year" value="<?= $selected_year; ?>"><?php endif; ?>
                                <?php if (!empty($sort_by)): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by); ?>"><?php endif; ?>

                                <div class="modal-body p-4">
                                    <p class="text-muted small mb-3">Centang akun client yang diizinkan untuk melihat informasi kapal ini di dashboard mereka:</p>
                                    
                                    <?php if (!empty($client_users_list)): ?>
                                        <div class="list-group">
                                            <?php foreach($client_users_list as $client): 
                                                $isChecked = in_array($client['id'], $assigned_clients) ? 'checked' : '';
                                            ?>
                                                <label class="list-group-item d-flex align-items-center gap-3 py-2 cursor-pointer">
                                                    <input class="form-check-input m-0" type="checkbox" name="client_ids[]" value="<?= $client['id']; ?>" <?= $isChecked; ?>>
                                                    <span class="fw-semibold text-dark"><i class="fa-solid fa-building me-2 text-primary"></i><?= htmlspecialchars($client['username']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning small mb-0">Belum ada akun dengan role **client** yang terdaftar di sistem.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer bg-light">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-orange btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan Akses</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- MODAL SHIP INFORMATION DENGAN MULTI TAB -->
        <?php if (!empty($vessels_data)): ?>
            <?php foreach($vessels_data as $row): 
                if ($is_client && !$row['has_access']) continue;
            ?>
                <div class="modal fade" id="shipModal<?= $row['op_id']; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-orange-dark text-white">
                                <h5 class="modal-title fw-bold"><i class="fa-solid fa-ship text-orange me-2"></i>Ship Information: <?= htmlspecialchars($row['name']); ?></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            
                            <?php if ($is_admin): ?>
                            <!-- FORM ADMIN / MASTER -->
                            <form method="POST" action="update_ship_info">
                                <input type="hidden" name="vessel_id" value="<?= $row['id']; ?>">
                                <input type="hidden" name="operation_id" value="<?= $row['op_id']; ?>">
                                <input type="hidden" name="port_id" value="<?= $selected_port_id; ?>">
                                <div class="modal-body p-3">
                                    
                                    <!-- TAB NAVIGATION -->
                                    <ul class="nav nav-tabs mb-3" id="shipTabs<?= $row['op_id']; ?>" role="tablist">
                                        <li class="nav-item" role="presentation"><button class="nav-link active fw-bold small" id="info-tab<?= $row['op_id']; ?>" data-bs-toggle="tab" data-bs-target="#info<?= $row['op_id']; ?>" type="button" role="tab">Ship Information</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" id="cargo-tab<?= $row['op_id']; ?>" data-bs-toggle="tab" data-bs-target="#cargo<?= $row['op_id']; ?>" type="button" role="tab">Cargo Information</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" id="custody-tab<?= $row['op_id']; ?>" data-bs-toggle="tab" data-bs-target="#custody<?= $row['op_id']; ?>" type="button" role="tab">Custody Transfer</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" id="qty-tab<?= $row['op_id']; ?>" data-bs-toggle="tab" data-bs-target="#qty<?= $row['op_id']; ?>" type="button" role="tab">Quantity Information</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" id="hourly-tab<?= $row['op_id']; ?>" data-bs-toggle="tab" data-bs-target="#hourly<?= $row['op_id']; ?>" type="button" role="tab">Hourly Transfer</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" id="space-tab<?= $row['op_id']; ?>" data-bs-toggle="tab" data-bs-target="#space<?= $row['op_id']; ?>" type="button" role="tab">Cargo Space</button></li>
                                    </ul>

                                    <!-- TAB CONTENT -->
                                    <div class="tab-content" id="shipTabsContent<?= $row['op_id']; ?>">
                                        
                                        <!-- 1. SHIP INFORMATION -->
                                        <div class="tab-pane fade show active" id="info<?= $row['op_id']; ?>" role="tabpanel">
                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Detail & Karakteristik Kapal</h6>
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Ship Name</label><input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($row['name'] ?? ''); ?>" required></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Port of Registry</label><input type="text" name="port_of_registry" class="form-control form-control-sm" value="<?= htmlspecialchars($row['port_of_registry'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Call Sign</label><input type="text" name="call_sign" class="form-control form-control-sm" value="<?= htmlspecialchars($row['call_sign'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Official Number</label><input type="text" name="official_number" class="form-control form-control-sm" value="<?= htmlspecialchars($row['official_number'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Class ID</label><input type="text" name="class_id" class="form-control form-control-sm" value="<?= htmlspecialchars($row['class_id'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Type of Ship</label><input type="text" name="vessel_type" class="form-control form-control-sm" value="<?= htmlspecialchars($row['vessel_type'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Classification</label><input type="text" name="classification" class="form-control form-control-sm" value="<?= htmlspecialchars($row['classification'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">IMO no</label><input type="text" name="imo_number" class="form-control form-control-sm" value="<?= htmlspecialchars($row['imo_number'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Operators Ship</label><input type="text" name="operator" class="form-control form-control-sm" value="<?= htmlspecialchars($row['operator'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Owners</label><input type="text" name="owners" class="form-control form-control-sm" value="<?= htmlspecialchars($row['owners'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">LOA / LBP</label><input type="text" name="loa_lbp" class="form-control form-control-sm" value="<?= htmlspecialchars($row['loa_lbp'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">GT / NT</label><input type="text" name="gt_nt" class="form-control form-control-sm" value="<?= htmlspecialchars($row['gt_nt'] ?? ''); ?>"></div>
                                            </div>

                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Draft Initial (Awal)</h6>
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-3"><label class="form-label small">Fwd</label><input type="text" name="draft_initial_fwd" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_initial_fwd'] ?? '-'); ?>"></div>
                                                <div class="col-md-3"><label class="form-label small">Mid</label><input type="text" name="draft_initial_mid" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_initial_mid'] ?? '-'); ?>"></div>
                                                <div class="col-md-3"><label class="form-label small">Aft</label><input type="text" name="draft_initial_aft" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_initial_aft'] ?? '-'); ?>"></div>
                                                <div class="col-md-3"><label class="form-label small">Trim</label><input type="text" name="draft_initial_trim" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_initial_trim'] ?? '-'); ?>"></div>
                                            </div>

                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Draft Final (Akhir)</h6>
                                            <div class="row g-2">
                                                <div class="col-md-3"><label class="form-label small">Fwd</label><input type="text" name="draft_final_fwd" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_final_fwd'] ?? '-'); ?>"></div>
                                                <div class="col-md-3"><label class="form-label small">Mid</label><input type="text" name="draft_final_mid" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_final_mid'] ?? '-'); ?>"></div>
                                                <div class="col-md-3"><label class="form-label small">Aft</label><input type="text" name="draft_final_aft" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_final_aft'] ?? '-'); ?>"></div>
                                                <div class="col-md-3"><label class="form-label small">Trim</label><input type="text" name="draft_final_trim" class="form-control form-control-sm" value="<?= htmlspecialchars($row['draft_final_trim'] ?? '-'); ?>"></div>
                                            </div>
                                        </div>

                                        <!-- 2. CARGO INFORMATION -->
                                        <div class="tab-pane fade" id="cargo<?= $row['op_id']; ?>" role="tabpanel">
                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Cargo Information</h6>
                                            <div class="row g-3">
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Cargo Name</label><input type="text" name="cargo_name" class="form-control form-control-sm" value="<?= htmlspecialchars($row['cargo_type'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Type Cargo</label><input type="text" name="cargo_type_detail" class="form-control form-control-sm" value="<?= htmlspecialchars($row['vessel_type'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Shipper</label><input type="text" name="shipper" class="form-control form-control-sm" value="<?= htmlspecialchars($row['shipper'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Consignee</label><input type="text" name="consignee" class="form-control form-control-sm" value="<?= htmlspecialchars($row['consignee'] ?? ''); ?>"></div>
                                                <div class="col-md-12"><label class="form-label fw-semibold small">Notify Address</label><textarea name="notify_address" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($row['notify_address'] ?? ''); ?></textarea></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Port Of Loading</label><input type="text" name="port_of_loading" class="form-control form-control-sm" value="<?= htmlspecialchars($row['port_of_loading'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Port Of Discharging</label><input type="text" name="port_of_discharging" class="form-control form-control-sm" value="<?= htmlspecialchars($row['port_of_discharging'] ?? ''); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Quantity on BL</label><input type="number" step="0.01" name="quantity_on_bl" class="form-control form-control-sm" value="<?= htmlspecialchars($row['quantity_on_bl'] ?? 0); ?>"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">BL No</label><input type="text" name="bl_no" class="form-control form-control-sm" value="<?= htmlspecialchars($row['bl_no'] ?? ''); ?>"></div>
                                            </div>
                                        </div>

                                        <!-- 3. CUSTODY TRANSFER -->
                                        <div class="tab-pane fade" id="custody<?= $row['op_id']; ?>" role="tabpanel">
                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Custody Transfer Parameters</h6>
                                            
                                            <!-- Shore Tank -->
                                            <div class="card p-3 mb-3 bg-light">
                                                <h6 class="text-orange fw-bold small">Shore Tank</h6>
                                                <div class="row g-2">
                                                    <div class="col-md-4"><label class="form-label small">Shore Tank no</label><input type="text" name="shore_tank_no" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Capacity</label><input type="text" name="shore_capacity" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Calibrated by</label><input type="text" name="shore_calibrated_by" class="form-control form-control-sm"></div>
                                                    <div class="col-md-6"><label class="form-label small">Calibrated Dated</label><input type="date" name="shore_calibrated_date" class="form-control form-control-sm"></div>
                                                    <div class="col-md-6"><label class="form-label small">Recalibration Dated</label><input type="date" name="shore_recalibration_date" class="form-control form-control-sm"></div>
                                                </div>
                                            </div>

                                            <!-- Weighbridge -->
                                            <div class="card p-3 mb-3 bg-light">
                                                <h6 class="text-orange fw-bold small">Weighbridge</h6>
                                                <div class="row g-2">
                                                    <div class="col-md-4"><label class="form-label small">Weighbridge Name</label><input type="text" name="wb_name" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Merk</label><input type="text" name="wb_merk" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Type</label><input type="text" name="wb_type" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Capacity</label><input type="text" name="wb_capacity" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Calibrated by</label><input type="text" name="wb_calibrated_by" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Calibrated Dated</label><input type="date" name="wb_calibrated_date" class="form-control form-control-sm"></div>
                                                </div>
                                            </div>

                                            <!-- Flowmeter -->
                                            <div class="card p-3 bg-light">
                                                <h6 class="text-orange fw-bold small">Flowmeter</h6>
                                                <div class="row g-2">
                                                    <div class="col-md-4"><label class="form-label small">Merk</label><input type="text" name="fm_merk" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Type</label><input type="text" name="fm_type" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Capacity</label><input type="text" name="fm_capacity" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Calibrated by</label><input type="text" name="fm_calibrated_by" class="form-control form-control-sm"></div>
                                                    <div class="col-md-4"><label class="form-label small">Calibrated Dated</label><input type="date" name="fm_calibrated_date" class="form-control form-control-sm"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 4. QUANTITY INFORMATION -->
                                        <div class="tab-pane fade" id="qty<?= $row['op_id']; ?>" role="tabpanel">
                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Quantity Comparison & Measurements</h6>
                                            <div class="row g-3">
                                                <div class="col-md-6 border-end">
                                                    <h6 class="small fw-bold text-orange">Loading Comparison</h6>
                                                    <div class="mb-2"><label class="form-label small">Bill of Lading</label><input type="number" step="0.01" name="q_bl" class="form-control form-control-sm"></div>
                                                    <div class="mb-2"><label class="form-label small">Ship Figure After Loading</label><input type="number" step="0.01" name="q_ship_after_load" class="form-control form-control-sm"></div>
                                                    <div class="mb-2"><label class="form-label small">Difference</label><input type="number" step="0.01" name="q_diff_1" class="form-control form-control-sm" readonly></div>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6 class="small fw-bold text-orange">Discharge Comparison</h6>
                                                    <div class="mb-2"><label class="form-label small">Ship Figure Before Discharge</label><input type="number" step="0.01" name="q_ship_before_disc" class="form-control form-control-sm"></div>
                                                    <div class="mb-2"><label class="form-label small">Actual Receipt</label><input type="number" step="0.01" name="q_actual_receipt" class="form-control form-control-sm"></div>
                                                    <div class="mb-2"><label class="form-label small">Difference</label><input type="number" step="0.01" name="q_diff_2" class="form-control form-control-sm" readonly></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 5. HOURLY TRANSFER INFORMATION -->
                                        <div class="tab-pane fade" id="hourly<?= $row['op_id']; ?>" role="tabpanel">
                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Hourly Transfer Log</h6>
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-sm small">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Time</th>
                                                            <th>Pressure</th>
                                                            <th>Rate/Hour</th>
                                                            <th>Volume Transfer</th>
                                                            <th>Information</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td><input type="datetime-local" name="h_time[]" class="form-control form-control-sm"></td>
                                                            <td><input type="text" name="h_press[]" class="form-control form-control-sm"></td>
                                                            <td><input type="number" step="0.01" name="h_rate[]" class="form-control form-control-sm"></td>
                                                            <td><input type="number" step="0.01" name="h_vol[]" class="form-control form-control-sm"></td>
                                                            <td><input type="text" name="h_info[]" class="form-control form-control-sm"></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- 6. CARGO SPACE INFORMATION -->
                                        <div class="tab-pane fade" id="space<?= $row['op_id']; ?>" role="tabpanel">
                                            <h6 class="fw-bold text-secondary border-bottom pb-1 mb-3">Cargo Space & Tank Information</h6>
                                            <div class="row g-3">
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Total Cargo Oil Tank</label><input type="text" name="total_cot" class="form-control form-control-sm"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Capacity</label><input type="text" name="cot_capacity" class="form-control form-control-sm"></div>
                                                <div class="col-md-4"><label class="form-label fw-semibold small">Calibrated by</label><input type="text" name="cot_calibrated_by" class="form-control form-control-sm"></div>
                                                <div class="col-md-4"><label class="form-label fw-semibold small">Calibrated Dated</label><input type="date" name="cot_calibrated_date" class="form-control form-control-sm"></div>
                                                <div class="col-md-4"><label class="form-label fw-semibold small">Recalibration Dated</label><input type="date" name="cot_recalibration_date" class="form-control form-control-sm"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Nomination Cargo Oil Tank</label><input type="text" name="nomination_cot" class="form-control form-control-sm"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Last Three Cargo</label><input type="text" name="last_three_cargo" class="form-control form-control-sm"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Metode Cleaning</label><input type="text" name="cleaning_method" class="form-control form-control-sm"></div>
                                                <div class="col-md-6"><label class="form-label fw-semibold small">Cargo to be loaded</label><input type="text" name="cargo_to_be_loaded" class="form-control form-control-sm" value="<?= htmlspecialchars($row['cargo_type'] ?? ''); ?>"></div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <div class="modal-footer bg-light">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                                    <button type="submit" class="btn btn-orange btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan Perubahan</button>
                                </div>
                            </form>
                            <?php else: ?>
                            <!-- MODAL VIEWER (TAMPILAN READ-ONLY BERDASARKAN TAB) -->
                            <div class="modal-body p-3">
                                <ul class="nav nav-tabs mb-3" id="shipTabsV<?= $row['op_id']; ?>" role="tablist">
                                    <li class="nav-item" role="presentation"><button class="nav-link active fw-bold small" data-bs-toggle="tab" data-bs-target="#v-info<?= $row['op_id']; ?>" type="button" role="tab">Ship Information</button></li>
                                    <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" data-bs-toggle="tab" data-bs-target="#v-cargo<?= $row['op_id']; ?>" type="button" role="tab">Cargo Information</button></li>
                                    <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" data-bs-toggle="tab" data-bs-target="#v-custody<?= $row['op_id']; ?>" type="button" role="tab">Custody Transfer</button></li>
                                    <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" data-bs-toggle="tab" data-bs-target="#v-qty<?= $row['op_id']; ?>" type="button" role="tab">Quantity Information</button></li>
                                    <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" data-bs-toggle="tab" data-bs-target="#v-hourly<?= $row['op_id']; ?>" type="button" role="tab">Hourly Transfer</button></li>
                                    <li class="nav-item" role="presentation"><button class="nav-link fw-bold small" data-bs-toggle="tab" data-bs-target="#v-space<?= $row['op_id']; ?>" type="button" role="tab">Cargo Space</button></li>
                                </ul>

                                <div class="tab-content">
                                    <!-- 1. Ship Info -->
                                    <div class="tab-pane fade show active" id="v-info<?= $row['op_id']; ?>" role="tabpanel">
                                        <table class="table table-bordered table-striped small mb-3">
                                            <tr><th style="width: 35%;">Ship Name</th><td><?= htmlspecialchars($row['name'] ?? '-'); ?></td></tr>
                                            <tr><th>Port of Registry</th><td><?= htmlspecialchars($row['port_of_registry'] ?? '-'); ?></td></tr>
                                            <tr><th>Call Sign</th><td><?= htmlspecialchars($row['call_sign'] ?? '-'); ?></td></tr>
                                            <tr><th>Official Number</th><td><?= htmlspecialchars($row['official_number'] ?? '-'); ?></td></tr>
                                            <tr><th>Class ID</th><td><?= htmlspecialchars($row['class_id'] ?? '-'); ?></td></tr>
                                            <tr><th>Type of Ship</th><td><?= htmlspecialchars($row['vessel_type'] ?? '-'); ?></td></tr>
                                            <tr><th>Classification</th><td><?= htmlspecialchars($row['classification'] ?? '-'); ?></td></tr>
                                            <tr><th>IMO no</th><td><?= htmlspecialchars($row['imo_number'] ?? '-'); ?></td></tr>
                                            <tr><th>Operators Ship</th><td><?= htmlspecialchars($row['operator'] ?? '-'); ?></td></tr>
                                            <tr><th>Owners</th><td><?= htmlspecialchars($row['owners'] ?? '-'); ?></td></tr>
                                            <tr><th>LOA / LBP</th><td><?= htmlspecialchars($row['loa_lbp'] ?? '-'); ?></td></tr>
                                            <tr><th>GT / NT</th><td><?= htmlspecialchars($row['gt_nt'] ?? '-'); ?></td></tr>
                                        </table>
                                    </div>
                                    <!-- 2. Cargo Info -->
                                    <div class="tab-pane fade" id="v-cargo<?= $row['op_id']; ?>" role="tabpanel">
                                        <table class="table table-bordered table-striped small">
                                            <tr><th style="width: 35%;">Cargo Name</th><td><?= htmlspecialchars($row['cargo_type'] ?? '-'); ?></td></tr>
                                            <tr><th>Shipper</th><td><?= htmlspecialchars($row['shipper'] ?? '-'); ?></td></tr>
                                            <tr><th>Consignee</th><td><?= htmlspecialchars($row['consignee'] ?? '-'); ?></td></tr>
                                            <tr><th>Notify Address</th><td><?= htmlspecialchars($row['notify_address'] ?? '-'); ?></td></tr>
                                            <tr><th>Port Of Loading</th><td><?= htmlspecialchars($row['port_of_loading'] ?? '-'); ?></td></tr>
                                            <tr><th>Port Of Discharging</th><td><?= htmlspecialchars($row['port_of_discharging'] ?? '-'); ?></td></tr>
                                            <tr><th>Quantity on BL</th><td><?= number_format($row['quantity_on_bl'] ?? 0, 2); ?> Ton</td></tr>
                                            <tr><th>BL No</th><td><?= htmlspecialchars($row['bl_no'] ?? '-'); ?></td></tr>
                                        </table>
                                    </div>
                                    <!-- 3. Custody -->
                                    <div class="tab-pane fade" id="v-custody<?= $row['op_id']; ?>" role="tabpanel">
                                        <p class="text-muted small">Belum ada data parameter Custody Transfer yang tersimpan.</p>
                                    </div>
                                    <!-- 4. Quantity -->
                                    <div class="tab-pane fade" id="v-qty<?= $row['op_id']; ?>" role="tabpanel">
                                        <p class="text-muted small">Belum ada data Quantity Information yang tersimpan.</p>
                                    </div>
                                    <!-- 5. Hourly -->
                                    <div class="tab-pane fade" id="v-hourly<?= $row['op_id']; ?>" role="tabpanel">
                                        <p class="text-muted small">Belum ada data Hourly Transfer yang tersimpan.</p>
                                    </div>
                                    <!-- 6. Space -->
                                    <div class="tab-pane fade" id="v-space<?= $row['op_id']; ?>" role="tabpanel">
                                        <p class="text-muted small">Belum ada data Cargo Space yang tersimpan.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="dash-card p-4">
            <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-water text-orange me-2"></i>Status Operasional Dermaga (Jetty 1 - Jetty 6)</h5>
            <div class="row g-3">
                <?php 
                for ($i = 1; $i <= 6; $i++):
                    $j_name = "Jetty " . $i;
                    $occupied = isset($active_jetties[$j_name]);
                    $j_color = getJettyColor($j_name);
                ?>
                    <div class="col-12 col-md-4 col-lg-2">
                        <div class="border rounded p-2 bg-light text-center shadow-sm">
                            <?php if ($occupied): 
                                $occ_op = strtoupper($active_jetties[$j_name]['operation_type']);
                                $occ_badge = ($occ_op === 'LOADING') ? 'bg-success' : 'bg-primary';
                                $occ_op_id = $active_jetties[$j_name]['op_id'];
                                $occ_ship_name = $active_jetties[$j_name]['name'];
                                $occ_has_access = $active_jetties[$j_name]['has_access'];
                            ?>
                                <div class="p-3 text-white rounded small mb-2 shadow-sm" style="background-color: <?= $j_color; ?> !important;">
                                    <strong class="d-block text-white">
                                        <?php if ($occ_has_access || $is_admin): ?>
                                            <a href="javascript:void(0);" class="jetty-ship-link" data-bs-toggle="modal" data-bs-target="#shipModal<?= $occ_op_id; ?>" title="Klik untuk melihat Ship Information">
                                                <?= htmlspecialchars($occ_ship_name); ?>
                                            </a>
                                        <?php else: ?>
                                            <span><i class="fa-solid fa-lock me-1"></i><?= htmlspecialchars($occ_ship_name); ?></span>
                                        <?php endif; ?>
                                    </strong>
                                    <span class="badge <?= $occ_badge; ?> text-white mt-1"><?= htmlspecialchars($active_jetties[$j_name]['operation_type']); ?></span>
                                </div>
                            <?php else: ?>
                                <div class="p-3 bg-secondary text-white rounded small mb-2 shadow-sm" style="background-color: #6c757d !important;">
                                    <strong class="d-block text-white">Tersedia</strong>
                                    <span class="badge bg-dark text-light mt-1">Siap Sandar</span>
                                </div>
                            <?php endif; ?>
                            <div class="jetty-box py-2 small" style="background-color: <?= $j_color; ?>;">
                                <?= $j_name; ?>
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="dash-card p-5 text-center">
            <i class="fa-solid fa-hand-pointer fs-1 text-orange mb-3"></i>
            <h4 class="text-dark fw-bold">Silakan Pilih Pelabuhan Terlebih Dahulu</h4>
            <p class="text-muted small">Gunakan pilihan menu dropdown di atas untuk memantau data kapal dan aktivitas operasional secara real-time.</p>
        </div>
    <?php endif; ?>
</div>

<script>
// Fungsi AJAX untuk submit akses client agar modal langsung tertutup dan halaman tidak reset ke atas
function submitClientAccess(event, opId) {
    event.preventDefault();
    
    let form = document.getElementById('accessForm' + opId);
    if (!form) {
        alert('Form tidak ditemukan');
        return;
    }

    let formData = new FormData(form);

    fetch('save_client_access.php?operation_id=' + opId, {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        let text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Response bukan JSON:', text);
            throw new Error('Server mengembalikan respons tidak valid: ' + text);
        }
    })
    .then(data => {
        if (data.status === 'success') {
            // 1. Tutup modal secara langsung
            let modalEl = document.getElementById('assignClientModal' + opId);
            let modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                modalInstance.hide();
            } else {
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
                document.body.classList.remove('modal-open');
                let backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) backdrop.remove();
            }

            // 2. Tampilkan notifikasi hijau sukses di atas dashboard
            let alertBox = `
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fa-solid fa-circle-check me-2"></i><strong>Berhasil!</strong> Hak akses untuk kapal terpilih telah diperbarui.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            let container = document.getElementById('alertContainer');
            if (container) {
                container.innerHTML = alertBox;
            }

            // 3. Refresh halaman secara mulus setelah 0.8 detik
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            alert('Gagal menyimpan akses: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan: ' + error.message);
    });
}

async function fetchIpipWeather() {
    const lat = -1.2686; 
    const lon = 116.8286; 

    try {
        const response = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current=temperature_2m,weather_code,wind_speed_10m&wind_speed_unit=kn`);
        const data = await response.json();
        
        if (data && data.current) {
            const temp = Math.round(data.current.temperature_2m);
            const windKnots = data.current.wind_speed_10m.toFixed(1);
            const wCode = data.current.weather_code;

            let weatherIcon = 'fa-sun text-warning';
            let weatherText = 'Cerah';

            if (wCode >= 1 && wCode <= 3) {
                weatherIcon = 'fa-cloud-sun text-warning';
                weatherText = 'Berawan';
            } else if (wCode >= 51 && wCode <= 67) {
                weatherIcon = 'fa-cloud-rain text-info';
                weatherText = 'Gerimis';
            } else if (wCode >= 80 && wCode <= 99) {
                weatherIcon = 'fa-cloud-showers-heavy text-primary';
                weatherText = 'Hujan';
            }

            document.getElementById('ipip-weather').innerHTML = `
                <i class="fa-solid ${weatherIcon} me-1"></i> IPIP: <strong>${temp}°C</strong> (${weatherText}) 
                <span class="ms-2 border-start ps-2 border-secondary"><i class="fa-solid fa-wind text-info me-1"></i> <strong>${windKnots} kts</strong></span>
            `;
        }
    } catch (error) {
        console.error('Gagal memuat cuaca:', error);
        document.getElementById('ipip-weather').innerHTML = `<i class="fa-solid fa-cloud text-secondary me-1"></i> IPIP Weather N/A`;
    }
}

fetchIpipWeather();
setInterval(fetchIpipWeather, 600000);

function filterTable() {
    let input = document.getElementById('tableSearchInput');
    let filter = input.value.toLowerCase();
    let table = document.getElementById('vesselsTable');
    let tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let tdName = tr[i].getElementsByTagName('td')[1];
        if (tdName) {
            let txtName = tdName.textContent || tdName.innerText;
            if (txtName.toLowerCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
}

function clearSearch() {
    document.getElementById('tableSearchInput').value = '';
    filterTable();
}

function updateClock() {
    let now = new Date();
    let options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
    document.getElementById('realtime-clock').innerHTML = '<i class="fa-regular fa-clock me-1 text-orange"></i> ' + now.toLocaleDateString('id-ID', options);
}
setInterval(updateClock, 1000);
updateClock();

const ctxAnnual = document.getElementById('annualLineChart').getContext('2d');
new Chart(ctxAnnual, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'],
        datasets: [
            {
                label: 'Loading',
                data: [<?= implode(',', array_values($annual_loading)); ?>],
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderWidth: 3,
                tension: 0.3,
                pointRadius: 4,
                fill: true
            },
            {
                label: 'Discharging',
                data: [<?= implode(',', array_values($annual_discharging)); ?>],
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                borderWidth: 3,
                tension: 0.3,
                pointRadius: 4,
                fill: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto close alert notice hijau setelah 7 detik
window.addEventListener('DOMContentLoaded', (event) => {
    let alertEl = document.getElementById('autoCloseAlert');
    if (alertEl) {
        setTimeout(() => {
            let bsAlert = new bootstrap.Alert(alertEl);
            bsAlert.close();
        }, 7000); // 7000 milidetik = 7 detik
    }
});

// Simpan posisi scroll sebelum simpan akses diklik
document.querySelectorAll('form[action*="save_client_access.php"]').forEach(form => {
    form.addEventListener('submit', function() {
        sessionStorage.setItem('scrollpos', window.scrollY);
    });
});

// Kembalikan posisi scroll tepat di tempat semula
window.addEventListener('load', function() {
    let scrollpos = sessionStorage.getItem('scrollpos');
    if (scrollpos) {
        window.scrollTo(0, parseInt(scrollpos));
        sessionStorage.removeItem('scrollpos');
    }
});
</script>
</body>
</html>