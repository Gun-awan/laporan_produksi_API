
<?php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

if (
    empty($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'ppic'], true)
) {
    http_response_code(403);
    exit('Akses ditolak. Silakan login sebagai admin atau PPIC.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectLine(): void
{
    header('Location: line.php');
    exit;
}

function flashLine(string $type, string $message): void
{
    $_SESSION['flash_line'] = [
        'type' => $type,
        'message' => $message
    ];
}

$editData = null;
$error = '';

/* =========================
   PROSES FORM
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman.');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $kode = strtoupper(trim($_POST['kode_line'] ?? ''));
            $nama = trim($_POST['nama_line'] ?? '');

            if ($kode === '' || $nama === '') {
                throw new RuntimeException(
                    'Kode line dan nama line wajib diisi.'
                );
            }

            if (
                strlen($kode) > 30 ||
                strlen($nama) > 100
            ) {
                throw new RuntimeException(
                    'Kode maksimal 30 karakter dan nama maksimal 100 karakter.'
                );
            }

            if (!preg_match('/^[A-Z0-9_-]+$/', $kode)) {
                throw new RuntimeException(
                    'Kode line hanya boleh berisi huruf, angka, tanda minus, dan underscore.'
                );
            }

            if ($aksi === 'tambah') {
                $stmt = $conn->prepare(
                    "INSERT INTO master_line (kode_line, nama_line)
                     VALUES (?, ?)"
                );
                $stmt->bind_param('ss', $kode, $nama);
                $stmt->execute();
                $stmt->close();

                flashLine('success', 'Line berhasil ditambahkan.');
                redirectLine();
            }

            if ($id <= 0) {
                throw new RuntimeException('ID line tidak valid.');
            }

            $stmt = $conn->prepare(
                "UPDATE master_line
                 SET kode_line = ?, nama_line = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('ssi', $kode, $nama, $id);
            $stmt->execute();
            $stmt->close();

            flashLine('success', 'Data line berhasil diperbarui.');
            redirectLine();
        }

        if ($aksi === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID line tidak valid.');
            }

            $stmt = $conn->prepare(
                "SELECT status FROM master_line WHERE id = ?"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $line = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$line) {
                throw new RuntimeException('Data line tidak ditemukan.');
            }

            if ($line['status'] === 'AKTIF') {
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS total
                     FROM master_mesin
                     WHERE line_id = ? AND status = 'AKTIF'"
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $jumlahMesin = (int) $stmt->get_result()
                    ->fetch_assoc()['total'];
                $stmt->close();

                if ($jumlahMesin > 0) {
                    throw new RuntimeException(
                        'Nonaktifkan mesin pada line ini terlebih dahulu.'
                    );
                }

                $statusBaru = 'NONAKTIF';
            } else {
                $statusBaru = 'AKTIF';
            }

            $stmt = $conn->prepare(
                "UPDATE master_line SET status = ? WHERE id = ?"
            );
            $stmt->bind_param('si', $statusBaru, $id);
            $stmt->execute();
            $stmt->close();

            flashLine(
                'success',
                'Status line berhasil diubah menjadi ' . $statusBaru . '.'
            );
            redirectLine();
        }

        throw new RuntimeException('Aksi tidak dikenal.');

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (mysqli_sql_exception $ex) {
        error_log($ex->getMessage());

        if ((int) $ex->getCode() === 1062) {
            $error = 'Kode line sudah digunakan. Gunakan kode yang berbeda.';
        } else {
            $error = 'Terjadi kesalahan database. Periksa kembali data.';
        }
    }
}

/* =========================
   DATA EDIT
========================= */
if (isset($_GET['edit'])) {
    $idEdit = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($idEdit) {
        $stmt = $conn->prepare(
            "SELECT id, kode_line, nama_line
             FROM master_line
             WHERE id = ?"
        );
        $stmt->bind_param('i', $idEdit);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* =========================
   DATA TABEL
========================= */
$sql = "
    SELECT
        l.id,
        l.kode_line,
        l.nama_line,
        l.status,
        l.created_at,
        COUNT(m.id) AS jumlah_mesin
    FROM master_line l
    LEFT JOIN master_mesin m ON m.line_id = l.id
    GROUP BY
        l.id, l.kode_line, l.nama_line,
        l.status, l.created_at
    ORDER BY l.kode_line ASC
";
$result = $conn->query($sql);

$totalLine = 0;
$totalAktif = 0;
$totalNonaktif = 0;

$lines = [];
while ($row = $result->fetch_assoc()) {
    $lines[] = $row;
    $totalLine++;

    if ($row['status'] === 'AKTIF') {
        $totalAktif++;
    } else {
        $totalNonaktif++;
    }
}

$flash = $_SESSION['flash_line'] ?? null;
unset($_SESSION['flash_line']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Master Line | Laporan Produksi</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">

    <style>
        body { background: #f4f6fa; }
        .page-card {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }
        .stat-number { font-size: 1.65rem; font-weight: 700; }
        .table th { white-space: nowrap; }
        .form-control { min-height: 43px; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold" href="../dashboard/index.php">
            <i class="bi bi-clipboard-data me-2"></i>Laporan Produksi
        </a>
        <div class="d-flex align-items-center gap-3 text-white">
            <span class="small d-none d-sm-inline">
                <?= e($_SESSION['nama'] ?? 'Pengguna') ?>
                (<?= e(strtoupper($_SESSION['role'])) ?>)
            </span>
            <a href="../auth/logout.php"
               class="btn btn-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-3 px-lg-4 py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-1">Master Line</h3>
            <div class="text-secondary">
                Kelola daftar line produksi.
            </div>
        </div>
        <a href="mesin.php" class="btn btn-outline-primary">
            <i class="bi bi-gear me-1"></i> Master Mesin
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Total Line</div>
                    <div class="stat-number"><?= $totalLine ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Line Aktif</div>
                    <div class="stat-number text-success"><?= $totalAktif ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Line Nonaktif</div>
                    <div class="stat-number text-secondary"><?= $totalNonaktif ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-4">
            <div class="card page-card">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">
                        <?= $editData ? 'Edit Line' : 'Tambah Line' ?>
                    </h5>

                    <form method="POST">
                        <input type="hidden" name="csrf_token"
                               value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="aksi"
                               value="<?= $editData ? 'edit' : 'tambah' ?>">

                        <?php if ($editData): ?>
                            <input type="hidden" name="id"
                                   value="<?= (int) $editData['id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Kode Line</label>
                            <input type="text" name="kode_line"
                                   class="form-control"
                                   maxlength="30"
                                   placeholder="Contoh: A1"
                                   value="<?= e($editData['kode_line'] ?? '') ?>"
                                   pattern="[A-Za-z0-9_-]+"
                                   required>
                            <div class="form-text">
                                Huruf, angka, minus, atau underscore.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nama Line</label>
                            <input type="text" name="nama_line"
                                   class="form-control"
                                   maxlength="100"
                                   placeholder="Contoh: Line Injection A1"
                                   value="<?= e($editData['nama_line'] ?? '') ?>"
                                   required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-save me-1"></i>
                            <?= $editData ? 'Simpan Perubahan' : 'Simpan Line' ?>
                        </button>

                        <?php if ($editData): ?>
                            <a href="line.php"
                               class="btn btn-light border w-100 mt-2">
                                Batal Edit
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card page-card">
                <div class="card-body p-3 p-lg-4">
                    <h5 class="fw-bold mb-3">Daftar Line</h5>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Kode</th>
                                    <th>Nama Line</th>
                                    <th>Mesin</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$lines): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-secondary py-4">
                                        Belum ada data line.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lines as $i => $line): ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td class="fw-semibold"><?= e($line['kode_line']) ?></td>
                                        <td><?= e($line['nama_line']) ?></td>
                                        <td><?= (int) $line['jumlah_mesin'] ?></td>
                                        <td>
                                            <?php if ($line['status'] === 'AKTIF'): ?>
                                                <span class="badge text-bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Nonaktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="line.php?edit=<?= (int) $line['id'] ?>"
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>

                                                <form method="POST"
                                                      onsubmit="return confirm('Yakin ingin mengubah status line ini?')">
                                                    <input type="hidden" name="csrf_token"
                                                           value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="aksi" value="toggle">
                                                    <input type="hidden" name="id"
                                                           value="<?= (int) $line['id'] ?>">
                                                    <button type="submit"
                                                            class="btn btn-sm <?= $line['status'] === 'AKTIF' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                            title="<?= $line['status'] === 'AKTIF' ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                        <i class="bi <?= $line['status'] === 'AKTIF' ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>