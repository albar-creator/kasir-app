<?php

function csvHeaderMap(array $header): array
{
    $map = [];
    foreach ($header as $index => $value) {
        $key = strtolower(trim((string)$value, " \t\n\r\0\x0B\xEF\xBB\xBF"));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
        $map[$key] = $index;
    }
    return $map;
}

function csvValue(array $row, array $map, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($map[$key])) return trim((string)($row[$map[$key]] ?? ''));
    }
    return '';
}

function importProductsCsv(PDO $pdo, string $filePath): array
{
    $handle = fopen($filePath, 'rb');
    if (!$handle) return ['success' => 0, 'failed' => 0, 'errors' => ['File tidak dapat dibaca.']];
    $header = fgetcsv($handle);
    $map = csvHeaderMap($header ?: []);
    $success = 0;
    $failed = 0;
    $errors = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, static fn($value) => trim((string)$value) !== '')) === 0) continue;
        $barcode = csvValue($row, $map, ['kode_barcode', 'barcode', 'kode']);
        $name = csvValue($row, $map, ['nama_produk', 'produk', 'nama']);
        $price = (int)preg_replace('/[^0-9]/', '', csvValue($row, $map, ['harga', 'price']));
        if ($barcode === '' || $name === '' || $price <= 0) {
            $failed++;
            if (count($errors) < 5) $errors[] = 'Produk tidak valid: ' . ($name ?: $barcode ?: 'baris kosong');
            continue;
        }
        try {
            $existing = $pdo->prepare('SELECT id_produk FROM produk WHERE kode_barcode = ? LIMIT 1');
            $existing->execute([$barcode]);
            $productId = (int)$existing->fetchColumn();
            if ($productId > 0) {
                $stmt = $pdo->prepare('UPDATE produk SET nama_produk = ?, harga = ? WHERE id_produk = ?');
                $stmt->execute([$name, $price, $productId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO produk (kode_barcode, nama_produk, harga) VALUES (?, ?, ?)');
                $stmt->execute([$barcode, $name, $price]);
            }
            $success++;
        } catch (PDOException $e) {
            $failed++;
            if (count($errors) < 5) $errors[] = 'Gagal menyimpan produk ' . $barcode . '.';
        }
    }
    fclose($handle);
    return compact('success', 'failed', 'errors');
}

function importStocksCsv(PDO $pdo, string $filePath, int $currentBranchId, bool $isAdmin): array
{
    $handle = fopen($filePath, 'rb');
    if (!$handle) return ['success' => 0, 'failed' => 0, 'errors' => ['File tidak dapat dibaca.']];
    $header = fgetcsv($handle);
    $map = csvHeaderMap($header ?: []);
    $success = 0;
    $failed = 0;
    $errors = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, static fn($value) => trim((string)$value) !== '')) === 0) continue;
        $branchName = csvValue($row, $map, ['nama_cabang', 'cabang', 'branch']);
        $barcode = csvValue($row, $map, ['kode_barcode', 'barcode', 'kode']);
        $quantityValue = csvValue($row, $map, ['jumlah_stok', 'stok', 'jumlah', 'quantity']);
        $quantity = (int)preg_replace('/[^0-9]/', '', $quantityValue);
        $branchId = $isAdmin ? 0 : $currentBranchId;
        if ($isAdmin) {
            $branchStmt = $pdo->prepare('SELECT id_cabang FROM cabang WHERE nama_cabang = ?');
            $branchStmt->execute([$branchName]);
            $branchId = (int)$branchStmt->fetchColumn();
        }
        $productStmt = $pdo->prepare('SELECT id_produk FROM produk WHERE kode_barcode = ?');
        $productStmt->execute([$barcode]);
        $productId = (int)$productStmt->fetchColumn();
        if ($branchId <= 0 || $productId <= 0 || $quantity < 0) {
            $failed++;
            if (count($errors) < 5) $errors[] = 'Stok tidak valid: ' . ($barcode ?: 'barcode kosong');
            continue;
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO stok_cabang (id_cabang, id_produk, jumlah_stok) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE jumlah_stok = VALUES(jumlah_stok)');
            $stmt->execute([$branchId, $productId, $quantity]);
            $success++;
        } catch (PDOException $e) {
            $failed++;
            if (count($errors) < 5) $errors[] = 'Gagal menyimpan stok ' . $barcode . '.';
        }
    }
    fclose($handle);
    return compact('success', 'failed', 'errors');
}
