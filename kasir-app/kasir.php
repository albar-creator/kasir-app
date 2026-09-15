<?php
// kasir.php
session_start();
require 'koneksi.php';
require 'otorisasi.php';
require 'promo.php';

if (!isset($_SESSION['id_user']) || !hasPermission('access_pos')) {
    header('Location: login.php');
    exit;
}

$message = '';
$promoMessage = '';
$autoPrint = false;
$receiptCart = [];
$receiptPromoCode = '';
$receiptTotal = null;
$receiptNoFaktur = '';
ensureDetailPenjualanTable($pdo);
$activePromos = getActivePromos($pdo, (int)$_SESSION['id_cabang']);
$reportStartDate = isset($_GET['report_start']) && $_GET['report_start'] !== '' ? $_GET['report_start'] : date('Y-m-d', strtotime('-30 days'));
$reportEndDate = isset($_GET['report_end']) && $_GET['report_end'] !== '' ? $_GET['report_end'] : date('Y-m-d');
$mySalesStmt = $pdo->prepare('SELECT no_faktur, total_bayar, tanggal FROM penjualan WHERE id_kasir = ? AND DATE(tanggal) BETWEEN ? AND ? ORDER BY id_penjualan DESC LIMIT 50');
$mySalesStmt->execute([$_SESSION['id_user'], $reportStartDate, $reportEndDate]);
$mySales = $mySalesStmt->fetchAll();
$mySalesSummaryStmt = $pdo->prepare('SELECT COUNT(*) AS total_transaksi, COALESCE(SUM(total_bayar), 0) AS total_pendapatan FROM penjualan WHERE id_kasir = ? AND DATE(tanggal) BETWEEN ? AND ?');
$mySalesSummaryStmt->execute([$_SESSION['id_user'], $reportStartDate, $reportEndDate]);
$mySalesSummary = $mySalesSummaryStmt->fetch();

// Proses Simpan Transaksi Penjualan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_bayar'])) {
    $cart_data = json_decode($_POST['cart_data'], true);
    $promoCode = trim($_POST['promo_code'] ?? '');
    $id_cabang  = $_SESSION['id_cabang'];
    $id_kasir   = $_SESSION['id_user'];
    $no_faktur  = 'FK-' . date('YmdHis') . '-' . rand(10, 99);
    $calculation = calculatePromo($pdo, is_array($cart_data) ? $cart_data : [], (int)$id_cabang, $promoCode);
    $total_bayar = $calculation['total'];
    $promoMessage = $calculation['message'];
    $receiptPromoCode = $promoCode;

    if (!empty($cart_data) && $total_bayar > 0) {
        try {
            $pdo->beginTransaction();

            // 1. Simpan ke tabel penjualan
            $stmtPenjualan = $pdo->prepare("INSERT INTO penjualan (no_faktur, id_cabang, id_kasir, total_bayar) VALUES (?, ?, ?, ?)");
            $stmtPenjualan->execute([$no_faktur, $id_cabang, $id_kasir, $total_bayar]);
            $id_penjualan = $pdo->lastInsertId();

            // 2. Ambil harga terkini dari DB (tidak mempercayai harga dari client)
            $produkIds    = array_values(array_unique(array_map('intval', array_column($cart_data, 'id_produk'))));
            $placeholders = implode(',', array_fill(0, count($produkIds), '?'));
            $stmtHarga    = $pdo->prepare("SELECT id_produk, harga FROM produk WHERE id_produk IN ($placeholders)");
            $stmtHarga->execute($produkIds);
            $hargaMap = [];
            foreach ($stmtHarga->fetchAll() as $p) {
                $hargaMap[(int)$p['id_produk']] = (int)$p['harga'];
            }

            // 3. Simpan detail item & kurangi stok dengan validasi stok cukup
            $stmtDetail     = $pdo->prepare("INSERT INTO detail_penjualan (id_penjualan, id_produk, qty, harga_satuan, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmtUpdateStok = $pdo->prepare("UPDATE stok_cabang SET jumlah_stok = jumlah_stok - ? WHERE id_cabang = ? AND id_produk = ? AND jumlah_stok >= ?");

            foreach ($cart_data as $item) {
                $pid      = (int)$item['id_produk'];
                $qty      = max(1, (int)$item['qty']);
                $harga    = $hargaMap[$pid] ?? 0;
                $subtotal = $harga * $qty;

                // Simpan detail penjualan
                $stmtDetail->execute([$id_penjualan, $pid, $qty, $harga, $subtotal]);

                // Kurangi stok — rollback otomatis jika stok tidak cukup
                $stmtUpdateStok->execute([$qty, $id_cabang, $pid, $qty]);
                if ($stmtUpdateStok->rowCount() === 0) {
                    throw new Exception("Stok tidak mencukupi untuk produk ID: $pid");
                }
            }

            $pdo->commit();
            $message = "Transaksi Berhasil! No Faktur: $no_faktur";
            $autoPrint = true;
            $receiptCart = is_array($cart_data) ? $cart_data : [];
            $receiptTotal = $total_bayar;
            $receiptNoFaktur = $no_faktur;
            if ($calculation['discount'] > 0 && $calculation['promo']) {
                $message .= ' Promo ' . $calculation['promo']['kode_promo'] . ' berhasil digunakan.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Gagal memproses transaksi: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sistem Kasir - <?= htmlspecialchars($_SESSION['nama_cabang']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <!-- Pustaka HTML5 QRCode Scanner -->
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        /* CSS Khusus Printer Thermal 58mm/80mm saat Print */
        @media print {
            body * { visibility: hidden; }
            #area-struk, #area-struk * { visibility: visible; }
            #area-struk {
                position: absolute;
                left: 0;
                top: 0;
                width: 58mm;
                font-family: 'Courier New', Courier, monospace;
                font-size: 11px;
            }
            @page { margin: 0; }
        }
    </style>
</head>
<body class="app-body">
    <!-- Navbar Top -->
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">POS Kasir - <?= htmlspecialchars($_SESSION['nama_cabang']) ?></span>
            <span class="text-white"><a href="dashboard.php" class="text-white me-2">Dashboard</a>Kasir: <?= htmlspecialchars($_SESSION['username']) ?> | <a href="#laporan-saya" class="text-info me-2">Laporan Saya</a><a href="logout.php" class="text-danger">Logout</a></span>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <?php if($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if($promoMessage): ?><div class="alert alert-warning"><?= htmlspecialchars($promoMessage) ?></div><?php endif; ?>

        <div class="row">
            <!-- Kolom Input Scanner & Keranjang -->
            <div class="col-md-8">
                <div class="card p-3 shadow-sm mb-3">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <label class="fw-bold mb-0">Scan Barcode Produk:</label>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="startCameraScanner()">Scan Kamera</button>
                    </div>
                    <input type="text" id="barcodeInput" class="form-control form-control-lg" placeholder="Arahkan barcode scanner ke sini..." autofocus>
                </div>

                <div class="card p-3 shadow-sm mb-3">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <label for="manualProductSearch" class="fw-bold mb-0">Tambah Produk Manual</label>
                        <small class="text-muted">Cari nama atau barcode</small>
                    </div>
                    <input type="search" id="manualProductSearch" class="form-control" placeholder="Contoh: Kopi Hitam atau 8999999001" autocomplete="off">
                    <div id="manualProductResults" class="list-group mt-2"></div>
                </div>

                <div class="card p-3 shadow-sm">
                    <h5>Keranjang Belanja</h5>
                    <table class="table table-striped mt-2">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Harga</th>
                                <th>Qty</th>
                                <th>Subtotal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tabel-keranjang">
                            <!-- Baris barang muncul otomatis via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Kolom Total & Pembayaran -->
            <div class="col-md-4">
                <div class="card p-3 shadow-sm">
                    <h5>Ringkasan Transaksi</h5>
                    <hr>
                    <div class="d-flex justify-content-between mb-2 text-muted">
                        <span>Subtotal</span>
                        <span id="label-subtotal">Rp 0</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3 text-success">
                        <span>Diskon</span>
                        <span id="label-discount">- Rp 0</span>
                    </div>
                    <div class="d-flex justify-content-between fs-3 fw-bold my-3 text-primary">
                        <span>Total</span>
                        <span id="label-total">Rp 0</span>
                    </div>

                    <form method="POST" action="" id="payment-form" onsubmit="return persiapkanSubmit();">
                        <label class="form-label mt-2">Kode Promo</label>
                        <input type="text" name="promo_code" id="promo_code" class="form-control text-uppercase mb-3" placeholder="Masukkan kode promo" value="<?= htmlspecialchars($receiptPromoCode) ?>">
                        <?php if (!empty($activePromos)): ?><small class="text-muted d-block mb-2">Promo aktif: <?= htmlspecialchars(implode(', ', array_column($activePromos, 'kode_promo'))) ?></small><?php endif; ?>

                        <input type="hidden" name="cart_data" id="cart_data">
                        <input type="hidden" name="total_bayar" id="total_bayar" value="0">
                        <button type="submit" name="proses_bayar" id="btn-bayar" class="btn btn-success btn-lg w-100 mb-2" disabled>Proses &amp; Bayar</button>
                    </form>

                    <button class="btn btn-outline-secondary w-100" onclick="window.print()">Cetak Struk Terakhir</button>
                </div>
            </div>
        </div>
    </div>

    <div id="laporan-saya" class="container-fluid mt-4 mb-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="mb-0">Laporan Penjualan Saya</h5>
                    <small class="text-muted">Hanya transaksi yang diproses oleh akun ini</small>
                </div>
                <form method="GET" class="row g-2 align-items-end mb-3">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Tanggal Mulai</label>
                        <input type="date" name="report_start" class="form-control" value="<?= htmlspecialchars($reportStartDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Tanggal Selesai</label>
                        <input type="date" name="report_end" class="form-control" value="<?= htmlspecialchars($reportEndDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">Tampilkan</button>
                    </div>
                </form>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><div class="border rounded p-3"><small class="text-muted">Total Transaksi</small><div class="fs-4 fw-bold"><?= number_format((int)($mySalesSummary['total_transaksi'] ?? 0), 0, ',', '.') ?></div></div></div>
                    <div class="col-md-6"><div class="border rounded p-3"><small class="text-muted">Total Penjualan</small><div class="fs-4 fw-bold">Rp <?= number_format((int)($mySalesSummary['total_pendapatan'] ?? 0), 0, ',', '.') ?></div></div></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm mb-0">
                        <thead><tr><th>No Faktur</th><th>Total</th><th>Tanggal</th></tr></thead>
                        <tbody>
                            <?php if (empty($mySales)): ?>
                                <tr><td colspan="3" class="text-center text-muted">Belum ada transaksi pada periode ini.</td></tr>
                            <?php else: foreach ($mySales as $sale): ?>
                                <tr><td><?= htmlspecialchars($sale['no_faktur']) ?></td><td>Rp <?= number_format((int)$sale['total_bayar'], 0, ',', '.') ?></td><td><?= htmlspecialchars($sale['tanggal']) ?></td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Template Struk Thermal (Tersembunyi di Tampilan Web Normal) -->
    <div id="area-struk" class="d-none d-print-block">
        <div class="text-center">
            <strong>TOKO SERBA ADA</strong><br>
            <?= htmlspecialchars($_SESSION['nama_cabang']) ?><br>
            --------------------------------
        </div>
        <div style="font-size:10px; margin: 2px 0;">No: <?= htmlspecialchars($receiptNoFaktur) ?></div>
        <div id="struk-item-list"></div>
        --------------------------------<br>
        <div class="d-flex justify-content-between">
            <span>TOTAL:</span>
            <span id="struk-total">Rp 0</span>
        </div>
        --------------------------------<br>
        <div class="text-center">
            Terima Kasih Atas Kunjungan Anda!
        </div>
    </div>

    <div id="cameraModal" class="modal fade show" tabindex="-1" style="display:none; background: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Scan Barcode Kamera</h5>
                    <button type="button" class="btn-close" onclick="stopCameraScanner()"></button>
                </div>
                <div class="modal-body text-center">
                    <video id="scannerVideo" playsinline autoplay muted style="width: 100%; max-height: 420px; border-radius: 12px; background: #000;"></video>
                    <p class="mt-3 mb-0 text-muted">Arahkan kamera ke barcode produk</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="stopCameraScanner()">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/quagga@1.4.1/dist/quagga.min.js"></script>
    <script>
        // keranjang menyimpan: { id_produk, nama, harga, qty, stok }
        let keranjang = <?= json_encode($receiptCart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        let cameraStream = null;
        let nativeScannerLoop = null;
        const activePromos = <?= json_encode($activePromos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const autoPrint = <?= $autoPrint ? 'true' : 'false' ?>;
        const receiptTotal = <?= $receiptTotal === null ? 'null' : (int)$receiptTotal ?>;

        const barcodeInput = document.getElementById('barcodeInput');
        const manualProductSearch = document.getElementById('manualProductSearch');
        const manualProductResults = document.getElementById('manualProductResults');

        // Mendengarkan input dari scanner barcode fisik (yang menekan Enter secara otomatis)
        barcodeInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const barcode = this.value.trim();
                if (barcode !== '') {
                    prosesBarcode(barcode);
                    this.value = '';
                }
            }
        });

        function prosesBarcode(barcode) {
            if (!barcode) return;
            cariProduk(barcode);
        }

        function cariProduk(barcode) {
            fetch(`api_get_produk.php?barcode=${encodeURIComponent(barcode)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        tambahKeKeranjang(data.produk);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(err => console.error(err));
        }

        let searchTimer = null;
        manualProductSearch.addEventListener('input', function() {
            clearTimeout(searchTimer);
            const search = this.value.trim();
            if (search.length < 2) {
                manualProductResults.innerHTML = '';
                return;
            }
            searchTimer = setTimeout(() => cariProdukManual(search), 250);
        });

        function cariProdukManual(search) {
            fetch(`api_get_produk.php?search=${encodeURIComponent(search)}`)
                .then(res => res.json())
                .then(data => {
                    manualProductResults.innerHTML = '';
                    if (!data.success || data.produk.length === 0) {
                        manualProductResults.innerHTML = '<div class="list-group-item text-muted">Produk tidak ditemukan.</div>';
                        return;
                    }
                    data.produk.forEach(produk => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                        button.disabled = parseInt(produk.stok) < 1;
                        button.innerHTML = `<span><strong>${escapeHtml(produk.nama_produk)}</strong><br><small class="text-muted">${escapeHtml(produk.kode_barcode)} | Stok: ${produk.stok}</small></span><span>Rp ${parseInt(produk.harga).toLocaleString('id-ID')}</span>`;
                        button.addEventListener('click', () => {
                            tambahKeKeranjang(produk);
                            manualProductSearch.value = '';
                            manualProductResults.innerHTML = '';
                            barcodeInput.focus();
                        });
                        manualProductResults.appendChild(button);
                    });
                })
                .catch(() => {
                    manualProductResults.innerHTML = '<div class="list-group-item text-danger">Pencarian produk gagal.</div>';
                });
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[character]));
        }

        async function startCameraScanner() {
            const modal = document.getElementById('cameraModal');
            const video = document.getElementById('scannerVideo');

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('Browser Anda tidak mendukung akses kamera. Silakan gunakan Chrome/Edge di HP atau laptop.');
                return;
            }

            modal.style.display = 'block';

            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    },
                    audio: false
                });

                video.srcObject = cameraStream;
                video.play();

                if ('BarcodeDetector' in window) {
                    startNativeBarcodeScanner();
                } else if (window.Quagga) {
                    startQuaggaScanner();
                } else {
                    alert('Fitur kamera barcode tidak bisa dimulai di browser ini. Gunakan browser Chrome/Edge terbaru.');
                    stopCameraScanner();
                }
            } catch (error) {
                console.error(error);
                alert('Kamera tidak bisa diakses. Pastikan izin kamera sudah diberikan.');
                stopCameraScanner();
            }
        }

        function startNativeBarcodeScanner() {
            const video = document.getElementById('scannerVideo');
            const detector = new BarcodeDetector({
                formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e', 'qr_code']
            });

            const scanFrame = async () => {
                if (!cameraStream || video.readyState < 2) {
                    nativeScannerLoop = requestAnimationFrame(scanFrame);
                    return;
                }

                try {
                    const barcodes = await detector.detect(video);

                    if (barcodes && barcodes.length > 0) {
                        const code = barcodes[0].rawValue;
                        if (code) {
                            stopCameraScanner();
                            prosesBarcode(code);
                            return;
                        }
                    }
                } catch (error) {
                    console.error('BarcodeDetector error:', error);
                }

                nativeScannerLoop = requestAnimationFrame(scanFrame);
            };

            nativeScannerLoop = requestAnimationFrame(scanFrame);
        }

        function startQuaggaScanner() {
            const video = document.getElementById('scannerVideo');

            Quagga.init({
                inputStream: {
                    name: 'Live',
                    type: 'LiveStream',
                    target: video,
                    constraints: {
                        facingMode: 'environment'
                    }
                },
                decoder: {
                    readers: ['ean_reader', 'ean_8_reader', 'code_128_reader', 'code_39_reader', 'upc_reader', 'upc_e_reader']
                },
                locate: true
            }, function(err) {
                if (err) {
                    console.error(err);
                    alert('Scanner kamera gagal diinisialisasi.');
                    stopCameraScanner();
                    return;
                }
                Quagga.start();
            });

            Quagga.onDetected(function(result) {
                const code = result.codeResult && result.codeResult.code;
                if (code) {
                    Quagga.stop();
                    stopCameraScanner();
                    prosesBarcode(code);
                }
            });
        }

        function stopCameraScanner() {
            const modal = document.getElementById('cameraModal');
            const video = document.getElementById('scannerVideo');

            if (nativeScannerLoop) {
                cancelAnimationFrame(nativeScannerLoop);
                nativeScannerLoop = null;
            }

            if (window.Quagga) {
                try {
                    Quagga.stop();
                } catch (error) {
                    console.log('Quagga already stopped');
                }
            }

            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }

            if (video) {
                video.srcObject = null;
            }

            if (modal) {
                modal.style.display = 'none';
            }
        }

        function tambahKeKeranjang(produk) {
            const stokTersedia = parseInt(produk.stok) || 0;
            const itemAda = keranjang.find(item => item.id_produk == produk.id_produk);

            if (itemAda) {
                // Gunakan stok yang tersimpan di keranjang jika produk sudah ada
                const stokMax = itemAda.stok !== undefined ? itemAda.stok : stokTersedia;
                if (itemAda.qty + 1 > stokMax) {
                    alert('Stok cabang tidak mencukupi!');
                    return;
                }
                itemAda.qty += 1;
            } else {
                if (stokTersedia < 1) {
                    alert('Stok barang ini di cabang Anda sedang habis!');
                    return;
                }
                keranjang.push({
                    id_produk: produk.id_produk,
                    nama: produk.nama_produk,
                    harga: parseInt(produk.harga),
                    qty: 1,
                    stok: stokTersedia   // simpan stok agar validasi qty berikutnya akurat
                });
            }
            renderKeranjang();
        }

        function hapusItem(index) {
            keranjang.splice(index, 1);
            renderKeranjang();
        }

        function renderKeranjang() {
            const tbody = document.getElementById('tabel-keranjang');
            const strukList = document.getElementById('struk-item-list');
            tbody.innerHTML = '';
            strukList.innerHTML = '';

            let total = 0;

            keranjang.forEach((item, index) => {
                const subtotal = item.harga * item.qty;
                total += subtotal;

                // Tampilan Tabel Kasir
                tbody.innerHTML += `
                    <tr>
                        <td>${escapeHtml(item.nama)}</td>
                        <td>Rp ${item.harga.toLocaleString('id-ID')}</td>
                        <td>${item.qty}</td>
                        <td>Rp ${subtotal.toLocaleString('id-ID')}</td>
                        <td><button class="btn btn-sm btn-danger" onclick="hapusItem(${index})">Hapus</button></td>
                    </tr>
                `;

                // Tampilan Struk Thermal
                strukList.innerHTML += `
                    <div>
                        ${escapeHtml(item.nama)}<br>
                        ${item.qty} x ${item.harga.toLocaleString('id-ID')} = ${subtotal.toLocaleString('id-ID')}
                    </div>
                `;
            });

            const promoCode = document.getElementById('promo_code').value.trim().toUpperCase();
            const eligiblePromos = activePromos.filter(promo => (!promoCode || promo.kode_promo.toUpperCase() === promoCode) && total >= parseInt(promo.minimal_belanja));
            let bestDiscount = 0;
            eligiblePromos.forEach(promo => {
                let eligibleTotal = total;
                if (promo.id_produk) {
                    eligibleTotal = keranjang.filter(item => parseInt(item.id_produk) === parseInt(promo.id_produk)).reduce((sum, item) => sum + (item.harga * item.qty), 0);
                }
                let candidate = promo.tipe === 'persen' ? Math.floor(eligibleTotal * (parseInt(promo.nilai) / 100)) : parseInt(promo.nilai);
                bestDiscount = Math.max(bestDiscount, Math.min(candidate, eligibleTotal, total));
            });
            const grandTotal = receiptTotal !== null && autoPrint ? receiptTotal : Math.max(0, total - bestDiscount);
            document.getElementById('label-subtotal').innerText = `Rp ${total.toLocaleString('id-ID')}`;
            document.getElementById('label-discount').innerText = `- Rp ${bestDiscount.toLocaleString('id-ID')}`;
            document.getElementById('label-total').innerText = `Rp ${grandTotal.toLocaleString('id-ID')}`;
            document.getElementById('struk-total').innerText = `Rp ${grandTotal.toLocaleString('id-ID')}`;
            document.getElementById('total_bayar').value = grandTotal;
            document.getElementById('btn-bayar').disabled = keranjang.length === 0;
        }

        document.getElementById('promo_code').addEventListener('input', renderKeranjang);
        renderKeranjang();
        if (autoPrint) {
            setTimeout(() => window.print(), 350);
        }

        function persiapkanSubmit() {
            if (keranjang.length === 0) return false;
            document.getElementById('cart_data').value = JSON.stringify(keranjang);
            return true;
        }
    </script>
</body>
</html>