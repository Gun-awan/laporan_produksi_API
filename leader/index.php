<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/koneksi.php';

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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectLeader(array $params = []): void
{
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    $url .= '#daftarPlanning';
    header('Location: ' . $url);
    exit;
}

function flashLeader(string $type, string $message): void
{
    $_SESSION['flash_leader'] = ['type' => $type, 'message' => $message];
}

// Proses koreksi laporan per jam oleh Leader.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'koreksi_laporan') {
    $params = [
        'tanggal' => (string)($_POST['filter_tanggal'] ?? date('Y-m-d')),
        'shift' => (string)($_POST['filter_shift'] ?? ''),
        'line_id' => (string)($_POST['filter_line_id'] ?? ''),
        'mesin_id' => (string)($_POST['filter_mesin_id'] ?? ''),
        'status' => (string)($_POST['filter_status'] ?? ''),
    ];

    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new Exception('Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        }

        $laporanId = filter_var($_POST['laporan_id'] ?? null, FILTER_VALIDATE_INT);
        $jumlahOk = filter_var($_POST['jumlah_ok'] ?? null, FILTER_VALIDATE_INT);
        $jumlahNg = filter_var($_POST['jumlah_ng'] ?? null, FILTER_VALIDATE_INT);
        $keterangan = trim((string)($_POST['keterangan'] ?? ''));

        if (!$laporanId || $laporanId < 1) {
            throw new Exception('ID laporan tidak valid.');
        }
        if ($jumlahOk === false || $jumlahNg === false || $jumlahOk < 0 || $jumlahNg < 0) {
            throw new Exception('Jumlah OK dan NG harus berupa bilangan bulat nol atau lebih.');
        }
        if (mb_strlen($keterangan) > 1000) {
            throw new Exception('Keterangan maksimal 1.000 karakter.');
        }

        $conn->begin_transaction();

        // Kunci laporan dan planning agar tidak terkoreksi bersamaan oleh dua proses.
        $stmt = $conn->prepare("
            SELECT r.id, r.planning_id, r.slot_jam, r.jumlah_ok, r.jumlah_ng,
                   r.keterangan, p.status AS status_planning, p.counter_awal
            FROM laporan_produksi_jam r
            INNER JOIN planning_produksi p ON p.id = r.planning_id
            WHERE r.id = ?
            FOR UPDATE
        ");
        $stmt->bind_param('i', $laporanId);
        $stmt->execute();
        $laporanLama = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$laporanLama) {
            throw new Exception('Laporan tidak ditemukan.');
        }
        if ($laporanLama['status_planning'] === 'BATAL') {
            throw new Exception('Laporan dari planning yang dibatalkan tidak dapat dikoreksi.');
        }

        $planningId = (int)$laporanLama['planning_id'];
        $sebelum = [
            'jumlah_ok' => (int)$laporanLama['jumlah_ok'],
            'jumlah_ng' => (int)$laporanLama['jumlah_ng'],
            'keterangan' => (string)($laporanLama['keterangan'] ?? ''),
        ];
        $sesudah = [
            'jumlah_ok' => $jumlahOk,
            'jumlah_ng' => $jumlahNg,
            'keterangan' => $keterangan,
        ];

        $stmt = $conn->prepare("
            UPDATE laporan_produksi_jam
            SET jumlah_ok = ?, jumlah_ng = ?, keterangan = ?
            WHERE id = ?
        ");
        $stmt->bind_param('iisi', $jumlahOk, $jumlahNg, $keterangan, $laporanId);
        $stmt->execute();
        $stmt->close();

        // Perbarui counter akhir planning jika counter awal sudah diisi.
        if ($laporanLama['counter_awal'] !== null) {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(jumlah_ok), 0) AS total_ok,
                       COALESCE(SUM(jumlah_ng), 0) AS total_ng
                FROM laporan_produksi_jam
                WHERE planning_id = ?
            ");
            $stmt->bind_param('i', $planningId);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $counterAkhir = (int)$laporanLama['counter_awal']
                + (int)$total['total_ok'] + (int)$total['total_ng'];
            $stmt = $conn->prepare("UPDATE planning_produksi SET counter_akhir = ? WHERE id = ?");
            $stmt->bind_param('ii', $counterAkhir, $planningId);
            $stmt->execute();
            $stmt->close();
        }

        // Tabel audit diperlukan agar nilai sebelum/sesudah dan pelaku koreksi tercatat.
        $aksiAudit = 'KOREKSI_LAPORAN_JAM';
        $entitas = 'laporan_produksi_jam';
        $sebelumJson = json_encode($sebelum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sesudahJson = json_encode($sesudah, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $stmt = $conn->prepare("
            INSERT INTO audit_koreksi_laporan
                (user_id, aksi, entitas, entitas_id, data_sebelum, data_sesudah, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('ississss', $leaderId, $aksiAudit, $entitas, $laporanId, $sebelumJson, $sesudahJson, $ip, $userAgent);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        flashLeader('success', 'Koreksi laporan berhasil disimpan dan tercatat pada audit.');
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Koreksi laporan oleh leader: ' . $ex->getMessage());
        if ($ex instanceof mysqli_sql_exception && str_contains(strtolower($ex->getMessage()), 'audit_koreksi_laporan')) {
            flashLeader('danger', 'Tabel audit belum tersedia. Jalankan SQL pembuatan tabel audit yang disertakan sebelum melakukan koreksi.');
        } else {
            flashLeader('danger', $ex instanceof mysqli_sql_exception ? 'Koreksi gagal disimpan. Periksa struktur database dan log PHP.' : $ex->getMessage());
        }
    }
    redirectLeader($params);
}

$flash = $_SESSION['flash_leader'] ?? null;
unset($_SESSION['flash_leader']);

$tanggal = (string)($_GET['tanggal'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$shift = (string)($_GET['shift'] ?? '');
$lineId = filter_var($_GET['line_id'] ?? '', FILTER_VALIDATE_INT) ?: '';
$mesinId = filter_var($_GET['mesin_id'] ?? '', FILTER_VALIDATE_INT) ?: '';
$status = (string)($_GET['status'] ?? '');

$lineList = $conn->query("SELECT id, kode_line, nama_line FROM master_line WHERE status = 'AKTIF' ORDER BY nama_line");
$mesinList = $conn->query("SELECT id, kode_mesin, nama_mesin, line_id FROM master_mesin WHERE status = 'AKTIF' ORDER BY nama_mesin");

$where = ["p.tanggal_produksi = ?"];
$types = 's';
$values = [$tanggal];
if (in_array($shift, ['1', '2', '3'], true)) {
    $where[] = 'p.shift = ?'; $types .= 'i'; $values[] = (int)$shift;
} else { $shift = ''; }
if ($lineId !== '') {
    $where[] = 'm.line_id = ?'; $types .= 'i'; $values[] = (int)$lineId;
}
if ($mesinId !== '') {
    $where[] = 'p.mesin_id = ?'; $types .= 'i'; $values[] = (int)$mesinId;
}
if (in_array($status, ['TERJADWAL', 'BERJALAN', 'SELESAI', 'BATAL'], true)) {
    $where[] = 'p.status = ?'; $types .= 's'; $values[] = $status;
} else { $status = ''; }

$sql = "
    SELECT p.id AS planning_id, p.tanggal_produksi, p.shift, p.urutan,
           p.waktu_mulai, p.waktu_selesai, p.status, p.target_planning,
           p.target_per_jam_snapshot, p.counter_awal, p.counter_akhir,
           p.no_jo_injection, p.nama_ipqc,
           m.id AS mesin_id, m.kode_mesin, m.nama_mesin,
           l.id AS line_id, l.kode_line, l.nama_line,
           pt.kode_part, pt.nama_part,
           op.nama AS nama_operator,
           COALESCE(SUM(r.jumlah_ok), 0) AS total_ok,
           COALESCE(SUM(r.jumlah_ng), 0) AS total_ng,
           COUNT(DISTINCT r.id) AS jumlah_laporan,
           COALESCE(SUM(dt.durasi_menit), 0) AS total_downtime_menit
    FROM planning_produksi p
    INNER JOIN master_mesin m ON m.id = p.mesin_id
    INNER JOIN master_line l ON l.id = m.line_id
    INNER JOIN master_part pt ON pt.id = p.part_id
    LEFT JOIN users op ON op.id = p.dimulai_oleh
    LEFT JOIN laporan_produksi_jam r ON r.planning_id = p.id
    LEFT JOIN (
        SELECT r2.planning_id, d.id, d.durasi_menit
        FROM downtime d
        INNER JOIN laporan_produksi_jam r2 ON r2.id = d.laporan_id
    ) dt ON dt.planning_id = p.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY p.id
    ORDER BY l.nama_line, m.nama_mesin, p.shift, p.urutan, p.waktu_mulai
";

// Hindari penggandaan total OK/NG karena join downtime: hitung downtime melalui subquery terpisah.
$sql = "
    SELECT p.id AS planning_id, p.tanggal_produksi, p.shift, p.urutan,
           p.waktu_mulai, p.waktu_selesai, p.status, p.target_planning,
           p.target_per_jam_snapshot, p.counter_awal, p.counter_akhir,
           p.no_jo_injection, p.nama_ipqc,
           m.id AS mesin_id, m.kode_mesin, m.nama_mesin,
           l.id AS line_id, l.kode_line, l.nama_line,
           pt.kode_part, pt.nama_part,
           op.nama AS nama_operator,
           COALESCE(rep.total_ok, 0) AS total_ok,
           COALESCE(rep.total_ng, 0) AS total_ng,
           COALESCE(rep.jumlah_laporan, 0) AS jumlah_laporan,
           COALESCE(dt.total_downtime_menit, 0) AS total_downtime_menit
    FROM planning_produksi p
    INNER JOIN master_mesin m ON m.id = p.mesin_id
    INNER JOIN master_line l ON l.id = m.line_id
    INNER JOIN master_part pt ON pt.id = p.part_id
    LEFT JOIN users op ON op.id = p.dimulai_oleh
    LEFT JOIN (
        SELECT planning_id, SUM(jumlah_ok) total_ok, SUM(jumlah_ng) total_ng, COUNT(*) jumlah_laporan
        FROM laporan_produksi_jam GROUP BY planning_id
    ) rep ON rep.planning_id = p.id
    LEFT JOIN (
        SELECT r.planning_id, SUM(d.durasi_menit) total_downtime_menit
        FROM downtime d INNER JOIN laporan_produksi_jam r ON r.id = d.laporan_id
        GROUP BY r.planning_id
    ) dt ON dt.planning_id = p.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY l.nama_line, m.nama_mesin, p.shift, p.urutan, p.waktu_mulai
";
$stmt = $conn->prepare($sql);
$bindArgs = [$types];
foreach ($values as $key => $value) { $bindArgs[] = &$values[$key]; }
call_user_func_array([$stmt, 'bind_param'], $bindArgs);
$stmt->execute();
$planningResult = $stmt->get_result();
$planningRows = $planningResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Laporan per jam ditampilkan hanya saat Leader membuka detail planning.
$detailId = filter_var($_GET['detail'] ?? null, FILTER_VALIDATE_INT);
$detailPlanning = null;
$detailReports = [];
if ($detailId && $detailId > 0) {
    $stmt = $conn->prepare("
        SELECT p.id, p.tanggal_produksi, p.shift, p.status, p.counter_awal, p.counter_akhir,
               pt.kode_part, pt.nama_part, m.nama_mesin, l.nama_line
        FROM planning_produksi p
        INNER JOIN master_part pt ON pt.id = p.part_id
        INNER JOIN master_mesin m ON m.id = p.mesin_id
        INNER JOIN master_line l ON l.id = m.line_id
        WHERE p.id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $detailId);
    $stmt->execute();
    $detailPlanning = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($detailPlanning) {
        $stmt = $conn->prepare("
            SELECT r.id, r.slot_jam, r.waktu_mulai, r.waktu_selesai, r.jumlah_ok, r.jumlah_ng,
                   r.keterangan, u.nama AS nama_operator,
                   COALESCE(dt.total_menit, 0) AS downtime_menit,
                   COALESCE(dt.detail_downtime, '') AS detail_downtime
            FROM laporan_produksi_jam r
            LEFT JOIN users u ON u.id = r.operator_id
            LEFT JOIN (
                SELECT laporan_id, SUM(durasi_menit) total_menit,
                       GROUP_CONCAT(CONCAT(penyebab, ' (', durasi_menit, ' menit)') SEPARATOR '; ') detail_downtime
                FROM downtime GROUP BY laporan_id
            ) dt ON dt.laporan_id = r.id
            WHERE r.planning_id = ?
            ORDER BY r.slot_jam
        ");
        $stmt->bind_param('i', $detailId);
        $stmt->execute();
        $detailReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$jumlahPlanning = count($planningRows);
$totalOkSemua = 0; $totalNgSemua = 0; $jumlahBerjalan = 0; $downtimeSemua = 0;
foreach ($planningRows as $row) {
    $totalOkSemua += (int)$row['total_ok'];
    $totalNgSemua += (int)$row['total_ng'];
    $downtimeSemua += (int)$row['total_downtime_menit'];
    if ($row['status'] === 'BERJALAN') $jumlahBerjalan++;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitoring Produksi - Leader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#f5f7fb;color:#243047}.page-wrap{max-width:1600px;margin:auto;padding:24px}.hero{background:linear-gradient(120deg,#123c69,#1d6a96);color:#fff;border-radius:18px;padding:24px}.metric{border:0;border-radius:15px;box-shadow:0 4px 18px #2430470b}.metric .metric-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:#eaf2ff;color:#175cd3;font-size:1.2rem}.table thead th{white-space:nowrap;background:#f1f5f9;font-size:.82rem}.table td{vertical-align:middle}.status-badge{font-size:.75rem}.filter-card,.section-card{border:0;border-radius:16px;box-shadow:0 4px 18px #2430470b}.table-responsive{border-radius:12px}.flash-alert{position:fixed;right:20px;top:20px;z-index:1090;max-width:min(520px,calc(100vw - 40px));box-shadow:0 8px 30px #0002}.highlight-target{animation:highlightPulse 2s ease}@keyframes highlightPulse{0%,100%{box-shadow:0 0 0 0 #0d6efd00}25%,70%{box-shadow:0 0 0 5px #0d6efd35;background:#f0f7ff}}.small-muted{font-size:.82rem;color:#6b7280}.edit-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px}
    </style>
</head>
<body>
<div class="page-wrap">
    <header class="hero mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div><div class="text-white-50 small mb-1">SISTEM LAPORAN PRODUKSI</div><h1 class="h3 mb-1">Monitoring Produksi</h1><div class="text-white-50">Halo, <?= e($leaderNama) ?>. Pantau hasil produksi dan koreksi laporan per jam.</div></div>
        <div class="d-flex gap-2"><a class="btn btn-light" href="../dashboard/index.php"><i class="bi bi-house-door me-1"></i> Dashboard</a><a class="btn btn-outline-light" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-1"></i> Keluar</a></div>
    </header>

    <?php if ($flash): ?><div id="flashLeader" class="alert alert-<?= e($flash['type']) ?> flash-alert alert-dismissible fade show" role="alert"><i class="bi bi-info-circle me-2"></i><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div><?php endif; ?>

    <section class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="card metric h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small-muted">Planning ditampilkan</div><div class="fs-3 fw-bold"><?= $jumlahPlanning ?></div></div><div class="metric-icon"><i class="bi bi-calendar2-check"></i></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small-muted">Sedang berjalan</div><div class="fs-3 fw-bold"><?= $jumlahBerjalan ?></div></div><div class="metric-icon"><i class="bi bi-play-circle"></i></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small-muted">Total OK</div><div class="fs-3 fw-bold text-success"><?= number_format($totalOkSemua) ?></div></div><div class="metric-icon"><i class="bi bi-check-circle"></i></div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric h-100"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="small-muted">Total NG</div><div class="fs-3 fw-bold text-danger"><?= number_format($totalNgSemua) ?></div></div><div class="metric-icon"><i class="bi bi-exclamation-triangle"></i></div></div></div></div>
    </section>

    <section class="card filter-card mb-4"><div class="card-body p-4"><div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-funnel fs-5"></i><h2 class="h5 mb-0">Filter Monitoring</h2></div>
        <form method="get" class="row g-3" id="formFilter">
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label">Tanggal produksi</label><input type="date" name="tanggal" class="form-control" value="<?= e($tanggal) ?>" required></div>
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label">Shift</label><select name="shift" class="form-select"><option value="">Semua shift</option><?php foreach ([1,2,3] as $s): ?><option value="<?= $s ?>" <?= $shift === (string)$s ? 'selected' : '' ?>>Shift <?= $s ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label">Line</label><select name="line_id" id="filterLine" class="form-select"><option value="">Semua line</option><?php while ($line = $lineList->fetch_assoc()): ?><option value="<?= (int)$line['id'] ?>" <?= (string)$lineId === (string)$line['id'] ? 'selected' : '' ?>><?= e($line['kode_line'] . ' - ' . $line['nama_line']) ?></option><?php endwhile; ?></select></div>
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label">Mesin</label><select name="mesin_id" id="filterMesin" class="form-select"><option value="">Semua mesin</option><?php while ($mesin = $mesinList->fetch_assoc()): ?><option value="<?= (int)$mesin['id'] ?>" data-line="<?= (int)$mesin['line_id'] ?>" <?= (string)$mesinId === (string)$mesin['id'] ? 'selected' : '' ?>><?= e($mesin['kode_mesin'] . ' - ' . $mesin['nama_mesin']) ?></option><?php endwhile; ?></select></div>
            <div class="col-12 col-sm-6 col-lg-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Semua status</option><?php foreach (['TERJADWAL','BERJALAN','SELESAI','BATAL'] as $st): ?><option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-lg-2 d-flex align-items-end gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-search me-1"></i> Filter</button><a class="btn btn-outline-secondary" href="index.php" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a></div>
        </form>
    </div></section>

    <section class="card section-card mb-4" id="daftarPlanning"><div class="card-body p-3 p-lg-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">Daftar Planning</h2><div class="small-muted">Ringkasan laporan operator dan status produksi berdasarkan filter.</div></div><span class="badge text-bg-light border"><?= $jumlahPlanning ?> planning</span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Line / Mesin</th><th>Shift / Urutan</th><th>Part</th><th>Operator</th><th>Target planning</th><th>OK</th><th>NG</th><th>Downtime</th><th>Laporan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
        <?php if (!$planningRows): ?><tr><td colspan="11" class="text-center py-5 text-secondary"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada planning untuk filter ini.</td></tr><?php else: foreach ($planningRows as $row): $target = (float)($row['target_planning'] ?? 0); $pencapaian = $target > 0 ? ((int)$row['total_ok'] / $target) * 100 : 0; ?>
            <tr><td><div class="fw-semibold"><?= e($row['kode_line'] . ' - ' . $row['nama_line']) ?></div><div class="small-muted"><?= e($row['kode_mesin'] . ' - ' . $row['nama_mesin']) ?></div></td><td><div>Shift <?= (int)$row['shift'] ?></div><div class="small-muted">Urutan <?= (int)$row['urutan'] ?></div><div class="small-muted"><?= e(substr((string)$row['waktu_mulai'], 11, 5)) ?>–<?= e(substr((string)$row['waktu_selesai'], 11, 5)) ?></div></td><td><div class="fw-semibold"><?= e($row['kode_part']) ?></div><div class="small-muted"><?= e($row['nama_part']) ?></div><?php if (!empty($row['no_jo_injection'])): ?><div class="small-muted">JO: <?= e($row['no_jo_injection']) ?></div><?php endif; ?></td><td><?= e($row['nama_operator'] ?: 'Belum dimulai') ?></td><td><div class="fw-semibold"><?= number_format($target) ?></div><div class="progress mt-1" style="height:5px" title="Pencapaian OK terhadap target planning"><div class="progress-bar" style="width:<?= min(100, max(0, $pencapaian)) ?>%"></div></div><div class="small-muted"><?= number_format($pencapaian, 1) ?>% OK</div></td><td class="fw-semibold text-success"><?= number_format((int)$row['total_ok']) ?></td><td class="fw-semibold text-danger"><?= number_format((int)$row['total_ng']) ?></td><td><?= number_format((int)$row['total_downtime_menit']) ?> mnt</td><td><?= (int)$row['jumlah_laporan'] ?>/8 jam</td><td><?php $badge = ['TERJADWAL'=>'secondary','BERJALAN'=>'primary','SELESAI'=>'success','BATAL'=>'danger'][$row['status']] ?? 'secondary'; ?><span class="badge text-bg-<?= $badge ?> status-badge"><?= e($row['status']) ?></span></td><td><a class="btn btn-sm btn-outline-primary" href="?<?= e(http_build_query(['tanggal'=>$tanggal,'shift'=>$shift,'line_id'=>$lineId,'mesin_id'=>$mesinId,'status'=>$status,'detail'=>(int)$row['planning_id']])) ?>#detailLaporan"><i class="bi bi-eye me-1"></i>Detail</a></td></tr>
        <?php endforeach; endif; ?></tbody></table></div>
        <div class="small-muted mt-3">Downtime ditampilkan dalam menit. Pencapaian dihitung dari total OK dibanding target planning; angka ini merupakan indikator ringkas, bukan pengganti perhitungan target berdasarkan waktu efektif.</div>
    </div></section>

    <?php if ($detailId): ?><section class="card section-card mb-4" id="detailLaporan"><div class="card-body p-3 p-lg-4"><div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5 mb-1">Detail Laporan Per Jam</h2><?php if ($detailPlanning): ?><div class="small-muted"><?= e($detailPlanning['nama_line'] . ' / ' . $detailPlanning['nama_mesin'] . ' — ' . $detailPlanning['kode_part'] . ' ' . $detailPlanning['nama_part']) ?> · Shift <?= (int)$detailPlanning['shift'] ?> · <?= e($detailPlanning['status']) ?></div><?php endif; ?></div><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(['tanggal'=>$tanggal,'shift'=>$shift,'line_id'=>$lineId,'mesin_id'=>$mesinId,'status'=>$status])) ?>#daftarPlanning">Tutup detail</a></div>
        <?php if (!$detailPlanning): ?><div class="alert alert-warning">Planning tidak ditemukan.</div><?php elseif (!$detailReports): ?><div class="alert alert-info">Belum ada laporan per jam untuk planning ini.</div><?php else: ?>
            <?php foreach ($detailReports as $report): ?><div class="edit-card mb-3"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><div class="fw-bold">Jam <?= (int)$report['slot_jam'] ?> <span class="text-secondary fw-normal small"><?= e($report['waktu_mulai']) ?> – <?= e($report['waktu_selesai']) ?></span></div><div class="small-muted">Operator: <?= e($report['nama_operator'] ?: 'Tidak tersedia') ?> · Downtime: <?= (int)$report['downtime_menit'] ?> menit<?php if ($report['detail_downtime']): ?> · <?= e($report['detail_downtime']) ?><?php endif; ?></div></div></div>
                <form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="aksi" value="koreksi_laporan"><input type="hidden" name="laporan_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="filter_tanggal" value="<?= e($tanggal) ?>"><input type="hidden" name="filter_shift" value="<?= e($shift) ?>"><input type="hidden" name="filter_line_id" value="<?= e($lineId) ?>"><input type="hidden" name="filter_mesin_id" value="<?= e($mesinId) ?>"><input type="hidden" name="filter_status" value="<?= e($status) ?>">
                    <div class="col-6 col-md-2"><label class="form-label">Jumlah OK</label><input type="number" min="0" step="1" name="jumlah_ok" class="form-control" value="<?= (int)$report['jumlah_ok'] ?>" required></div>
                    <div class="col-6 col-md-2"><label class="form-label">Jumlah NG</label><input type="number" min="0" step="1" name="jumlah_ng" class="form-control" value="<?= (int)$report['jumlah_ng'] ?>" required></div>
                    <div class="col-12 col-md-6"><label class="form-label">Keterangan</label><input type="text" maxlength="1000" name="keterangan" class="form-control" value="<?= e($report['keterangan']) ?>" placeholder="Keterangan koreksi / kondisi produksi"></div>
                    <div class="col-12 col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-warning w-100" onclick="return confirm('Simpan koreksi untuk laporan jam ini? Perubahan akan dicatat dalam audit.')"><i class="bi bi-pencil-square me-1"></i>Koreksi</button></div>
                </form>
            </div><?php endforeach; ?>
            <div class="alert alert-info mb-0 small"><i class="bi bi-shield-check me-1"></i>Setiap koreksi menyimpan nilai sebelum/sesudah, akun Leader, waktu, alamat IP, dan user-agent di tabel audit.</div>
        <?php endif; ?></div></section><?php endif; ?>

    <footer class="text-center text-secondary small py-3">Sistem Laporan Produksi · Monitoring Leader</footer>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
 const flash=document.getElementById('flashLeader');
 if(flash){setTimeout(()=>{if(window.bootstrap){bootstrap.Alert.getOrCreateInstance(flash).close();}else{flash.remove();}},3000);}
 const line=document.getElementById('filterLine'); const mesin=document.getElementById('filterMesin');
 function filterMesin(){if(!line||!mesin)return;const val=line.value;Array.from(mesin.options).forEach((opt,i)=>{if(i===0){opt.hidden=false;return;}const ok=!val||opt.dataset.line===val;opt.hidden=!ok;if(!ok&&opt.selected)mesin.value='';});}
 if(line&&mesin){line.addEventListener('change',filterMesin);filterMesin();}
 const params=new URLSearchParams(location.search);
 if(params.has('detail')){const el=document.getElementById('detailLaporan');if(el){el.scrollIntoView({behavior:'smooth',block:'start'});el.classList.add('highlight-target');}}
 if(location.hash==='#daftarPlanning'){const el=document.getElementById('daftarPlanning');if(el){setTimeout(()=>{el.scrollIntoView({behavior:'smooth',block:'start'});el.classList.add('highlight-target');},100);}}
})();
</script>
</body>
</html>
