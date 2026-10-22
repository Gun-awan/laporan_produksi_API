
<?php
session_start();
require_once "../config/koneksi.php";

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'operator'
) {
    header("Location: ../index.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$namaOperator = $_SESSION['nama'] ?? 'Operator';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_operator'])) {
    $_SESSION['csrf_operator'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_operator'];
$pesan = '';
$jenisPesan = 'info';

// Nilai filter
$tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$lineId = (int) ($_GET['line_id'] ?? 0);
$mesinId = (int) ($_GET['mesin_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

if (isset($_GET['started'])) {
    $pesan = 'Planning berhasil dimulai. Aktivitas sudah tercatat.';
    $jenisPesan = 'success';
}

if (isset($_GET['error'])) {
    $pesan = (string) $_GET['error'];
    $jenisPesan = 'danger';
}

// ======================================================
// PROSES MULAI PRODUKSI
// ======================================================
// if ($_SERVER['REQUEST_METHOD'] === 'POST'
//     && ($_POST['aksi'] ?? '') === 'mulai_produksi') {

//     $tanggalPost = $_POST['tanggal'] ?? date('Y-m-d');
//     $linePost = (int) ($_POST['line_id'] ?? 0);
//     $mesinPost = (int) ($_POST['mesin_id'] ?? 0);
//     $planningId = (int) ($_POST['planning_id'] ?? 0);
//     $tokenPost = $_POST['csrf_token'] ?? '';

//     $redirectBase = 'index.php?' . http_build_query([
//         'tanggal' => $tanggalPost,
//         'line_id' => $linePost,
//         'mesin_id' => $mesinPost
//     ]);

//     if (!hash_equals($csrf, $tokenPost)) {
//         header("Location: index.php?error=" .
//             urlencode('Sesi formulir tidak valid. Silakan muat ulang halaman.'));
//         exit;
//     }

//     try {
//         if ($planningId < 1 || $linePost < 1 || $mesinPost < 1) {
//             throw new Exception('Planning, line, dan mesin harus dipilih.');
//         }

//         $conn->begin_transaction();

//         // Mengunci akun operator untuk mencegah dua aktivitas
//         // dimulai bersamaan oleh operator yang sama.
//         $stmt = $conn->prepare(
//             "SELECT id FROM users WHERE id = ? FOR UPDATE"
//         );
//         $stmt->bind_param("i", $userId);
//         $stmt->execute();
//         $akun = $stmt->get_result()->fetch_assoc();
//         $stmt->close();

//         if (!$akun) {
//             throw new Exception('Akun operator tidak ditemukan.');
//         }

//         // Kunci planning yang akan dimulai.
//         $stmt = $conn->prepare(
//             "SELECT p.id, p.status, p.dimulai_oleh,
//                     p.tanggal_produksi, p.mesin_id
//              FROM planning_produksi p
//              WHERE p.id = ?
//              FOR UPDATE"
//         );
//         $stmt->bind_param("i", $planningId);
//         $stmt->execute();
//         $planning = $stmt->get_result()->fetch_assoc();
//         $stmt->close();

//         if (!$planning) {
//             throw new Exception('Planning tidak ditemukan.');
//         }

//         if (
//             $planning['status'] === 'BERJALAN'
//             && (int) $planning['dimulai_oleh'] === $userId
//         ) {
//             $conn->commit();
//             header("Location: aktivitas/laporan.php?planning_id=" . $planningId);
//             exit;
//         }

//         if ($planning['status'] !== 'TERJADWAL') {
//             throw new Exception(
//                 'Planning ini tidak tersedia untuk dimulai.'
//             );
//         }

//         if (
//             $planning['tanggal_produksi'] !== $tanggalPost
//             || (int) $planning['mesin_id'] !== $mesinPost
//         ) {
//             throw new Exception(
//                 'Data planning tidak sesuai dengan filter yang dipilih.'
//             );
//         }

//         // Satu operator hanya boleh memiliki satu aktivitas berjalan.
//         $stmt = $conn->prepare(
//             "SELECT id
//              FROM planning_produksi
//              WHERE dimulai_oleh = ?
//                AND status = 'BERJALAN'
//              LIMIT 1
//              FOR UPDATE"
//         );
//         $stmt->bind_param("i", $userId);
//         $stmt->execute();
//         $aktivitasAktif = $stmt->get_result()->fetch_assoc();
//         $stmt->close();

//         if ($aktivitasAktif) {
//             throw new Exception(
//                 'Kamu masih memiliki aktivitas produksi yang berjalan. ' .
//                 'Selesaikan aktivitas tersebut terlebih dahulu.'
//             );
//         }

//         $sekarang = date('Y-m-d H:i:s');

//         $stmt = $conn->prepare(
//             "UPDATE planning_produksi
//              SET status = 'BERJALAN',
//                  dimulai_oleh = ?,
//                  mulai_aktual = ?
//              WHERE id = ?
//                AND status = 'TERJADWAL'"
//         );
//         $stmt->bind_param("isi", $userId, $sekarang, $planningId);
//         $stmt->execute();

//         if ($stmt->affected_rows !== 1) {
//             $stmt->close();
//             throw new Exception(
//                 'Planning sudah berubah status. Muat ulang halaman.'
//             );
//         }

//         $stmt->close();
//         $conn->commit();

//         header("Location: aktivitas/laporan.php?planning_id=" . $planningId);
//         exit;

//     } catch (Throwable $ex) {
//         try {
//             $conn->rollback();
//         } catch (Throwable $ignored) {
//             // Tidak ada tindakan tambahan.
//         }

//         error_log('Mulai produksi: ' . $ex->getMessage());

//         $pesanError = $ex instanceof Exception
//             ? $ex->getMessage()
//             : 'Terjadi kesalahan saat memulai produksi.';

//         header("Location: index.php?error=" . urlencode($pesanError));
//         exit;
//     }
// }


if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['aksi'] ?? '') === 'mulai_produksi'
) {


    $planningId = filter_input(
        INPUT_POST,
        'planning_id',
        FILTER_VALIDATE_INT
    );

    $operatorId = (int) ($_SESSION['user_id'] ?? 0);

    if (!$planningId || $planningId < 1 || $operatorId < 1) {
        $_SESSION['flash_error'] = 'Data planning tidak valid.';
        header('Location: index.php');
        exit;
    }

    try {
        $conn->begin_transaction();

        /*
         * 1. Kunci data planning dan periksa statusnya.
         */
        $stmt = $conn->prepare("
            SELECT id, status
            FROM planning_produksi
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->bind_param('i', $planningId);
        $stmt->execute();

        $planning = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$planning) {
            throw new Exception('Planning tidak ditemukan.');
        }

        if ($planning['status'] !== 'TERJADWAL') {
            throw new Exception(
                'Planning tidak dapat dimulai karena statusnya bukan TERJADWAL.'
            );
        }

        /*
         * 2. Pastikan operator belum memiliki aktivitas berjalan.
         */
        $stmt = $conn->prepare("
            SELECT id
            FROM planning_produksi
            WHERE dimulai_oleh = ?
              AND status = 'BERJALAN'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $operatorId);
        $stmt->execute();

        $aktivitasAktif = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($aktivitasAktif) {
            throw new Exception(
                'Anda masih memiliki aktivitas produksi yang berjalan. '
                . 'Selesaikan atau lanjutkan aktivitas tersebut terlebih dahulu.'
            );
        }

        /*
         * 3. Mulai planning menggunakan waktu server.
         */
        $waktuMulai = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            UPDATE planning_produksi
            SET status = 'BERJALAN',
                dimulai_oleh = ?,
                mulai_aktual = ?
            WHERE id = ?
              AND status = 'TERJADWAL'
        ");

        $stmt->bind_param(
            'isi',
            $operatorId,
            $waktuMulai,
            $planningId
        );

        $stmt->execute();
        $berhasil = $stmt->affected_rows;
        $stmt->close();

        if ($berhasil !== 1) {
            throw new Exception(
                'Planning gagal dimulai. Silakan muat ulang halaman.'
            );
        }

        $conn->commit();

        $_SESSION['flash_success'] =
            'Planning berhasil dimulai. Selamat bekerja!';

        header(
            'Location: aktivitas/laporan.php?planning_id='
            . $planningId
        );
        exit;

    } catch (Throwable $e) {
        $conn->rollback();

        $_SESSION['flash_error'] = $e instanceof mysqli_sql_exception
            ? 'Terjadi kesalahan database saat memulai planning.'
            : $e->getMessage();

        header('Location: index.php');
        exit;
    }
}

// ======================================================
// DATA MASTER LINE
// ======================================================
$daftarLine = [];

$result = $conn->query(
    "SELECT id, kode_line, nama_line
     FROM master_line
     WHERE status = 'AKTIF'
     ORDER BY kode_line"
);

while ($row = $result->fetch_assoc()) {
    $daftarLine[] = $row;
}

// ======================================================
// DATA MASTER MESIN
// ======================================================
$daftarMesin = [];

$sqlMesin = "
    SELECT m.id, m.line_id, m.kode_mesin, m.nama_mesin
    FROM master_mesin m
    INNER JOIN master_line l ON l.id = m.line_id
    WHERE m.status = 'AKTIF'
      AND l.status = 'AKTIF'
    ORDER BY l.kode_line, m.kode_mesin
";

$resultMesin = $conn->query($sqlMesin);

while ($row = $resultMesin->fetch_assoc()) {
    $daftarMesin[] = $row;
}

// Pastikan line dan mesin filter benar-benar cocok.
$lineValid = false;
foreach ($daftarLine as $line) {
    if ((int) $line['id'] === $lineId) {
        $lineValid = true;
        break;
    }
}

$mesinValid = false;
foreach ($daftarMesin as $mesin) {
    if (
        (int) $mesin['id'] === $mesinId
        && (int) $mesin['line_id'] === $lineId
    ) {
        $mesinValid = true;
        break;
    }
}

if (!$lineValid) {
    $lineId = 0;
}

if (!$mesinValid) {
    $mesinId = 0;
}

// ======================================================
// CEK AKTIVITAS AKTIF OPERATOR
// ======================================================
$stmt = $conn->prepare(
    "SELECT p.id, p.tanggal_produksi, p.waktu_mulai,
            p.waktu_selesai, p.mesin_id,
            m.kode_mesin, l.kode_line, mp.kode_part, mp.nama_part
     FROM planning_produksi p
     INNER JOIN master_mesin m ON m.id = p.mesin_id
     INNER JOIN master_line l ON l.id = m.line_id
     INNER JOIN master_part mp ON mp.id = p.part_id
     WHERE p.dimulai_oleh = ?
       AND p.status = 'BERJALAN'
     LIMIT 1"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$aktivitasAktif = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ======================================================
// AMBIL PLANNING SESUAI FILTER
// ======================================================

$planningList = [];

if ($lineId > 0 && $mesinId > 0) {

    $sqlPlanning = "
        SELECT
            p.id,
            p.tanggal_produksi,
            p.shift,
            p.urutan,
            p.waktu_mulai,
            p.waktu_selesai,

            p.cycle_time_snapshot,
            p.cavity_snapshot,
            p.target_per_jam_snapshot,
            p.target_per_shift_snapshot,
            p.target_per_hari_snapshot,
            p.target_planning,

            p.terdapat_second_proses_snapshot,
            p.status,
            p.mulai_aktual,
            p.stop_jo_at,
            p.dimulai_oleh,

            m.kode_mesin,
            m.nama_mesin,

            l.kode_line,
            l.nama_line,

            mp.kode_part,
            mp.nama_part,
            mp.terdapat_second_proses,
            mp.material,
            mp.warna,

            COALESCE((
                SELECT SUM(lpj.jumlah_ok)
                FROM laporan_produksi_jam lpj
                WHERE lpj.planning_id = p.id
            ), 0) AS total_ok,

            COALESCE((
                SELECT SUM(lpj.jumlah_ng)
                FROM laporan_produksi_jam lpj
                WHERE lpj.planning_id = p.id
            ), 0) AS total_ng,

            COALESCE((
                SELECT SUM(COALESCE(d.durasi_menit, 0))
                FROM laporan_produksi_jam lpj
                INNER JOIN downtime d
                    ON d.laporan_id = lpj.id
                WHERE lpj.planning_id = p.id
            ), 0) AS total_downtime

        FROM planning_produksi p

        INNER JOIN master_mesin m
            ON m.id = p.mesin_id

        INNER JOIN master_line l
            ON l.id = m.line_id

        INNER JOIN master_part mp
            ON mp.id = p.part_id

        WHERE p.tanggal_produksi = ?
          AND p.mesin_id = ?
          AND m.line_id = ?
          AND (
                p.status IN ('TERJADWAL', 'SELESAI')
                OR (
                    p.status = 'BERJALAN'
                    AND p.dimulai_oleh = ?
                )
          )

        ORDER BY
            p.waktu_mulai ASC,
            p.urutan ASC
    ";

    $stmt = $conn->prepare($sqlPlanning);

    if (!$stmt) {
        die('Gagal menyiapkan query planning: ' . $conn->error);
    }

    $stmt->bind_param(
        "siii",
        $tanggal,
        $mesinId,
        $lineId,
        $userId
    );

    if (!$stmt->execute()) {
        die('Gagal mengambil planning: ' . $stmt->error);
    }

    $resultPlanning = $stmt->get_result();

    while ($row = $resultPlanning->fetch_assoc()) {
        $planningList[] = $row;
    }

    $stmt->close();
}

function waktuLokal($datetime): string
{
    return date('H:i', strtotime($datetime));
}

function tanggalLokal($tanggal): string
{
    return date('d-m-Y', strtotime($tanggal));
}

function labelShift(int $shift): string
{
    return 'Shift ' . $shift;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Operator | Laporan Produksi</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <style>
        body {
            background: #f4f6fa;
            color: #202938;
        }

        .topbar {
            background: #fff;
            border-bottom: 1px solid #e8ebf1;
        }

        .page-container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .welcome-card {
            background: linear-gradient(135deg, #164e9b, #2875d1);
            color: white;
            border: 0;
            border-radius: 18px;
        }

        .panel-card,
        .planning-card {
            border: 1px solid #e8ebf1;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 4px 18px rgba(30, 50, 80, .04);
        }

        .planning-card {
            height: 100%;
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .planning-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(30, 50, 80, .08);
        }

        .part-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eaf2ff;
            color: #1f5ba9;
            font-size: 22px;
            flex-shrink: 0;
        }

        .info-label {
            color: #778196;
            font-size: .78rem;
        }

        .info-value {
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .target-box {
            background: #f0f6ff;
            border-radius: 12px;
            padding: 13px;
        }

        .form-label {
            font-weight: 600;
            font-size: .9rem;
        }

        .form-select,
        .form-control {
            min-height: 44px;
            border-radius: 10px;
        }

        .btn {
            border-radius: 10px;
            min-height: 42px;
        }

        .muted-small {
            color: #7a8496;
            font-size: .82rem;
        }

        @media (max-width: 576px) {
            .page-container {
                padding-left: 12px;
                padding-right: 12px;
            }

            .welcome-card {
                border-radius: 14px;
            }
        }

        /* Posisi section tidak tertutup navbar saat diarahkan */
#sectionPlanningProduksi {
    scroll-margin-top: 24px;
}

/* Efek highlight sementara */
.planning-highlight {
    border-radius: 16px;
    transition: background-color .4s ease,
                box-shadow .4s ease;
}

.planning-highlight.highlight-aktif {
    background-color: #eaf2ff;
    box-shadow: 0 0 0 5px rgba(13, 110, 253, .20);
}
    </style>
</head>

<body>

<nav class="navbar topbar py-3">
    <div class="container page-container">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2"
           href="index.php">
            <i class="bi bi-clipboard2-data text-primary"></i>
            <span>Laporan Produksi</span>
        </a>

        <div class="d-flex align-items-center gap-2">
            <span class="d-none d-sm-inline small text-secondary">
                <?= e($namaOperator) ?>
            </span>
            <a href="../auth/logout.php"
               class="btn btn-outline-secondary btn-sm"
               onclick="return confirm('Yakin ingin logout?')">
                <i class="bi bi-box-arrow-right me-1"></i>Keluar
            </a>
        </div>
    </div>
</nav>

<main class="container page-container py-4">

    <section class="welcome-card p-4 mb-4">
        <div class="d-flex align-items-start gap-3">
            <div class="rounded-3 bg-white bg-opacity-25 p-3">
                <i class="bi bi-person-workspace fs-3"></i>
            </div>
            <div>
                <div class="small opacity-75 mb-1">Dashboard Operator</div>
                <h1 class="h4 fw-bold mb-2">
                    Selamat datang, <?= e($namaOperator) ?>!
                </h1>
                <p class="mb-0 opacity-75">
                    Pilih tanggal produksi, line, dan mesin untuk melihat
                    planning yang harus dikerjakan.
                </p>
            </div>
        </div>
    </section>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show"
             role="alert">
            <?= e($pesan) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($aktivitasAktif): ?>
        <section class="panel-card p-3 p-md-4 mb-4">
            <div class="d-flex align-items-start gap-3">
                <div class="part-icon">
                    <i class="bi bi-play-circle-fill"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <h2 class="h6 fw-bold mb-0">Aktivitas kamu sedang berjalan</h2>
                        <span class="badge text-bg-success">BERJALAN</span>
                    </div>

                    <div class="fw-semibold">
                        <?= e($aktivitasAktif['nama_part']) ?>
                        <span class="text-secondary fw-normal">
                            (<?= e($aktivitasAktif['kode_part']) ?>)
                        </span>
                    </div>

                    <div class="muted-small mt-1">
                        <?= e($aktivitasAktif['kode_line']) ?>
                        · <?= e($aktivitasAktif['kode_mesin']) ?>
                        · <?= e(tanggalLokal($aktivitasAktif['tanggal_produksi'])) ?>
                        · <?= e(waktuLokal($aktivitasAktif['waktu_mulai'])) ?>
                        – <?= e(waktuLokal($aktivitasAktif['waktu_selesai'])) ?>
                    </div>

                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                        Kamu tidak dapat memulai planning lain sebelum aktivitas
                        ini diselesaikan.
                    </div>

                    <a class="btn btn-primary mt-3 w-100"
                       href="aktivitas/laporan.php?planning_id=<?= (int) $aktivitasAktif['id'] ?>">
                        <i class="bi bi-clipboard-check me-1"></i>
                        Buka Laporan Produksi
                    </a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel-card p-3 p-md-4 mb-4">
        <div class="mb-3">
            <h2 class="h5 fw-bold mb-1">Cari Planning Produksi</h2>
            <p class="text-secondary small mb-0">
                Tentukan tanggal, line, dan mesin yang akan kamu kerjakan.
            </p>
        </div>

        <form method="GET"
            action="index.php#sectionPlanningProduksi"
            id="filterForm">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="tanggal" class="form-label">
                        Tanggal Produksi
                    </label>
                    <input type="date"
                           class="form-control"
                           id="tanggal"
                           name="tanggal"
                           value="<?= e($tanggal) ?>"
                           required>
                </div>

                <div class="col-12 col-md-4">
                    <label for="line_id" class="form-label">Line</label>
                    <select class="form-select" id="line_id" name="line_id"
                            required>
                        <option value="">-- Pilih Line --</option>
                        <?php foreach ($daftarLine as $line): ?>
                            <option value="<?= (int) $line['id'] ?>"
                                <?= $lineId === (int) $line['id'] ? 'selected' : '' ?>>
                                <?= e($line['kode_line']) ?> -
                                <?= e($line['nama_line']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label for="mesin_id" class="form-label">Mesin</label>
                    <select class="form-select" id="mesin_id" name="mesin_id"
                            required>
                        <option value="">-- Pilih Mesin --</option>
                        <?php foreach ($daftarMesin as $mesin): ?>
                            <option
                                value="<?= (int) $mesin['id'] ?>"
                                data-line="<?= (int) $mesin['line_id'] ?>"
                                <?= $mesinId === (int) $mesin['id'] ? 'selected' : '' ?>>
                                <?= e($mesin['kode_mesin']) ?> -
                                <?= e($mesin['nama_mesin']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>
                        Lihat Planning
                    </button>
                </div>
            </div>
        </form>
    </section>

    <?php if ($lineId > 0 && $mesinId > 0): ?>

    <section id="sectionPlanningProduksi"
             class="planning-highlight">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">Planning Produksi</h2>
                <div class="text-secondary small">
                    Tanggal <?= e(tanggalLokal($tanggal)) ?>
                </div>
            </div>

            <span class="badge rounded-pill text-bg-light border text-dark">
                <?= count($planningList) ?> planning
            </span>
        </div>

        <?php if (count($planningList) === 0): ?>
            <div class="panel-card p-4 text-center">
                <i class="bi bi-calendar-x fs-1 text-secondary"></i>
                <h3 class="h6 fw-bold mt-3">Planning tidak ditemukan</h3>
                <p class="text-secondary small mb-0">
                    Tidak ada planning yang dapat dikerjakan untuk tanggal,
                    line, dan mesin tersebut.
                </p>
            </div>
        <?php else: ?>

            <div class="row g-3">
                <?php foreach ($planningList as $p): ?>
                    <?php
                    $sedangBerjalan = $p['status'] === 'BERJALAN';
                    $milikOperator = $sedangBerjalan
                        && (int) $p['dimulai_oleh'] === $userId;

                    $bolehMulai = (
                        $p['status'] === 'TERJADWAL'
                        && !$aktivitasAktif
                    );
                    ?>

                    <div class="col-12 col-md-6">
                        <article class="planning-card p-3 p-md-4">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <div class="part-icon">
                                    <i class="bi bi-box-seam"></i>
                                </div>

                                <div class="flex-grow-1">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <h3 class="h6 fw-bold mb-0">
                                            <?= e($p['nama_part']) ?>
                                        </h3>

                                        <?php if ($p['status'] === 'BERJALAN'): ?>

                                            <span class="badge text-bg-success">
                                                <i class="bi bi-play-circle me-1"></i>
                                                BERJALAN
                                            </span>

                                        <?php elseif ($p['status'] === 'SELESAI'): ?>

                                            <span class="badge text-bg-secondary">
                                                <i class="bi bi-check-circle me-1"></i>
                                                SELESAI
                                            </span>

                                        <?php else: ?>

                                            <span class="badge text-bg-primary">
                                                <i class="bi bi-calendar-check me-1"></i>
                                                TERJADWAL
                                            </span>

                                        <?php endif; ?>
                                    </div>

                                    <div class="text-secondary small">
                                        <?= e($p['kode_part']) ?>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <div class="info-label">Line / Mesin</div>
                                    <div class="info-value">
                                        <?= e($p['kode_line']) ?> /
                                        <?= e($p['kode_mesin']) ?>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="info-label">Shift</div>
                                    <div class="info-value">
                                        <?= e(labelShift((int) $p['shift'])) ?>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="info-label">Waktu Planning</div>
                                    <div class="info-value">
                                        <?= e(waktuLokal($p['waktu_mulai'])) ?>
                                        –
                                        <?= e(waktuLokal($p['waktu_selesai'])) ?>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="info-label">Cycle Time / Cavity</div>
                                    <div class="info-value">
                                        <?= e($p['cycle_time_snapshot']) ?> dtk /
                                        <?= (int) $p['cavity_snapshot'] ?>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="info-label">Target per Jam</div>
                                    <div class="info-value">
                                        <?= number_format((int) $p['target_per_jam_snapshot']) ?>
                                        pcs
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="info-label">Urutan Planning</div>
                                    <div class="info-value">
                                        <?= (int) $p['urutan'] ?>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-between
                                                border rounded-3 p-3">

                                        <?php
                                    $terdapatSecondProses =
                                        (int) $p['terdapat_second_proses_snapshot'] === 1;
                                    ?>

                                    <div class="mb-2">
                                        <small class="text-muted d-block">Second Proses</small>

                                        <?php if ($terdapatSecondProses): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle me-1"></i> Ya
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-dash-circle me-1"></i> Tidak
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                        <i class="bi bi-arrow-left-right fs-4 text-secondary"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <?php
                            $totalOkPlanning = (int)($p['total_ok'] ?? 0);
                            $totalNgPlanning = (int)($p['total_ng'] ?? 0);
                            $totalDowntimePlanning = (int)($p['total_downtime'] ?? 0);

                            $effectiveRunTimeMenit = 0;

                            $waktuMulaiAktual = $p['mulai_aktual'] ?? null;
                            $waktuStopJO = $p['stop_jo_at'] ?? null;

                            if (
                                !empty($waktuMulaiAktual) &&
                                !empty($waktuStopJO)
                            ) {
                                $timestampMulai = strtotime($waktuMulaiAktual);
                                $timestampStop = strtotime($waktuStopJO);

                                if (
                                    $timestampMulai !== false &&
                                    $timestampStop !== false &&
                                    $timestampStop >= $timestampMulai
                                ) {
                                    $durasiAktivitasMenit = (int)floor(
                                        ($timestampStop - $timestampMulai) / 60
                                    );

                                    $effectiveRunTimeMenit = max(
                                        0,
                                        $durasiAktivitasMenit - $totalDowntimePlanning
                                    );
                                }
                            }

                            $effectiveJam = intdiv($effectiveRunTimeMenit, 60);
                            $effectiveMenit = $effectiveRunTimeMenit % 60;
                            ?>

                            <div class="target-box mb-3">
                                <div class="info-label">Target Planning</div>

                                <div class="fs-4 fw-bold text-primary">
                                    <?= number_format((int)$p['target_planning']) ?>
                                    <span class="fs-6 fw-normal">pcs</span>
                                </div>
                            </div>

                            <?php if ($p['status'] === 'SELESAI'): ?>

                                <div class="border rounded-3 p-3 mb-3 bg-light">
                                    <div class="fw-bold mb-3">
                                        <i class="bi bi-clipboard-data me-1"></i>
                                        Ringkasan Hasil Produksi
                                    </div>

                                    <div class="row g-3">

                                        <div class="col-6">
                                            <div class="border rounded-3 bg-white p-3 h-100">
                                                <div class="small text-secondary mb-1">
                                                    Total OK
                                                </div>
                                                <div class="fs-5 fw-bold text-success">
                                                    <?= number_format($totalOkPlanning) ?>
                                                </div>
                                                <div class="small text-secondary">pcs</div>
                                            </div>
                                        </div>

                                        <div class="col-6">
                                            <div class="border rounded-3 bg-white p-3 h-100">
                                                <div class="small text-secondary mb-1">
                                                    Total NG
                                                </div>
                                                <div class="fs-5 fw-bold text-danger">
                                                    <?= number_format($totalNgPlanning) ?>
                                                </div>
                                                <div class="small text-secondary">pcs</div>
                                            </div>
                                        </div>

                                        <div class="col-6">
                                            <div class="border rounded-3 bg-white p-3 h-100">
                                                <div class="small text-secondary mb-1">
                                                    Effective Run Time
                                                </div>
                                                <div class="fs-5 fw-bold text-primary">
                                                    <?= $effectiveJam ?>j
                                                    <?= $effectiveMenit ?>m
                                                </div>
                                                <div class="small text-secondary">
                                                    <?= number_format($effectiveRunTimeMenit) ?> menit
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-6">
                                            <div class="border rounded-3 bg-white p-3 h-100">
                                                <div class="small text-secondary mb-1">
                                                    Downtime
                                                </div>
                                                <div class="fs-5 fw-bold text-warning">
                                                    <?= intdiv($totalDowntimePlanning, 60) ?>j
                                                    <?= $totalDowntimePlanning % 60 ?>m
                                                </div>
                                                <div class="small text-secondary">
                                                    <?= number_format($totalDowntimePlanning) ?> menit
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>

                            <?php endif; ?>


                            <?php if ($milikOperator): ?>
                                <a class="btn btn-primary w-100"
                                   href="aktivitas/laporan.php?planning_id=<?= (int) $p['id'] ?>">
                                    <i class="bi bi-clipboard-check me-1"></i>
                                    Buka Laporan Produksi
                                </a>

                            <?php elseif ($bolehMulai): ?>
                                <form method="POST" action="index.php"
                                      onsubmit="return confirm('Mulai produksi untuk part <?= e($p['kode_part']) ?>?');">
                                    <input type="hidden" name="aksi"
                                           value="mulai_produksi">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($csrf) ?>">
                                    <input type="hidden" name="planning_id"
                                           value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="tanggal"
                                           value="<?= e($tanggal) ?>">
                                    <input type="hidden" name="line_id"
                                           value="<?= $lineId ?>">
                                    <input type="hidden" name="mesin_id"
                                           value="<?= $mesinId ?>">

                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="bi bi-play-fill me-1"></i>
                                        Mulai Produksi
                                    </button>
                                </form>

                            <?php elseif ($p['status'] === 'SELESAI'): ?>

                                <button class="btn btn-outline-secondary w-100"
                                        type="button" disabled>
                                    <i class="bi bi-check-circle me-1"></i>
                                    Produksi Selesai
                                </button>

                            <?php else: ?>

                                <button class="btn btn-secondary w-100"
                                        type="button" disabled>
                                    <i class="bi bi-lock me-1"></i>
                                    Selesaikan Aktivitas Aktif Dahulu
                                </button>

                            <?php endif; ?>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    <?php else: ?>
        <div class="panel-card p-4 text-center">
            <i class="bi bi-funnel fs-1 text-secondary"></i>
            <h2 class="h6 fw-bold mt-3">Pilih filter terlebih dahulu</h2>
            <p class="text-secondary small mb-0">
                Planning akan muncul setelah tanggal produksi, line, dan mesin
                dipilih.
            </p>
        </div>
    </section>
    <?php endif; ?>

    <footer class="text-center text-secondary small py-4">
        Laporan Produksi &middot; Dashboard Operator
    </footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    const section = document.getElementById('sectionPlanningProduksi');

    // Jalankan hanya setelah filter Lihat Planning dikirim
    if (
        section &&
        params.has('tanggal') &&
        params.has('line_id') &&
        params.has('mesin_id')
    ) {
        // Arahkan layar ke section planning
        section.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });

        // Aktifkan highlight sementara
        section.classList.add('highlight-aktif');

        setTimeout(function () {
            section.classList.remove('highlight-aktif');
        }, 2500);
    }
});
</script>

<script>
const lineSelect = document.getElementById('line_id');
const mesinSelect = document.getElementById('mesin_id');

function filterMesin() {
    const lineDipilih = lineSelect.value;
    const opsiMesin = Array.from(mesinSelect.options);

    opsiMesin.forEach(function(opsi, index) {
        if (index === 0) {
            opsi.hidden = false;
            return;
        }

        opsi.hidden = opsi.dataset.line !== lineDipilih;
    });

    const opsiTerpilih = mesinSelect.options[mesinSelect.selectedIndex];

    if (
        !opsiTerpilih ||
        opsiTerpilih.value === '' ||
        opsiTerpilih.dataset.line !== lineDipilih
    ) {
        mesinSelect.value = '';
    }
}

lineSelect.addEventListener('change', filterMesin);
filterMesin();

// Auto-hide notifikasi setelah 4 detik.
document.querySelectorAll('.alert-dismissible').forEach(function(alertEl) {
    setTimeout(function() {
        if (document.body.contains(alertEl)) {
            bootstrap.Alert.getOrCreateInstance(alertEl).close();
        }
    }, 4000);
});
</script>

</body>
</html>