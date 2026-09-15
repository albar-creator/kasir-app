<?php
session_start();
require 'koneksi.php';
require 'otorisasi.php';
require 'promo.php';
require 'transfer_stok.php';
require 'master_data_io.php';
ensurePromoTable($pdo);
ensureTransferTable($pdo);

if (!isset($_SESSION['id_user']) || !canAccessAdmin()) {
    header('Location: login.php');
    exit;
}

$message = '';

$editUser = null;
$editCabang = null;
$editProduk = null;
$editStok = null;
$editPromo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_user']) && hasPermission('manage_users')) {
        $id_user = (int)$_POST['id_user'];
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role = $_POST['role'];
        $id_cabang = (int)$_POST['id_cabang'];

        if (!canAssignRole($role)) {
            $message = 'Anda tidak memiliki hak untuk menetapkan role tersebut.';
            $role = '';
        }

        if ($_SESSION['role'] === 'admin_cabang') {
            $id_cabang = (int)$_SESSION['id_cabang'];
        }

        if ($role === '') {
            $message = 'Role user tidak valid untuk akun Anda.';
        } elseif ($username === '') {
            $message = 'Username tidak boleh kosong.';
        } else {
            if ($id_user > 0) {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $userUpdateSql = $_SESSION['role'] === 'admin' ? 'UPDATE users SET username = ?, password = ?, role = ?, id_cabang = ? WHERE id_user = ?' : 'UPDATE users SET username = ?, password = ?, role = ?, id_cabang = ? WHERE id_user = ? AND id_cabang = ?';
                    $userUpdateParams = [$username, $hash, $role, $id_cabang, $id_user];
                    if ($_SESSION['role'] !== 'admin') $userUpdateParams[] = (int)$_SESSION['id_cabang'];
                    $stmt = $pdo->prepare($userUpdateSql);
                    $stmt->execute($userUpdateParams);
                } else {
                    $userUpdateSql = $_SESSION['role'] === 'admin' ? 'UPDATE users SET username = ?, role = ?, id_cabang = ? WHERE id_user = ?' : 'UPDATE users SET username = ?, role = ?, id_cabang = ? WHERE id_user = ? AND id_cabang = ?';
                    $userUpdateParams = [$username, $role, $id_cabang, $id_user];
                    if ($_SESSION['role'] !== 'admin') $userUpdateParams[] = (int)$_SESSION['id_cabang'];
                    $stmt = $pdo->prepare($userUpdateSql);
                    $stmt->execute($userUpdateParams);
                }
                $message = 'User berhasil diperbarui.';
            } else {
                if ($password === '') {
                    $message = 'Password wajib diisi untuk user baru.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (username, password, role, id_cabang) VALUES (?, ?, ?, ?)');
                    try {
                        $stmt->execute([$username, $hash, $role, $id_cabang]);
                        $message = 'User berhasil ditambahkan.';
                    } catch (PDOException $e) {
                        $message = 'Username sudah ada atau data tidak valid.';
                    }
                }
            }
        }
    }

    if (isset($_POST['delete_user']) && hasPermission('manage_users')) {
        $id_user = (int)$_POST['id_user'];
        if ($id_user > 0) {
            $deleteUserSql = $_SESSION['role'] === 'admin' ? 'DELETE FROM users WHERE id_user = ?' : 'DELETE FROM users WHERE id_user = ? AND id_cabang = ?';
            $deleteUserParams = $_SESSION['role'] === 'admin' ? [$id_user] : [$id_user, (int)$_SESSION['id_cabang']];
            $pdo->prepare($deleteUserSql)->execute($deleteUserParams);
            $message = 'User berhasil dihapus.';
        }
    }

    if (isset($_POST['save_cabang']) && hasPermission('manage_cabang')) {
        $id_cabang = (int)$_POST['id_cabang'];
        $nama_cabang = trim($_POST['nama_cabang']);
        $alamat = trim($_POST['alamat']);

        if ($nama_cabang === '') {
            $message = 'Nama cabang wajib diisi.';
        } else {
            if ($id_cabang > 0) {
                $pdo->prepare('UPDATE cabang SET nama_cabang = ?, alamat = ? WHERE id_cabang = ?')->execute([$nama_cabang, $alamat, $id_cabang]);
                $message = 'Cabang berhasil diperbarui.';
            } else {
                $pdo->prepare('INSERT INTO cabang (nama_cabang, alamat) VALUES (?, ?)')->execute([$nama_cabang, $alamat]);
                $message = 'Cabang berhasil ditambahkan.';
            }
        }
    }

    if (isset($_POST['delete_cabang']) && hasPermission('manage_cabang')) {
        $id_cabang = (int)$_POST['id_cabang'];
        if ($id_cabang > 0) {
            $pdo->prepare('DELETE FROM cabang WHERE id_cabang = ?')->execute([$id_cabang]);
            $message = 'Cabang berhasil dihapus.';
        }
    }

    if (isset($_POST['save_produk']) && hasPermission('manage_produk')) {
        $id_produk = (int)$_POST['id_produk'];
        $kode_barcode = trim($_POST['kode_barcode']);
        $nama_produk = trim($_POST['nama_produk']);
        $harga = (int)$_POST['harga'];

        if ($kode_barcode === '' || $nama_produk === '' || $harga <= 0) {
            $message = 'Kode barcode, nama produk, dan harga wajib valid.';
        } else {
            if ($id_produk > 0) {
                $pdo->prepare('UPDATE produk SET kode_barcode = ?, nama_produk = ?, harga = ? WHERE id_produk = ?')->execute([$kode_barcode, $nama_produk, $harga, $id_produk]);
                $message = 'Produk berhasil diperbarui.';
            } else {
                $pdo->prepare('INSERT INTO produk (kode_barcode, nama_produk, harga) VALUES (?, ?, ?)')->execute([$kode_barcode, $nama_produk, $harga]);
                $message = 'Produk berhasil ditambahkan.';
            }
        }
    }

    if (isset($_POST['delete_produk']) && hasPermission('manage_produk')) {
        $id_produk = (int)$_POST['id_produk'];
        if ($id_produk > 0) {
            $pdo->prepare('DELETE FROM produk WHERE id_produk = ?')->execute([$id_produk]);
            $message = 'Produk berhasil dihapus.';
        }
    }

    if (isset($_POST['import_produk']) && hasPermission('manage_produk')) {
        if (!isset($_FILES['file_produk']) || $_FILES['file_produk']['error'] !== UPLOAD_ERR_OK) {
            $message = 'File CSV produk belum dipilih atau tidak dapat dibaca.';
        } else {
            $result = importProductsCsv($pdo, $_FILES['file_produk']['tmp_name']);
            $message = 'Import produk selesai: ' . $result['success'] . ' berhasil, ' . $result['failed'] . ' gagal.';
            if (!empty($result['errors'])) $message .= ' ' . implode(' ', $result['errors']);
        }
    }

    if (isset($_POST['save_stok']) && hasPermission('manage_stok')) {
        $id_stok = (int)$_POST['id_stok'];
        $id_cabang = (int)$_POST['id_cabang'];
        $id_produk = (int)$_POST['id_produk'];
        $jumlah_stok = (int)$_POST['jumlah_stok'];

        if ($_SESSION['role'] !== 'admin') {
            $id_cabang = (int)$_SESSION['id_cabang'];
        }

        if ($id_cabang > 0 && $id_produk > 0 && $jumlah_stok >= 0) {
            if ($id_stok > 0) {
                $stockUpdateSql = $_SESSION['role'] === 'admin' ? 'UPDATE stok_cabang SET id_cabang = ?, id_produk = ?, jumlah_stok = ? WHERE id_stok = ?' : 'UPDATE stok_cabang SET id_cabang = ?, id_produk = ?, jumlah_stok = ? WHERE id_stok = ? AND id_cabang = ?';
                $stockUpdateParams = [$id_cabang, $id_produk, $jumlah_stok, $id_stok];
                if ($_SESSION['role'] !== 'admin') $stockUpdateParams[] = (int)$_SESSION['id_cabang'];
                $pdo->prepare($stockUpdateSql)->execute($stockUpdateParams);
                $message = 'Stok cabang berhasil diperbarui.';
            } else {
                $pdo->prepare('INSERT INTO stok_cabang (id_cabang, id_produk, jumlah_stok) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE jumlah_stok = VALUES(jumlah_stok)')->execute([$id_cabang, $id_produk, $jumlah_stok]);
                $message = 'Stok cabang berhasil ditambah.';
            }
        } else {
            $message = 'Data stok tidak valid.';
        }
    }

    if (isset($_POST['delete_stok']) && hasPermission('manage_stok')) {
        $id_stok = (int)$_POST['id_stok'];
        if ($id_stok > 0) {
            $deleteStockSql = $_SESSION['role'] === 'admin' ? 'DELETE FROM stok_cabang WHERE id_stok = ?' : 'DELETE FROM stok_cabang WHERE id_stok = ? AND id_cabang = ?';
            $deleteStockParams = $_SESSION['role'] === 'admin' ? [$id_stok] : [$id_stok, (int)$_SESSION['id_cabang']];
            $pdo->prepare($deleteStockSql)->execute($deleteStockParams);
            $message = 'Stok cabang berhasil dihapus.';
        }
    }

    if (isset($_POST['import_stok']) && hasPermission('manage_stok')) {
        if (!isset($_FILES['file_stok']) || $_FILES['file_stok']['error'] !== UPLOAD_ERR_OK) {
            $message = 'File CSV stok belum dipilih atau tidak dapat dibaca.';
        } else {
            $result = importStocksCsv($pdo, $_FILES['file_stok']['tmp_name'], (int)$_SESSION['id_cabang'], $_SESSION['role'] === 'admin');
            $message = 'Import stok selesai: ' . $result['success'] . ' berhasil, ' . $result['failed'] . ' gagal.';
            if (!empty($result['errors'])) $message .= ' ' . implode(' ', $result['errors']);
        }
    }

    if (isset($_POST['save_transfer']) && hasPermission('manage_stok')) {
        $sourceBranch = (int)$_POST['id_cabang_asal'];
        $targetBranch = (int)$_POST['id_cabang_tujuan'];
        $transferProduct = (int)$_POST['id_produk_transfer'];
        $transferQuantity = (int)$_POST['jumlah_transfer'];
        $transferNote = trim($_POST['keterangan_transfer']);
        if ($_SESSION['role'] !== 'admin') $sourceBranch = (int)$_SESSION['id_cabang'];

        if ($sourceBranch <= 0 || $targetBranch <= 0 || $sourceBranch === $targetBranch || $transferProduct <= 0 || $transferQuantity <= 0) {
            $message = 'Data transfer tidak valid. Cabang asal dan tujuan harus berbeda.';
        } else {
            try {
                $pdo->beginTransaction();
                $stockCheck = $pdo->prepare('SELECT jumlah_stok FROM stok_cabang WHERE id_cabang = ? AND id_produk = ? FOR UPDATE');
                $stockCheck->execute([$sourceBranch, $transferProduct]);
                $sourceStock = $stockCheck->fetchColumn();
                if ($sourceStock === false || (int)$sourceStock < $transferQuantity) {
                    throw new RuntimeException('Stok cabang asal tidak mencukupi.');
                }
                $pdo->prepare('UPDATE stok_cabang SET jumlah_stok = jumlah_stok - ? WHERE id_cabang = ? AND id_produk = ?')->execute([$transferQuantity, $sourceBranch, $transferProduct]);
                $pdo->prepare('INSERT INTO stok_cabang (id_cabang, id_produk, jumlah_stok) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE jumlah_stok = jumlah_stok + VALUES(jumlah_stok)')->execute([$targetBranch, $transferProduct, $transferQuantity]);
                $pdo->prepare('INSERT INTO transfer_stok (id_cabang_asal, id_cabang_tujuan, id_produk, jumlah, keterangan, id_user) VALUES (?, ?, ?, ?, ?, ?)')->execute([$sourceBranch, $targetBranch, $transferProduct, $transferQuantity, $transferNote ?: null, (int)$_SESSION['id_user']]);
                $pdo->commit();
                $message = 'Transfer stok berhasil diselesaikan.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = 'Transfer stok gagal: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['save_promo']) && $_SESSION['role'] === 'admin') {
        $idPromo = (int)$_POST['id_promo'];
        $namaPromo = trim($_POST['nama_promo']);
        $kodePromo = strtoupper(trim($_POST['kode_promo']));
        $tipePromo = $_POST['tipe_promo'] === 'nominal' ? 'nominal' : 'persen';
        $nilaiPromo = max(0, (int)$_POST['nilai_promo']);
        $minimalBelanja = max(0, (int)$_POST['minimal_belanja']);
        $tanggalMulai = $_POST['tanggal_mulai'];
        $tanggalSelesai = $_POST['tanggal_selesai'];
        $idProdukPromo = (int)$_POST['id_produk_promo'] ?: null;
        $idCabangPromo = (int)$_POST['id_cabang_promo'] ?: null;
        $aktifPromo = isset($_POST['aktif_promo']) ? 1 : 0;

        if ($namaPromo === '' || $kodePromo === '' || $nilaiPromo <= 0 || $tanggalMulai === '' || $tanggalSelesai === '' || $tanggalMulai > $tanggalSelesai || ($tipePromo === 'persen' && $nilaiPromo > 100)) {
            $message = 'Data promo tidak valid. Periksa kode, nilai, periode, dan batas diskon persen.';
        } else {
            try {
                if ($idPromo > 0) {
                    $stmt = $pdo->prepare('UPDATE promo SET nama_promo = ?, kode_promo = ?, tipe = ?, nilai = ?, minimal_belanja = ?, tanggal_mulai = ?, tanggal_selesai = ?, id_produk = ?, id_cabang = ?, aktif = ? WHERE id_promo = ?');
                    $stmt->execute([$namaPromo, $kodePromo, $tipePromo, $nilaiPromo, $minimalBelanja, $tanggalMulai, $tanggalSelesai, $idProdukPromo, $idCabangPromo, $aktifPromo, $idPromo]);
                    $message = 'Promo berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO promo (nama_promo, kode_promo, tipe, nilai, minimal_belanja, tanggal_mulai, tanggal_selesai, id_produk, id_cabang, aktif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$namaPromo, $kodePromo, $tipePromo, $nilaiPromo, $minimalBelanja, $tanggalMulai, $tanggalSelesai, $idProdukPromo, $idCabangPromo, $aktifPromo]);
                    $message = 'Promo berhasil ditambahkan.';
                }
            } catch (PDOException $e) {
                $message = 'Kode promo sudah digunakan atau data promo tidak valid.';
            }
        }
    }

    if (isset($_POST['delete_promo']) && $_SESSION['role'] === 'admin') {
        $pdo->prepare('DELETE FROM promo WHERE id_promo = ?')->execute([(int)$_POST['id_promo']]);
        $message = 'Promo berhasil dihapus.';
    }
}

if (isset($_GET['edit_user'])) {
    $editUserSql = $_SESSION['role'] === 'admin' ? 'SELECT * FROM users WHERE id_user = ?' : 'SELECT * FROM users WHERE id_user = ? AND id_cabang = ?';
    $editUserParams = $_SESSION['role'] === 'admin' ? [(int)$_GET['edit_user']] : [(int)$_GET['edit_user'], (int)$_SESSION['id_cabang']];
    $editUser = $pdo->prepare($editUserSql);
    $editUser->execute($editUserParams);
    $editUser = $editUser->fetch();
}
if (isset($_GET['edit_cabang'])) {
    $editCabang = $pdo->prepare('SELECT * FROM cabang WHERE id_cabang = ?');
    $editCabang->execute([(int)$_GET['edit_cabang']]);
    $editCabang = $editCabang->fetch();
}
if (isset($_GET['edit_produk'])) {
    $editProduk = $pdo->prepare('SELECT * FROM produk WHERE id_produk = ?');
    $editProduk->execute([(int)$_GET['edit_produk']]);
    $editProduk = $editProduk->fetch();
}
if (isset($_GET['edit_stok'])) {
    $editStokSql = $_SESSION['role'] === 'admin' ? 'SELECT * FROM stok_cabang WHERE id_stok = ?' : 'SELECT * FROM stok_cabang WHERE id_stok = ? AND id_cabang = ?';
    $editStokParams = $_SESSION['role'] === 'admin' ? [(int)$_GET['edit_stok']] : [(int)$_GET['edit_stok'], (int)$_SESSION['id_cabang']];
    $editStok = $pdo->prepare($editStokSql);
    $editStok->execute($editStokParams);
    $editStok = $editStok->fetch();
}
if (isset($_GET['edit_promo']) && $_SESSION['role'] === 'admin') {
    $editPromo = $pdo->prepare('SELECT * FROM promo WHERE id_promo = ?');
    $editPromo->execute([(int)$_GET['edit_promo']]);
    $editPromo = $editPromo->fetch();
}

$startDate = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : date('Y-m-d');
$isBranchScoped = in_array($_SESSION['role'] ?? '', ['admin_cabang', 'kepala_cabang', 'gudang'], true);
$branchId = (int)($_SESSION['id_cabang'] ?? 0);

$users = $isBranchScoped
    ? $pdo->prepare('SELECT u.*, c.nama_cabang FROM users u LEFT JOIN cabang c ON c.id_cabang = u.id_cabang WHERE u.id_cabang = ? ORDER BY u.id_user DESC')
    : $pdo->query('SELECT u.*, c.nama_cabang FROM users u LEFT JOIN cabang c ON c.id_cabang = u.id_cabang ORDER BY u.id_user DESC');
if ($isBranchScoped) $users->execute([$branchId]);
$users = $users->fetchAll();
$cabangs = $isBranchScoped
    ? $pdo->prepare('SELECT * FROM cabang WHERE id_cabang = ? ORDER BY id_cabang ASC')
    : $pdo->query('SELECT * FROM cabang ORDER BY id_cabang ASC');
if ($isBranchScoped) $cabangs->execute([$branchId]);
$cabangs = $cabangs->fetchAll();
$allCabangs = $pdo->query('SELECT * FROM cabang ORDER BY nama_cabang ASC')->fetchAll();
$produks = $pdo->query('SELECT * FROM produk ORDER BY id_produk DESC')->fetchAll();
$promos = getAllPromos($pdo);
$transfers = $isBranchScoped
    ? $pdo->prepare('SELECT t.*, ca.nama_cabang AS cabang_asal, ct.nama_cabang AS cabang_tujuan, p.nama_produk, u.username FROM transfer_stok t LEFT JOIN cabang ca ON ca.id_cabang = t.id_cabang_asal LEFT JOIN cabang ct ON ct.id_cabang = t.id_cabang_tujuan LEFT JOIN produk p ON p.id_produk = t.id_produk LEFT JOIN users u ON u.id_user = t.id_user WHERE t.id_cabang_asal = ? OR t.id_cabang_tujuan = ? ORDER BY t.id_transfer DESC LIMIT 20')
    : $pdo->query('SELECT t.*, ca.nama_cabang AS cabang_asal, ct.nama_cabang AS cabang_tujuan, p.nama_produk, u.username FROM transfer_stok t LEFT JOIN cabang ca ON ca.id_cabang = t.id_cabang_asal LEFT JOIN cabang ct ON ct.id_cabang = t.id_cabang_tujuan LEFT JOIN produk p ON p.id_produk = t.id_produk LEFT JOIN users u ON u.id_user = t.id_user ORDER BY t.id_transfer DESC LIMIT 20');
if ($isBranchScoped) $transfers->execute([$branchId, $branchId]);
$transfers = $transfers->fetchAll();
$stok = $isBranchScoped
    ? $pdo->prepare('SELECT s.*, c.nama_cabang, p.nama_produk FROM stok_cabang s LEFT JOIN cabang c ON c.id_cabang = s.id_cabang LEFT JOIN produk p ON p.id_produk = s.id_produk WHERE s.id_cabang = ? ORDER BY s.id_stok DESC')
    : $pdo->query('SELECT s.*, c.nama_cabang, p.nama_produk FROM stok_cabang s LEFT JOIN cabang c ON c.id_cabang = s.id_cabang LEFT JOIN produk p ON p.id_produk = s.id_produk ORDER BY s.id_stok DESC');
if ($isBranchScoped) $stok->execute([$branchId]);
$stok = $stok->fetchAll();
$penjualan = $isBranchScoped
    ? $pdo->prepare('SELECT p.*, c.nama_cabang, u.username FROM penjualan p LEFT JOIN cabang c ON c.id_cabang = p.id_cabang LEFT JOIN users u ON u.id_user = p.id_kasir WHERE p.id_cabang = ? ORDER BY p.id_penjualan DESC LIMIT 20')
    : $pdo->query('SELECT p.*, c.nama_cabang, u.username FROM penjualan p LEFT JOIN cabang c ON c.id_cabang = p.id_cabang LEFT JOIN users u ON u.id_user = p.id_kasir ORDER BY p.id_penjualan DESC LIMIT 20');
if ($isBranchScoped) $penjualan->execute([$branchId]);
$penjualan = $penjualan->fetchAll();

$totalTransaksi = $isBranchScoped
    ? $pdo->prepare('SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(total_bayar), 0) AS total_pendapatan FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ? AND id_cabang = ?')
    : $pdo->prepare('SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(total_bayar), 0) AS total_pendapatan FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ?');
$isBranchScoped ? $totalTransaksi->execute([$startDate, $endDate, $branchId]) : $totalTransaksi->execute([$startDate, $endDate]);
$totalTransaksi = $totalTransaksi->fetch();

$todayTransaksi = $pdo->prepare('SELECT COUNT(*) AS total_hari_ini, COALESCE(SUM(total_bayar), 0) AS pendapatan_hari_ini FROM penjualan WHERE DATE(tanggal) = ?');
$isBranchScoped ? $todayTransaksi = $pdo->prepare('SELECT COUNT(*) AS total_hari_ini, COALESCE(SUM(total_bayar), 0) AS pendapatan_hari_ini FROM penjualan WHERE DATE(tanggal) = ? AND id_cabang = ?') : null;
$isBranchScoped ? $todayTransaksi->execute([date('Y-m-d'), $branchId]) : $todayTransaksi->execute([date('Y-m-d')]);
$todayTransaksi = $todayTransaksi->fetch();

$branchSales = $isBranchScoped
    ? $pdo->prepare('SELECT c.nama_cabang, COUNT(p.id_penjualan) AS total_transaksi, COALESCE(SUM(p.total_bayar), 0) AS total_pendapatan FROM cabang c LEFT JOIN penjualan p ON p.id_cabang = c.id_cabang AND DATE(p.tanggal) BETWEEN ? AND ? WHERE c.id_cabang = ? GROUP BY c.id_cabang, c.nama_cabang ORDER BY total_pendapatan DESC')
    : $pdo->prepare('SELECT c.nama_cabang, COUNT(p.id_penjualan) AS total_transaksi, COALESCE(SUM(p.total_bayar), 0) AS total_pendapatan FROM cabang c LEFT JOIN penjualan p ON p.id_cabang = c.id_cabang AND DATE(p.tanggal) BETWEEN ? AND ? GROUP BY c.id_cabang, c.nama_cabang ORDER BY total_pendapatan DESC');
$isBranchScoped ? $branchSales->execute([$startDate, $endDate, $branchId]) : $branchSales->execute([$startDate, $endDate]);
$branchSales = $branchSales->fetchAll();

$lowStock = $isBranchScoped
    ? $pdo->prepare('SELECT s.id_stok, c.nama_cabang, p.nama_produk, s.jumlah_stok FROM stok_cabang s LEFT JOIN cabang c ON c.id_cabang = s.id_cabang LEFT JOIN produk p ON p.id_produk = s.id_produk WHERE s.jumlah_stok <= 10 AND s.id_cabang = ? ORDER BY s.jumlah_stok ASC, c.nama_cabang ASC')
    : $pdo->query('SELECT s.id_stok, c.nama_cabang, p.nama_produk, s.jumlah_stok FROM stok_cabang s LEFT JOIN cabang c ON c.id_cabang = s.id_cabang LEFT JOIN produk p ON p.id_produk = s.id_produk WHERE s.jumlah_stok <= 10 ORDER BY s.jumlah_stok ASC, c.nama_cabang ASC');
if ($isBranchScoped) $lowStock->execute([$branchId]);
$lowStock = $lowStock->fetchAll();

$totalStok = $isBranchScoped
    ? $pdo->prepare('SELECT COALESCE(SUM(jumlah_stok), 0) AS total_stok FROM stok_cabang WHERE id_cabang = ?')
    : $pdo->query('SELECT COALESCE(SUM(jumlah_stok), 0) AS total_stok FROM stok_cabang');
if ($isBranchScoped) $totalStok->execute([$branchId]);
$totalStok = $totalStok->fetch();

$dailySales = $isBranchScoped
    ? $pdo->prepare('SELECT DATE(tanggal) AS tanggal, COUNT(*) AS jumlah_transaksi, COALESCE(SUM(total_bayar), 0) AS total_pendapatan FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ? AND id_cabang = ? GROUP BY DATE(tanggal) ORDER BY DATE(tanggal) DESC LIMIT 7')
    : $pdo->prepare('SELECT DATE(tanggal) AS tanggal, COUNT(*) AS jumlah_transaksi, COALESCE(SUM(total_bayar), 0) AS total_pendapatan FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ? GROUP BY DATE(tanggal) ORDER BY DATE(tanggal) DESC LIMIT 7');
$isBranchScoped ? $dailySales->execute([$startDate, $endDate, $branchId]) : $dailySales->execute([$startDate, $endDate]);
$dailySales = $dailySales->fetchAll();

$monthlySales = $isBranchScoped
    ? $pdo->prepare('SELECT DATE_FORMAT(tanggal, "%Y-%m") AS bulan, COUNT(*) AS jumlah_transaksi, COALESCE(SUM(total_bayar), 0) AS total_pendapatan FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ? AND id_cabang = ? GROUP BY DATE_FORMAT(tanggal, "%Y-%m") ORDER BY bulan DESC LIMIT 6')
    : $pdo->prepare('SELECT DATE_FORMAT(tanggal, "%Y-%m") AS bulan, COUNT(*) AS jumlah_transaksi, COALESCE(SUM(total_bayar), 0) AS total_pendapatan FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ? GROUP BY DATE_FORMAT(tanggal, "%Y-%m") ORDER BY bulan DESC LIMIT 6');
$isBranchScoped ? $monthlySales->execute([$startDate, $endDate, $branchId]) : $monthlySales->execute([$startDate, $endDate]);
$monthlySales = $monthlySales->fetchAll();

$currentRole = $_SESSION['role'] ?? '';
$assignableRoles = $roleAssignments[$currentRole] ?? [];
$canManageMasterData = hasPermission('manage_users') || hasPermission('manage_cabang') || hasPermission('manage_produk') || hasPermission('manage_stok');
$permissionDescriptions = [
    'admin' => 'Akses penuh seluruh cabang, pengguna, master data, POS, laporan, dan export.',
    'admin_cabang' => 'Mengelola user, produk, dan stok operasional cabang serta laporan.',
    'kepala_cabang' => 'Memantau dashboard dan laporan serta mengatur stok cabang.',
    'accounting' => 'Melihat dashboard, laporan penjualan, dan melakukan export laporan.',
    'gudang' => 'Memantau dashboard dan mengelola stok cabang.',
    'auditor' => 'Akses baca untuk dashboard dan laporan tanpa dapat mengubah data.',
    'kasir' => 'Memproses penjualan melalui POS dan scanner barcode.',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-tabs { position: sticky; top: 0; z-index: 1020; }
        .admin-section { scroll-margin-top: 86px; }
        @media (max-width: 575.98px) {
            .admin-tabs .nav-link { padding: .75rem .85rem; font-size: 1rem; }
        }
    </style>
</head>
<body class="app-body">
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">Admin Panel - Kasir Multi Cabang</span>
            <span class="text-white"><a href="dashboard.php" class="text-white me-2">Dashboard</a><?= htmlspecialchars(roleLabel($currentRole)) ?>: <?= htmlspecialchars($_SESSION['username']) ?> | <?php if (hasPermission('access_pos')): ?><a href="kasir.php" class="text-info me-2">Buka POS</a><?php endif; ?><a href="logout.php" class="text-danger">Logout</a></span>
        </div>
    </nav>

    <div class="container py-4">
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <nav class="admin-tabs bg-white border rounded shadow-sm mb-4" aria-label="Menu admin">
            <ul class="nav nav-tabs border-0 px-2 pt-2 flex-nowrap overflow-auto">
                <li class="nav-item"><a class="nav-link" href="#dashboard">Dashboard</a></li>
                <?php if ($canManageMasterData): ?><li class="nav-item"><a class="nav-link" href="#master-data">Master Data</a></li><?php endif; ?>
                <?php if ($_SESSION['role'] === 'admin'): ?><li class="nav-item"><a class="nav-link" href="#promo">Promo</a></li><?php endif; ?>
                <?php if (hasPermission('view_reports')): ?><li class="nav-item"><a class="nav-link" href="#laporan">Laporan</a></li><?php endif; ?>
                <li class="nav-item"><a class="nav-link" href="#daftar-data">Daftar Data</a></li>
                <?php if (hasPermission('access_pos')): ?><li class="nav-item ms-auto"><a class="nav-link text-success" href="kasir.php">POS Kasir</a></li><?php endif; ?>
            </ul>
        </nav>

        <section id="dashboard" class="admin-section">
        <form method="GET" class="row g-2 align-items-end mb-4">
            <div class="col-md-3">
                <label class="form-label mb-1">Tanggal Mulai</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Tanggal Selesai</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="admin.php" class="btn btn-outline-secondary">Reset</a>
            </div>
            <div class="col-md-4 text-md-end">
                <small class="text-muted">Periode aktif: <?= htmlspecialchars($startDate) ?> s.d <?= htmlspecialchars($endDate) ?></small>
            </div>
        </form>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Total Pendapatan</div>
                        <h4 class="mt-2 mb-0">Rp <?= number_format((int)($totalTransaksi['total_pendapatan'] ?? 0), 0, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Total Transaksi</div>
                        <h4 class="mt-2 mb-0"><?= number_format((int)($totalTransaksi['total_transaksi'] ?? 0), 0, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Hari Ini</div>
                        <h4 class="mt-2 mb-0">Rp <?= number_format((int)($todayTransaksi['pendapatan_hari_ini'] ?? 0), 0, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Total Stok</div>
                        <h4 class="mt-2 mb-0"><?= number_format((int)($totalStok['total_stok'] ?? 0), 0, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="mb-3">Role Aktif: <?= htmlspecialchars(roleLabel($currentRole)) ?></h5>
                <p class="text-muted mb-0"><?= htmlspecialchars($permissionDescriptions[$currentRole] ?? 'Hak akses belum didefinisikan.') ?></p>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Performa Cabang</h5>
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Cabang</th>
                                    <th>Transaksi</th>
                                    <th>Pendapatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($branchSales as $branch): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($branch['nama_cabang'] ?? '-') ?></td>
                                        <td><?= (int)$branch['total_transaksi'] ?></td>
                                        <td>Rp <?= number_format((int)$branch['total_pendapatan'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h5>Stok Rendah</h5>
                        <ul class="list-group list-group-flush">
                            <?php if (empty($lowStock)): ?>
                                <li class="list-group-item px-0">Semua stok aman.</li>
                            <?php else: ?>
                                <?php foreach ($lowStock as $item): ?>
                                    <li class="list-group-item px-0">
                                        <div class="d-flex justify-content-between">
                                            <span><?= htmlspecialchars($item['nama_produk'] ?? '-') ?></span>
                                            <strong><?= (int)$item['jumlah_stok'] ?></strong>
                                        </div>
                                        <small class="text-muted"><?= htmlspecialchars($item['nama_cabang'] ?? '-') ?></small>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Trend Penjualan Harian</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Transaksi</th>
                                        <th>Pendapatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dailySales as $daily): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($daily['tanggal']) ?></td>
                                            <td><?= (int)$daily['jumlah_transaksi'] ?></td>
                                            <td>Rp <?= number_format((int)$daily['total_pendapatan'], 0, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Trend Penjualan Bulanan</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Bulan</th>
                                        <th>Transaksi</th>
                                        <th>Pendapatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthlySales as $monthly): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($monthly['bulan']) ?></td>
                                            <td><?= (int)$monthly['jumlah_transaksi'] ?></td>
                                            <td>Rp <?= number_format((int)$monthly['total_pendapatan'], 0, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </section>
        <section id="master-data" class="admin-section" data-permitted="<?= $canManageMasterData ? '1' : '0' ?>">
        <nav class="master-tabs bg-white border rounded shadow-sm mb-4" aria-label="Menu master data">
            <ul class="nav nav-tabs border-0 px-2 pt-2 flex-nowrap overflow-auto">
                <?php if (hasPermission('manage_users')): ?><li class="nav-item"><a class="nav-link active" href="#" data-master-tab="master-users">User</a></li><?php endif; ?>
                <?php if (hasPermission('manage_cabang')): ?><li class="nav-item"><a class="nav-link" href="#" data-master-tab="master-cabang">Cabang</a></li><?php endif; ?>
                <?php if (hasPermission('manage_produk')): ?><li class="nav-item"><a class="nav-link" href="#" data-master-tab="master-produk">Produk</a></li><?php endif; ?>
                <?php if (hasPermission('manage_stok')): ?><li class="nav-item"><a class="nav-link" href="#" data-master-tab="master-stok">Stok Cabang</a></li><li class="nav-item"><a class="nav-link" href="#" data-master-tab="master-transfer">Transfer Stok</a></li><?php endif; ?>
            </ul>
        </nav>
        <?php if (hasPermission('manage_stok')): ?>
        <div id="master-transfer" class="master-pane row g-4 mt-1">
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Transfer Stok Antar Cabang</h5>
                        <p class="text-muted small">Stok asal dikurangi dan stok tujuan ditambah dalam satu transaksi.</p>
                        <form method="POST">
                            <div class="mb-3"><label class="form-label">Cabang Asal</label><select name="id_cabang_asal" class="form-select" <?= $_SESSION['role'] !== 'admin' ? 'disabled' : '' ?>><?php foreach (($_SESSION['role'] === 'admin' ? $allCabangs : $cabangs) as $c): ?><option value="<?= (int)$c['id_cabang'] ?>" <?= ((int)$c['id_cabang'] === (int)($_SESSION['id_cabang'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_cabang']) ?></option><?php endforeach; ?></select><?php if ($_SESSION['role'] !== 'admin'): ?><input type="hidden" name="id_cabang_asal" value="<?= (int)$_SESSION['id_cabang'] ?>"><?php endif; ?></div>
                            <div class="mb-3"><label class="form-label">Cabang Tujuan</label><select name="id_cabang_tujuan" class="form-select" required><option value="">Pilih cabang tujuan</option><?php foreach ($allCabangs as $c): ?><option value="<?= (int)$c['id_cabang'] ?>"><?= htmlspecialchars($c['nama_cabang']) ?></option><?php endforeach; ?></select></div>
                            <div class="mb-3"><label class="form-label">Produk</label><select name="id_produk_transfer" class="form-select" required><option value="">Pilih produk</option><?php foreach ($produks as $p): ?><option value="<?= (int)$p['id_produk'] ?>"><?= htmlspecialchars($p['nama_produk']) ?> (<?= htmlspecialchars($p['kode_barcode']) ?>)</option><?php endforeach; ?></select></div>
                            <div class="mb-3"><label class="form-label">Jumlah Transfer</label><input type="number" name="jumlah_transfer" class="form-control" min="1" required></div>
                            <div class="mb-3"><label class="form-label">Keterangan (opsional)</label><textarea name="keterangan_transfer" class="form-control" rows="2" placeholder="Contoh: Pengisian stok mingguan"></textarea></div>
                            <button type="submit" name="save_transfer" class="btn btn-primary">Transfer Stok</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body"><h5>Riwayat Transfer Terbaru</h5><div class="table-responsive"><table class="table table-striped table-sm"><thead><tr><th>Dari</th><th>Ke</th><th>Produk</th><th>Jumlah</th><th>Tanggal</th></tr></thead><tbody><?php if (empty($transfers)): ?><tr><td colspan="5" class="text-center text-muted">Belum ada transfer.</td></tr><?php else: foreach ($transfers as $transfer): ?><tr><td><?= htmlspecialchars($transfer['cabang_asal'] ?? '-') ?></td><td><?= htmlspecialchars($transfer['cabang_tujuan'] ?? '-') ?></td><td><?= htmlspecialchars($transfer['nama_produk'] ?? '-') ?></td><td><?= (int)$transfer['jumlah'] ?></td><td><?= htmlspecialchars($transfer['tanggal']) ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div></div></div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div id="master-users" class="col-md-6 master-pane">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5><?= $editUser ? 'Edit User' : 'Tambah User' ?></h5>
                        <form method="POST">
                            <input type="hidden" name="id_user" value="<?= htmlspecialchars((string)($editUser['id_user'] ?? 0)) ?>">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($editUser['username'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ingin mengubah">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <?php foreach ($assignableRoles as $roleValue): ?>
                                        <option value="<?= htmlspecialchars($roleValue) ?>" <?= (($editUser['role'] ?? 'kasir') === $roleValue) ? 'selected' : '' ?>><?= htmlspecialchars($roleLabels[$roleValue]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cabang</label>
                                <select name="id_cabang" class="form-select">
                                    <?php foreach ($cabangs as $c): ?>
                                        <option value="<?= $c['id_cabang'] ?>" <?= (($editUser['id_cabang'] ?? '') == $c['id_cabang']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_cabang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="save_user" class="btn btn-primary"><?= $editUser ? 'Simpan Perubahan' : 'Tambah User' ?></button>
                            <?php if ($editUser): ?>
                                <a href="admin.php" class="btn btn-outline-secondary ms-2">Batal</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <div id="master-cabang" class="col-md-6 master-pane">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5><?= $editCabang ? 'Edit Cabang' : 'Tambah Cabang' ?></h5>
                        <form method="POST">
                            <input type="hidden" name="id_cabang" value="<?= htmlspecialchars((string)($editCabang['id_cabang'] ?? 0)) ?>">
                            <div class="mb-3">
                                <label class="form-label">Nama Cabang</label>
                                <input type="text" name="nama_cabang" class="form-control" value="<?= htmlspecialchars($editCabang['nama_cabang'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Alamat</label>
                                <textarea name="alamat" class="form-control" rows="3"><?= htmlspecialchars($editCabang['alamat'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" name="save_cabang" class="btn btn-success"><?= $editCabang ? 'Simpan Perubahan' : 'Tambah Cabang' ?></button>
                            <?php if ($editCabang): ?>
                                <a href="admin.php" class="btn btn-outline-secondary ms-2">Batal</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <div id="master-produk" class="col-md-6 master-pane">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5><?= $editProduk ? 'Edit Produk' : 'Tambah Produk' ?></h5>
                        <?php if (hasPermission('manage_produk')): ?><div class="d-flex gap-2 flex-wrap mb-3"><a class="btn btn-sm btn-outline-success" href="export_master.php?data=produk&format=csv">Export CSV</a><a class="btn btn-sm btn-outline-primary" href="export_master.php?data=produk&format=xls">Export Excel</a></div><?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="id_produk" value="<?= htmlspecialchars((string)($editProduk['id_produk'] ?? 0)) ?>">
                            <div class="mb-3">
                                <label class="form-label">Kode Barcode</label>
                                <input type="text" name="kode_barcode" class="form-control" value="<?= htmlspecialchars($editProduk['kode_barcode'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nama Produk</label>
                                <input type="text" name="nama_produk" class="form-control" value="<?= htmlspecialchars($editProduk['nama_produk'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Harga</label>
                                <input type="number" name="harga" class="form-control" min="1" value="<?= htmlspecialchars((string)($editProduk['harga'] ?? 0)) ?>" required>
                            </div>
                            <button type="submit" name="save_produk" class="btn btn-warning"><?= $editProduk ? 'Simpan Perubahan' : 'Tambah Produk' ?></button>
                            <?php if ($editProduk): ?>
                                <a href="admin.php" class="btn btn-outline-secondary ms-2">Batal</a>
                            <?php endif; ?>
                        </form>
                        <?php if (hasPermission('manage_produk')): ?><hr><form method="POST" enctype="multipart/form-data"><label class="form-label">Import Produk dari CSV</label><input type="file" name="file_produk" class="form-control mb-2" accept=".csv,text/csv" required><small class="text-muted d-block mb-2">Kolom: kode_barcode, nama_produk, harga. CSV dari Excel/Google Sheets didukung.</small><button type="submit" name="import_produk" class="btn btn-outline-dark btn-sm">Import Produk</button></form><?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="master-stok" class="col-md-6 master-pane">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5><?= $editStok ? 'Edit Stok Cabang' : 'Tambah Stok Cabang' ?></h5>
                        <?php if (hasPermission('manage_stok')): ?><div class="d-flex gap-2 flex-wrap mb-3"><a class="btn btn-sm btn-outline-success" href="export_master.php?data=stok&format=csv">Export CSV</a><a class="btn btn-sm btn-outline-primary" href="export_master.php?data=stok&format=xls">Export Excel</a></div><?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="id_stok" value="<?= htmlspecialchars((string)($editStok['id_stok'] ?? 0)) ?>">
                            <div class="mb-3">
                                <label class="form-label">Cabang</label>
                                <select name="id_cabang" class="form-select">
                                    <?php foreach ($cabangs as $c): ?>
                                        <option value="<?= $c['id_cabang'] ?>" <?= (($editStok['id_cabang'] ?? '') == $c['id_cabang']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_cabang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Produk</label>
                                <select name="id_produk" class="form-select">
                                    <?php foreach ($produks as $p): ?>
                                        <option value="<?= $p['id_produk'] ?>" <?= (($editStok['id_produk'] ?? '') == $p['id_produk']) ? 'selected' : '' ?>><?= htmlspecialchars($p['nama_produk']) ?> (<?= htmlspecialchars($p['kode_barcode']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Jumlah Stok</label>
                                <input type="number" name="jumlah_stok" class="form-control" min="0" value="<?= htmlspecialchars((string)($editStok['jumlah_stok'] ?? 0)) ?>" required>
                            </div>
                            <button type="submit" name="save_stok" class="btn btn-info text-white"><?= $editStok ? 'Simpan Perubahan' : 'Tambah Stok' ?></button>
                            <?php if ($editStok): ?>
                                <a href="admin.php" class="btn btn-outline-secondary ms-2">Batal</a>
                            <?php endif; ?>
                        </form>
                        <?php if (hasPermission('manage_stok')): ?><hr><form method="POST" enctype="multipart/form-data"><label class="form-label">Import Stok dari CSV</label><input type="file" name="file_stok" class="form-control mb-2" accept=".csv,text/csv" required><small class="text-muted d-block mb-2">Kolom admin: nama_cabang, kode_barcode, jumlah_stok. Role cabang memakai cabangnya sendiri.</small><button type="submit" name="import_stok" class="btn btn-outline-dark btn-sm">Import Stok</button></form><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        </section>

        <section id="promo" class="admin-section" data-permitted="<?= $_SESSION['role'] === 'admin' ? '1' : '0' ?>">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5><?= $editPromo ? 'Edit Promo' : 'Buat Promo Baru' ?></h5>
                            <form method="POST">
                                <input type="hidden" name="id_promo" value="<?= (int)($editPromo['id_promo'] ?? 0) ?>">
                                <div class="mb-3"><label class="form-label">Nama Promo</label><input type="text" name="nama_promo" class="form-control" value="<?= htmlspecialchars($editPromo['nama_promo'] ?? '') ?>" placeholder="Contoh: Diskon Akhir Pekan" required></div>
                                <div class="mb-3"><label class="form-label">Kode Promo</label><input type="text" name="kode_promo" class="form-control" value="<?= htmlspecialchars($editPromo['kode_promo'] ?? '') ?>" placeholder="Contoh: HEMAT10" required></div>
                                <div class="row g-2">
                                    <div class="col-5"><label class="form-label">Tipe</label><select name="tipe_promo" class="form-select"><option value="persen" <?= (($editPromo['tipe'] ?? 'persen') === 'persen') ? 'selected' : '' ?>>Persen (%)</option><option value="nominal" <?= (($editPromo['tipe'] ?? '') === 'nominal') ? 'selected' : '' ?>>Nominal (Rp)</option></select></div>
                                    <div class="col-7"><label class="form-label">Nilai Diskon</label><input type="number" name="nilai_promo" class="form-control" min="1" value="<?= (int)($editPromo['nilai'] ?? 0) ?>" required></div>
                                </div>
                                <div class="mb-3 mt-3"><label class="form-label">Minimum Belanja</label><input type="number" name="minimal_belanja" class="form-control" min="0" value="<?= (int)($editPromo['minimal_belanja'] ?? 0) ?>"></div>
                                <div class="row g-2"><div class="col-6"><label class="form-label">Mulai</label><input type="date" name="tanggal_mulai" class="form-control" value="<?= htmlspecialchars($editPromo['tanggal_mulai'] ?? date('Y-m-d')) ?>" required></div><div class="col-6"><label class="form-label">Selesai</label><input type="date" name="tanggal_selesai" class="form-control" value="<?= htmlspecialchars($editPromo['tanggal_selesai'] ?? date('Y-m-d', strtotime('+30 days'))) ?>" required></div></div>
                                <div class="mb-3 mt-3"><label class="form-label">Produk Khusus (opsional)</label><select name="id_produk_promo" class="form-select"><option value="0">Semua Produk</option><?php foreach ($produks as $p): ?><option value="<?= (int)$p['id_produk'] ?>" <?= (($editPromo['id_produk'] ?? '') == $p['id_produk']) ? 'selected' : '' ?>><?= htmlspecialchars($p['nama_produk']) ?></option><?php endforeach; ?></select></div>
                                <div class="mb-3"><label class="form-label">Cabang (opsional)</label><select name="id_cabang_promo" class="form-select"><option value="0">Semua Cabang</option><?php foreach ($cabangs as $c): ?><option value="<?= (int)$c['id_cabang'] ?>" <?= (($editPromo['id_cabang'] ?? '') == $c['id_cabang']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_cabang']) ?></option><?php endforeach; ?></select></div>
                                <div class="form-check mb-3"><input type="checkbox" name="aktif_promo" class="form-check-input" id="aktifPromo" <?= (($editPromo['aktif'] ?? 1) ? 'checked' : '') ?>><label class="form-check-label" for="aktifPromo">Promo aktif</label></div>
                                <button type="submit" name="save_promo" class="btn btn-primary"><?= $editPromo ? 'Simpan Perubahan' : 'Buat Promo' ?></button><?php if ($editPromo): ?><a href="admin.php#promo" class="btn btn-outline-secondary ms-2">Batal</a><?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7"><div class="card shadow-sm"><div class="card-body"><h5>Daftar Promo</h5><div class="table-responsive"><table class="table table-striped table-sm"><thead><tr><th>Promo</th><th>Diskon</th><th>Periode</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php if (empty($promos)): ?><tr><td colspan="5" class="text-center text-muted">Belum ada promo.</td></tr><?php else: foreach ($promos as $promo): ?><tr><td><strong><?= htmlspecialchars($promo['nama_promo']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($promo['kode_promo']) ?></small></td><td><?= $promo['tipe'] === 'persen' ? (int)$promo['nilai'] . '%' : 'Rp ' . number_format((int)$promo['nilai'], 0, ',', '.') ?></td><td><?= htmlspecialchars($promo['tanggal_mulai']) ?><br><?= htmlspecialchars($promo['tanggal_selesai']) ?></td><td><?= $promo['aktif'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Nonaktif</span>' ?></td><td><a href="admin.php?edit_promo=<?= (int)$promo['id_promo'] ?>#promo" class="btn btn-sm btn-primary">Edit</a><form method="POST" class="d-inline" onsubmit="return confirm('Hapus promo ini?')"><input type="hidden" name="id_promo" value="<?= (int)$promo['id_promo'] ?>"><button type="submit" name="delete_promo" class="btn btn-sm btn-danger">Hapus</button></form></td></tr><?php endforeach; endif; ?></tbody></table></div></div></div></div>
            </div>
        </section>

        <section id="laporan" class="admin-section" data-permitted="<?= hasPermission('view_reports') ? '1' : '0' ?>">
        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Laporan Penjualan</h5>
                            <div class="btn-group" role="group">
                                <a href="export_report.php?type=csv&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" class="btn btn-outline-success btn-sm">Export CSV</a>
                                <a href="export_report.php?type=xls&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" class="btn btn-outline-primary btn-sm">Export Excel</a>
                                <a href="export_report.php?type=pdf&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" class="btn btn-outline-danger btn-sm" target="_blank">Export PDF</a>
                            </div>
                        </div>
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Faktur</th>
                                    <th>Cabang</th>
                                    <th>Kasir</th>
                                    <th>Total</th>
                                    <th>Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($penjualan as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['no_faktur']) ?></td>
                                        <td><?= htmlspecialchars($p['nama_cabang'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['username'] ?? '-') ?></td>
                                        <td>Rp <?= number_format((int)$p['total_bayar'], 0, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($p['tanggal']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
        </section>

        <section id="daftar-data" class="admin-section">
        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Daftar User</h5>
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Cabang</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($u['username']) ?></td>
                                        <td><?= htmlspecialchars(roleLabel($u['role'])) ?></td>
                                        <td><?= htmlspecialchars($u['nama_cabang'] ?? '-') ?></td>
                                        <td>
                                            <a href="admin.php?edit_user=<?= (int)$u['id_user'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus user ini?')">
                                                <input type="hidden" name="id_user" value="<?= (int)$u['id_user'] ?>">
                                                <button type="submit" name="delete_user" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Daftar Cabang</h5>
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Nama Cabang</th>
                                    <th>Alamat</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cabangs as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['nama_cabang']) ?></td>
                                        <td><?= htmlspecialchars($c['alamat'] ?? '-') ?></td>
                                        <td>
                                            <a href="admin.php?edit_cabang=<?= (int)$c['id_cabang'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus cabang ini?')">
                                                <input type="hidden" name="id_cabang" value="<?= (int)$c['id_cabang'] ?>">
                                                <button type="submit" name="delete_cabang" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Daftar Produk</h5>
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Barcode</th>
                                    <th>Nama Produk</th>
                                    <th>Harga</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($produks as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['kode_barcode']) ?></td>
                                        <td><?= htmlspecialchars($p['nama_produk']) ?></td>
                                        <td>Rp <?= number_format((int)$p['harga'], 0, ',', '.') ?></td>
                                        <td>
                                            <a href="admin.php?edit_produk=<?= (int)$p['id_produk'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus produk ini?')">
                                                <input type="hidden" name="id_produk" value="<?= (int)$p['id_produk'] ?>">
                                                <button type="submit" name="delete_produk" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5>Stok Cabang</h5>
                        <table class="table table-striped table-sm">
                            <thead>
                                <tr>
                                    <th>Cabang</th>
                                    <th>Produk</th>
                                    <th>Stok</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stok as $s): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($s['nama_cabang'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($s['nama_produk'] ?? '-') ?></td>
                                        <td><?= (int)$s['jumlah_stok'] ?></td>
                                        <td>
                                            <a href="admin.php?edit_stok=<?= (int)$s['id_stok'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin hapus stok ini?')">
                                                <input type="hidden" name="id_stok" value="<?= (int)$s['id_stok'] ?>">
                                                <button type="submit" name="delete_stok" class="btn btn-sm btn-danger">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
        </section>
    </div>
    <script>
        const adminSections = document.querySelectorAll('.admin-section');
        const adminTabs = document.querySelectorAll('.admin-tabs .nav-link[href^="#"]');
        const masterPanes = document.querySelectorAll('.master-pane');
        const masterTabs = document.querySelectorAll('.master-tabs [data-master-tab]');

        function activateMasterTab(tabId) {
            const requestedPane = document.getElementById(tabId);
            const activePane = requestedPane || document.querySelector('.master-pane');
            masterPanes.forEach(pane => pane.classList.toggle('d-none', pane !== activePane));
            masterTabs.forEach(tab => tab.classList.toggle('active', tab.dataset.masterTab === activePane.id));
        }

        function activateAdminTab() {
            const query = new URLSearchParams(window.location.search);
            const hasEditRequest = ['edit_user', 'edit_cabang', 'edit_produk', 'edit_stok'].some(key => query.has(key));
            const hasPromoEditRequest = query.has('edit_promo');
            const requested = window.location.hash.replace('#', '') || (hasPromoEditRequest ? 'promo' : (hasEditRequest ? 'master-data' : 'dashboard'));
            const requestedSection = document.getElementById(requested);
            const activeSection = requestedSection && requestedSection.dataset.permitted !== '0' ? requestedSection : document.getElementById('dashboard');
            adminSections.forEach(section => {
                section.classList.toggle('d-none', section !== activeSection);
            });
            adminTabs.forEach(tab => {
                tab.classList.toggle('active', tab.getAttribute('href') === '#' + activeSection.id);
            });
            if (activeSection.id === 'master-data') {
                const masterTabByQuery = query.has('edit_cabang') ? 'master-cabang' : (query.has('edit_produk') ? 'master-produk' : (query.has('edit_stok') ? 'master-stok' : 'master-users'));
                activateMasterTab(masterTabByQuery);
            }
        }

        masterTabs.forEach(tab => tab.addEventListener('click', event => {
            event.preventDefault();
            activateMasterTab(tab.dataset.masterTab);
        }));
        window.addEventListener('hashchange', activateAdminTab);
        activateAdminTab();
    </script>
</body>
</html>
