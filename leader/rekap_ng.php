<?php
session_start();

date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'leader') {
    header('Location: ../index.php');
    exit;
}

$leaderId = (int) $_SESSION['user_id'];
$leaderNama = $_SESSION['nama'] ?? 'Leader';

if (empty($_SESSION['csrf_leader'])) {
    $_SESSION['csrf_leader'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_leader'];

require_once __DIR__ . '/../config/koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->set_charset('utf8mb4');

/* =====================================================
   HELPER
===================================================== */

function h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function angka($value): string
{
    return number_format((float) $value, 0, ',', '.');
}

function berat($value): string
{
    return number_format((float) $value, 2, ',', '.');
}

/* =====================================================
   FILTER
===================================================== */

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
$shift   = trim((string) ($_GET['shift'] ?? ''));
$lineId  = trim((int) ($_GET['line_id'] ?? ''));
$mesinId = trim((string) ($_GET['mesin_id'] ?? ''));

// Validasi tanggal
$tanggalObj = DateTime::createFromFormat('!Y-m-d', $tanggal);

if (
    !$tanggalObj ||
    $tanggalObj->format('Y-m-d') !== $tanggal
) {
    $tanggal = date('Y-m-d');
}

// Validasi shift
if (!in_array($shift, ['', '1', '2', '3'], true)) {
    $shift = '';
}

// Validasi ID filter
$lineIdsRaw = $_GET['line_id'] ?? [];

if (!is_array($lineIdsRaw)) {
    $lineIdsRaw = [$lineIdsRaw];
}

$lineIds = [];

foreach ($lineIdsRaw as $id) {
    $id = filter_var($id, FILTER_VALIDATE_INT);

    if ($id !== false && $id > 0) {
        $lineIds[] = (int) $id;
    }
}

$lineIds = array_values(array_unique($lineIds));

$mesinId = trim((string) ($_GET['mesin_id'] ?? ''));

if (!ctype_digit($mesinId) || (int) $mesinId < 1) {
    $mesinId = '';
}

/* =====================================================
   DATA DROPDOWN FILTER
===================================================== */

$daftarLine = [];
$daftarMesin = [];

$resultLine = $conn->query("
    SELECT id, kode_line, nama_line
    FROM master_line
    WHERE status = 'AKTIF'
    ORDER BY nama_line ASC
");

while ($row = $resultLine->fetch_assoc()) {
    $daftarLine[] = $row;
}

$resultMesin = $conn->query("
    SELECT
        m.id,
        m.kode_mesin,
        m.nama_mesin,
        m.line_id,
        l.nama_line
    FROM master_mesin m
    INNER JOIN master_line l ON l.id = m.line_id
    WHERE m.status = 'AKTIF'
      AND l.status = 'AKTIF'
    ORDER BY l.nama_line ASC, m.kode_mesin ASC
");

while ($row = $resultMesin->fetch_assoc()) {
    $daftarMesin[] = $row;
}

/* =====================================================
   QUERY REKAP NG PER PLANNING
===================================================== */

$where = [
    "p.status = 'SELESAI'",
    "p.tanggal_produksi = ?"
];

$params = [$tanggal];
$types  = 's';

if ($shift !== '') {
    $where[] = 'p.shift = ?';
    $params[] = (int) $shift;
    $types .= 'i';
}

if (!empty($lineIds)) {
    $placeholders = implode(
        ', ',
        array_fill(0, count($lineIds), '?')
    );

    $where[] = "l.id IN ($placeholders)";

    foreach ($lineIds as $id) {
        $params[] = $id;
        $types .= 'i';
    }
}

if ($mesinId !== '') {
    $where[] = 'm.id = ?';
    $params[] = (int) $mesinId;
    $types .= 'i';
}

$sql = "
    SELECT
        p.id AS planning_id,
        p.tanggal_produksi,
        p.shift,
        p.urutan,

        l.kode_line,
        l.nama_line,

        m.kode_mesin,
        m.nama_mesin,

        pt.kode_part,
        pt.nama_part,
        pt.material,
        pt.warna,
        pt.berat_runner,
        pt.berat_part,

        COALESCE(rep.total_ok, 0) AS total_ok,
        COALESCE(rep.total_ng, 0) AS total_ng,

        COALESCE(kat.kategori_ng, '-') AS kategori_ng

    FROM planning_produksi p

    INNER JOIN master_mesin m
        ON m.id = p.mesin_id

    INNER JOIN master_line l
        ON l.id = m.line_id

    INNER JOIN master_part pt
        ON pt.id = p.part_id

    LEFT JOIN (
        SELECT
            planning_id,
            COALESCE(SUM(jumlah_ok), 0) AS total_ok,
            COALESCE(SUM(jumlah_ng), 0) AS total_ng
        FROM laporan_produksi_jam
        GROUP BY planning_id
    ) rep
        ON rep.planning_id = p.id

    LEFT JOIN (
        SELECT
            r.planning_id,
            GROUP_CONCAT(
                DISTINCT k.nama_kategori
                ORDER BY k.nama_kategori
                SEPARATOR ', '
            ) AS kategori_ng
        FROM laporan_produksi_jam r
        INNER JOIN laporan_ng_kategori rk
            ON rk.laporan_id = r.id
        INNER JOIN master_kategori_ng k
            ON k.id = rk.kategori_ng_id
        GROUP BY r.planning_id
    ) kat
        ON kat.planning_id = p.id

    WHERE " . implode(' AND ', $where) . "

    ORDER BY
        l.nama_line ASC,
        m.kode_mesin ASC,
        p.shift ASC,
        p.urutan ASC,
        p.id ASC
";

$stmt = $conn->prepare($sql);

// Bind parameter secara dinamis
$bindParams = [$types];

foreach ($params as $key => &$value) {
    $bindParams[] = &$value;
}

call_user_func_array(
    [$stmt, 'bind_param'],
    $bindParams
);

$stmt->execute();

$resultRekap = $stmt->get_result();

$dataRekap = [];

$totalPlanning = 0;
$totalOK = 0;
$totalNG = 0;
$totalProduksi = 0;
$totalBeratRunner = 0;
$totalBeratNG = 0;

while ($row = $resultRekap->fetch_assoc()) {
    $ok = (int) $row['total_ok'];
    $ng = (int) $row['total_ng'];

    $beratRunnerPerProduk = (float) ($row['berat_runner'] ?? 0);
    $beratPartPerProduk   = (float) ($row['berat_part'] ?? 0);

    $jumlahProduksi = $ok + $ng;

    // Berat runner = seluruh produk x berat runner per produk
    $beratRunnerTotal = $jumlahProduksi * $beratRunnerPerProduk;

    // Berat NG = total NG x berat part per produk
    $beratNGTotal = $ng * $beratPartPerProduk;

    $row['total_ok'] = $ok;
    $row['total_ng'] = $ng;
    $row['total_produksi'] = $jumlahProduksi;
    $row['berat_runner_total'] = $beratRunnerTotal;
    $row['berat_ng_total'] = $beratNGTotal;

    $dataRekap[] = $row;

    $totalPlanning++;
    $totalOK += $ok;
    $totalNG += $ng;
    $totalProduksi += $jumlahProduksi;
    $totalBeratRunner += $beratRunnerTotal;
    $totalBeratNG += $beratNGTotal;
}

$stmt->close();

/* =====================================================
   EXPORT EXCEL-COMPATIBLE CSV
===================================================== */

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $namaFile = 'rekap_ng_' . $tanggal . '.csv';

    // Bersihkan output buffer jika ada, agar header unduhan tidak bentrok.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' . $namaFile . '"'
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM UTF-8 agar karakter terbaca dengan baik di Excel.
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    if ($output === false) {
        exit;
    }

    fputcsv($output, [
        'ID Planning',
        'Tanggal Produksi',
        'Shift',
        'Kode Line',
        'Nama Line',
        'Kode Mesin',
        'Nama Mesin',
        'Kode Part',
        'Nama Part',
        'Material',
        'Warna',
        'Total OK (pcs)',
        'Total NG (pcs)',
        'Total Produksi (pcs)',
        'Berat Runner (gram)',
        'Berat NG (gram)',
        'Kategori NG'
    ], ';');

    foreach ($dataRekap as $row) {
        fputcsv($output, [
            $row['planning_id'],
            $row['tanggal_produksi'],
            $row['shift'],
            $row['kode_line'],
            $row['nama_line'],
            $row['kode_mesin'],
            $row['nama_mesin'],
            $row['kode_part'],
            $row['nama_part'],
            $row['material'],
            $row['warna'],
            $row['total_ok'],
            $row['total_ng'],
            $row['total_produksi'],
            number_format(
                (float) $row['berat_runner_total'],
                2,
                '.',
                ''
            ),
            number_format(
                (float) $row['berat_ng_total'],
                2,
                '.',
                ''
            ),
            $row['kategori_ng']
        ], ';');
    }

    fclose($output);
    exit;
}

/* =====================================================
   FLASH / JUDUL
===================================================== */

$namaShift = [
    '1' => 'Shift 1',
    '2' => 'Shift 2',
    '3' => 'Shift 3'
];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Rekap NG - Leader</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f4f6f9;
            color: #263238;
        }

        .page-header {
            background: linear-gradient(135deg, #172554, #1d4ed8);
            color: #fff;
            border-radius: 18px;
            padding: 25px;
            margin-bottom: 22px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .10);
        }

        .card-custom {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, .05);
        }

        .summary-card {
            height: 100%;
            padding: 19px;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, .05);
        }

        .summary-icon {
            width: 43px;
            height: 43px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 21px;
            margin-bottom: 13px;
        }

        .summary-label {
            font-size: .84rem;
            color: #64748b;
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .table thead th {
            background: #eef2ff;
            color: #334155;
            font-size: .78rem;
            white-space: nowrap;
            vertical-align: middle;
        }

        .table tbody td {
            font-size: .85rem;
            vertical-align: middle;
        }

        .table tfoot th {
            background: #f1f5f9;
            white-space: nowrap;
            font-size: .85rem;
        }

        .category-text {
            min-width: 180px;
            max-width: 300px;
            white-space: normal;
        }

        .filter-label {
            font-size: .85rem;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .empty-state {
            padding: 45px 15px;
            text-align: center;
            color: #64748b;
        }

        .empty-state i {
            font-size: 42px;
            color: #94a3b8;
        }

        @media (max-width: 767px) {
            .page-header {
                padding: 20px;
            }

            .page-header .header-actions {
                width: 100%;
            }

            .page-header .header-actions a {
                flex: 1;
            }

            .summary-value {
                font-size: 1.25rem;
            }
        }

        @media print {
            .no-print,
            .page-header .header-actions,
            form {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .card-custom,
            .summary-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .page-header {
                background: #fff !important;
                color: #000 !important;
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .table-responsive {
                overflow: visible !important;
            }

            .table {
                font-size: 10px;
            }
        }

        #daftarRekap {
            scroll-margin-top: 20px;
        }

        #daftarRekap:target {
            animation: highlightRekap 2.5s ease-out;
        }

        @keyframes highlightRekap {
            0% {
                box-shadow: 0 0 0 4px rgba(13, 110, 253, .55);
                background-color: #eaf2ff;
            }

            100% {
                box-shadow: 0 0 0 0 transparent;
                background-color: #fff;
            }
        }
    </style>
</head>

<body>

<div class="container-fluid px-3 px-lg-4 py-4">

    <!-- HEADER -->
    <div class="page-header">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">

            <div>
                <div class="small opacity-75 mb-1">
                    LEADER / LAPORAN PRODUKSI
                </div>

                <h3 class="fw-bold mb-2">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Rekap NG
                </h3>

                <div class="opacity-75">
                    Rekap jumlah NG dan kategori NG berdasarkan masing-masing planning selesai.
                </div>
            </div>

            <div class="header-actions d-flex flex-wrap gap-2">
                <a href="index.php" class="btn btn-light">
                    <i class="bi bi-arrow-left me-1"></i>
                    Kembali
                </a>

                <button
                    type="button"
                    onclick="window.print()"
                    class="btn btn-outline-light"
                >
                    <i class="bi bi-printer me-1"></i>
                    Cetak
                </button>
            </div>

        </div>
    </div>

    <!-- FILTER -->
    <div class="card card-custom mb-4 no-print">
        <div class="card-body p-3 p-lg-4">

            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-funnel text-primary fs-5"></i>
                <h5 class="mb-0 fw-bold">Filter Rekap</h5>
            </div>

            <form method="GET" action="rekap_ng.php">
                <div class="row g-3">

                    <div class="col-12 col-md-6 col-xl-3">
                        <label for="tanggal" class="filter-label">
                            Tanggal Produksi
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            id="tanggal"
                            name="tanggal"
                            value="<?= h($tanggal) ?>"
                            required
                        >
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label for="shift" class="filter-label">
                            Shift
                        </label>

                        <select
                            class="form-select"
                            id="shift"
                            name="shift"
                        >
                            <option value="">Semua Shift</option>

                            <?php foreach ($namaShift as $key => $nama): ?>
                                <option
                                    value="<?= h($key) ?>"
                                    <?= $shift === $key ? 'selected' : '' ?>
                                >
                                    <?= h($nama) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="filter-label">
                            Line Produksi
                        </label>

                        <div class="border rounded-3 bg-white p-3">
                            <!-- <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-secondary">
                                    Pilih satu atau beberapa line
                                </small> -->

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary mb-2"
                                    id="pilihSemuaLine"
                                >
                                    Pilih Semua
                                </button>
                            <!-- </div> -->

                            <div class="d-flex flex-column gap-2" id="daftarChecklistLine">
                                <?php foreach ($daftarLine as $line): ?>
                                    <div class="form-check">
                                        <input
                                            class="form-check-input line-checkbox"
                                            type="checkbox"
                                            name="line_id[]"
                                            value="<?= (int) $line['id'] ?>"
                                            id="line_<?= (int) $line['id'] ?>"
                                            <?= in_array(
                                                (int) $line['id'],
                                                $lineIds,
                                                true
                                            ) ? 'checked' : '' ?>
                                        >

                                        <label
                                            class="form-check-label"
                                            for="line_<?= (int) $line['id'] ?>"
                                        >
                                            <strong><?= h($line['kode_line']) ?></strong>
                                            - <?= h($line['nama_line']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- <div class="small text-secondary mt-2">
                                Jika tidak ada line yang dicentang, semua line akan ditampilkan.
                            </div> -->
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label for="mesin_id" class="filter-label">
                            Mesin
                        </label>

                        <select
                            class="form-select"
                            id="mesin_id"
                            name="mesin_id"
                        >
                            <option value="">Semua Mesin</option>

                            <?php foreach ($daftarMesin as $mesin): ?>
                                <option
                                    value="<?= (int) $mesin['id'] ?>"
                                    data-line="<?= (int) $mesin['line_id'] ?>"
                                    <?= $mesinId !== ''
                                        && (int) $mesinId === (int) $mesin['id']
                                            ? 'selected'
                                            : '' ?>
                                >
                                    <?= h($mesin['kode_mesin']) ?>
                                    - <?= h($mesin['nama_mesin']) ?>
                                    (<?= h($mesin['nama_line']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            id="btnTampilkanRekap"
                            formaction="rekap_ng.php#daftarRekap"
                        >
                            <i class="bi bi-search me-1"></i>
                            Tampilkan Rekap
                        </button>

                        <a
                            href="rekap_ng.php"
                            class="btn btn-outline-secondary"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            Reset
                        </a>
                    </div>

                </div>
            </form>

        </div>
    </div>

    <!-- RINGKASAN -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div
                    class="summary-icon"
                    style="background:#dbeafe;color:#1d4ed8;"
                >
                    <i class="bi bi-clipboard-data"></i>
                </div>

                <div class="summary-label">Planning Selesai</div>
                <div class="summary-value">
                    <?= angka($totalPlanning) ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div
                    class="summary-icon"
                    style="background:#dcfce7;color:#15803d;"
                >
                    <i class="bi bi-check-circle"></i>
                </div>

                <div class="summary-label">Total OK</div>
                <div class="summary-value">
                    <?= angka($totalOK) ?>
                    <small class="fs-6 fw-normal">pcs</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div
                    class="summary-icon"
                    style="background:#fee2e2;color:#b91c1c;"
                >
                    <i class="bi bi-exclamation-triangle"></i>
                </div>

                <div class="summary-label">Total NG</div>
                <div class="summary-value text-danger">
                    <?= angka($totalNG) ?>
                    <small class="fs-6 fw-normal">pcs</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div
                    class="summary-icon"
                    style="background:#fef3c7;color:#92400e;"
                >
                    <i class="bi bi-box-seam"></i>
                </div>

                <div class="summary-label">Berat NG</div>
                <div class="summary-value">
                    <?= berat($totalBeratNG) ?>
                    <small class="fs-6 fw-normal">gram</small>
                </div>
            </div>
        </div>

    </div>

    <!-- TABEL REKAP -->
    <div class="card card-custom" id="daftarRekap">
        <div class="card-body p-3 p-lg-4">

            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">

                <div>
                    <h5 class="fw-bold mb-1">
                        <i class="bi bi-table me-2 text-primary"></i>
                        Daftar Rekap NG per Planning
                    </h5>

                    <div class="text-secondary small">
                        Tanggal:
                        <strong><?= h($tanggal) ?></strong>

                        <?php if ($shift !== ''): ?>
                            &middot;
                            <?= h($namaShift[$shift] ?? $shift) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <span class="badge text-bg-primary fs-6">
                    <?= angka($totalPlanning) ?> planning
                </span>

            </div>

            <?php if (empty($dataRekap)): ?>

                <div class="empty-state">
                    <i class="bi bi-inbox d-block mb-3"></i>

                    <h5 class="fw-semibold">
                        Belum ada rekap NG
                    </h5>

                    <p class="mb-0">
                        Tidak ditemukan planning berstatus SELESAI
                        sesuai filter yang dipilih.
                    </p>
                </div>

            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">

                        <thead>
                            <tr>
                                <th class="text-center">No.</th>
                                <th>ID Planning</th>
                                <th>Tanggal</th>
                                <th>Shift</th>
                                <th>Kode Line</th>
                                <th>Kode Mesin</th>
                                <th>Nama Part</th>
                                <th>Material</th>
                                <th>Warna</th>
                                <th class="text-end">Total Produksi</th>
                                <th class="text-end">Berat Runner (gram)</th>
                                <th class="text-end">Jumlah NG (pcs)</th>
                                <th class="text-end">Berat NG (gram)</th>
                                <th>Keterangan NG</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($dataRekap as $index => $row): ?>

                                <tr>
                                    <td class="text-center">
                                        <?= $index + 1 ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-secondary">
                                            #<?= (int) $row['planning_id'] ?>
                                        </span>
                                    </td>

                                    <td class="text-nowrap">
                                        <?= h($row['tanggal_produksi']) ?>
                                    </td>

                                    <td>
                                        <span class="badge text-bg-info">
                                            Shift <?= h($row['shift']) ?>
                                        </span>
                                    </td>

                                    <td class="text-nowrap">
                                        <div class="fw-semibold">
                                            <?= h($row['kode_line']) ?>
                                        </div>
                                        <small class="text-secondary">
                                            <?= h($row['nama_line']) ?>
                                        </small>
                                    </td>

                                    <td class="text-nowrap">
                                        <div class="fw-semibold">
                                            <?= h($row['kode_mesin']) ?>
                                        </div>
                                        <small class="text-secondary">
                                            <?= h($row['nama_mesin']) ?>
                                        </small>
                                    </td>

                                    <td>
                                        <div class="fw-semibold">
                                            <?= h($row['nama_part']) ?>
                                        </div>
                                        <?php if (!empty($row['kode_part'])): ?>
                                            <small class="text-secondary">
                                                <?= h($row['kode_part']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= h($row['material'] ?: '-') ?>
                                    </td>

                                    <td>
                                        <?= h($row['warna'] ?: '-') ?>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        <?= angka($row['total_produksi']) ?>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        <?= berat($row['berat_runner_total']) ?>
                                    </td>

                                    <td class="text-end">
                                        <span class="badge text-bg-danger fs-6">
                                            <?= angka($row['total_ng']) ?>
                                        </span>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        <?= berat($row['berat_ng_total']) ?>
                                    </td>

                                    <td class="category-text">
                                        <?php if (
                                            $row['kategori_ng'] !== '-' &&
                                            $row['total_ng'] > 0
                                        ): ?>
                                            <span class="badge text-bg-warning text-dark mb-1">
                                                NG
                                            </span>
                                            <div>
                                                <?= h($row['kategori_ng']) ?>
                                            </div>
                                        <?php elseif ($row['kategori_ng'] !== '-'): ?>
                                            <?= h($row['kategori_ng']) ?>
                                            <div class="small text-secondary">
                                                Total NG planning: 0 pcs
                                            </div>
                                        <?php else: ?>
                                            <span class="text-secondary">
                                                Tidak ada kategori NG
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>

                        <tfoot>
                            <tr>
                                <th colspan="9" class="text-end">
                                    TOTAL FILTER
                                </th>

                                <th class="text-end">
                                    <?= angka($totalProduksi) ?>
                                </th>

                                <th class="text-end">
                                    <?= berat($totalBeratRunner) ?>
                                </th>

                                <th class="text-end text-danger">
                                    <?= angka($totalNG) ?>
                                </th>

                                <th class="text-end">
                                    <?= berat($totalBeratNG) ?>
                                </th>

                                <th>-</th>
                            </tr>
                        </tfoot>

                    </table>
                </div>
               
                <!-- Tombol Export Excel di bawah tabel rekap -->
                <div class="d-flex justify-content-end mt-3">
                    <form method="GET" action="rekap_ng.php">
                        <input type="hidden" name="tanggal"
                            value="<?= htmlspecialchars($tanggal ?? '', ENT_QUOTES, 'UTF-8') ?>">

                        <input type="hidden" name="shift"
                            value="<?= htmlspecialchars($shift ?? '', ENT_QUOTES, 'UTF-8') ?>">

                        <input type="hidden" name="mesin_id"
                            value="<?= htmlspecialchars($mesinId ?? '', ENT_QUOTES, 'UTF-8') ?>">

                        <?php foreach (($lineIds ?? []) as $lineId): ?>
                            <input type="hidden" name="line_id[]"
                                value="<?= (int) $lineId ?>">
                        <?php endforeach; ?>

                        <button type="submit" name="export" value="csv"
                                class="btn btn-success">
                            <i class="bi bi-file-earmark-excel me-1"></i>
                            Export Excel
                        </button>
                    </form>
                </div>

                </div>

                <!-- <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>

                    Setiap baris mewakili satu planning. Total NG dihitung
                    dari seluruh laporan per jam pada planning tersebut.
                    Kategori NG merupakan daftar kategori yang dipilih
                    operator pada laporan-laporan per jam tersebut.

                    Jumlah pcs untuk setiap kategori belum dapat dipisahkan
                    karena database saat ini belum menyimpan jumlah NG
                    per kategori.
                </div> -->

            <?php endif; ?>

        </div>
    </div>

    <div class="text-center text-secondary small mt-4">
        Rekap NG &middot; Sistem Informasi Produksi
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const lineCheckboxes = Array.from(
        document.querySelectorAll('.line-checkbox')
    );

    const mesinSelect = document.getElementById('mesin_id');
    const tombolPilihSemua = document.getElementById('pilihSemuaLine');

    if (!mesinSelect) {
        return;
    }

    const semuaOpsiMesin = Array.from(mesinSelect.options).map(function (opsi) {
        return {
            value: opsi.value,
            text: opsi.text,
            lineId: opsi.dataset.line || ''
        };
    });

    function filterMesin() {
        const lineTerpilih = lineCheckboxes
            .filter(function (checkbox) {
                return checkbox.checked;
            })
            .map(function (checkbox) {
                return checkbox.value;
            });

        const mesinSebelumnya = mesinSelect.value;

        mesinSelect.innerHTML = '';

        semuaOpsiMesin.forEach(function (data) {
            // Opsi Semua Mesin selalu tersedia.
            if (data.value !== '') {
                // Jika ada line yang dipilih, tampilkan mesin
                // yang termasuk dalam salah satu line tersebut.
                if (
                    lineTerpilih.length > 0 &&
                    !lineTerpilih.includes(data.lineId)
                ) {
                    return;
                }
            }

            const opsi = document.createElement('option');
            opsi.value = data.value;
            opsi.textContent = data.text;

            if (data.lineId) {
                opsi.dataset.line = data.lineId;
            }

            mesinSelect.appendChild(opsi);
        });

        const pilihanMasihAda = Array.from(
            mesinSelect.options
        ).some(function (opsi) {
            return opsi.value === mesinSebelumnya;
        });

        mesinSelect.value = pilihanMasihAda ? mesinSebelumnya : '';
    }

    lineCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', filterMesin);
    });

    if (tombolPilihSemua) {
        tombolPilihSemua.addEventListener('click', function () {
            const semuaSudahDipilih =
                lineCheckboxes.length > 0 &&
                lineCheckboxes.every(function (checkbox) {
                    return checkbox.checked;
                });

            lineCheckboxes.forEach(function (checkbox) {
                checkbox.checked = !semuaSudahDipilih;
            });

            filterMesin();

            tombolPilihSemua.textContent = semuaSudahDipilih
                ? 'Pilih Semua'
                : 'Batal Pilih Semua';
        });
    }

    filterMesin();
});
</script>

</body>
</html>