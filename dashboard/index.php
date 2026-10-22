
<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$nama = $_SESSION['nama'] ?? 'Pengguna';
$role = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Laporan Produksi</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <span class="navbar-brand fw-semibold">
            <i class="bi bi-clipboard-data me-2"></i>
            Laporan Produksi
        </span>

        <a href="../auth/logout.php"
           class="btn btn-light btn-sm">
            <i class="bi bi-box-arrow-right me-1"></i>
            Logout
        </a>
    </div>
</nav>

<main class="container py-4">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h4 class="fw-bold">
                Selamat datang, <?= htmlspecialchars($nama) ?>!
            </h4>

            <p class="text-secondary mb-3">
                Login berhasil ke Sistem Laporan Produksi.
            </p>

            <span class="badge bg-primary">
                Role: <?= htmlspecialchars(ucfirst($role)) ?>
            </span>

            <hr>

            <p class="mb-0">
                Halaman ini akan dikembangkan menjadi dashboard
                sesuai hak akses pengguna.
            </p>
        </div>
    </div>
</main>

</body>
</html>