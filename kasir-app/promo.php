<?php

function ensureDetailPenjualanTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS detail_penjualan (
        id_detail      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_penjualan   INT UNSIGNED NOT NULL,
        id_produk      INT UNSIGNED NOT NULL,
        qty            INT UNSIGNED NOT NULL DEFAULT 1,
        harga_satuan   INT UNSIGNED NOT NULL DEFAULT 0,
        subtotal       INT UNSIGNED NOT NULL DEFAULT 0,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_id_penjualan (id_penjualan),
        INDEX idx_id_produk (id_produk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensurePromoTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS promo (
        id_promo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nama_promo VARCHAR(120) NOT NULL,
        kode_promo VARCHAR(40) NOT NULL UNIQUE,
        tipe ENUM('persen', 'nominal') NOT NULL DEFAULT 'persen',
        nilai INT UNSIGNED NOT NULL,
        minimal_belanja INT UNSIGNED NOT NULL DEFAULT 0,
        tanggal_mulai DATE NOT NULL,
        tanggal_selesai DATE NOT NULL,
        id_produk INT UNSIGNED NULL,
        id_cabang INT UNSIGNED NULL,
        aktif TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getActivePromos(PDO $pdo, int $branchId): array
{
    ensurePromoTable($pdo);
    $stmt = $pdo->prepare("SELECT pr.*, p.nama_produk, c.nama_cabang
        FROM promo pr
        LEFT JOIN produk p ON p.id_produk = pr.id_produk
        LEFT JOIN cabang c ON c.id_cabang = pr.id_cabang
        WHERE pr.aktif = 1 AND CURDATE() BETWEEN pr.tanggal_mulai AND pr.tanggal_selesai
        AND (pr.id_cabang IS NULL OR pr.id_cabang = ?)
        ORDER BY pr.nilai DESC, pr.id_promo DESC");
    $stmt->execute([$branchId]);
    return $stmt->fetchAll();
}

function getAllPromos(PDO $pdo): array
{
    ensurePromoTable($pdo);
    return $pdo->query("SELECT pr.*, p.nama_produk, c.nama_cabang
        FROM promo pr
        LEFT JOIN produk p ON p.id_produk = pr.id_produk
        LEFT JOIN cabang c ON c.id_cabang = pr.id_cabang
        ORDER BY pr.id_promo DESC")->fetchAll();
}

function calculatePromo(PDO $pdo, array $cart, int $branchId, string $promoCode = ''): array
{
    ensurePromoTable($pdo);
    $promoCode = trim($promoCode);
    $today = date('Y-m-d');
    $sql = "SELECT * FROM promo WHERE aktif = 1 AND tanggal_mulai <= ? AND tanggal_selesai >= ?
        AND (id_cabang IS NULL OR id_cabang = ?)";
    $params = [$today, $today, $branchId];
    if ($promoCode !== '') {
        $sql .= ' AND kode_promo = ?';
        $params[] = $promoCode;
    }
    $sql .= ' ORDER BY nilai DESC, id_promo DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $promos = $stmt->fetchAll();

    $productIds = array_values(array_unique(array_map(static fn($item) => (int)($item['id_produk'] ?? 0), $cart)));
    $prices = [];
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $priceStmt = $pdo->prepare("SELECT id_produk, harga FROM produk WHERE id_produk IN ($placeholders)");
        $priceStmt->execute($productIds);
        foreach ($priceStmt->fetchAll() as $product) {
            $prices[(int)$product['id_produk']] = (int)$product['harga'];
        }
    }

    $subtotal = 0;
    foreach ($cart as $item) {
        $productId = (int)($item['id_produk'] ?? 0);
        $quantity = max(0, (int)($item['qty'] ?? 0));
        $subtotal += ($prices[$productId] ?? 0) * $quantity;
    }

    $selectedPromo = null;
    $discount = 0;
    foreach ($promos as $promo) {
        if ($subtotal < (int)$promo['minimal_belanja']) continue;
        $eligibleTotal = $subtotal;
        if ($promo['id_produk'] !== null) {
            $eligibleTotal = 0;
            foreach ($cart as $item) {
                if ((int)($item['id_produk'] ?? 0) === (int)$promo['id_produk']) {
                    $eligibleTotal += ($prices[(int)$promo['id_produk']] ?? 0) * max(0, (int)($item['qty'] ?? 0));
                }
            }
        }
        if ($eligibleTotal <= 0) continue;
        $candidate = $promo['tipe'] === 'persen'
            ? (int)floor($eligibleTotal * ((int)$promo['nilai'] / 100))
            : (int)$promo['nilai'];
        $candidate = min($candidate, $eligibleTotal, $subtotal);
        if ($candidate > $discount) {
            $discount = $candidate;
            $selectedPromo = $promo;
        }
    }

    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => max(0, $subtotal - $discount),
        'promo' => $selectedPromo,
        'message' => $promoCode !== '' && $selectedPromo === null ? 'Kode promo tidak berlaku untuk transaksi ini.' : '',
    ];
}
