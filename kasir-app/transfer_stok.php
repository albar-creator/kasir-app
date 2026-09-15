<?php

function ensureTransferTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS transfer_stok (
        id_transfer INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_cabang_asal INT UNSIGNED NOT NULL,
        id_cabang_tujuan INT UNSIGNED NOT NULL,
        id_produk INT UNSIGNED NOT NULL,
        jumlah INT UNSIGNED NOT NULL,
        keterangan VARCHAR(255) NULL,
        id_user INT UNSIGNED NOT NULL,
        status ENUM('selesai', 'dibatalkan') NOT NULL DEFAULT 'selesai',
        tanggal TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
