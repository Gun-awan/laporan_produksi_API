
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

function redirectMesin(): void
{
    header('Location: mesin.php');
    exit;
}

function flashMesin(string $type, string $message): void
{
    $_SESSION['flash_mesin'] = [
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
            $lineId = (int) ($_POST['line_id'] ?? 0);
            $kode = strtoupper(trim($_POST['kode_mesin'] ?? ''));
            $nama = trim($_POST['nama_mesin'] ?? '');

            if ($lineId <= 0 || $kode === '' || $nama === '') {
                throw new RuntimeException(
                    'Line, kode mesin, dan nama mesin wajib diisi.'
                );
            }

            if (
                strlen($kode) > 30 ||
                strlen($nama) > 100
            ) {
                throw new RuntimeException(
                    'Kode mesin maksimal 30 karakter dan nama maksimal 100 karakter.'
                );
            }

            if (!preg_match('/^[A-Z0-9_-]+$/', $kode)) {
                throw new RuntimeException(
                    'Kode mesin hanya boleh berisi huruf, angka, minus, dan underscore.'
                );
            }

            // Pastikan line tersedia dan aktif.
            $stmt = $conn->prepare(
                "SELECT id
                 FROM master_line
                 WHERE id = ? AND status = 'AKTIF'
                 LIMIT 1"
            );
            $stmt->bind_param('i', $lineId);
            $stmt->execute();
            $lineValid = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$lineValid) {
                throw new RuntimeException(
                    'Line tidak ditemukan atau sedang nonaktif. Pilih line yang aktif.'
                );
            }

            if ($aksi === 'tambah') {
                $stmt = $conn->prepare(
                    "INSERT INTO master_mesin
                        (line_id, kode_mesin, nama_mesin)
                     VALUES (?, ?, ?)"
                );
                $stmt->bind_param('iss', $lineId, $kode, $nama);
                $stmt->execute();
                $stmt->close();

                flashMesin('success', 'Mesin berhasil ditambahkan.');
                redirectMesin();
            }

            if ($id <= 0) {
                throw new RuntimeException('ID mesin tidak valid.');
            }

            $stmt = $conn->prepare(
                "UPDATE master_mesin
                 SET line_id = ?, kode_mesin = ?, nama_mesin = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('issi', $lineId, $kode, $nama, $id);
            $stmt->execute();
            $stmt->close();

            flashMesin('success', 'Data mesin berhasil diperbarui.');
            redirectMesin();
        }

        if ($aksi === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID mesin tidak valid.');
            }

            $stmt = $conn->prepare(
                "SELECT m.status, l.status AS status_line
                 FROM master_mesin m
                 INNER JOIN master_line l ON l.id = m.line_id
                 WHERE m.id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $mesin = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$mesin) {
                throw new RuntimeException('Data mesin tidak ditemukan.');
            }

            if ($mesin['status'] === 'AKTIF') {
                $statusBaru = 'NONAKTIF';
            } else {
                if ($mesin['status_line'] !== 'AKTIF') {
                    throw new RuntimeException(
                        'Mesin tidak dapat diaktifkan karena line induknya nonaktif.'
                    );
                }

                $statusBaru = 'AKTIF';
            }

            $stmt = $conn->prepare(
                "UPDATE master_mesin SET status = ? WHERE id = ?"
            );
            $stmt->bind_param('si', $statusBaru, $id);
            $stmt->execute();
            $stmt->close();

            flashMesin(
                'success',
                'Status mesin berhasil diubah menjadi ' . $statusBaru . '.'
            );
            redirectMesin();
        }

        throw new RuntimeException('Aksi tidak dikenal.');

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (mysqli_sql_exception $ex) {
        error_log($ex->getMessage());

        if ((int) $ex->getCode() === 1062) {
            $error = 'Kode mesin sudah digunakan. Gunakan kode yang berbeda.';
        } else {
            $error = 'Terjadi kesalahan database. Periksa kembali data.';
        }
    }
}

/* =========================
   DATA UNTUK FORM EDIT
========================= */
if (isset($_GET['edit'])) {
    $idEdit = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($idEdit) {
        $stmt = $conn->prepare(
            "SELECT id, line_id, kode_mesin, nama_mesin
             FROM master_mesin
             WHERE id = ?"
        );
        $stmt->bind_param('i', $idEdit);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* =========================
   DATA LINE UNTUK DROPDOWN
========================= */
$resultLine = $conn->query(
    "SELECT id, kode_line, nama_line, status
     FROM master_line
     ORDER BY kode_line ASC"
);

$lines = [];
while ($row = $resultLine->fetch_assoc()) {
    $lines[] = $row;
}

/* =========================
   DATA MESIN
========================= */
$sql = "
    SELECT
        m.id,
        m.kode_mesin,
        m.nama_mesin,
        m.status,
        m.created_at,
        l.kode_line,
        l.nama_line,
        l.status AS status_line
    FROM master_mesin m
    INNER JOIN master_line l ON l.id = m.line_id
    ORDER BY l.kode_line ASC, m.kode_mesin ASC
";

$result = $conn->query($sql);

$mesins = [];
$totalMesin = 0;
$totalAktif = 0;
$totalNonaktif = 0;

while ($row = $result->fetch_assoc()) {
    $mesins[] = $row;
    $totalMesin++;

    if ($row['status'] === 'AKTIF') {
        $totalAktif++;
    } else {
        $totalNonaktif++;
    }
}

$flash = $_SESSION['flash_mesin'] ?? null;
unset($_SESSION['flash_mesin']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Master Mesin | Laporan Produksi</title>

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
        .form-control, .form-select { min-height: 43px; }
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
            <h3 class="fw-bold mb-1">Master Mesin</h3>
            <div class="text-secondary">
                Kelola mesin dan line tempat mesin digunakan.
            </div>
        </div>
        <a href="line.php" class="btn btn-outline-primary">
            <i class="bi bi-diagram-3 me-1"></i> Master Line
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
                    <div class="text-secondary small">Total Mesin</div>
                    <div class="stat-number"><?= $totalMesin ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Mesin Aktif</div>
                    <div class="stat-number text-success"><?= $totalAktif ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Mesin Nonaktif</div>
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
                        <?= $editData ? 'Edit Mesin' : 'Tambah Mesin' ?>
                    </h5>

                    <?php if (!$lines): ?>
                        <div class="alert alert-warning">
                            Belum ada line. Tambahkan line terlebih dahulu.
                            <a href="line.php" class="alert-link">Buka Master Line</a>.
                        </div>
                    <?php endif; ?>

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
                            <label class="form-label">Line</label>
                            <select name="line_id" class="form-select" required>
                                <option value="">-- Pilih Line --</option>
                                <?php foreach ($lines as $line): ?>
                                    <option
                                        value="<?= (int) $line['id'] ?>"
                                        <?= (
                                            (string) ($editData['line_id'] ?? '') ===
                                            (string) $line['id']
                                        ) ? 'selected' : '' ?>
                                        <?= $line['status'] !== 'AKTIF' ? 'disabled' : '' ?>>
                                        <?= e($line['kode_line']) ?> -
                                        <?= e($line['nama_line']) ?>
                                        <?= $line['status'] !== 'AKTIF'
                                            ? '(Nonaktif)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Kode Mesin</label>
                            <input type="text" name="kode_mesin"
                                   class="form-control"
                                   maxlength="30"
                                   placeholder="Contoh: M-01"
                                   pattern="[A-Za-z0-9_-]+"
                                   value="<?= e($editData['kode_mesin'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nama Mesin</label>
                            <input type="text" name="nama_mesin"
                                   class="form-control"
                                   maxlength="100"
                                   placeholder="Contoh: Mesin Injection 01"
                                   value="<?= e($editData['nama_mesin'] ?? '') ?>"
                                   required>
                        </div>

                        <button type="submit" class="btn btn-primary w-100"
                            <?= count(array_filter($lines, fn($l) => $l['status'] === 'AKTIF')) === 0
                                ? 'disabled' : '' ?>>
                            <i class="bi bi-save me-1"></i>
                            <?= $editData ? 'Simpan Perubahan' : 'Simpan Mesin' ?>
                        </button>

                        <?php if ($editData): ?>
                            <a href="mesin.php"
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
                    <h5 class="fw-bold mb-3">Daftar Mesin</h5>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Kode</th>
                                    <th>Nama Mesin</th>
                                    <th>Line</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$mesins): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-secondary py-4">
                                        Belum ada data mesin.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mesins as $i => $mesin): ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td class="fw-semibold"><?= e($mesin['kode_mesin']) ?></td>
                                        <td><?= e($mesin['nama_mesin']) ?></td>
                                        <td>
                                            <?= e($mesin['kode_line']) ?>
                                            <?php if ($mesin['status_line'] !== 'AKTIF'): ?>
                                                <div>
                                                    <span class="badge text-bg-warning">
                                                        Line Nonaktif
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($mesin['status'] === 'AKTIF'): ?>
                                                <span class="badge text-bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Nonaktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="mesin.php?edit=<?= (int) $mesin['id'] ?>"
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>

                                                <form method="POST"
                                                      onsubmit="return confirm('Yakin ingin mengubah status mesin ini?')">
                                                    <input type="hidden" name="csrf_token"
                                                           value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="aksi" value="toggle">
                                                    <input type="hidden" name="id"
                                                           value="<?= (int) $mesin['id'] ?>">
                                                    <button type="submit"
                                                            class="btn btn-sm <?= $mesin['status'] === 'AKTIF' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                            title="<?= $mesin['status'] === 'AKTIF' ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                        <i class="bi <?= $mesin['status'] === 'AKTIF' ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
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