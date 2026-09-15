<?php
// koneksi.php
$host    = getenv('KASIR_DB_HOST') ?: 'localhost';
$db      = getenv('KASIR_DB_NAME') ?: 'db_kasir';
$user    = getenv('KASIR_DB_USER') ?: 'root';
$pass    = getenv('KASIR_DB_PASS') ?: 'root';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>