<?php
// Path: C:\xampp\htdocs\laporan_produksi\rekap\index.php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../config/koneksi.php';

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'leader' && ($_SESSION['role'] ?? '') !== 'admin' && ($_SESSION['role'] ?? '') !== 'supervisor'
) {
    header("Location: ../index.php");
    exit;
}

$role = strtolower((string)($_SESSION['role'] ?? ''));
$allowedRoles = ['admin', 'leader', 'supervisor'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Akses ditolak. Modul rekap hanya untuk Admin, Leader, dan Supervisor.');
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function validDateOrEmpty(string $value): bool {
    if ($value === '') return true;
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

$tanggalMulai = trim((string)($_GET['tanggal_mulai'] ?? date('Y-m-01')));
$tanggalSelesai = trim((string)($_GET['tanggal_selesai'] ?? date('Y-m-d')));
$shiftFilter = trim((string)($_GET['shift'] ?? ''));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
$lineRawValues = $_GET['line_ids'] ?? [];
if (!is_array($lineRawValues)) {
    $lineRawValues = [$lineRawValues];
}
$lineFilters = [];
$lineFilterInvalid = false;
foreach ($lineRawValues as $lineRaw) {
    $lineRaw = trim((string)$lineRaw);
    if ($lineRaw === '') continue;
    $lineId = filter_var($lineRaw, FILTER_VALIDATE_INT);
    if ($lineId === false || $lineId < 1) {
        $lineFilterInvalid = true;
        continue;
    }
    $lineFilters[] = (int)$lineId;
}
$lineRaw  = trim((string)($_GET['line_id'] ?? ''));
$mesinRaw = trim((string)($_GET['mesin_id'] ?? ''));
$partRaw  = trim((string)($_GET['part_id'] ?? ''));

// Nilai kosong berarti "Semua"
$lineFilter  = ($lineRaw === '')  ? null : filter_var($lineRaw, FILTER_VALIDATE_INT);
$mesinFilter = ($mesinRaw === '') ? null : filter_var($mesinRaw, FILTER_VALIDATE_INT);
$partFilter  = ($partRaw === '')  ? null : filter_var($partRaw, FILTER_VALIDATE_INT);


$export = (string)($_GET['export'] ?? '') === 'excel';

$errors = [];

if (!validDateOrEmpty($tanggalMulai) || !validDateOrEmpty($tanggalSelesai)) {
    $errors[] = 'Format tanggal tidak valid.';
}

if (
    $tanggalMulai !== '' &&
    $tanggalSelesai !== '' &&
    $tanggalMulai > $tanggalSelesai
) {
    $errors[] = 'Tanggal mulai tidak boleh melewati tanggal selesai.';
}

if (
    $shiftFilter !== '' &&
    !in_array($shiftFilter, ['1', '2', '3'], true)
) {
    $errors[] = 'Pilihan shift tidak valid.';
}

$allowedStatuses = ['TERJADWAL', 'BERJALAN', 'SELESAI', 'BATAL'];

if (
    $statusFilter !== '' &&
    !in_array($statusFilter, $allowedStatuses, true)
) {
    $errors[] = 'Filter status tidak valid.';
}

if ($lineFilterInvalid) {
    $errors[] = 'Filter Line tidak valid.';
}

foreach (['Mesin' => $mesinFilter, 'Part' => $partFilter] as $label => $value) {
    if (
        $value === false ||
        ($value !== null && $value !== '' && (int)$value < 1)
    ) {
        $errors[] = "Filter {$label} tidak valid.";
    }
}

/*
|--------------------------------------------------------------------------
| DROPDOWN FILTER
|--------------------------------------------------------------------------
*/

$lines = [];

$res = $conn->query("
    SELECT id, nama_line
    FROM master_line
    WHERE status = 'AKTIF'
    ORDER BY nama_line
");

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $lines[] = $r;
    }
    $res->free();
}

$mesins = [];

$res = $conn->query("
    SELECT id, nama_mesin, line_id
    FROM master_mesin
    WHERE status = 'AKTIF'
    ORDER BY nama_mesin
");

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $mesins[] = $r;
    }
    $res->free();
}

$parts = [];

$res = $conn->query("
    SELECT id, kode_part, nama_part
    FROM master_part
    WHERE status = 'AKTIF'
    ORDER BY kode_part
");

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $parts[] = $r;
    }
    $res->free();
}

/*
|--------------------------------------------------------------------------
| DATA REKAP DAN SUMMARY
|--------------------------------------------------------------------------
*/

$rows = [];

$summary = [
    'planning' => 0,
    'ok' => 0,
    'ng' => 0,
    'downtime' => 0,
    'target' => 0,
    'effective_runtime' => 0
];

if (!$errors) {

    $sql = "
        SELECT
            p.id AS planning_id,
            p.tanggal_produksi,
            p.shift,
            p.urutan,
            p.waktu_mulai,
            p.waktu_selesai,
            p.mulai_aktual,
            p.selesai_aktual,
            p.status AS status_planning,
            p.target_planning,
            p.target_per_jam_snapshot,
            p.no_jo_injection,
            p.no_jo_second_proses,
            p.nama_ipqc,
            p.nama_operator_second_proses,

            COALESCE(NULLIF(u.nama, ''), '-') AS nama_operator_utama,

            l.nama_line,
            m.nama_mesin,
            pt.kode_part,
            pt.nama_part,

            COALESCE(lap.total_ok, 0) AS total_ok,
            COALESCE(lap.total_ng, 0) AS total_ng,
            COALESCE(dt.total_downtime, 0) AS total_downtime,

            COALESCE(kng.kategori_ng, '-') AS kategori_ng

        FROM planning_produksi p

        INNER JOIN master_mesin m
            ON m.id = p.mesin_id

        INNER JOIN master_line l
            ON l.id = m.line_id

        INNER JOIN master_part pt
            ON pt.id = p.part_id

        LEFT JOIN users u
            ON u.id = p.dimulai_oleh

        /*
         * Total OK dan NG dihitung terpisah supaya tidak
         * berlipat akibat satu laporan memiliki banyak kategori NG.
         */
        LEFT JOIN (
            SELECT
                planning_id,
                SUM(jumlah_ok) AS total_ok,
                SUM(jumlah_ng) AS total_ng
            FROM laporan_produksi_jam
            GROUP BY planning_id
        ) lap
            ON lap.planning_id = p.id

        /*
         * Total downtime juga dihitung secara terpisah.
         */
        LEFT JOIN (
            SELECT
                lpj.planning_id,
                SUM(COALESCE(d.durasi_menit, 0)) AS total_downtime
            FROM laporan_produksi_jam lpj
            INNER JOIN downtime d
                ON d.laporan_id = lpj.id
            GROUP BY lpj.planning_id
        ) dt
            ON dt.planning_id = p.id

        /*
         * Semua kategori NG untuk satu planning digabung
         * menjadi satu teks, dipisahkan dengan koma.
         */
        LEFT JOIN (
            SELECT
                lpj.planning_id,
                GROUP_CONCAT(
                    DISTINCT mng.nama_kategori
                    ORDER BY mng.nama_kategori ASC
                    SEPARATOR ', '
                ) AS kategori_ng
            FROM laporan_produksi_jam lpj
            INNER JOIN laporan_ng_kategori lng
                ON lng.laporan_id = lpj.id
            INNER JOIN master_kategori_ng mng
                ON mng.id = lng.kategori_ng_id
            GROUP BY lpj.planning_id
        ) kng
            ON kng.planning_id = p.id

        WHERE 1 = 1
    ";

    $types = '';
    $params = [];

    /*
    |--------------------------------------------------------------------------
    | FILTER TANGGAL
    |--------------------------------------------------------------------------
    */

    if ($tanggalMulai !== '') {
        $sql .= ' AND p.tanggal_produksi >= ?';
        $types .= 's';
        $params[] = $tanggalMulai;
    }

    if ($tanggalSelesai !== '') {
        $sql .= ' AND p.tanggal_produksi <= ?';
        $types .= 's';
        $params[] = $tanggalSelesai;
    }

    /*
    |--------------------------------------------------------------------------
    | FILTER SHIFT
    |--------------------------------------------------------------------------
    */

    if ($shiftFilter !== '') {
        $sql .= ' AND p.shift = ?';
        $types .= 'i';
        $params[] = (int)$shiftFilter;
    }

    /*
    |--------------------------------------------------------------------------
    | FILTER STATUS
    |--------------------------------------------------------------------------
    */

    if ($statusFilter !== '') {
        $sql .= ' AND p.status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }

    /*
    |--------------------------------------------------------------------------
    | FILTER LINE
    |--------------------------------------------------------------------------
    */

    if ($lineFilters) {
        $placeholders = implode(
            ',',
            array_fill(0, count($lineFilters), '?')
        );

        $sql .= " AND m.line_id IN ($placeholders)";

        $types .= str_repeat('i', count($lineFilters));

        foreach ($lineFilters as $lineId) {
            $params[] = (int)$lineId;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FILTER MESIN
    |--------------------------------------------------------------------------
    */

    if (
        $mesinFilter !== false &&
        $mesinFilter !== null &&
        $mesinFilter !== ''
    ) {
        $sql .= ' AND p.mesin_id = ?';
        $types .= 'i';
        $params[] = (int)$mesinFilter;
    }

    /*
    |--------------------------------------------------------------------------
    | FILTER PART
    |--------------------------------------------------------------------------
    */

    if (
        $partFilter !== false &&
        $partFilter !== null &&
        $partFilter !== ''
    ) {
        $sql .= ' AND p.part_id = ?';
        $types .= 'i';
        $params[] = (int)$partFilter;
    }

    /*
    |--------------------------------------------------------------------------
    | URUTAN DATA
    |--------------------------------------------------------------------------
    */

    $sql .= "
        ORDER BY
            p.tanggal_produksi DESC,
            p.shift ASC,
            l.nama_line ASC,
            m.nama_mesin ASC,
            p.urutan ASC,
            p.waktu_mulai ASC
    ";

    /*
    |--------------------------------------------------------------------------
    | EKSEKUSI QUERY
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        $errors[] = 'Gagal menyiapkan query rekap: ' . $conn->error;

    } else {

        if ($types !== '') {
            $bindArgs = [$types];

            foreach ($params as $k => $v) {
                $bindArgs[] = &$params[$k];
            }

            call_user_func_array(
                [$stmt, 'bind_param'],
                $bindArgs
            );
        }

        if (!$stmt->execute()) {

            $errors[] = 'Gagal mengambil data rekap: ' . $stmt->error;

        } else {

            $result = $stmt->get_result();

            
            while ($r = $result->fetch_assoc()) {

                $totalOK = (int)$r['total_ok'];
                $targetPlanning = (int)($r['target_planning'] ?? 0);
                $totalDowntime = (int)$r['total_downtime'];

                // Persentase pencapaian target berdasarkan jumlah OK.
                $r['persentase_target'] = $targetPlanning > 0
                    ? ($totalOK / $targetPlanning) * 100
                    : null;

                // Effective Run Time dihitung dari waktu aktual.
                $r['effective_runtime_menit'] = null;

                $mulaiAktual = !empty($r['mulai_aktual'])
                    ? strtotime($r['mulai_aktual'])
                    : false;

                $selesaiAktual = !empty($r['selesai_aktual'])
                    ? strtotime($r['selesai_aktual'])
                    : false;

                if (
                    $mulaiAktual !== false &&
                    $selesaiAktual !== false &&
                    $selesaiAktual >= $mulaiAktual
                ) {
                    $durasiAktual = (int)floor(
                        ($selesaiAktual - $mulaiAktual) / 60
                    );

                    $r['effective_runtime_menit'] = max(
                        0,
                        $durasiAktual - $totalDowntime
                    );
                }

                $rows[] = $r;

                $summary['planning']++;
                $summary['ok'] += $totalOK;
                $summary['ng'] += (int)$r['total_ng'];
                $summary['downtime'] += $totalDowntime;
                $summary['target'] += $targetPlanning;

                if ($r['effective_runtime_menit'] !== null) {
                    $summary['effective_runtime'] +=
                        $r['effective_runtime_menit'];
                }
            }


            $result->free();
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| EXPORT EXCEL
|--------------------------------------------------------------------------
*/

if ($export && !$errors) {

    $filename = 'rekap-produksi-' . date('Ymd-His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    header('Cache-Control: max-age=0');

    // BOM agar karakter UTF-8 terbaca dengan baik oleh Excel.
    echo "\xEF\xBB\xBF";

    echo '<table border="1">';

    echo '<tr>
        <th colspan="21">REKAP LAPORAN PRODUKSI</th>
    </tr>';

    echo '<tr>
        <th colspan="21">Periode: ' .
        h($tanggalMulai ?: '-') .
        ' s.d. ' .
        h($tanggalSelesai ?: '-') .
    '</th></tr>';

    
echo '<tr>
    <th>No</th>
    <th>Tanggal Produksi</th>
    <th>Shift</th>
    <th>Line</th>
    <th>Mesin</th>
    <th>Urutan</th>
    <th>Jam Rencana Mulai</th>
    <th>Jam Rencana Selesai</th>
    <th>Kode Part</th>
    <th>Nama Part</th>
    <th>JO Injection</th>
    <th>JO Second Proses</th>
    <th>Operator Utama</th>
    <th>Operator SP</th>
    <th>QC / IPQC</th>
    <th>Kategori NG</th>
    <th>Status</th>
    <th>Target Planning</th>
    <th>Total OK</th>
    <th>Pencapaian Target (%)</th>
    <th>Total NG</th>
    <th>Downtime (menit)</th>
    <th>Effective Run Time</th>
</tr>';

    foreach ($rows as $i => $r) {

        echo '<tr>';

        echo '<td>' . ($i + 1) . '</td>';

        echo '<td>' . h($r['tanggal_produksi']) . '</td>';

        echo '<td>' . h($r['shift']) . '</td>';

        echo '<td>' . h($r['nama_line']) . '</td>';

        echo '<td>' . h($r['nama_mesin']) . '</td>';

        echo '<td>' . h($r['urutan']) . '</td>';

        echo '<td>' . h($r['waktu_mulai']) . '</td>';

        echo '<td>' . h($r['waktu_selesai']) . '</td>';

        echo '<td>' . h($r['kode_part']) . '</td>';

        echo '<td>' . h($r['nama_part']) . '</td>';

        // Paksa nomor JO diperlakukan sebagai teks di Excel.
        echo '<td style="mso-number-format:\'\@\';">' .
            h($r['no_jo_injection'] ?? '-') .
        '</td>';

        echo '<td style="mso-number-format:\'\@\';">' .
            h($r['no_jo_second_proses'] ?? '-') .
        '</td>';

        echo '<td>' .
            h($r['nama_operator_utama'] ?: '-') .
        '</td>';

        echo '<td>' .
            h($r['nama_operator_second_proses'] ?: '-') .
        '</td>';

        echo '<td>' .
            h($r['nama_ipqc'] ?: '-') .
        '</td>';

        // Kategori NG hasil pilihan checkbox operator.
        echo '<td>' .
            h($r['kategori_ng'] ?: '-') .
        '</td>';
 
        echo '<td>' . h($r['status_planning']) . '</td>';

        echo '<td>' . (int)($r['target_planning'] ?? 0) . '</td>';

        echo '<td>' . (int)$r['total_ok'] . '</td>';

        echo '<td>' .
            ($r['persentase_target'] !== null
                ? number_format($r['persentase_target'], 2) . '%'
                : '-') .
        '</td>';

        echo '<td>' . (int)$r['total_ng'] . '</td>';

        echo '<td>' . (int)$r['total_downtime'] . '</td>';

        $runtimeMenit = $r['effective_runtime_menit'];

        $runtimeText = $runtimeMenit !== null
            ? intdiv($runtimeMenit, 60) . ' jam ' .
                ($runtimeMenit % 60) . ' menit'
            : '-';

        echo '<td>' . h($runtimeText) . '</td>';

        echo '</tr>';

    }

    /*
     * Kolom kategori NG tidak dijumlahkan karena berisi teks.
     * Total tetap sesuai dengan 21 kolom Excel.
     */
    
        $persentaseTotalExcel = $summary['target'] > 0
            ? ($summary['ok'] / $summary['target']) * 100
            : null;

        $runtimeTotalExcel = (int)$summary['effective_runtime'];

        $runtimeTotalTextExcel =
            intdiv($runtimeTotalExcel, 60) . ' jam ' .
            ($runtimeTotalExcel % 60) . ' menit';

        echo '<tr>
            <th colspan="17">TOTAL</th>
            <th>' . (int)$summary['target'] . '</th>
            <th>' . (int)$summary['ok'] . '</th>
            <th>' .
                ($persentaseTotalExcel !== null
                    ? number_format($persentaseTotalExcel, 2) . '%'
                    : '-') .
            '</th>
            <th>' . (int)$summary['ng'] . '</th>
            <th>' . (int)$summary['downtime'] . '</th>
            <th>' . h($runtimeTotalTextExcel) . '</th>
        </tr>';

        echo '</table>';
        exit;

}


$pageTitle = 'Rekap Laporan Produksi';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#f5f7fb;color:#202938}.page-wrap{max-width:1500px;margin:auto;padding:22px 14px 40px}.panel{background:#fff;border:1px solid #e7ebf1;border-radius:16px;box-shadow:0 5px 20px rgba(25,42,70,.04)}.summary-card{height:100%;padding:18px}.summary-label{font-size:.85rem;color:#687386}.summary-value{font-size:1.65rem;font-weight:750;margin-top:4px}.table thead th{font-size:.78rem;white-space:nowrap;background:#f1f4f8;color:#445064}.table td{font-size:.86rem;vertical-align:middle;white-space:nowrap}.filter-label{font-size:.83rem;font-weight:600;margin-bottom:6px}.floating-alert{position:fixed;top:16px;right:16px;z-index:1090;max-width:min(500px,calc(100vw - 32px));box-shadow:0 8px 28px #0002}.table-responsive{border-radius:12px}#daftarRekap{scroll-margin-top:18px}#daftarRekap:target{animation:highlightRekap 2.2s ease}@keyframes highlightRekap{0%,100%{box-shadow:0 5px 20px rgba(25,42,70,.04);border-color:#e7ebf1}20%,65%{box-shadow:0 0 0 4px rgba(13,110,253,.18);border-color:#0d6efd}}@media(max-width:767px){.page-wrap{padding:14px 10px 28px}.summary-value{font-size:1.35rem}.header-actions{width:100%}.header-actions .btn{flex:1}}
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <div class="text-secondary small mb-1"><i class="bi bi-bar-chart-line me-1"></i> Laporan Produksi</div>
            <h1 class="h3 fw-bold mb-1">Rekap Laporan Produksi</h1>
            <div class="text-secondary">Ringkasan hasil OK, NG, target planning, dan downtime berdasarkan filter.</div>
        </div>
        <div class="d-flex flex-wrap gap-2 header-actions">
            <a href="../dashboard/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
            <!-- <button type="submit" form="filterForm" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Terapkan Filter</button> -->
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger floating-alert" role="alert"><strong>Terjadi kesalahan</strong><ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="panel p-3 p-lg-4 mb-3">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-sliders me-2"></i>Filter Rekap</h2>
        <form id="filterForm" action="index.php#daftarRekap" method="get" class="row g-3 align-items-end">
            <div class="col-6 col-md-4 col-xl-2"><label class="filter-label" for="tanggal_mulai">Tanggal Mulai</label><input class="form-control" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= h($tanggalMulai) ?>"></div>
            <div class="col-6 col-md-4 col-xl-2"><label class="filter-label" for="tanggal_selesai">Tanggal Selesai</label><input class="form-control" type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= h($tanggalSelesai) ?>"></div>
            <div class="col-6 col-md-4 col-xl-1"><label class="filter-label" for="shift">Shift</label><select class="form-select" id="shift" name="shift"><option value="">Semua</option><?php foreach ([1,2,3] as $s): ?><option value="<?= $s ?>" <?= $shiftFilter === (string)$s ? 'selected' : '' ?>>Shift <?= $s ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-4 col-xl-2"><label class="filter-label" for="status">Status Planning</label><select class="form-select" id="status" name="status"><option value="">Semua status</option><?php foreach (['TERJADWAL', 'BERJALAN', 'SELESAI', 'BATAL'] as $st): ?><option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h($st) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6 col-xl-2">
                <div class="filter-label">Line <span class="text-secondary fw-normal">(pilih satu atau lebih)</span></div>
                <div class="border rounded-3 p-2 bg-white line-checkbox-list" style="max-height:160px;overflow-y:auto">
                    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="line_all"><label class="form-check-label fw-semibold" for="line_all">Semua line</label></div>
                    <hr class="my-2">
                    <?php foreach ($lines as $line): ?>
                        <div class="form-check"><input class="form-check-input line-checkbox" type="checkbox" id="line_<?= (int)$line['id'] ?>" name="line_ids[]" value="<?= (int)$line['id'] ?>" data-line-id="<?= (int)$line['id'] ?>" <?= in_array((int)$line['id'], $lineFilters, true) ? 'checked' : '' ?>><label class="form-check-label" for="line_<?= (int)$line['id'] ?>"><?= h($line['nama_line']) ?></label></div>
                    <?php endforeach; ?>
                    <!-- <div class="small text-secondary mt-2">Jika tidak ada line yang dicentang, semua line ditampilkan.</div> -->
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2"><label class="filter-label" for="mesin_id">Mesin</label><select class="form-select" id="mesin_id" name="mesin_id"><option value="">Semua mesin</option><?php foreach ($mesins as $mesin): ?><option value="<?= (int)$mesin['id'] ?>" data-line="<?= (int)$mesin['line_id'] ?>" <?= (string)$mesinFilter === (string)$mesin['id'] ? 'selected' : '' ?>><?= h($mesin['nama_mesin']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-4 col-xl-2"><label class="filter-label" for="part_id">Part</label><select class="form-select" id="part_id" name="part_id"><option value="">Semua part</option><?php foreach ($parts as $part): ?><option value="<?= (int)$part['id'] ?>" <?= (string)$partFilter === (string)$part['id'] ? 'selected' : '' ?>><?= h($part['kode_part'] . ' - ' . $part['nama_part']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-xl-1 d-grid"><a class="btn btn-outline-secondary" href="index.php" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i> Reset</a></div>
            <div class="col-12 col-xl-2 d-grid">
            <button type="submit" form="filterForm" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Terapkan Filter</button>
            </div>
        </form>
        <!-- <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
            <div class="small text-secondary">Data berdasarkan planning produksi dan total laporan per jam.</div>
            <a class="btn btn-success" href="<?= h('index.php?' . http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>"><i class="bi bi-file-earmark-excel me-1"></i> Export Excel</a>
        </div> -->
    </section>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg"><div class="panel summary-card"><div class="summary-label"><i class="bi bi-calendar2-check me-1"></i> Jumlah Planning</div><div class="summary-value"><?= number_format($summary['planning']) ?></div></div></div>
        <div class="col-6 col-lg"><div class="panel summary-card"><div class="summary-label"><i class="bi bi-check-circle me-1 text-success"></i> Total OK</div><div class="summary-value text-success"><?= number_format($summary['ok']) ?></div></div></div>
        <div class="col-6 col-lg"><div class="panel summary-card"><div class="summary-label"><i class="bi bi-x-circle me-1 text-danger"></i> Total NG</div><div class="summary-value text-danger"><?= number_format($summary['ng']) ?></div></div></div>
        <div class="col-6 col-lg"><div class="panel summary-card"><div class="summary-label"><i class="bi bi-bullseye me-1 text-primary"></i> Target Planning</div><div class="summary-value text-primary"><?= number_format($summary['target']) ?></div></div></div>
        <div class="col-12 col-lg"><div class="panel summary-card"><div class="summary-label"><i class="bi bi-stopwatch me-1 text-warning"></i> Downtime</div><div class="summary-value"><?= number_format($summary['downtime']) ?> <span class="fs-6 fw-semibold">menit</span></div></div></div>
    </div>

    
<section id="daftarRekap" class="panel p-3 p-lg-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="h5 fw-bold mb-1">Daftar Rekap</h2>
            <div class="text-secondary small">
                Ditemukan <?= number_format(count($rows)) ?> planning.
            </div>
        </div>

        <span class="badge text-bg-light border">
            Periode <?= h($tanggalMulai ?: 'Semua') ?>
            — <?= h($tanggalSelesai ?: 'Semua') ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-bordered align-middle mb-0">

            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Shift</th>
                    <th>Line</th>
                    <th>Mesin</th>
                    <th>Urutan</th>
                    <th>Jam Rencana</th>
                    <th>Part</th>
                    <th>JO Injection</th>
                    <th>JO Second Proses</th>
                    <th>Operator INJ</th>
                    <th>Operator SP</th>
                    <th>QC / IPQC</th>
                    <th>Status</th>
                    <th class="text-end">Target</th>
                    <th class="text-end">OK</th>
                    <th class="text-end">Pencapaian Target</th>
                    <th class="text-end">NG</th>
                    <th class="text-end">Downtime (menit)</th>
                    <th>Effective Run Time</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$rows): ?>

                <tr>
                    <td colspan="20" class="text-center text-secondary py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        Tidak ada data untuk filter yang dipilih.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($rows as $i => $r): ?>
                    <?php
                    $persentaseTarget = $r['persentase_target'];
                    $runtimeMenit = $r['effective_runtime_menit'];

                    $runtimeText = '-';

                    if ($runtimeMenit !== null) {
                        $runtimeText =
                            intdiv($runtimeMenit, 60) . 'j ' .
                            ($runtimeMenit % 60) . 'm';
                    }

                    $statusClass = [
                        'SELESAI' => 'success',
                        'BERJALAN' => 'primary',
                        'TERJADWAL' => 'secondary',
                        'BATAL' => 'danger'
                    ];
                    ?>

                    <tr>
                        <td><?= $i + 1 ?></td>

                        <td>
                            <?= h(date(
                                'd-m-Y',
                                strtotime($r['tanggal_produksi'])
                            )) ?>
                        </td>

                        <td>
                            <span class="badge text-bg-light border">
                                Shift <?= h($r['shift']) ?>
                            </span>
                        </td>

                        <td><?= h($r['nama_line']) ?></td>
                        <td><?= h($r['nama_mesin']) ?></td>
                        <td><?= h($r['urutan']) ?></td>

                        <td>
                            <?= h(date(
                                'H:i',
                                strtotime((string)$r['waktu_mulai'])
                            )) ?>
                            –
                            <?= h(date(
                                'H:i',
                                strtotime((string)$r['waktu_selesai'])
                            )) ?>
                        </td>

                        <td>
                            <div class="fw-semibold">
                                <?= h($r['kode_part']) ?>
                            </div>
                            <div class="small text-secondary">
                                <?= h($r['nama_part']) ?>
                            </div>
                        </td>

                        <td><?= h($r['no_jo_injection'] ?: '-') ?></td>
                        <td><?= h($r['no_jo_second_proses'] ?: '-') ?></td>
                        <td><?= h($r['nama_operator_utama'] ?: '-') ?></td>
                        <td><?= h($r['nama_operator_second_proses'] ?: '-') ?></td>
                        <td><?= h($r['nama_ipqc'] ?: '-') ?></td>

                        <td>
                            <span class="badge text-bg-<?= $statusClass[$r['status_planning']] ?? 'secondary' ?>">
                                <?= h($r['status_planning']) ?>
                            </span>
                        </td>

                        <td class="text-end">
                            <?= number_format((int)($r['target_planning'] ?? 0)) ?>
                        </td>

                        <td class="text-end fw-semibold text-success">
                            <?= number_format((int)$r['total_ok']) ?>
                        </td>

                        <td class="text-end">
                            <?php if ($persentaseTarget !== null): ?>
                                <span class="badge <?= $persentaseTarget >= 100 ? 'text-bg-success' : 'text-bg-warning' ?>">
                                    <?= number_format($persentaseTarget, 2) ?>%
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td class="text-end fw-semibold text-danger">
                            <?= number_format((int)$r['total_ng']) ?>
                        </td>

                        <td class="text-end">
                            <?= number_format((int)$r['total_downtime']) ?>
                        </td>

                        <td class="text-nowrap">
                            <?= h($runtimeText) ?>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>
            </tbody>

            <?php if ($rows): ?>
                <?php
                $persentaseTotal = $summary['target'] > 0
                    ? ($summary['ok'] / $summary['target']) * 100
                    : null;

                $runtimeTotalMenit = (int)$summary['effective_runtime'];

                $runtimeTotalText =
                    intdiv($runtimeTotalMenit, 60) . 'j ' .
                    ($runtimeTotalMenit % 60) . 'm';
                ?>

                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="14" class="text-end">
                            TOTAL
                        </td>

                        <td class="text-end">
                            <?= number_format($summary['target']) ?>
                        </td>

                        <td class="text-end text-success">
                            <?= number_format($summary['ok']) ?>
                        </td>

                        <td class="text-end">
                            <?= $persentaseTotal !== null
                                ? number_format($persentaseTotal, 2) . '%'
                                : '-' ?>
                        </td>

                        <td class="text-end text-danger">
                            <?= number_format($summary['ng']) ?>
                        </td>

                        <td class="text-end">
                            <?= number_format($summary['downtime']) ?>
                        </td>

                        <td class="text-nowrap">
                            <?= h($runtimeTotalText) ?>
                        </td>
                    </tr>
                </tfoot>
            <?php endif; ?>

        </table>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
        <div class="small text-secondary">
            Data berdasarkan planning produksi dan total laporan per jam.
        </div>

        <a class="btn btn-success"
           href="<?= h('index.php?' . http_build_query(
               array_merge($_GET, ['export' => 'excel'])
           )) ?>">
            <i class="bi bi-file-earmark-excel me-1"></i>
            Export Excel
        </a>
    </div>

</section>

    <div class="text-center text-secondary small mt-3">Sistem Laporan Produksi · <?= date('Y') ?></div>
</div>
<script>
(() => {
    const lineCheckboxes = [...document.querySelectorAll('.line-checkbox')];
    const lineAll = document.getElementById('line_all');
    const mesinSelect = document.getElementById('mesin_id');
    function selectedLines() {
        return lineCheckboxes.filter(cb => cb.checked).map(cb => cb.value);
    }
    function syncLineAll() {
        lineAll.checked = lineCheckboxes.length > 0 && lineCheckboxes.every(cb => cb.checked);
        lineAll.indeterminate = lineCheckboxes.some(cb => cb.checked) && !lineAll.checked;
    }
    function filterMesin() {
        const selected = selectedLines();
        const selectedMachine = mesinSelect.value;
        [...mesinSelect.options].forEach((option, index) => {
            if (index === 0) return;
            const matches = selected.length === 0 || selected.includes(option.dataset.line);
            option.hidden = !matches;
            option.disabled = !matches;
        });
        if (selectedMachine && mesinSelect.selectedOptions[0]?.disabled) mesinSelect.value = '';
        syncLineAll();
    }
    lineAll.addEventListener('change', () => {
        lineCheckboxes.forEach(cb => cb.checked = lineAll.checked);
        filterMesin();
    });
    lineCheckboxes.forEach(cb => cb.addEventListener('change', filterMesin));
    filterMesin();
    document.querySelectorAll('.floating-alert').forEach(alert => setTimeout(() => {
        alert.style.transition = 'opacity .35s ease, transform .35s ease';
        alert.style.opacity = '0'; alert.style.transform = 'translateY(-8px)';
        setTimeout(() => alert.remove(), 400);
    }, 3000));
})();
</script>
</body>
</html>
