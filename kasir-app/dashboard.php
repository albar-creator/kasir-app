<?php
session_start();
require 'otorisasi.php';

if (!isset($_SESSION['id_user'])) {
    header('Location: login.php');
    exit;
}

if (canAccessAdmin()) {
    header('Location: admin.php#dashboard');
    exit;
}

if (hasPermission('access_pos')) {
    header('Location: kasir.php');
    exit;
}

http_response_code(403);
exit('Akses dashboard tidak tersedia untuk role ini.');
