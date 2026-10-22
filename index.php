
<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header("Location: dashboard/index.php");
    exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Laporan Produksi</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background: #f3f6fb;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: Arial, sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 12px 40px rgba(0,0,0,.08);
        }

        .brand-icon {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            border-radius: 16px;
            background: #e8f0ff;
            color: #2457c5;
            font-size: 28px;
        }

        .form-control {
            padding: 12px;
            border-radius: 10px;
        }

        .btn-login {
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand-icon">
        <i class="bi bi-clipboard-data"></i>
    </div>

    <div class="text-center mb-4">
        <h4 class="fw-bold mb-2">Laporan Produksi</h4>
        <p class="text-secondary mb-0">
            Sistem Informasi Produksi Injection / Molding
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger py-2">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="auth/proses_login.php" method="POST">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text"
                   name="username"
                   class="form-control"
                   placeholder="Masukkan username"
                   autocomplete="username"
                   required autofocus>
        </div>

        <div class="mb-4">
            <label class="form-label">Password</label>
            <input type="password"
                   name="password"
                   class="form-control"
                   placeholder="Masukkan password"
                   autocomplete="current-password"
                   required>
        </div>

        <button type="submit"
                class="btn btn-primary btn-login w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i>
            Masuk
        </button>
    </form>

    <div class="text-center mt-4 text-secondary small">
        &copy; <?= date('Y') ?> Sistem Laporan Produksi
    </div>
</div>

</body>
</html>