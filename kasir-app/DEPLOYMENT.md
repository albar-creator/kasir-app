# Deployment Kasir Multi Cabang

## Prasyarat

- PHP 8.1 atau lebih baru
- Ekstensi PHP `pdo_mysql`, `mbstring`, `fileinfo`, dan `json`
- MySQL 8 atau MariaDB 10.4+
- Apache/Nginx dengan document root ke folder aplikasi
- HTTPS untuk akses kamera barcode di perangkat selain localhost

## Intranet dengan Laragon

1. Letakkan folder aplikasi di `C:\laragon\www\kasir-app`.
2. Jalankan Apache dan MySQL dari Laragon.
3. Pastikan database `db_kasir` tersedia dan user database sudah dibuat.
4. Buka `http://localhost/kasir-app/login.php` di komputer server.
5. Cari alamat IP komputer server dengan `ipconfig`, misalnya `192.168.1.20`.
6. Izinkan Apache melalui Windows Firewall pada jaringan Private.
7. Dari komputer kasir lain buka `http://192.168.1.20/kasir-app/login.php`.
8. Pastikan semua perangkat berada di jaringan LAN/Wi-Fi yang sama.

Jika memakai virtual host Laragon, gunakan domain lokal seperti `http://kasir-app.test`, lalu tambahkan pemetaan domain di file `hosts` setiap komputer klien.

## Hosting atau cPanel

1. Buat database dan user MySQL dari cPanel.
2. Import database aplikasi melalui phpMyAdmin. Struktur awal minimal membutuhkan tabel `users`, `cabang`, `produk`, `stok_cabang`, dan `penjualan`.
3. Upload seluruh isi folder aplikasi ke `public_html/kasir-app` atau document root domain.
4. Set environment variable berikut pada hosting, atau sesuaikan nilainya di `koneksi.php`:

```text
KASIR_DB_HOST=localhost
KASIR_DB_NAME=nama_database_hosting
KASIR_DB_USER=nama_user_database
KASIR_DB_PASS=password_database
```

5. Pastikan folder dan file dapat dibaca oleh web server.
6. Aktifkan PHP 8.1+ dan ekstensi `pdo_mysql`.
7. Akses `https://domain-anda.com/kasir-app/login.php`.
8. Pastikan HTTPS aktif agar scanner kamera dapat digunakan.

## Catatan database otomatis

Tabel `promo` dibuat otomatis saat halaman admin/POS dipakai pertama kali.
Tabel `transfer_stok` dibuat otomatis saat halaman admin dibuka pertama kali.

Untuk produksi, sebaiknya buat tabel tersebut melalui migration atau SQL backup terlebih dahulu agar proses deployment dapat direproduksi.

## Checklist setelah online

- Login admin berhasil.
- User kasir terikat ke cabang yang benar.
- API barcode mengembalikan produk dan stok.
- Import/export CSV berjalan.
- Transfer stok hanya dapat dilakukan sesuai role.
- Promo menghitung total di server.
- Cetak struk membuka dialog printer.
- Backup database terjadwal.
- Password database tidak menggunakan user `root`.
