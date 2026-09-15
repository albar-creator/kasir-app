<?php
// login.php
session_start();
require 'koneksi.php';
require 'otorisasi.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT u.*, c.nama_cabang 
                               FROM users u 
                               JOIN cabang c ON u.id_cabang = c.id_cabang 
                               WHERE u.username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            $storedPassword = $user['password'];
            $passwordValid = password_verify($password, $storedPassword) || $storedPassword === $password;

            if ($passwordValid) {
                $_SESSION['id_user']     = $user['id_user'];
                $_SESSION['username']    = $user['username'];
                $_SESSION['role']        = $user['role'];
                $_SESSION['id_cabang']   = $user['id_cabang'];
                $_SESSION['nama_cabang'] = $user['nama_cabang'];

                header('Location: dashboard.php');
                exit;
            }
        }

        $error = 'Username atau password salah!';
    } else {
        $error = 'Harap isi semua kolom!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login - Kasir Multi Cabang</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page d-flex align-items-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="text-center mb-4">Login Kasir</h4>
                        <?php if($error): ?>
                            <div class="alert alert-danger p-2 fs-6"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Masuk</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>