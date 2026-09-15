<?php
session_start();
require 'koneksi.php';
require 'otorisasi.php';

if (!isset($_SESSION['id_user']) || !hasPermission('export_reports')) {
    header('Location: login.php');
    exit;
}

$type = $_GET['type'] ?? 'csv';
$startDate = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : date('Y-m-d');

$report = $pdo->prepare('SELECT p.no_faktur, c.nama_cabang, u.username AS kasir, p.total_bayar, p.tanggal FROM penjualan p LEFT JOIN cabang c ON c.id_cabang = p.id_cabang LEFT JOIN users u ON u.id_user = p.id_kasir WHERE DATE(p.tanggal) BETWEEN ? AND ? ORDER BY p.id_penjualan DESC');
$report->execute([$startDate, $endDate]);
$report = $report->fetchAll();

if ($type === 'pdf') {
    $html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Laporan Penjualan</title><style>body{font-family:Arial,sans-serif;margin:32px;color:#111}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border:1px solid #444;padding:8px;text-align:left;font-size:12px}h2{text-align:center;margin-bottom:4px}.meta{text-align:center;margin-bottom:16px}.small{font-size:11px;color:#555}@media print{body{margin:0} .no-print{display:none;}}</style></head><body>';
    $html .= '<h2>Laporan Penjualan</h2><div class="meta">Periode: ' . htmlspecialchars($startDate) . ' s.d ' . htmlspecialchars($endDate) . '<br>Dicetak pada ' . date('d-m-Y H:i:s') . '</div>';
    $html .= '<table><thead><tr><th>No Faktur</th><th>Cabang</th><th>Kasir</th><th>Total</th><th>Tanggal</th></tr></thead><tbody>';
    foreach ($report as $row) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['no_faktur']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['nama_cabang'] ?? '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['kasir'] ?? '-') . '</td>';
        $html .= '<td>Rp ' . number_format((int)$row['total_bayar'], 0, ',', '.') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['tanggal']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<script>window.onload = function(){ window.print(); };</script>';
    $html .= '</body></html>';
    echo $html;
    exit;
}

if ($type === 'xls') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="laporan_penjualan.xls"');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    echo "<Worksheet ss:Name=\"Laporan Penjualan\">\n";
    echo "<Table>\n";
    echo "<Row><Cell><Data ss:Type=\"String\">No Faktur</Data></Cell><Cell><Data ss:Type=\"String\">Cabang</Data></Cell><Cell><Data ss:Type=\"String\">Kasir</Data></Cell><Cell><Data ss:Type=\"String\">Total</Data></Cell><Cell><Data ss:Type=\"String\">Tanggal</Data></Cell></Row>\n";
    foreach ($report as $row) {
        echo "<Row><Cell><Data ss:Type=\"String\">" . htmlspecialchars($row['no_faktur']) . "</Data></Cell><Cell><Data ss:Type=\"String\">" . htmlspecialchars($row['nama_cabang'] ?? '-') . "</Data></Cell><Cell><Data ss:Type=\"String\">" . htmlspecialchars($row['kasir'] ?? '-') . "</Data></Cell><Cell><Data ss:Type=\"Number\">" . (int)$row['total_bayar'] . "</Data></Cell><Cell><Data ss:Type=\"String\">" . htmlspecialchars($row['tanggal']) . "</Data></Cell></Row>\n";
    }
    echo "</Table></Worksheet></Workbook>\n";
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="laporan_penjualan.csv"');

$output = fopen('php://output', 'w');

fputcsv($output, ['No Faktur', 'Cabang', 'Kasir', 'Total', 'Tanggal']);
foreach ($report as $row) {
    fputcsv($output, [
        $row['no_faktur'],
        $row['nama_cabang'] ?? '-',
        $row['kasir'] ?? '-',
        (int)$row['total_bayar'],
        $row['tanggal']
    ]);
}

fclose($output);
exit;
