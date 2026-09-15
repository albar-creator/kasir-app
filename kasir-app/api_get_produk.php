<?php
// api_get_produk.php
session_start();
require 'koneksi.php';

header('Content-Type: application/json');

if (!isset($_SESSION['id_cabang'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid']);
    exit;
}

$barcode   = $_GET['barcode'] ?? '';
$search    = trim($_GET['search'] ?? '');
$id_cabang = $_SESSION['id_cabang'];

if ($search !== '') {
    $stmt = $pdo->prepare("SELECT p.id_produk, p.kode_barcode, p.nama_produk, p.harga, IFNULL(s.jumlah_stok, 0) AS stok
        FROM produk p
        LEFT JOIN stok_cabang s ON p.id_produk = s.id_produk AND s.id_cabang = ?
        WHERE p.nama_produk LIKE ? OR p.kode_barcode LIKE ?
        ORDER BY p.nama_produk ASC
        LIMIT 20");
    $likeSearch = '%' . $search . '%';
    $stmt->execute([$id_cabang, $likeSearch, $likeSearch]);
    echo json_encode(['success' => true, 'produk' => $stmt->fetchAll()]);
    exit;
}

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => 'Barcode kosong']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.id_produk, p.kode_barcode, p.nama_produk, p.harga, IFNULL(s.jumlah_stok, 0) AS stok
    FROM produk p
    LEFT JOIN stok_cabang s ON p.id_produk = s.id_produk AND s.id_cabang = ?
    WHERE p.kode_barcode = ?
");
$stmt->execute([$id_cabang, $barcode]);
$produk = $stmt->fetch();

if ($produk) {
    echo json_encode(['success' => true, 'produk' => $produk]);
} else {
    echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan']);
}
?>