<?php
// Memuat autoloader dari folder vendor
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

include 'config.php';

$selected_port_id = isset($_GET['port_id']) ? intval($_GET['port_id']) : 0;
$selected_month = isset($_GET['month']) && $_GET['month'] !== '' ? $_GET['month'] : date('n');
$selected_year = isset($_GET['year']) && $_GET['year'] !== '' ? intval($_GET['year']) : intval(date('Y'));

if ($selected_port_id <= 0) {
    die("Silakan pilih pelabuhan terlebih dahulu.");
}

// 1. Ambil nama pelabuhan
$stmt_p = $conn->prepare("SELECT name FROM ports WHERE id = ?");
$stmt_p->bind_param("i", $selected_port_id);
$stmt_p->execute();
$port_name = $stmt_p->get_result()->fetch_assoc()['name'] ?? 'Pelabuhan';

$nama_bulan_arr = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 
    5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agt', 
    9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
];
$display_month_name = $nama_bulan_arr[intval($selected_month)] ?? '';

// 2. Hitung Data Bulanan (Jan - Des) untuk Line Chart Aktivitas Kapal
$annual_loading = array_fill(1, 12, 0);
$annual_discharging = array_fill(1, 12, 0);

$chart_where = ["vo.main_status != 'WAITING_LIST'", "YEAR(vo.berthing_time) = ?"];
$chart_params = [$selected_year];
$chart_types = "i";

if ($selected_port_id > 0) {
    $chart_where[] = "vo.port_id = ?";
    $chart_params[] = $selected_port_id;
    $chart_types .= "i";
}

$chart_sql = "SELECT vo.operation_type, vo.berthing_time FROM vessel_operations vo WHERE " . implode(" AND ", $chart_where);
$stmt_c = $conn->prepare($chart_sql);
$stmt_c->bind_param($chart_types, ...$chart_params);
$stmt_c->execute();
$chart_res = $stmt_c->get_result();

while($c_row = $chart_res->fetch_assoc()) {
    $op = strtoupper($c_row['operation_type'] ?? '');
    $b_time = $c_row['berthing_time'];
    if (!$b_time) continue;
    $m = intval(date('n', strtotime($b_time)));

    if ($m >= 1 && $m <= 12) {
        if ($op === 'LOADING') {
            $annual_loading[$m]++;
        } elseif ($op === 'UNLOADING' || $op === 'DISCHARGING') {
            $annual_discharging[$m]++;
        }
    }
}

// 3. Hitung Volume Komoditas pada Bulan & Tahun yang Dipilih
$commodity_volumes = [];
$total_monthly_volume = 0;

$comm_where = ["vo.port_id = ?", "MONTH(vo.berthing_time) = ?", "YEAR(vo.berthing_time) = ?"];
$comm_params = [$selected_port_id, intval($selected_month), intval($selected_year)];
$comm_types = "iii";

$comm_sql = "SELECT vo.cargo_type, vo.target_volume_tons FROM vessel_operations vo WHERE " . implode(" AND ", $comm_where);
$stmt_comm = $conn->prepare($comm_sql);
$stmt_comm->bind_param($comm_types, ...$comm_params);
$stmt_comm->execute();
$comm_res = $stmt_comm->get_result();

while($comm_row = $comm_res->fetch_assoc()) {
    $cargo = !empty($comm_row['cargo_type']) ? $comm_row['cargo_type'] : 'Lainnya';
    $vol = floatval($comm_row['target_volume_tons'] ?? 0);
    if (!isset($commodity_volumes[$cargo])) {
        $commodity_volumes[$cargo] = 0;
    }
    $commodity_volumes[$cargo] += $vol;
    $total_monthly_volume += $vol;
}

// 4. Query Data Tabel Utama Kapal
$table_where = ["vo.port_id = ?"];
$table_params = [$selected_port_id];
$table_types = "i";

if (!empty($selected_month) && !empty($selected_year)) {
    $table_where[] = "((vo.main_status = 'WAITING_LIST' AND MONTH(vo.created_at) = ? AND YEAR(vo.created_at) = ?) OR (vo.main_status != 'WAITING_LIST' AND vo.berthing_time IS NOT NULL AND MONTH(vo.berthing_time) = ? AND YEAR(vo.berthing_time) = ?))";
    $table_params[] = intval($selected_month); $table_params[] = intval($selected_year);
    $table_params[] = intval($selected_month); $table_params[] = intval($selected_year);
    $table_types .= "iiii";
} elseif (!empty($selected_month)) {
    $table_where[] = "((vo.main_status = 'WAITING_LIST' AND MONTH(vo.created_at) = ?) OR (vo.main_status != 'WAITING_LIST' AND vo.berthing_time IS NOT NULL AND MONTH(vo.berthing_time) = ?))";
    $table_params[] = intval($selected_month); $table_params[] = intval($selected_month);
    $table_types .= "ii";
} elseif (!empty($selected_year)) {
    $table_where[] = "((vo.main_status = 'WAITING_LIST' AND YEAR(vo.created_at) = ?) OR (vo.main_status != 'WAITING_LIST' AND vo.berthing_time IS NOT NULL AND YEAR(vo.berthing_time) = ?))";
    $table_params[] = intval($selected_year); $table_params[] = intval($selected_year);
    $table_types .= "ii";
}

$query = "SELECT v.name as vessel_name, v.imo_number, v.vessel_type, v.flag,
                 vo.operation_type, vo.cargo_type, vo.target_volume_tons, vo.main_status,
                 vo.berthing_time, vo.operation_start, vo.departed_time, vo.operation_end, vo.created_at,
                 j.name as jetty_name
          FROM vessel_operations vo
          JOIN vessels v ON vo.vessel_id = v.id
          LEFT JOIN jetties j ON vo.jetty_id = j.id
          WHERE " . implode(" AND ", $table_where) . " ORDER BY vo.id DESC";

$stmt_v = $conn->prepare($query);
$stmt_v->bind_param($table_types, ...$table_params);
$stmt_v->execute();
$result = $stmt_v->get_result();

// --- Inisialisasi Spreadsheet ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan Operasional');

// Header Informasi
$sheet->setCellValue('A1', "Laporan Operasional Pelabuhan: " . $port_name);
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->setCellValue('A2', "Periode Bulan: " . $display_month_name . " " . $selected_year);

// --- TABEL 1: SUMBER DATA LINE CHART BULANAN (Baris 4 s.d. 16) ---
$sheet->setCellValue('A4', 'Bulan');
$sheet->setCellValue('B4', 'Loading');
$sheet->setCellValue('C4', 'Discharging');
$sheet->getStyle('A4:C4')->getFont()->setBold(true);

$r = 5;
for ($m = 1; $m <= 12; $m++) {
    $sheet->setCellValue('A' . $r, $nama_bulan_arr[$m]);
    $sheet->setCellValue('B' . $r, $annual_loading[$m]);
    $sheet->setCellValue('C' . $r, $annual_discharging[$m]);
    $r++;
}

// Buat Line Chart Bulanan
$dataSeriesLabels1 = [
    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, '\'Laporan Operasional\'!$B$4', null, 1),
    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, '\'Laporan Operasional\'!$C$4', null, 1),
];
$xAxisTickValues1  = [
    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, '\'Laporan Operasional\'!$A$5:$A$16', null, 12)
];
$dataSeriesValues1 = [
    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, '\'Laporan Operasional\'!$B$5:$B$16', null, 12),
    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, '\'Laporan Operasional\'!$C$5:$C$16', null, 12),
];

$series1 = new DataSeries(
    DataSeries::TYPE_LINECHART,
    DataSeries::GROUPING_STANDARD,
    range(0, count($dataSeriesValues1) - 1),
    $dataSeriesLabels1,
    $xAxisTickValues1,
    $dataSeriesValues1
);
$series1->setPlotDirection(DataSeries::DIRECTION_COL);

$plotArea1 = new PlotArea(null, [$series1]);
$title1 = new Title('Data Aktivitas Kunjungan Kapal Bulanan (Tahun ' . $selected_year . ')');
$legend1 = new Legend(Legend::POSITION_TOP, null, false);
$chart1 = new Chart('annual_line_chart', $title1, $legend1, $plotArea1);
$chart1->setTopLeftPosition('E2');
$chart1->setBottomRightPosition('P17');
$sheet->addChart($chart1);


// --- TABEL 2: SUMBER DATA PIE CHART KOMODITAS BULANAN (Baris 19 s.d. Berjalan) ---
$sheet->setCellValue('A19', 'Komoditas');
$sheet->setCellValue('B19', 'Volume (Ton)');
$sheet->setCellValue('C19', 'Persentase (%)');
$sheet->getStyle('A19:C19')->getFont()->setBold(true);

$comm_row_start = 20;
if (!empty($commodity_volumes)) {
    $curr_r = $comm_row_start;
    foreach($commodity_volumes as $comm_name => $vol) {
        $sheet->setCellValue('A' . $curr_r, $comm_name);
        $sheet->setCellValue('B' . $curr_r, $vol);
        // Formula persentase terhadap total volume bulan tersebut
        $sheet->setCellValue('C' . $curr_r, '=B' . $curr_r . '/SUM($B$' . $comm_row_start . ':$B$' . ($comm_row_start + count($commodity_volumes) - 1) . ')');
        $sheet->getStyle('C' . $curr_r)->getNumberFormat()->setFormatCode('0.0%');
        $curr_r++;
    }
    $comm_row_end = $curr_r - 1;

    // Buat Pie Chart Persentase Komoditas
    $dataSeriesLabels2 = [
        new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, '\'Laporan Operasional\'!$B$19', null, 1),
    ];
    $xAxisTickValues2  = [
        new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, '\'Laporan Operasional\'!$A$' . $comm_row_start . ':$A$' . $comm_row_end, null, count($commodity_volumes))
    ];
    $dataSeriesValues2 = [
        new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, '\'Laporan Operasional\'!$B$' . $comm_row_start . ':$B$' . $comm_row_end, null, count($commodity_volumes)),
    ];

    $series2 = new DataSeries(
        DataSeries::TYPE_PIECHART,
        null,
        range(0, count($dataSeriesValues2) - 1),
        $dataSeriesLabels2,
        $xAxisTickValues2,
        $dataSeriesValues2
    );
    $series2->setPlotDirection(DataSeries::DIRECTION_COL);

    $plotArea2 = new PlotArea(null, [$series2]);
    $title2 = new Title('% Quantity Loading/Discharging Berdasarkan Komoditas (' . $display_month_name . ' ' . $selected_year . ')');
    $legend2 = new Legend(Legend::POSITION_RIGHT, null, false);
    $chart2 = new Chart('commodity_pie_chart', $title2, $legend2, $plotArea2);
    $chart2->setTopLeftPosition('E19');
    $chart2->setBottomRightPosition('P34');
    $sheet->addChart($chart2);
}


// --- TABEL 3: TABEL UTAMA DATA KAPAL ---
$startRow_table = max(36, (isset($comm_row_end) ? $comm_row_end + 4 : 36));
$headers = ['No', 'Nama Kapal', 'Nomor SO', 'Jenis Kapal', 'Bendera', 'Jetty / Status', 'Aktivitas', 'Komoditas', 'Target Volume (Ton)', 'Status Operasi', 'Waktu Berthing', 'Waktu Start', 'Waktu Departed/End', 'Tanggal Input'];

$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $startRow_table, $h);
    $col++;
}

// Styling Header Tabel Utama
$sheet->getStyle('A' . $startRow_table . ':N' . $startRow_table)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE));
$sheet->getStyle('A' . $startRow_table . ':N' . $startRow_table)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFD7E14');

$rowNum = $startRow_table + 1;
$no = 1;
while($row = $result->fetch_assoc()) {
    $is_waiting = (strtoupper($row['main_status']) === 'WAITING_LIST');
    $jetty_display = $is_waiting ? 'Waiting List' : ($row['jetty_name'] ?? '-');

    $sheet->setCellValue('A' . $rowNum, $no++);
    $sheet->setCellValue('B' . $rowNum, $row['vessel_name']);
    $sheet->setCellValue('C' . $rowNum, $row['imo_number']);
    $sheet->setCellValue('D' . $rowNum, $row['vessel_type']);
    $sheet->setCellValue('E' . $rowNum, $row['flag']);
    $sheet->setCellValue('F' . $rowNum, $jetty_display);
    $sheet->setCellValue('G' . $rowNum, $row['operation_type']);
    $sheet->setCellValue('H' . $rowNum, $row['cargo_type']);
    $sheet->setCellValue('I' . $rowNum, $row['target_volume_tons']);
    $sheet->setCellValue('J' . $rowNum, $row['main_status']);
    $sheet->setCellValue('K' . $rowNum, $row['berthing_time'] ?? '-');
    $sheet->setCellValue('L' . $rowNum, $row['operation_start'] ?? '-');
    $sheet->setCellValue('M' . $rowNum, $row['departed_time'] ?? $row['operation_end'] ?? '-');
    $sheet->setCellValue('N' . $rowNum, $row['created_at']);
    $rowNum++;
}

// Mengatur Lebar Kolom
$sheet->getColumnDimension('A')->setWidth(6);
foreach (range('B', 'N') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Export ke .xlsx dengan Chart Line dan Pie Chart Komoditas
$filename = "Laporan_Operasional_" . preg_replace('/[^A-Za-z0-9]/', '_', $port_name) . "_" . date('Ymd_His') . ".xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true); 
$writer->save('php://output');
exit();
?>