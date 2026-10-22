
<?php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

/* =========================
   CEK LOGIN DAN HAK AKSES
========================= */
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

/* =========================
   FUNGSI BANTU
========================= */
function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectPart(): void
{
    header('Location: part.php');
    exit;
}

function flashPart(string $type, string $message): void
{
    $_SESSION['flash_part'] = [
        'type' => $type,
        'message' => $message
    ];
}

/* =========================
   VARIABEL
========================= */
$error = '';
$editData = null;

/* =========================
   PROSES TAMBAH / EDIT /
   AKTIFKAN / NONAKTIFKAN
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Silakan muat ulang halaman.');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {
        /* -------------------------
           TAMBAH DAN EDIT PART
        ------------------------- */
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);

            $kode = strtoupper(trim($_POST['kode_part'] ?? ''));
            $nama = trim($_POST['nama_part'] ?? '');

            $cycleTimeRaw = trim($_POST['cycle_time'] ?? '');
            $cavityRaw = trim($_POST['cavity'] ?? '');

            $targetJamRaw = trim($_POST['target_per_jam'] ?? '');
            $targetShiftRaw = trim($_POST['target_per_shift'] ?? '');
            $targetHariRaw = trim($_POST['target_per_hari'] ?? '');

            $material = trim($_POST['material'] ?? '');
            $warna = trim($_POST['warna'] ?? '');
            $terdapatSecondProses = isset($_POST['terdapat_second_proses']) ? 1 : 0;
            $keterangan = trim($_POST['keterangan'] ?? '');

            /* Validasi wajib */
            if (
                $kode === '' ||
                $nama === '' ||
                $cycleTimeRaw === '' ||
                $cavityRaw === '' ||
                $targetJamRaw === '' ||
                $targetShiftRaw === '' ||
                $targetHariRaw === ''
            ) {
                throw new RuntimeException(
                    'Kode, nama, cycle time, cavity, dan ketiga target wajib diisi.'
                );
            }

            /* Validasi panjang */
            if (
                strlen($kode) > 50 ||
                strlen($nama) > 150 ||
                strlen($material) > 100 ||
                strlen($warna) > 100
            ) {
                throw new RuntimeException(
                    'Ada data yang melebihi batas panjang karakter.'
                );
            }

            /* Validasi kode part */
            if (!preg_match('/^[A-Z0-9_-]+$/', $kode)) {
                throw new RuntimeException(
                    'Kode part hanya boleh berisi huruf, angka, minus, dan underscore.'
                );
            }

            /* Validasi angka */
            if (
                !is_numeric($cycleTimeRaw) ||
                !is_numeric($cavityRaw) ||
                !ctype_digit($targetJamRaw) ||
                !ctype_digit($targetShiftRaw) ||
                !ctype_digit($targetHariRaw)
            ) {
                throw new RuntimeException(
                    'Cycle time harus berupa angka dan cavity serta target harus berupa bilangan bulat.'
                );
            }

            $cycleTime = (float) $cycleTimeRaw;
            $cavity = (int) $cavityRaw;
            $targetJam = (int) $targetJamRaw;
            $targetShift = (int) $targetShiftRaw;
            $targetHari = (int) $targetHariRaw;

            if (
                !is_finite($cycleTime) ||
                $cycleTime <= 0 ||
                $cycleTime > 99999999.99
            ) {
                throw new RuntimeException(
                    'Cycle time harus lebih besar dari 0 dan tidak melebihi batas.'
                );
            }

            if (
                $cavity <= 0 ||
                $cavity > 4294967295 ||
                $targetJam <= 0 ||
                $targetShift <= 0 ||
                $targetHari <= 0
            ) {
                throw new RuntimeException(
                    'Cavity dan semua target harus lebih besar dari 0.'
                );
            }

            /*
             * Target per shift dan target per hari disimpan
             * sebagai standar perusahaan. Tidak dipaksa sama
             * dengan target per jam x 8 atau x 24 karena nilai
             * aktual standar dapat memiliki aturan pembulatan
             * atau ketentuan khusus perusahaan.
             */

            if ($aksi === 'tambah') {
                $stmt = $conn->prepare(
                "INSERT INTO master_part (
                    kode_part,
                    nama_part,
                    cycle_time,
                    cavity,
                    target_per_jam,
                    target_per_shift,
                    target_per_hari,
                    material,
                    warna,
                    terdapat_second_proses,
                    keterangan
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                'ssdiiiissis',
                $kode,
                $nama,
                $cycleTime,
                $cavity,
                $targetJam,
                $targetShift,
                $targetHari,
                $material,
                $warna,
                $terdapatSecondProses,
                $keterangan
            );

            $stmt->execute();
            $stmt->close();

                flashPart('success', 'Data part berhasil ditambahkan.');
                redirectPart();
            }

            /* EDIT */
            if ($id <= 0) {
                throw new RuntimeException('ID part tidak valid.');
            }

            $stmt = $conn->prepare(
            "UPDATE master_part
            SET
                kode_part = ?,
                nama_part = ?,
                cycle_time = ?,
                cavity = ?,
                target_per_jam = ?,
                target_per_shift = ?,
                target_per_hari = ?,
                material = ?,
                warna = ?,
                terdapat_second_proses = ?,
                keterangan = ?
            WHERE id = ?"
        );

        $stmt->bind_param(
            'ssdiiiissisi',
            $kode,
            $nama,
            $cycleTime,
            $cavity,
            $targetJam,
            $targetShift,
            $targetHari,
            $material,
            $warna,
            $terdapatSecondProses,
            $keterangan,
            $id
        );

        $stmt->execute();
        $stmt->close();

            flashPart('success', 'Data part berhasil diperbarui.');
            redirectPart();
        }

        /* -------------------------
           UBAH STATUS
        ------------------------- */
        if ($aksi === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID part tidak valid.');
            }

            $stmt = $conn->prepare(
                "SELECT status
                 FROM master_part
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $part = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$part) {
                throw new RuntimeException('Data part tidak ditemukan.');
            }

            $statusBaru = $part['status'] === 'AKTIF'
                ? 'NONAKTIF'
                : 'AKTIF';

            $stmt = $conn->prepare(
                "UPDATE master_part
                 SET status = ?
                 WHERE id = ?"
            );

            $stmt->bind_param('si', $statusBaru, $id);
            $stmt->execute();
            $stmt->close();

            flashPart(
                'success',
                'Status part berhasil diubah menjadi ' . $statusBaru . '.'
            );

            redirectPart();
        }

        throw new RuntimeException('Aksi tidak dikenal.');

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();

    } catch (mysqli_sql_exception $ex) {
        error_log($ex->getMessage());

        if ((int) $ex->getCode() === 1062) {
            $error = 'Kode part sudah digunakan. Silakan gunakan kode lain.';
        } else {
            $error = 'Terjadi kesalahan database. Periksa struktur tabel dan data.';
        }
    }
}

/* =========================
   AMBIL DATA UNTUK EDIT
========================= */
if (isset($_GET['edit'])) {
    $idEdit = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($idEdit) {
        $stmt = $conn->prepare(
            "SELECT *
             FROM master_part
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param('i', $idEdit);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* =========================
   PENCARIAN
========================= */
$keyword = trim($_GET['q'] ?? '');

$sql = "
    SELECT
        id,
        kode_part,
        nama_part,
        terdapat_second_proses,
        cycle_time,
        cavity,
        target_per_jam,
        target_per_shift,
        target_per_hari,
        material,
        warna,
        status
    FROM master_part
";

if ($keyword !== '') {
    $sql .= "
        WHERE kode_part LIKE ?
           OR nama_part LIKE ?
           OR material LIKE ?
           OR warna LIKE ?
    ";
}

$sql .= " ORDER BY kode_part ASC";

$stmt = $conn->prepare($sql);

if ($keyword !== '') {
    $like = '%' . $keyword . '%';

    $stmt->bind_param(
        'ssss',
        $like,
        $like,
        $like,
        $like
    );
}

$stmt->execute();
$result = $stmt->get_result();

$parts = [];
$totalPart = 0;
$totalAktif = 0;
$totalNonaktif = 0;

while ($row = $result->fetch_assoc()) {
    $parts[] = $row;
    $totalPart++;

    if ($row['status'] === 'AKTIF') {
        $totalAktif++;
    } else {
        $totalNonaktif++;
    }
}

$stmt->close();

$flash = $_SESSION['flash_part'] ?? null;
unset($_SESSION['flash_part']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Master Part | Laporan Produksi</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f6fa;
        }

        .page-card {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, .04);
        }

        .stat-number {
            font-size: 1.65rem;
            font-weight: 700;
        }

        .form-control,
        .form-select {
            min-height: 43px;
        }

        .table th {
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .target-value {
            white-space: nowrap;
            font-weight: 600;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold"
           href="../dashboard/index.php">
            <i class="bi bi-clipboard-data me-2"></i>
            Laporan Produksi
        </a>

        <div class="d-flex align-items-center gap-3 text-white">
            <span class="small d-none d-sm-inline">
                <?= e($_SESSION['nama'] ?? 'Pengguna') ?>
                (<?= e(strtoupper($_SESSION['role'])) ?>)
            </span>

            <a href="../auth/logout.php"
               class="btn btn-light btn-sm">
                Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid px-3 px-lg-4 py-4">

    <!-- HEADER -->
    <div class="d-flex flex-wrap justify-content-between
                align-items-center gap-2 mb-4">

        <div>
            <h3 class="fw-bold mb-1">Master Part</h3>
            <div class="text-secondary">
                Kelola data part dan standar target produksi.
            </div>
        </div>

        <div class="d-flex gap-2">
            <a href="line.php"
               class="btn btn-outline-primary">
                <i class="bi bi-diagram-3 me-1"></i> Master Line
            </a>

            <a href="mesin.php"
               class="btn btn-outline-primary">
                <i class="bi bi-gear me-1"></i> Master Mesin
            </a>
        </div>
    </div>

    <!-- NOTIFIKASI -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>
                    alert-dismissible fade show">
            <?= e($flash['message']) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?>

            <button type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- RINGKASAN -->
    <div class="row g-3 mb-4">

        <div class="col-12 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Total Part</div>
                    <div class="stat-number"><?= $totalPart ?></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Part Aktif</div>
                    <div class="stat-number text-success">
                        <?= $totalAktif ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4">
            <div class="card page-card">
                <div class="card-body">
                    <div class="text-secondary small">Part Nonaktif</div>
                    <div class="stat-number text-secondary">
                        <?= $totalNonaktif ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4">

        <!-- FORM PART -->
        <div class="col-12 col-xl-4">
            <div class="card page-card">
                <div class="card-body p-4">

                    <h5 class="fw-bold mb-1">
                        <?= $editData ? 'Edit Data Part' : 'Tambah Part' ?>
                    </h5>

                    <p class="text-secondary small mb-4">
                        Isi data part dan standar produksi.
                    </p>

                    <form method="POST">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= e($_SESSION['csrf_token']) ?>">

                        <input type="hidden"
                               name="aksi"
                               value="<?= $editData ? 'edit' : 'tambah' ?>">

                        <?php if ($editData): ?>
                            <input type="hidden"
                                   name="id"
                                   value="<?= (int) $editData['id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">
                                Kode Part <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                   name="kode_part"
                                   class="form-control"
                                   maxlength="50"
                                   pattern="[A-Za-z0-9_-]+"
                                   placeholder="Contoh: PRT-001"
                                   value="<?= e($editData['kode_part'] ?? '') ?>"
                                   required>

                            <div class="form-text">
                                Kode harus unik.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Nama Part <span class="text-danger">*</span>
                            </label>

                            <input type="text"
                                   name="nama_part"
                                   class="form-control"
                                   maxlength="150"
                                   placeholder="Contoh: Housing A"
                                   value="<?= e($editData['nama_part'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="form-check border rounded-3 p-3 ps-5 mb-3 bg-light">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="terdapat_second_proses"
                                name="terdapat_second_proses"
                                value="1"
                                <?= !empty($editData['terdapat_second_proses']) ? 'checked' : '' ?>
                            >

                            <label
                                class="form-check-label fw-semibold"
                                for="terdapat_second_proses">
                                Terdapat Second Proses
                            </label>

                            <div class="form-text">
                                Centang jika part ini memiliki proses lanjutan setelah injection.
                            </div>
                        </div>

                        <div class="row g-3">

                            <div class="col-7">
                                <label class="form-label">
                                    Cycle Time (detik)
                                    <span class="text-danger">*</span>
                                </label>

                                <input type="number"
                                       name="cycle_time"
                                       class="form-control"
                                       min="0.01"
                                       max="99999999.99"
                                       step="0.01"
                                       placeholder="30"
                                       value="<?= e($editData['cycle_time'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="col-5">
                                <label class="form-label">
                                    Cavity
                                    <span class="text-danger">*</span>
                                </label>

                                <input type="number"
                                       name="cavity"
                                       class="form-control"
                                       min="1"
                                       step="1"
                                       placeholder="2"
                                       value="<?= e($editData['cavity'] ?? '') ?>"
                                       required>
                            </div>

                        </div>

                        <hr class="my-4">

                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-bullseye me-1"></i>
                            Standar Target Produksi
                        </h6>

                        <div class="mb-3">
                            <label class="form-label">
                                Target per Jam (pcs)
                                <span class="text-danger">*</span>
                            </label>

                            <input type="number"
                                   name="target_per_jam"
                                   class="form-control"
                                   min="1"
                                   step="1"
                                   placeholder="200"
                                   value="<?= e($editData['target_per_jam'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Target per Shift (pcs)
                                <span class="text-danger">*</span>
                            </label>

                            <input type="number"
                                   name="target_per_shift"
                                   class="form-control"
                                   min="1"
                                   step="1"
                                   placeholder="1600"
                                   value="<?= e($editData['target_per_shift'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Target per Hari (pcs)
                                <span class="text-danger">*</span>
                            </label>

                            <input type="number"
                                   name="target_per_hari"
                                   class="form-control"
                                   min="1"
                                   step="1"
                                   placeholder="4800"
                                   value="<?= e($editData['target_per_hari'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="alert alert-info small">
                            Target per jam digunakan untuk menghitung
                            target planning berdasarkan durasi jadwal.
                            Target shift dan target hari tetap disimpan
                            sebagai standar part.
                        </div>

                        <hr class="my-4">

                        <h6 class="fw-bold mb-3">
                            Informasi Material
                        </h6>

                        <div class="mb-3">
                            <label class="form-label">Material</label>

                            <input type="text"
                                   name="material"
                                   class="form-control"
                                   maxlength="100"
                                   placeholder="Contoh: ABS"
                                   value="<?= e($editData['material'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Warna</label>

                            <input type="text"
                                   name="warna"
                                   class="form-control"
                                   maxlength="100"
                                   placeholder="Contoh: Hitam"
                                   value="<?= e($editData['warna'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Keterangan</label>

                            <textarea name="keterangan"
                                      class="form-control"
                                      rows="3"
                                      placeholder="Keterangan tambahan"><?= e($editData['keterangan'] ?? '') ?></textarea>
                        </div>

                        <button type="submit"
                                class="btn btn-primary w-100">
                            <i class="bi bi-save me-1"></i>
                            <?= $editData ? 'Simpan Perubahan' : 'Simpan Part' ?>
                        </button>

                        <?php if ($editData): ?>
                            <a href="part.php"
                               class="btn btn-light border w-100 mt-2">
                                Batal Edit
                            </a>
                        <?php endif; ?>

                    </form>

                </div>
            </div>
        </div>

        <!-- TABEL PART -->
        <div class="col-12 col-xl-8">
            <div class="card page-card">

                <div class="card-body p-3 p-lg-4">

                    <div class="d-flex flex-wrap justify-content-between
                                align-items-center gap-2 mb-3">

                        <h5 class="fw-bold mb-0">Daftar Part</h5>

                        <form method="GET" class="d-flex gap-2">
                            <input type="search"
                                   name="q"
                                   class="form-control"
                                   placeholder="Cari kode/nama part..."
                                   value="<?= e($keyword) ?>">

                            <button class="btn btn-primary"
                                    type="submit"
                                    title="Cari">
                                <i class="bi bi-search"></i>
                            </button>

                            <?php if ($keyword !== ''): ?>
                                <a href="part.php"
                                   class="btn btn-outline-secondary"
                                   title="Reset pencarian">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </form>

                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">

                            <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Part</th>
                                    <th>Second Proses</th>
                                    <th>Cycle Time</th>
                                    <th>Cavity</th>
                                    <th>Target/Jam</th>
                                    <th>Target/Shift</th>
                                    <th>Target/Hari</th>
                                    <th>Material</th>
                                    <th>Warna</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php if (!$parts): ?>
                                <tr>
                                    <td colspan="12"
                                        class="text-center text-secondary py-4">
                                        <?= $keyword !== ''
                                            ? 'Part tidak ditemukan.'
                                            : 'Belum ada data part.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>

                                <?php foreach ($parts as $i => $part): ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>

                                        <td>
                                            <div class="fw-semibold">
                                                <?= e($part['kode_part']) ?>
                                            </div>
                                            <div class="small text-secondary">
                                                <?= e($part['nama_part']) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php if ((int) $part['terdapat_second_proses'] === 1): ?>
                                                <span class="badge text-bg-success">Ya</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Tidak</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(number_format(
                                                (float) $part['cycle_time'],
                                                2,
                                                ',',
                                                '.'
                                            )) ?> detik
                                        </td>

                                        <td><?= (int) $part['cavity'] ?></td>

                                        <td class="target-value">
                                            <?= number_format(
                                                (int) $part['target_per_jam'],
                                                0,
                                                ',',
                                                '.'
                                            ) ?>
                                        </td>

                                        <td class="target-value">
                                            <?= number_format(
                                                (int) $part['target_per_shift'],
                                                0,
                                                ',',
                                                '.'
                                            ) ?>
                                        </td>

                                        <td class="target-value">
                                            <?= number_format(
                                                (int) $part['target_per_hari'],
                                                0,
                                                ',',
                                                '.'
                                            ) ?>
                                        </td>

                                        <td><?= e($part['material'] ?? '-') ?></td>

                                        <td><?= e($part['warna'] ?? '-') ?></td>

                                        <td>
                                            <?php if ($part['status'] === 'AKTIF'): ?>
                                                <span class="badge text-bg-success">
                                                    Aktif
                                                </span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">
                                                    Nonaktif
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="part.php?edit=<?= (int) $part['id'] ?>"
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>

                                                <form method="POST"
                                                      onsubmit="return confirm('Yakin ingin mengubah status part ini?')">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= e($_SESSION['csrf_token']) ?>">

                                                    <input type="hidden"
                                                           name="aksi"
                                                           value="toggle">

                                                    <input type="hidden"
                                                           name="id"
                                                           value="<?= (int) $part['id'] ?>">

                                                    <button type="submit"
                                                            class="btn btn-sm <?= $part['status'] === 'AKTIF'
                                                                ? 'btn-outline-danger'
                                                                : 'btn-outline-success' ?>"
                                                            title="<?= $part['status'] === 'AKTIF'
                                                                ? 'Nonaktifkan'
                                                                : 'Aktifkan' ?>">

                                                        <i class="bi <?= $part['status'] === 'AKTIF'
                                                            ? 'bi-toggle-on'
                                                            : 'bi-toggle-off' ?>"></i>
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

                    <div class="small text-secondary mt-2">
                        Menampilkan <?= count($parts) ?> data
                        <?= $keyword !== '' ? 'hasil pencarian' : 'part' ?>.
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>