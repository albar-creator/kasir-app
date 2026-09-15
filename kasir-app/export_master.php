<?php
session_start();
require 'koneksi.php';
require 'otorisasi.php';

if (!isset($_SESSION['id_user']) || !canAccessAdmin()) {
    header('Location: login.php');
    exit;
}

$dataType = $_GET['data'] ?? 'produk';
$format = $_GET['format'] ?? 'csv';
$isStock = $dataType === 'stok';
if ($isStock && !hasPermission('manage_stok')) {
    http_response_code(403);
    exit('Anda tidak memiliki hak akses export stok.');
}
if (!$isStock && !hasPermission('manage_produk')) {
    http_response_code(403);
    exit('Anda tidak memiliki hak akses export produk.');
}

$isBranchScoped = in_array($_SESSION['role'] ?? '', ['admin_cabang', 'kepala_cabang', 'gudang'], true);
if ($isStock) {
    $sql = 'SELECT c.nama_cabang, p.kode_barcode, p.nama_produk, s.jumlah_stok FROM stok_cabang s JOIN cabang c ON c.id_cabang = s.id_cabang JOIN produk p ON p.id_produk = s.id_produk';
    if ($isBranchScoped) $sql .= ' WHERE s.id_cabang = ' . (int)$_SESSION['id_cabang'];
    $sql .= ' ORDER BY c.nama_cabang, p.nama_produk';
    $rows = $pdo->query($sql)->fetchAll();
    $headers = ['Nama Cabang', 'Kode Barcode', 'Nama Produk', 'Jumlah Stok'];
    $filename = 'export_stok_cabang';
} else {
    $rows = $pdo->query('SELECT kode_barcode, nama_produk, harga FROM produk ORDER BY nama_produk')->fetchAll();
    $headers = ['Kode Barcode', 'Nama Produk', 'Harga'];
    $filename = 'export_produk';
}

if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo "<table><tr>";
    foreach ($headers as $header) echo '<th>' . htmlspecialchars($header) . '</th>';
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $value) echo '<td>' . htmlspecialchars((string)$value) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
$output = fopen('php://output', 'w');
fputcsv($output, $headers);
foreach ($rows as $row) fputcsv($output, array_values($row));
fclose($output);
exit;
