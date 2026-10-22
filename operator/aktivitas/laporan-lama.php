<?php
session_start();

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config/koneksi.php';

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'operator'
) {
    header('Location: ../../index.php');
    exit;
}

$operatorId = (int) $_SESSION['user_id'];
$planningId = filter_input(INPUT_GET, 'planning_id', FILTER_VALIDATE_INT);

if (!$planningId || $planningId < 1) {
    http_response_code(400);
    exit('ID planning tidak valid.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectLaporan(int $id, ?int $slot = null): void
{
    $url = 'laporan.php?planning_id=' . $id;
    if ($slot !== null) {
        $url .= '&slot=' . $slot;
    }

    header('Location: ' . $url);
    exit;
}

function ambilPlanning(mysqli $conn, int $id, int $operatorId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            p.*,
            m.kode_mesin,
            m.nama_mesin,
            l.kode_line,
            l.nama_line,
            mp.kode_part,
            mp.nama_part,
            mp.material,
            mp.warna
        FROM planning_produksi p
        INNER JOIN master_mesin m ON m.id = p.mesin_id
        INNER JOIN master_line l ON l.id = m.line_id
        INNER JOIN master_part mp ON mp.id = p.part_id
        WHERE p.id = ?
          AND p.dimulai_oleh = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $id, $operatorId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $data ?: null;
}

function ambilLaporanSlot(mysqli $conn, int $planningId, int $slot): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM laporan_produksi_jam
        WHERE planning_id = ? AND slot_jam = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $planningId, $slot);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $data ?: null;
}

function simpanLaporanDasar(
    mysqli $conn,
    int $planningId,
    int $slot,
    string $waktuMulai,
    string $waktuSelesai,
    int $operatorId
): int {
    $laporan = ambilLaporanSlot($conn, $planningId, $slot);

    if ($laporan) {
        return (int) $laporan['id'];
    }

    $stmt = $conn->prepare("
        INSERT INTO laporan_produksi_jam (
            planning_id,
            slot_jam,
            waktu_mulai,
            waktu_selesai,
            operator_id,
            jumlah_ok,
            jumlah_ng,
            keterangan
        ) VALUES (?, ?, ?, ?, ?, 0, 0, '')
    ");
    $stmt->bind_param(
        'iissi',
        $planningId,
        $slot,
        $waktuMulai,
        $waktuSelesai,
        $operatorId
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

$pesan = '';
$jenisPesan = 'success';

try {
    $planning = ambilPlanning($conn, $planningId, $operatorId);

    if (!$planning) {
        http_response_code(404);
        exit('Planning tidak ditemukan atau bukan milik operator ini.');
    }

    if (!in_array($planning['status'], ['BERJALAN', 'SELESAI'], true)) {
        exit('Planning belum dimulai atau sudah dibatalkan.');
    }

    /*
     * Form POST
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new Exception('Sesi formulir tidak valid. Muat ulang halaman.');
        }

        $aksi = $_POST['aksi'] ?? '';
        $slotPost = filter_var($_POST['slot_jam'] ?? '', FILTER_VALIDATE_INT);

        if (!$slotPost || $slotPost < 1 || $slotPost > 8) {
            throw new Exception('Slot jam tidak valid.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        $planStart = new DateTimeImmutable($planning['waktu_mulai'], new DateTimeZone('Asia/Jakarta'));
        $planEnd = new DateTimeImmutable($planning['waktu_selesai'], new DateTimeZone('Asia/Jakarta'));

        $slotStart = $planStart->modify('+' . ($slotPost - 1) . ' hour');
        $slotEnd = $slotStart->modify('+1 hour');

        if ($slotEnd > $planEnd) {
            $slotEnd = $planEnd;
        }

        if ($slotStart >= $planEnd || $slotStart > $now) {
            throw new Exception('Slot jam tersebut belum tersedia.');
        }

        $waktuMulaiSlot = $slotStart->format('Y-m-d H:i:s');
        $waktuSelesaiSlot = $slotEnd->format('Y-m-d H:i:s');

        
if ($aksi === 'simpan_identitas') {
    if ($planning['status'] !== 'BERJALAN') {
        throw new Exception('Identitas produksi hanya dapat diubah saat planning berjalan.');
    }

    $noJoInjection = trim($_POST['no_jo_injection'] ?? '');
    $noJoSecond = trim($_POST['no_jo_second_proses'] ?? '');
    $namaOperatorSecond = trim($_POST['nama_operator_second_proses'] ?? '');
    $namaIpqc = trim($_POST['nama_ipqc'] ?? '');

    $secondProses = (int)($planning['terdapat_second_proses_snapshot'] ?? 0) === 1;

    if ($noJoInjection === '') {
        throw new Exception('No. JO Injection wajib diisi.');
    }

    if ($secondProses) {
        if ($noJoSecond === '') {
            throw new Exception('No. JO Second Proses wajib diisi.');
        }

        if ($namaOperatorSecond === '') {
            throw new Exception('Nama operator second proses wajib diisi.');
        }
    } else {
        $noJoSecond = '';
        $namaOperatorSecond = '';
    }

    $stmt = $conn->prepare("
        UPDATE planning_produksi
        SET no_jo_injection = ?,
            no_jo_second_proses = ?,
            nama_operator_second_proses = ?,
            nama_ipqc = ?
        WHERE id = ?
          AND dimulai_oleh = ?
          AND status = 'BERJALAN'
    ");

    $stmt->bind_param(
        'ssssii',
        $noJoInjection,
        $noJoSecond,
        $namaOperatorSecond,
        $namaIpqc,
        $planningId,
        $operatorId
    );

    $stmt->execute();
    $stmt->close();

    $_SESSION['flash_laporan'] = [
        'type' => 'success',
        'message' => 'Identitas produksi berhasil disimpan.'
    ];

    redirectLaporan($planningId, $slotPost);
}


        

if ($aksi === 'update_laporan') {

    if ($planning['status'] !== 'BERJALAN') {
        throw new Exception(
            'Laporan tidak dapat diubah karena planning bukan BERJALAN.'
        );
    }

    // Validasi hasil produksi pada slot jam yang sedang diedit.
    $jumlahOk = filter_var(
        $_POST['jumlah_ok'] ?? null,
        FILTER_VALIDATE_INT
    );

    $jumlahNg = filter_var(
        $_POST['jumlah_ng'] ?? null,
        FILTER_VALIDATE_INT
    );

    $keterangan = trim($_POST['keterangan'] ?? '');

    if (
        $jumlahOk === false ||
        $jumlahNg === false ||
        $jumlahOk < 0 ||
        $jumlahNg < 0
    ) {
        throw new Exception(
            'Jumlah OK dan NG harus berupa bilangan bulat nol atau lebih.'
        );
    }

    $conn->begin_transaction();

    // Kunci baris planning agar counter awal tidak terisi
    // atau diubah secara bersamaan oleh dua proses.
    $stmt = $conn->prepare("
        SELECT counter_awal
        FROM planning_produksi
        WHERE id = ?
          AND dimulai_oleh = ?
          AND status = 'BERJALAN'
        FOR UPDATE
    ");

    $stmt->bind_param('ii', $planningId, $operatorId);
    $stmt->execute();

    $dataCounter = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dataCounter) {
        throw new Exception(
            'Planning tidak ditemukan atau bukan milik operator yang sedang login.'
        );
    }

    // Jika counter awal belum pernah diisi, ambil dari form.
    // Jika sudah ada, gunakan nilai yang tersimpan di database.
    if ($dataCounter['counter_awal'] === null) {

        $counterAwal = filter_var(
            $_POST['counter_awal'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($counterAwal === false || $counterAwal < 0) {
            throw new Exception(
                'Counter awal wajib berupa bilangan bulat nol atau lebih.'
            );
        }

        $stmt = $conn->prepare("
            UPDATE planning_produksi
            SET counter_awal = ?
            WHERE id = ?
              AND dimulai_oleh = ?
              AND status = 'BERJALAN'
              AND counter_awal IS NULL
        ");

        $stmt->bind_param(
            'iii',
            $counterAwal,
            $planningId,
            $operatorId
        );

        $stmt->execute();
        $stmt->close();

    } else {
        // Counter awal yang sudah tersimpan tidak boleh diganti.
        $counterAwal = (int) $dataCounter['counter_awal'];
    }

    // Ambil laporan untuk slot jam yang sedang diedit.
    $laporan = ambilLaporanSlot(
        $conn,
        $planningId,
        $slotPost
    );

    if ($laporan) {

        // Perbarui laporan jam yang sudah tersedia.
        $laporanId = (int) $laporan['id'];

        $stmt = $conn->prepare("
            UPDATE laporan_produksi_jam
            SET jumlah_ok = ?,
                jumlah_ng = ?,
                keterangan = ?
            WHERE id = ?
              AND planning_id = ?
        ");

        $stmt->bind_param(
            'iisii',
            $jumlahOk,
            $jumlahNg,
            $keterangan,
            $laporanId,
            $planningId
        );

    } else {

        // Buat laporan jam baru jika belum tersedia.
        $stmt = $conn->prepare("
            INSERT INTO laporan_produksi_jam (
                planning_id,
                slot_jam,
                waktu_mulai,
                waktu_selesai,
                operator_id,
                jumlah_ok,
                jumlah_ng,
                keterangan
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iissiiis',
            $planningId,
            $slotPost,
            $waktuMulaiSlot,
            $waktuSelesaiSlot,
            $operatorId,
            $jumlahOk,
            $jumlahNg,
            $keterangan
        );
    }

    $stmt->execute();
    $stmt->close();

    // Jumlahkan OK dan NG dari SEMUA slot pada planning ini.
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(jumlah_ok), 0) AS total_ok,
            COALESCE(SUM(jumlah_ng), 0) AS total_ng
        FROM laporan_produksi_jam
        WHERE planning_id = ?
    ");

    $stmt->bind_param('i', $planningId);
    $stmt->execute();

    $total = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalOkPlanning = (int) $total['total_ok'];
    $totalNgPlanning = (int) $total['total_ng'];

    // Counter akhir berlaku untuk seluruh planning.
    $counterAkhir = $counterAwal
        + $totalOkPlanning
        + $totalNgPlanning;

    $stmt = $conn->prepare("
        UPDATE planning_produksi
        SET counter_akhir = ?
        WHERE id = ?
          AND dimulai_oleh = ?
          AND status = 'BERJALAN'
    ");

    $stmt->bind_param(
        'iii',
        $counterAkhir,
        $planningId,
        $operatorId
    );

    $stmt->execute();
    $stmt->close();

    $conn->commit();

    $_SESSION['flash_laporan'] = [
        'type' => 'success',
        'message' => 'Laporan berhasil disimpan. Total OK, NG, dan counter planning telah diperbarui.'
    ];

    redirectLaporan($planningId, $slotPost);
}



        if ($aksi === 'stop_mesin') {
            if ($planning['status'] !== 'BERJALAN') {
                throw new Exception('Planning tidak sedang berjalan.');
            }

            $downtimeId = filter_var($_POST['downtime_id'] ?? '', FILTER_VALIDATE_INT);
            $ketDowntime = trim($_POST['keterangan_downtime'] ?? '');

            if (!$downtimeId || $downtimeId < 1) {
                throw new Exception('Pilih alasan downtime terlebih dahulu.');
            }

            $stmt = $conn->prepare("
                SELECT 
                md.id AS id, nama_downtime
                FROM master_downtime md
                WHERE md.id = ? AND md.status = 'AKTIF'
                LIMIT 1
            ");
            $stmt->bind_param('i', $downtimeId);
            $stmt->execute();
            $alasan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$alasan) {
                throw new Exception('Alasan downtime tidak ditemukan atau tidak aktif.');
            }

            $stmt = $conn->prepare("
                SELECT d.id
                FROM downtime d
                INNER JOIN laporan_produksi_jam r ON r.id = d.laporan_id
                WHERE r.planning_id = ?
                  AND d.waktu_selesai IS NULL
                LIMIT 1
            ");
            $stmt->bind_param('i', $planningId);
            $stmt->execute();
            $downtimeAktif = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($downtimeAktif) {
                throw new Exception('Mesin sudah dalam status downtime.');
            }

            $conn->begin_transaction();

            $laporanId = simpanLaporanDasar(
                $conn,
                $planningId,
                $slotPost,
                $waktuMulaiSlot,
                $waktuSelesaiSlot,
                $operatorId
            );

            $waktuStop = $now->format('Y-m-d H:i:s');
            $penyebab = $alasan['nama_downtime'];

            $stmt = $conn->prepare("
                INSERT INTO downtime (
                    laporan_id,
                    durasi_menit,
                    penyebab,
                    keterangan,
                    waktu_mulai,
                    waktu_selesai,
                    created_at
                ) VALUES (?, 0, ?, ?, ?, NULL, ?)
            ");
            $stmt->bind_param(
                'issss',
                $laporanId,
                $penyebab,
                $ketDowntime,
                $waktuStop,
                $waktuStop
            );
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $_SESSION['flash_laporan'] = [
                'type' => 'warning',
                'message' => 'Downtime dimulai pada ' . $waktuStop . '.'
            ];

            redirectLaporan($planningId, $slotPost);
        }

        if ($aksi === 'lanjut_produksi') {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT d.id, d.waktu_mulai
                FROM downtime d
                INNER JOIN laporan_produksi_jam r ON r.id = d.laporan_id
                WHERE r.planning_id = ?
                  AND d.waktu_selesai IS NULL
                ORDER BY d.id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('i', $planningId);
            $stmt->execute();
            $downtimeAktif = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$downtimeAktif) {
                throw new Exception('Tidak ada downtime yang sedang berjalan.');
            }

            $waktuSelesaiDowntime = $now->format('Y-m-d H:i:s');
            $mulaiDowntime = new DateTimeImmutable(
                $downtimeAktif['waktu_mulai'],
                new DateTimeZone('Asia/Jakarta')
            );
            $durasiMenit = max(
                0,
                (int) floor(($now->getTimestamp() - $mulaiDowntime->getTimestamp()) / 60)
            );
            $downtimeRowId = (int)$downtimeAktif['id'];

            $stmt = $conn->prepare("
                UPDATE downtime
                SET waktu_selesai = ?,
                    durasi_menit = ?
                WHERE id = ? AND waktu_selesai IS NULL
            ");
            $stmt->bind_param(
                'sii',
                $waktuSelesaiDowntime,
                $durasiMenit,
                $downtimeRowId
            );
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $_SESSION['flash_laporan'] = [
                'type' => 'success',
                'message' => 'Downtime selesai. Produksi dapat dilanjutkan.'
            ];

            redirectLaporan($planningId, $slotPost);
        }

        
if ($aksi === 'selesai_produksi') {
    if ($planning['status'] !== 'BERJALAN') {
        throw new Exception('Planning ini tidak sedang berjalan.');
    }

    // Produksi tidak boleh diakhiri saat downtime masih aktif.
    $stmt = $conn->prepare("
        SELECT d.id
        FROM downtime d
        INNER JOIN laporan_produksi_jam r
            ON r.id = d.laporan_id
        WHERE r.planning_id = ?
          AND d.waktu_selesai IS NULL
        LIMIT 1
    ");
    $stmt->bind_param('i', $planningId);
    $stmt->execute();
    $downtimeMasihAktif = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($downtimeMasihAktif) {
        throw new Exception(
            'Selesaikan downtime terlebih dahulu sebelum mengakhiri produksi.'
        );
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare("
        UPDATE planning_produksi
        SET status = 'SELESAI',
            selesai_aktual = NOW()
        WHERE id = ?
          AND dimulai_oleh = ?
          AND status = 'BERJALAN'
    ");
    $stmt->bind_param('ii', $planningId, $operatorId);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new Exception(
            'Planning gagal diselesaikan. Muat ulang halaman dan coba lagi.'
        );
    }

    $stmt->close();
    $conn->commit();

    $_SESSION['flash_laporan'] = [
        'type' => 'success',
        'message' => 'Produksi berhasil diselesaikan.'
    ];

    header('Location: ../index.php');
    exit;
}


        throw new Exception('Aksi tidak dikenal.');
    }

} catch (Throwable $ex) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    error_log(
    'Error laporan produksi: ' . $ex->getMessage()
);

$pesan = $ex->getMessage();
$jenisPesan = 'danger';

}

/*
 * Ambil ulang planning setelah proses POST atau error.
 */
if (!isset($planning) || !$planning) {
    $planning = ambilPlanning($conn, $planningId, $operatorId);
}

if (!$planning) {
    http_response_code(404);
    exit('Planning tidak ditemukan.');
}

/*
 * Hitung slot yang tersedia berdasarkan waktu server dan waktu planning.
 */
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$planStart = new DateTimeImmutable($planning['waktu_mulai'], new DateTimeZone('Asia/Jakarta'));
$planEnd = new DateTimeImmutable($planning['waktu_selesai'], new DateTimeZone('Asia/Jakarta'));

$durasiDetik = max(0, $planEnd->getTimestamp() - $planStart->getTimestamp());
$totalSlot = min(8, (int) ceil($durasiDetik / 3600));

if ($totalSlot < 1) {
    $totalSlot = 1;
}

$slotBerjalan = (int) floor(
    max(0, $now->getTimestamp() - $planStart->getTimestamp()) / 3600
) + 1;

$slotBerjalan = min($totalSlot, max(1, $slotBerjalan));

$slotDipilih = filter_input(INPUT_GET, 'slot', FILTER_VALIDATE_INT);
if (!$slotDipilih || $slotDipilih < 1 || $slotDipilih > $totalSlot) {
    $slotDipilih = $slotBerjalan;
}

$slotMulai = $planStart->modify('+' . ($slotDipilih - 1) . ' hour');
$slotSelesai = $slotMulai->modify('+1 hour');
if ($slotSelesai > $planEnd) {
    $slotSelesai = $planEnd;
}

$laporan = ambilLaporanSlot($conn, $planningId, $slotDipilih);
if (!$laporan) {
    $laporan = [
        'id' => null,
        'jumlah_ok' => 0,
        'jumlah_ng' => 0,
        'counter_awal' => 0,
        'counter_akhir' => 0,
        'keterangan' => ''
    ];
}

// Akumulasi OK dan NG seluruh slot dalam planning ini.
$stmtTotal = $conn->prepare("
    SELECT
        COALESCE(SUM(jumlah_ok), 0) AS total_ok,
        COALESCE(SUM(jumlah_ng), 0) AS total_ng
    FROM laporan_produksi_jam
    WHERE planning_id = ?
");
$stmtTotal->bind_param('i', $planningId);
$stmtTotal->execute();
$hasilTotal = $stmtTotal->get_result()->fetch_assoc();
$stmtTotal->close();

$totalOkPlanning = (int) $hasilTotal['total_ok'];
$totalNgPlanning = (int) $hasilTotal['total_ng'];
$totalProduksiPlanning = $totalOkPlanning + $totalNgPlanning;

$counterAwalPlanning = (int) ($planning['counter_awal'] ?? 0);
$counterAkhirPlanning = $counterAwalPlanning + $totalProduksiPlanning;

/*
 * Daftar downtime aktif untuk modal.
 */
$downtimeAktif = null;
$stmt = $conn->prepare("
    SELECT d.id, d.penyebab, d.waktu_mulai
    FROM downtime d
    INNER JOIN laporan_produksi_jam r ON r.id = d.laporan_id
    WHERE r.planning_id = ?
      AND d.waktu_selesai IS NULL
    ORDER BY d.id DESC
    LIMIT 1
");
$stmt->bind_param('i', $planningId);
$stmt->execute();
$downtimeAktif = $stmt->get_result()->fetch_assoc();
$stmt->close();

/*
 * Master alasan downtime.
 */
$daftarDowntime = [];
$resultDowntime = $conn->query("
    SELECT id, nama_downtime
    FROM master_downtime
    WHERE status = 'AKTIF'
    ORDER BY nama_downtime
");
if ($resultDowntime) {
    while ($r = $resultDowntime->fetch_assoc()) {
        $daftarDowntime[] = $r;
    }
}

if (!empty($_SESSION['flash_laporan'])) {
    $jenisPesan = $_SESSION['flash_laporan']['type'];
    $pesan = $_SESSION['flash_laporan']['message'];
    unset($_SESSION['flash_laporan']);
}

$secondProses = (int)($planning['terdapat_second_proses_snapshot'] ?? 0) === 1;
$bolehEdit = $planning['status'] === 'BERJALAN';

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Produksi | Operator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7fb; color: #263244; }
        .panel { background: #fff; border: 1px solid #e5eaf1; border-radius: 16px; }
        .stat { border: 1px solid #e5eaf1; border-radius: 14px; background: #fff; }
        .counter-value { font-size: 2rem; font-weight: 800; min-width: 72px; text-align: center; }
        .slot-link { min-width: 70px; }
        .form-label { font-weight: 600; }
    </style>
</head>
<body>
<div class="container-fluid px-3 px-lg-4 py-4">

    <div class="panel p-3 p-lg-4 mb-3">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
            <div>
                <div class="text-primary small mb-2">
                    <i class="bi bi-clipboard2-check me-1"></i> AKTIVITAS OPERATOR
                </div>
                <h3 class="fw-bold mb-1">Laporan Produksi</h3>
                <div class="text-secondary">
                    <?= e($planning['kode_part']) ?> — <?= e($planning['nama_part']) ?>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-start flex-wrap">
                <span class="badge <?= $bolehEdit ? 'text-bg-success' : 'text-bg-secondary' ?> fs-6">
                    <?= e($planning['status']) ?>
                </span>
                <a href="../index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if ($pesan !== ''): ?>
            <div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show mt-3 mb-0">
                <?= e($pesan) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Line / Mesin</div>
                <div class="fw-bold"><?= e($planning['kode_line']) ?> / <?= e($planning['kode_mesin']) ?></div>
                <div class="small text-secondary"><?= e($planning['nama_mesin']) ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Shift</div>
                <div class="fw-bold">Shift <?= (int)$planning['shift'] ?></div>
                <div class="small text-secondary">
                    <?= e(date('d/m/Y H:i', strtotime($planning['waktu_mulai']))) ?>
                    – <?= e(date('d/m/Y H:i', strtotime($planning['waktu_selesai']))) ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Target per Jam</div>
                <div class="fw-bold fs-4"><?= number_format((int)$planning['target_per_jam_snapshot']) ?></div>
                <div class="small text-secondary">pcs/jam</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Target Planning</div>
                <div class="fw-bold fs-4"><?= number_format((int)$planning['target_planning']) ?></div>
                <div class="small text-secondary">pcs</div>
            </div>
        </div>
    </div>

    
<!-- Identitas Part -->
<div class="panel p-3 p-lg-4 mb-3">
    <h5 class="fw-bold mb-3">
        <i class="bi bi-box-seam me-2 text-primary"></i>
        Identitas Part
    </h5>

    <div class="mb-3">
        <div class="fw-bold fs-5">
            <?= e($planning['nama_part']) ?>
        </div>
        <div class="text-secondary">
            Kode Part: <?= e($planning['kode_part']) ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Cavity</div>
                <div class="fw-bold fs-5">
                    <?= e($planning['cavity_snapshot'] ?? '-') ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Cycle Time</div>
                <div class="fw-bold fs-5">
                    <?= e($planning['cycle_time_snapshot'] ?? '-') ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Warna</div>
                <div class="fw-bold">
                    <?= e($planning['warna'] ?? '-') ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Material</div>
                <div class="fw-bold">
                    <?= e($planning['material'] ?? '-') ?>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Target per Jam</div>
                <div class="fw-bold fs-5">
                    <?= number_format((float)$planning['target_per_jam_snapshot']) ?>
                </div>
                <div class="small text-secondary">pcs/jam</div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Target per Shift</div>
                <div class="fw-bold fs-5">
                    <?= number_format((float)$planning['target_per_shift_snapshot']) ?>
                </div>
                <div class="small text-secondary">pcs/shift</div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Target per Hari</div>
                <div class="fw-bold fs-5">
                    <?= number_format((float)$planning['target_per_hari_snapshot']) ?>
                </div>
                <div class="small text-secondary">pcs/hari</div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <div class="stat p-3 h-100">
                <div class="text-secondary small">Target Planning</div>
                <div class="fw-bold fs-5">
                    <?= number_format((float)$planning['target_planning']) ?>
                </div>
                <div class="small text-secondary">pcs</div>
            </div>
        </div>
    </div>
</div>

<!-- Identitas Produksi -->
<div class="panel p-3 p-lg-4 mb-2">
    <h5 class="fw-bold mb-3">
        <i class="bi bi-clipboard2-check me-2 text-primary"></i>
        Identitas Produksi
    </h5>

    <div class="border rounded-3 p-3 mb-3">
        <div class="text-secondary small">Operator Utama</div>
        <div class="fw-bold">
            <?= e(
                $_SESSION['nama']
                ?? $_SESSION['nama_lengkap']
                ?? $_SESSION['username']
                ?? 'Operator'
            ) ?>
        </div>
        <div class="small text-secondary">
            Diambil otomatis dari akun login.
        </div>
    </div>

    <div class="d-flex align-items-center justify-content-between border rounded-3 p-3 mb-3">
        <div>
            <div class="fw-semibold">Menggunakan second proses?</div>
            <div class="small text-secondary">
                Status mengikuti data planning.
            </div>
        </div>
        <span class="badge <?= $secondProses ? 'text-bg-success' : 'text-bg-secondary' ?> fs-6">
            <?= $secondProses ? 'Ya' : 'Tidak' ?>
        </span>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="aksi" value="simpan_identitas">
        <input type="hidden" name="slot_jam" value="<?= (int)$slotDipilih ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">
                    No. JO Injection <span class="text-danger">*</span>
                </label>
                <input type="text"
                       name="no_jo_injection"
                       maxlength="100"
                       class="form-control"
                       required
                       value="<?= e($planning['no_jo_injection'] ?? '') ?>"
                       <?= !$bolehEdit ? 'readonly' : '' ?>>
            </div>

            <?php if ($secondProses): ?>
                <div class="col-md-6">
                    <label class="form-label">
                        No. JO Second Proses <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           name="no_jo_second_proses"
                           maxlength="100"
                           class="form-control"
                           required
                           value="<?= e($planning['no_jo_second_proses'] ?? '') ?>"
                           <?= !$bolehEdit ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        Nama Operator Second Proses <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           name="nama_operator_second_proses"
                           maxlength="100"
                           class="form-control"
                           required
                           value="<?= e($planning['nama_operator_second_proses'] ?? '') ?>"
                           <?= !$bolehEdit ? 'readonly' : '' ?>>
                </div>
            <?php endif; ?>

            <div class="col-md-6">
                <label class="form-label">
                    Nama IPQC
                    <span class="text-secondary fw-normal">(opsional)</span>
                </label>
                <input type="text"
                       name="nama_ipqc"
                       maxlength="100"
                       class="form-control"
                       value="<?= e($planning['nama_ipqc'] ?? '') ?>"
                       <?= !$bolehEdit ? 'readonly' : '' ?>>
            </div>

            <?php if ($bolehEdit): ?>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save me-1"></i>
                        Simpan Identitas Produksi
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>


<!-- Ringkasan Total Planning -->
<div class="row g-3 mb-2">
    <!-- <div class="col-6 col-lg-3">
        <div class="stat p-3 h-100">
            <div class="text-secondary small">Total OK Planning</div>
            <div class="fw-bold fs-3 text-success">
                <?= number_format($totalOkPlanning, 0, ',', '.') ?>
            </div>
            <div class="small text-secondary">pcs</div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="stat p-3 h-100">
            <div class="text-secondary small">Total NG Planning</div>
            <div class="fw-bold fs-3 text-danger">
                <?= number_format($totalNgPlanning, 0, ',', '.') ?>
            </div>
            <div class="small text-secondary">pcs</div>
        </div>
    </div> -->

    <div class="col-6 col-lg-6">
        <div class="stat p-3 h-100">
            <div class="text-secondary small">Counter Awal</div>
            <input type="number"
       min="0"
       step="1"
       name="counter_awal"
       id="counter_awal"
       class="form-control form-control-lg"
       value="<?= $planning['counter_awal'] !== null
           ? (int) $planning['counter_awal']
           : '' ?>"
       <?= $planning['counter_awal'] !== null ? 'readonly' : '' ?>
       <?= $planning['counter_awal'] === null ? 'required' : '' ?>>

            <!-- <div class="small text-secondary">Input operator</div> -->
        </div>
    </div>

    <div class="col-6 col-lg-6">
        <div class="stat p-3 h-100">
            <div class="text-secondary small">Counter Akhir</div>
            <input type="number"
       name="counter_akhir"
       id="counter_akhir"
       class="form-control form-control-lg fw-bold"
       value="<?= (int) ($planning['counter_akhir'] ?? 0) ?>"
       readonly>
            <!-- <div class="small text-secondary">Akumulasi planning</div> -->
        </div>
    </div>
</div>



    <!-- Slot jam -->
    <div class="panel p-3 p-lg-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Laporan Per Jam</h5>
                <div class="text-secondary small">
                    Waktu server: <?= e($now->format('d/m/Y H:i:s')) ?>
                </div>
            </div>
            <div>
                    <span class="badge text-bg-success align-self-start">
                Total Ok : <?= number_format($totalOkPlanning, 0, ',', '.') ?>
            </span>
            <span class="badge text-bg-danger align-self-start">
                Total NG : <?= number_format($totalNgPlanning, 0, ',', '.') ?>
            </span>
                </div>
            <span class="badge text-bg-primary align-self-start">
                Slot <?= (int)$slotDipilih ?> dari <?= (int)$totalSlot ?>
            </span>
        </div>

        <div class="d-flex gap-2 flex-wrap mb-4">
            <?php for ($i = 1; $i <= $totalSlot; $i++):
                $awal = $planStart->modify('+' . ($i - 1) . ' hour');
                $akhir = $awal->modify('+1 hour');
                if ($akhir > $planEnd) $akhir = $planEnd;
                $dataSlot = ambilLaporanSlot($conn, $planningId, $i);
                $isFuture = $awal > $now;
                ?>
                <a class="btn slot-link <?= $i === $slotDipilih ? 'btn-primary' : 'btn-outline-secondary' ?> <?= $isFuture ? 'disabled' : '' ?>"
                   href="?planning_id=<?= $planningId ?>&slot=<?= $i ?>">
                    <div class="fw-bold">Jam <?= $i ?></div>
                    <div class="small"><?= e($awal->format('H:i')) ?></div>
                    <?php if ($dataSlot): ?>
                        <i class="bi bi-check-circle-fill"></i>
                    <?php endif; ?>
                </a>
            <?php endfor; ?>
        </div>

        <!-- <div class="bg-light rounded-3 p-3 mb-4">
            <div class="fw-bold">Slot <?= (int)$slotDipilih ?>:
                <?= e($slotMulai->format('d/m/Y H:i')) ?> –
                <?= e($slotSelesai->format('d/m/Y H:i')) ?>
            </div>
            <div class="small text-secondary">
                Counter akhir dikurangi counter awal harus sama dengan jumlah OK.
            </div>
        </div> -->

        <?php if ($bolehEdit): ?>
            <form method="post" id="formLaporan">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="aksi" value="update_laporan">
                <input type="hidden" name="slot_jam" value="<?= (int)$slotDipilih ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <label class="form-label">Jumlah OK</label>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <button type="button" class="btn btn-outline-danger btn-lg" data-counter="jumlah_ok" data-delta="-1">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                                <input type="number" min="0" name="jumlah_ok" id="jumlah_ok"
                                       class="form-control form-control-lg text-center fw-bold"
                                       value="<?= (int)$laporan['jumlah_ok'] ?>" required>
                                <button type="button" class="btn btn-outline-success btn-lg" data-counter="jumlah_ok" data-delta="1">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <label class="form-label">Jumlah NG</label>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <button type="button" class="btn btn-outline-danger btn-lg" data-counter="jumlah_ng" data-delta="-1">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                                <input type="number" min="0" name="jumlah_ng" id="jumlah_ng"
                                       class="form-control form-control-lg text-center fw-bold"
                                       value="<?= (int)$laporan['jumlah_ng'] ?>" required>
                                <button type="button" class="btn btn-outline-success btn-lg" data-counter="jumlah_ng" data-delta="1">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    
<!-- <div class="col-md-6">
    <label class="form-label">Counter Awal</label>
    <input type="number"
       min="0"
       step="1"
       name="counter_awal"
       id="counter_awal"
       class="form-control form-control-lg"
       required
       value="<?= (int)($planning['counter_awal'] ?? 0) ?>">
<div class="form-text">
    Counter awal berlaku untuk seluruh planning, bukan hanya slot ini.
</div>
</div>

<div class="col-md-6">
    <label class="form-label">Counter Akhir (Otomatis)</label>
    <input type="number"
       name="counter_akhir"
       id="counter_akhir"
       class="form-control form-control-lg fw-bold"
       readonly
       value="<?= $counterAkhirPlanning ?>">
<div class="form-text">
    Counter awal + total OK seluruh planning + total NG seluruh planning.
</div>
</div> -->

<!-- <div class="col-12">
    <div class="alert alert-info mb-0">
        <div class="d-flex justify-content-between align-items-center">
            <span>Total produksi (OK + NG)</span>
            <strong id="totalProduksi">0 pcs</strong>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <span>Counter akhir yang dihitung</span>
            <strong id="hasilCounter">0</strong>
        </div>
    </div>
</div> -->

                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-save me-1"></i> Update Laporan
                        </button>
                    </div>
                </div>
            </form>

            <hr class="my-4">

            <?php if ($downtimeAktif): ?>
                <div class="alert alert-warning d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="fw-bold"><i class="bi bi-pause-circle me-1"></i> Mesin sedang berhenti</div>
                        <div><?= e($downtimeAktif['penyebab']) ?></div>
                        <div class="small">Mulai: <?= e($downtimeAktif['waktu_mulai']) ?></div>
                    </div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="aksi" value="lanjut_produksi">
                        <input type="hidden" name="slot_jam" value="<?= (int)$slotDipilih ?>">
                        <button class="btn btn-success btn-lg" type="submit">
                            <i class="bi bi-play-circle me-1"></i> Lanjut Produksi
                        </button>

                    </form>

                </div>
            <?php else: ?>
                <button type="button"
        class="btn btn-danger btn-lg w-100"
        data-bs-toggle="modal"
        data-bs-target="#modalStopMesin">
    <i class="bi bi-pause-circle me-1"></i>
    Stop Mesin
</button>

<div class="col-12 mt-3">
                        <label class="form-label">Keterangan Produksi</label>
                        <textarea name="keterangan" class="form-control" rows="3"
                                  placeholder="Masukkan keterangan produksi..."><?= e($laporan['keterangan'] ?? '') ?></textarea>
                    </div>

<hr class="my-4">

<button type="button"
        class="btn btn-dark btn-lg w-100"
        data-bs-toggle="modal"
        data-bs-target="#modalSelesaiProduksi"
        <?= $downtimeAktif ? 'disabled' : '' ?>>
    <i class="bi bi-check-circle me-1"></i>
    Selesai Produksi
</button>

            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-secondary">
                Planning ini tidak lagi berstatus BERJALAN. Form laporan hanya dapat dibaca.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Stop Mesin -->
<div class="modal fade" id="modalStopMesin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-pause-circle text-danger me-2"></i>Stop Mesin
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="aksi" value="stop_mesin">
                    <input type="hidden" name="slot_jam" value="<?= (int)$slotDipilih ?>">

                    <div class="mb-3">
                        <label class="form-label">Alasan Downtime <span class="text-danger">*</span></label>
                        <select name="downtime_id" class="form-select" required>
                            <option value="">Pilih alasan downtime</option>
                            <?php foreach ($daftarDowntime as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_downtime']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$daftarDowntime): ?>
                            <div class="form-text text-danger">Belum ada alasan downtime aktif di master downtime.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Keterangan Tambahan</label>
                        <textarea name="keterangan_downtime" class="form-control" rows="3"
                                  placeholder="Jelaskan kondisi mesin..."></textarea>
                    </div>
                    <div class="small text-secondary">
                        Waktu mulai downtime dicatat otomatis oleh server saat tombol konfirmasi ditekan.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger" <?= !$daftarDowntime ? 'disabled' : '' ?>>
                        Konfirmasi Stop Mesin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<div class="modal fade" id="modalSelesaiProduksi" tabindex="-1"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        Konfirmasi Selesai Produksi
                    </h5>
                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    Apakah kamu yakin ingin menyelesaikan planning ini?
                    Setelah selesai, laporan pada planning ini tidak dapat
                    diubah melalui halaman operator.
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light"
                            data-bs-dismiss="modal">
                        Batal
                    </button>

                    <input type="hidden" name="csrf_token"
                           value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="aksi"
                           value="selesai_produksi">
                    <input type="hidden" name="slot_jam"
                           value="<?= (int)$slotDipilih ?>">

                    <button type="submit" class="btn btn-dark">
                        Ya, Selesaikan Produksi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>


const counterAwal = document.getElementById('counter_awal');
const counterAkhir = document.getElementById('counter_akhir');
const jumlahOk = document.getElementById('jumlah_ok');
const jumlahNg = document.getElementById('jumlah_ng');
const totalProduksi = document.getElementById('totalProduksi');
const hasilCounter = document.getElementById('hasilCounter');

function angka(input) {
    return Math.max(0, parseInt(input?.value || '0', 10) || 0);
}

// function updateCounter() {
//     if (!counterAwal || !counterAkhir || !jumlahOk || !jumlahNg) {
//         return;
//     }

//     const awal = angka(counterAwal);
//     const ok = angka(jumlahOk);
//     const ng = angka(jumlahNg);

//     const total = ok + ng;
//     const akhir = awal + total;

//     counterAkhir.value = akhir;

//     if (totalProduksi) {
//         totalProduksi.textContent = total.toLocaleString('id-ID') + ' pcs';
//     }

//     if (hasilCounter) {
//         hasilCounter.textContent = akhir.toLocaleString('id-ID');
//     }
// }

// Total dari slot lain, selain slot yang sedang diedit.
const totalOkSlotLain = <?= max(
    0,
    $totalOkPlanning - (int)($laporan['jumlah_ok'] ?? 0)
) ?>;

const totalNgSlotLain = <?= max(
    0,
    $totalNgPlanning - (int)($laporan['jumlah_ng'] ?? 0)
) ?>;

function updateCounter() {
    if (!counterAwal || !counterAkhir || !jumlahOk || !jumlahNg) {
        return;
    }

    const awal = angka(counterAwal);
    const okSlotIni = angka(jumlahOk);
    const ngSlotIni = angka(jumlahNg);

    // Akumulasi planning setelah perubahan slot saat ini.
    const totalOK = totalOkSlotLain + okSlotIni;
    const totalNG = totalNgSlotLain + ngSlotIni;

    const total = totalOK + totalNG;
    const akhir = awal + total;

    counterAkhir.value = akhir;

    if (totalProduksi) {
        totalProduksi.textContent =
            total.toLocaleString('id-ID') + ' pcs';
    }

    if (hasilCounter) {
        hasilCounter.textContent = akhir.toLocaleString('id-ID');
    }
}

document.querySelectorAll('[data-counter]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.counter);
        const delta = parseInt(button.dataset.delta, 10);

        if (!input) return;

        input.value = Math.max(0, angka(input) + delta);
        updateCounter();
    });
});

[counterAwal, jumlahOk, jumlahNg].forEach(input => {
    if (input) {
        input.addEventListener('input', updateCounter);
    }
});

const formLaporan = document.getElementById('formLaporan');

if (formLaporan) {
    formLaporan.addEventListener('submit', event => {
        const awal = angka(counterAwal);
        const ok = angka(jumlahOk);
        const ng = angka(jumlahNg);
        const akhir = angka(counterAkhir);

        // if (akhir !== awal + ok + ng) {
        //     event.preventDefault();
        //     alert('Counter akhir belum sesuai dengan jumlah OK dan NG.');
        // }
    });
}

updateCounter();

</script>
</body>
</html>