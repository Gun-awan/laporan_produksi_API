

<?php

session_start();

require_once __DIR__ . '/../../config/koneksi.php';



if (empty($_SESSION['user_id']) ||

    !in_array($_SESSION['role'] ?? '', ['admin', 'ppic'], true)) {

    http_response_code(403);

    exit('Akses ditolak. Silakan login sebagai admin atau PPIC.');

}



function e($value): string {

    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');

}



function redirectPlanning(string $anchor = ''): void {

    header('Location: index.php' . $anchor);

    exit;

}



if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

}



if (empty($_SESSION['flash_planning'])) {

    $_SESSION['flash_planning'] = '';

}



/* =========================

   PROSES FORM

\========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';



    if (!hash_equals($_SESSION['csrf_token'], $token)) {

        $_SESSION['flash_planning'] = 'Token keamanan tidak valid. Muat ulang halaman.';

        redirectPlanning();

    }



    $aksi = $_POST['aksi'] ?? '';



    try {

        /* Batalkan planning */

        if ($aksi === 'batal') {

            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);



            if (!$id || $id < 1) {

                throw new Exception('ID planning tidak valid.');

            }



            $stmt = $conn->prepare("

                UPDATE planning_produksi

                SET status = 'BATAL'

                WHERE id = ? AND status = 'TERJADWAL'

            ");

            $stmt->bind_param('i', $id);

            $stmt->execute();



            if ($stmt->affected_rows < 1) {

                throw new Exception(

                    'Planning tidak ditemukan atau statusnya bukan TERJADWAL.'

                );

            }



            $stmt->close();

            $_SESSION['flash_planning'] = 'Planning berhasil dibatalkan.';

            redirectPlanning();

        }



        if ($aksi !== 'simpan') {

            throw new Exception('Aksi tidak dikenali.');

        }



        $tanggal = trim($_POST['tanggal_produksi'] ?? '');

        $shift = filter_var($_POST['shift'] ?? '', FILTER_VALIDATE_INT);

        $mesinId = filter_var($_POST['mesin_id'] ?? '', FILTER_VALIDATE_INT);
        $lineId = filter_var($_POST['line_id'] ?? '', FILTER_VALIDATE_INT);

        $partId = filter_var($_POST['part_id'] ?? '', FILTER_VALIDATE_INT);

        $urutan = filter_var($_POST['urutan'] ?? '', FILTER_VALIDATE_INT);

        $mulaiRaw = trim($_POST['waktu_mulai'] ?? '');

        $selesaiRaw = trim($_POST['waktu_selesai'] ?? '');



        $tanggalObj = DateTime::createFromFormat('!Y-m-d', $tanggal);



        if (!$tanggalObj || $tanggalObj->format('Y-m-d') !== $tanggal) {

            throw new Exception('Tanggal produksi tidak valid.');

        }



        if (!$shift || $shift < 1 || $shift > 3) {

            throw new Exception('Pilih shift 1, 2, atau 3.');

        }



        if (
            !$lineId || $lineId < 1 ||
            !$mesinId || $mesinId < 1 ||
            !$partId || $partId < 1 ||
            !$urutan || $urutan < 1
        ) {
            throw new Exception(
                'Line, mesin, part, dan urutan wajib dipilih dengan benar.'
            );
        }



        $mulaiObj = DateTime::createFromFormat('!Y-m-d\TH:i', $mulaiRaw);

        $selesaiObj = DateTime::createFromFormat('!Y-m-d\TH:i', $selesaiRaw);



        if (!$mulaiObj || !$selesaiObj ||

            $mulaiObj->format('Y-m-d\TH:i') !== $mulaiRaw ||

            $selesaiObj->format('Y-m-d\TH:i') !== $selesaiRaw) {

            throw new Exception('Format waktu mulai atau selesai tidak valid.');

        }



        /* Batas waktu setiap shift */

        $batasMulai = new DateTime($tanggal . ' 07:30:00');

        $batasSelesai = new DateTime($tanggal . ' 15:30:00');



        if ($shift === 2) {

            $batasMulai = new DateTime($tanggal . ' 15:30:00');

            $batasSelesai = new DateTime($tanggal . ' 23:30:00');

        } elseif ($shift === 3) {

            $batasMulai = new DateTime($tanggal . ' 23:30:00');

            $tanggalBesok = (clone $tanggalObj)->modify('+1 day')->format('Y-m-d');

            $batasSelesai = new DateTime($tanggalBesok . ' 07:30:00');

        }



        if ($mulaiObj < $batasMulai || $selesaiObj > $batasSelesai) {

            throw new Exception(

                'Waktu planning harus berada di dalam jam shift yang dipilih.'

            );

        }



        if ($selesaiObj <= $mulaiObj) {

            throw new Exception('Waktu selesai harus setelah waktu mulai.');

        }



        $durasiDetik = $selesaiObj->getTimestamp() - $mulaiObj->getTimestamp();



        /* Planning wajib mengikuti slot per jam, dengan menit :30 */

        if (

            (int)$mulaiObj->format('i') !== 30 ||

            (int)$selesaiObj->format('i') !== 30 ||

            $durasiDetik % 3600 !== 0

        ) {

            throw new Exception(

                'Waktu harus mengikuti slot per jam, misalnya 07:30–09:30.'

            );

        }



        $durasiJam = (int)($durasiDetik / 3600);



        if ($durasiJam < 1 || $durasiJam > 8) {

            throw new Exception('Durasi planning harus 1 sampai 8 jam.');

        }



        $waktuMulai = $mulaiObj->format('Y-m-d H:i:s');

        $waktuSelesai = $selesaiObj->format('Y-m-d H:i:s');



        $conn->begin_transaction();



        /* Pastikan mesin masih aktif dan line-nya aktif */

        $stmt = $conn->prepare("

            SELECT m.id

            FROM master_mesin m

            INNER JOIN master_line l ON l.id = m.line_id

            WHERE m.id = ?

              AND m.line_id = ?

              AND m.status = 'AKTIF'

              AND l.status = 'AKTIF'

            LIMIT 1

        ");

        $stmt->bind_param('ii', $mesinId, $lineId);

        $stmt->execute();

        $mesinValid = $stmt->get_result()->num_rows > 0;

        $stmt->close();



        if (!$mesinValid) {

            throw new Exception('Mesin tidak aktif atau line-nya tidak aktif.');

        }



        /* Ambil snapshot standar part yang aktif */

        $stmt = $conn->prepare("



        SELECT cycle_time, cavity, target_per_jam,

            target_per_shift, target_per_hari,

            terdapat_second_proses

        FROM master_part

        WHERE id = ? AND status = 'AKTIF'

        LIMIT 1



        ");

        $stmt->bind_param('i', $partId);

        $stmt->execute();

        $part = $stmt->get_result()->fetch_assoc();

        $stmt->close();



        if (!$part) {

            throw new Exception('Part tidak ditemukan atau sudah nonaktif.');

        }



        $cycleTime = (float)$part['cycle_time'];

        $cavity = (int)$part['cavity'];

        $targetPerJam = (int)$part['target_per_jam'];

        $targetPerShift = (int)$part['target_per_shift'];

        $targetPerHari = (int)$part['target_per_hari'];

        $terdapatSecondProsesSnapshot = (int)$part['terdapat_second_proses'];



        $targetPlanning = $targetPerJam * $durasiJam;



        if ($targetPlanning > 4294967295) {

            throw new Exception('Target planning melebihi batas yang didukung database.');

        }



        /* Cek nomor urutan belum digunakan */

        $stmt = $conn->prepare("

            SELECT id

            FROM planning_produksi

            WHERE tanggal_produksi = ?

              AND shift = ?

              AND mesin_id = ?

              AND urutan = ?

            LIMIT 1

        ");

        $stmt->bind_param('siii', $tanggal, $shift, $mesinId, $urutan);

        $stmt->execute();

        $urutanAda = $stmt->get_result()->num_rows > 0;

        $stmt->close();



        if ($urutanAda) {

            throw new Exception(

                'Nomor urutan sudah digunakan pada mesin dan shift tersebut.'

            );

        }



        /* Cek bentrok waktu pada mesin dan shift yang sama */

        $stmt = $conn->prepare("

            SELECT id

            FROM planning_produksi

            WHERE tanggal_produksi = ?

              AND shift = ?

              AND mesin_id = ?

              AND status <> 'BATAL'

              AND waktu_mulai < ?

              AND waktu_selesai > ?

            LIMIT 1

        ");

        $stmt->bind_param(

            'sisss',

            $tanggal,

            $shift,

            $mesinId,

            $waktuSelesai,

            $waktuMulai

        );

        $stmt->execute();

        $bentrok = $stmt->get_result()->num_rows > 0;

        $stmt->close();



        if ($bentrok) {

            throw new Exception(

                'Jadwal bentrok dengan planning lain pada mesin tersebut.'

            );

        }



        /* Validasi urutan harus sesuai kronologi waktu */

        $stmt = $conn->prepare("

            SELECT id, urutan, waktu_mulai, waktu_selesai

            FROM planning_produksi

            WHERE tanggal_produksi = ?

              AND shift = ?

              AND mesin_id = ?

              AND status <> 'BATAL'

        ");

        $stmt->bind_param('sii', $tanggal, $shift, $mesinId);

        $stmt->execute();

        $resultUrutan = $stmt->get_result();



        while ($r = $resultUrutan->fetch_assoc()) {

            if (

                (int)$r['urutan'] < $urutan &&

                $r['waktu_selesai'] > $waktuMulai

            ) {

                throw new Exception(

                    'Planning dengan urutan lebih kecil harus selesai sebelum planning ini dimulai.'

                );

            }



            if (

                (int)$r['urutan'] > $urutan &&

                $r['waktu_mulai'] < $waktuSelesai

            ) {

                throw new Exception(

                    'Planning dengan urutan lebih besar harus dimulai setelah planning ini selesai.'

                );

            }

        }

        $stmt->close();





        /* Simpan planning beserta snapshot part */

        $dibuatOleh = (int)$_SESSION['user_id'];



        $stmt = $conn->prepare("

            INSERT INTO planning_produksi (

                tanggal_produksi,

                shift,

                mesin_id,

                part_id,

                urutan,

                waktu_mulai,

                waktu_selesai,

                cycle_time_snapshot,

                cavity_snapshot,

                target_per_jam_snapshot,

                target_per_shift_snapshot,

                target_per_hari_snapshot,

                target_planning,

                terdapat_second_proses_snapshot,

                status,

                dibuat_oleh

            ) VALUES (

                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TERJADWAL', ?

            )

        ");



        $stmt->bind_param(

            'siiiissdiiiiiii',

            $tanggal,

            $shift,

            $mesinId,

            $partId,

            $urutan,

            $waktuMulai,

            $waktuSelesai,

            $cycleTime,

            $cavity,

            $targetPerJam,

            $targetPerShift,

            $targetPerHari,

            $targetPlanning,

            $terdapatSecondProsesSnapshot,

            $dibuatOleh

        );



        $stmt->execute();

        $stmt->close();





        $conn->commit();



        $_SESSION['flash_planning'] = 'Planning produksi berhasil disimpan.';

        redirectPlanning('#daftarPlanning');



    } catch (Throwable $e) {

        try {

            $conn->rollback();

        } catch (Throwable $ignored) {

            // Tidak ada transaksi yang perlu dibatalkan.

        }



        error_log('Planning produksi: ' . $e->getMessage());



        $_SESSION['flash_planning'] = $e instanceof mysqli_sql_exception

            ? 'Gagal menyimpan planning. Periksa data dan struktur database.'

            : $e->getMessage();



        redirectPlanning();

    }

}



/* =========================

   DATA HALAMAN

\========================= */

$pesan = $_SESSION['flash_planning'];

unset($_SESSION['flash_planning']);



$tanggalFilter = $_GET['tanggal'] ?? date('Y-m-d');
$shiftFilter = $_GET['shift'] ?? '';
$lineFilter = filter_var($_GET['line_id'] ?? '', FILTER_VALIDATE_INT) ?: '';
$mesinFilter = filter_var($_GET['mesin_id'] ?? '', FILTER_VALIDATE_INT) ?: '';



if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalFilter)) {

    $tanggalFilter = date('Y-m-d');

}



 $lineList = $conn->query("SELECT id, nama_line FROM master_line WHERE status = 'AKTIF' ORDER BY nama_line");

$mesinList = $conn->query("

    SELECT m.id, m.kode_mesin, m.nama_mesin, m.line_id, l.nama_line

    FROM master_mesin m

    INNER JOIN master_line l ON l.id = m.line_id

    WHERE m.status = 'AKTIF' AND l.status = 'AKTIF'

    ORDER BY l.nama_line, m.kode_mesin

");



$partList = $conn->query("

    SELECT id, kode_part, nama_part, target_per_jam

    FROM master_part

    WHERE status = 'AKTIF'

    ORDER BY kode_part

");



$sql = "

    SELECT p.*,

           m.kode_mesin, m.nama_mesin,

           l.nama_line,

           pt.kode_part, pt.nama_part,

           u.nama AS nama_pembuat

    FROM planning_produksi p

    INNER JOIN master_mesin m ON m.id = p.mesin_id

    INNER JOIN master_line l ON l.id = m.line_id

    INNER JOIN master_part pt ON pt.id = p.part_id

    LEFT JOIN users u ON u.id = p.dibuat_oleh

    WHERE p.tanggal_produksi = ?

";



$conditions = [];
$params = [$tanggalFilter];
$types = 's';
if (in_array((string)$shiftFilter, ['1', '2', '3'], true)) { $conditions[] = 'p.shift = ?'; $params[] = (int)$shiftFilter; $types .= 'i'; }
if ($lineFilter !== '') { $conditions[] = 'm.line_id = ?'; $params[] = (int)$lineFilter; $types .= 'i'; }
if ($mesinFilter !== '') { $conditions[] = 'p.mesin_id = ?'; $params[] = (int)$mesinFilter; $types .= 'i'; }
$sqlFinal = $sql . (count($conditions) ? ' AND ' . implode(' AND ', $conditions) : '') . ' ORDER BY p.shift, p.mesin_id, p.urutan';
$stmt = $conn->prepare($sqlFinal);
$bind = [$types];
foreach ($params as $key => $value) { $bind[] = &$params[$key]; }
call_user_func_array([$stmt, 'bind_param'], $bind);

$stmt->execute();

$planningList = $stmt->get_result();



$totalPlanning = 0;

$totalTerjadwal = 0;

$totalBerjalan = 0;

$totalSelesai = 0;

$totalBatal = 0;



$planningRows = [];

while ($row = $planningList->fetch_assoc()) {

    $planningRows[] = $row;

    $totalPlanning++;



    if ($row['status'] === 'TERJADWAL') $totalTerjadwal++;

    if ($row['status'] === 'BERJALAN') $totalBerjalan++;

    if ($row['status'] === 'SELESAI') $totalSelesai++;

    if ($row['status'] === 'BATAL') $totalBatal++;

}

$stmt->close();



function labelStatusPlanning(string $status): string {

    return match ($status) {

        'TERJADWAL' => 'bg-primary',

        'BERJALAN' => 'bg-warning text-dark',

        'SELESAI' => 'bg-success',

        'BATAL' => 'bg-secondary',

        default => 'bg-secondary'

    };

}

?>

<!doctype html>

<html lang="id">

<head>

    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Planning Produksi | Laporan Produksi</title>



    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"

          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"

          rel="stylesheet">



    <style>

        body { background: #f5f7fb; color: #263244; }

        .page-header { background: #fff; border: 1px solid #e8edf3; border-radius: 16px; }

        .panel { background: #fff; border: 1px solid #e8edf3; border-radius: 16px; }

        .stat-card { background: #fff; border: 1px solid #e8edf3; border-radius: 14px; height: 100%; }

        .stat-number { font-size: 1.5rem; font-weight: 700; }

        .form-label { font-weight: 600; font-size: .9rem; }

        .table thead th { background: #f8fafc; white-space: nowrap; font-size: .83rem; }

        .table td { vertical-align: middle; font-size: .9rem; }

        .small-muted { color: #718096; font-size: .84rem; }

        .required::after { content: " *"; color: #dc3545; }
        .toast-alert { position: fixed; top: 20px; right: 20px; z-index: 1080; max-width: min(420px, calc(100vw - 40px)); margin: 0; display:flex; align-items:center; }

    
        #daftarPlanning {
            scroll-margin-top: 24px;
            transition: box-shadow .25s ease, background-color .25s ease;
        }
        #daftarPlanning:target {
            animation: highlightDaftarPlanning 2.2s ease-out;
        }
        @keyframes highlightDaftarPlanning {
            0%, 35% { box-shadow: 0 0 0 4px rgba(13, 110, 253, .35); background-color: rgba(13, 110, 253, .06); }
            100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); background-color: transparent; }
        }
        @media (prefers-reduced-motion: reduce) {
            #daftarPlanning:target { animation: none; outline: 3px solid rgba(13, 110, 253, .45); }
        }
</style>

</head>

<body>



<div class="container-fluid py-4 px-3 px-lg-4">



    <div class="page-header p-3 p-lg-4 mb-4">

        <div class="d-flex flex-column flex-md-row justify-content-between gap-3">

            <div>

                <div class="text-primary mb-2">

                    <i class="bi bi-calendar2-week me-1"></i> PPIC

                </div>

                <h3 class="fw-bold mb-1">Planning Produksi</h3>

                <div class="small-muted">

                    Atur jadwal produksi berdasarkan mesin, part, dan shift.

                </div>

            </div>

            <div class="d-flex gap-2 align-items-start flex-wrap">

                <a href="../../dashboard/index.php" class="btn btn-outline-secondary">

                    <i class="bi bi-house-door me-1"></i> Dashboard

                </a>

                <a href="../../master/line.php" class="btn btn-outline-primary">

                    <i class="bi bi-diagram-3 me-1"></i> Master Line

                </a>

                <a href="../../master/mesin.php" class="btn btn-outline-primary">

                    <i class="bi bi-gear me-1"></i> Master Mesin

                </a>

                <a href="../../master/part.php" class="btn btn-outline-primary">

                    <i class="bi bi-box-seam me-1"></i> Master Part

                </a>

            </div>

        </div>

    </div>



    <?php if ($pesan !== ''): ?>
        <div class="toast-alert alert alert-info shadow" role="alert" id="flashPlanning">
            <i class="bi bi-info-circle me-2"></i><?= e($pesan) ?>
            <button type="button" class="btn-close ms-2" aria-label="Tutup" onclick="this.parentElement.remove()"></button>
        </div>
    <?php endif; ?>



    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-3">

            <div class="stat-card p-3">

                <div class="small-muted">Total Planning</div>

                <div class="stat-number"><?= $totalPlanning ?></div>

            </div>

        </div>

        <div class="col-6 col-xl-3">

            <div class="stat-card p-3">

                <div class="small-muted">Terjadwal</div>

                <div class="stat-number text-primary"><?= $totalTerjadwal ?></div>

            </div>

        </div>

        <div class="col-6 col-xl-3">

            <div class="stat-card p-3">

                <div class="small-muted">Berjalan</div>

                <div class="stat-number text-warning"><?= $totalBerjalan ?></div>

            </div>

        </div>

        <div class="col-6 col-xl-3">

            <div class="stat-card p-3">

                <div class="small-muted">Selesai / Batal</div>

                <div class="stat-number">

                    <?= $totalSelesai ?>

                    <span class="text-muted fs-6">/ <?= $totalBatal ?></span>

                </div>

            </div>

        </div>

    </div>



    <div class="panel p-3 p-lg-4 mb-4">

        <div class="d-flex align-items-center gap-2 mb-3">

            <i class="bi bi-plus-circle text-primary fs-5"></i>

            <h5 class="fw-bold mb-0">Buat Planning Baru</h5>

        </div>



        <div class="alert alert-light border small">

            <strong>Perhatian:</strong> Waktu planning harus mengikuti slot per jam.

            Contoh Shift 1: 07:30–09:30. Target planning dihitung otomatis dari

            target per jam dikalikan durasi planning.

        </div>



        <?php if ($mesinList->num_rows === 0 || $partList->num_rows === 0): ?>

            <div class="alert alert-warning mb-0">

                Pastikan minimal ada satu mesin aktif pada line aktif dan satu part aktif

                sebelum membuat planning.

            </div>

        <?php else: ?>

        <form method="post" class="g-3" id="formPlanning">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="aksi" value="simpan">


            <div class="d-flex justify-content-between align-items-center mb-3">
            <div>

            <div class="row g-3 mb-3">

            <div class="col-12 col-md-11">

                <label class="form-label required">Tanggal Produksi</label>

                <input type="date" class="form-control" name="tanggal_produksi"

                       id="tanggal_produksi" value="<?= e($tanggalFilter) ?>" required>

                <!-- <div class="form-text">Tanggal awal shift, termasuk untuk Shift 3.</div> -->

            </div>



            <div class="col-12 col-md-11">

                <label class="form-label required">Shift</label>

                <select class="form-select" name="shift" id="shift" required>

                    <option value="">Pilih shift</option>

                    <option value="1">Shift 1 (07:30–15:30)</option>

                    <option value="2">Shift 2 (15:30–23:30)</option>

                    <option value="3">Shift 3 (23:30–07:30 hari berikutnya)</option>

                </select>

            </div>



            <div class="col-12 col-md-11">
                <label class="form-label required">Line</label>
                <select class="form-select" name="line_id" id="line_id" required>
                    <option value="">Pilih line</option>
                    <?php while ($l = $lineList->fetch_assoc()): ?>
                        <option value="<?= (int)$l['id'] ?>"><?= e($l['nama_line']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-12 col-md-11">
                <label class="form-label required">Mesin</label>
                <select class="form-select" name="mesin_id" id="mesin_id" required>
                    <option value="">Pilih mesin</option>
                    <?php while ($m = $mesinList->fetch_assoc()): ?>
                        <option value="<?= (int)$m['id'] ?>" data-line="<?= (int)$m['line_id'] ?>">
                            <?= e($m['kode_mesin']) ?> - <?= e($m['nama_mesin']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            </div>
            </div>

            <div>
            <div class="row g-3 mb-3">

            <div class="col-12 col-md-11">

                <label class="form-label required">Part</label>

                <select class="form-select" name="part_id" id="part_id" required>

                    <option value="">Pilih part</option>

                    <?php while ($p = $partList->fetch_assoc()): ?>

                        <option value="<?= (int)$p['id'] ?>"

                                data-target="<?= (int)$p['target_per_jam'] ?>">

                            <?= e($p['kode_part']) ?> - <?= e($p['nama_part']) ?>

                            (Target/jam: <?= number_format((int)$p['target_per_jam']) ?>)

                        </option>

                    <?php endwhile; ?>

                </select>

            </div>



            <div class="col-12 col-md-11">

                <label class="form-label required">Urutan Produksi</label>

                <input type="number" class="form-control" name="urutan"

                       min="1" step="1" placeholder="Contoh: 1" required>

                <!-- <div class="form-text">

                    Urutan harus sesuai waktu. Urutan 1 adalah planning pertama pada mesin/shift tersebut.

                </div> -->

            </div>



            <div class="col-6 col-md-11">

                <label class="form-label required">Waktu Mulai</label>

                <input type="datetime-local" class="form-control"

                       name="waktu_mulai" id="waktu_mulai" step="1800" required>

            </div>



            <div class="col-6 col-md-11">

                <label class="form-label required">Waktu Selesai</label>

                <input type="datetime-local" class="form-control"

                       name="waktu_selesai" id="waktu_selesai" step="1800" required>

            </div>
            </div>
            </div>
            </div>



            <div class="col-12">

                <div class="rounded-3 border bg-light p-3">

                    <div class="row g-2">

                        <div class="col-12 col-md-4">

                            <div class="small-muted">Durasi</div>

                            <div class="fw-bold" id="durasiPreview">-</div>

                        </div>

                        <div class="col-12 col-md-4">

                            <div class="small-muted">Target per jam</div>

                            <div class="fw-bold" id="targetJamPreview">-</div>

                        </div>

                        <div class="col-12 col-md-4">

                            <div class="small-muted">Perkiraan target planning</div>

                            <div class="fw-bold text-primary" id="targetPlanningPreview">-</div>

                        </div>

                    </div>

                    <div class="small-muted mt-2">

                        Perkiraan di halaman ini hanya untuk membantu pengisian.

                        Perhitungan dan validasi akhir dilakukan oleh server.

                    </div>

                </div>

            </div>



            <div class="col-12 d-flex justify-content-end mt-3">

                <button type="reset" class="btn btn-light border me-2">

                    Reset

                </button>

                <button type="submit" class="btn btn-primary">

                    <i class="bi bi-save me-1"></i> Simpan Planning

                </button>

            </div>

        </form>

        <?php endif; ?>

    </div>



    <div class="panel p-3 p-lg-4" id="daftarPlanning">

        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-3">

            <div>

                <h5 class="fw-bold mb-1">Daftar Planning</h5>

                <div class="small-muted">Planning berdasarkan tanggal produksi dan shift.</div>

            </div>



            <form method="get" action="index.php#daftarPlanning" class="d-flex gap-2 flex-wrap">

                <input type="date" class="form-control" name="tanggal"

                       value="<?= e($tanggalFilter) ?>" required>

                <select class="form-select" name="shift">

                    <option value="">Semua Shift</option>

                    <option value="1" <?= $shiftFilter === '1' ? 'selected' : '' ?>>Shift 1</option>

                    <option value="2" <?= $shiftFilter === '2' ? 'selected' : '' ?>>Shift 2</option>

                    <option value="3" <?= $shiftFilter === '3' ? 'selected' : '' ?>>Shift 3</option>

                </select>

                <select class="form-select" name="line_id" id="filter_line_id">
                    <option value="">Semua Line</option>
                    <?php $lineFilterList = $conn->query("SELECT id, nama_line FROM master_line WHERE status='AKTIF' ORDER BY nama_line"); ?>
                    <?php while ($fl = $lineFilterList->fetch_assoc()): ?>
                        <option value="<?= (int)$fl['id'] ?>" <?= (string)$lineFilter === (string)$fl['id'] ? 'selected' : '' ?>><?= e($fl['nama_line']) ?></option>
                    <?php endwhile; ?>
                </select>
                <select class="form-select" name="mesin_id" id="filter_mesin_id">
                    <option value="">Semua Mesin</option>
                    <?php $filterMesinList = $conn->query("SELECT m.id, m.kode_mesin, m.nama_mesin, m.line_id FROM master_mesin m INNER JOIN master_line l ON l.id=m.line_id WHERE m.status='AKTIF' AND l.status='AKTIF' ORDER BY m.kode_mesin"); ?>
                    <?php while ($fm = $filterMesinList->fetch_assoc()): ?>
                        <option value="<?= (int)$fm['id'] ?>" data-line="<?= (int)$fm['line_id'] ?>" <?= (string)$mesinFilter === (string)$fm['id'] ? 'selected' : '' ?>><?= e($fm['kode_mesin'] . ' - ' . $fm['nama_mesin']) ?></option>
                    <?php endwhile; ?>
                </select>
                <button class="btn btn-outline-primary" type="submit">

                    <i class="bi bi-search me-1"></i> Filter

                </button>

            </form>

        </div>



        <div class="table-responsive">

            <table class="table table-hover align-middle">

                <thead>

                <tr>

                    <th>Tanggal</th>

                    <th>Shift</th>

                    <th>Urutan</th>

                    <th>Line / Mesin</th>

                    <th>Part</th>

                    <th>Waktu</th>

                    <th>Durasi</th>

                    <th>Target/Jam</th>

                    <th>Target Planning</th>

                    <th>Status</th>

                    <th>Aksi</th>

                </tr>

                </thead>

                <tbody>

                <?php if (count($planningRows) === 0): ?>

                    <tr>

                        <td colspan="11" class="text-center text-muted py-4">

                            Belum ada planning pada filter tanggal/shift ini.

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($planningRows as $row): ?>

                        <?php

                        $durasi = (

                            strtotime($row['waktu_selesai']) -

                            strtotime($row['waktu_mulai'])

                        ) / 3600;

                        ?>

                        <tr>

                            <td><?= e(date('d-m-Y', strtotime($row['tanggal_produksi']))) ?></td>

                            <td>Shift <?= (int)$row['shift'] ?></td>

                            <td><?= (int)$row['urutan'] ?></td>

                            <td>

                                <div class="fw-semibold"><?= e($row['kode_mesin']) ?></div>

                                <div class="small-muted"><?= e($row['nama_line']) ?></div>

                            </td>

                            <td>

                                <div class="fw-semibold"><?= e($row['kode_part']) ?></div>

                                <div class="small-muted"><?= e($row['nama_part']) ?></div>

                            </td>

                            <td>

                                <?= e(date('d/m H:i', strtotime($row['waktu_mulai']))) ?>

                                <div class="small-muted">

                                    s.d. <?= e(date('d/m H:i', strtotime($row['waktu_selesai']))) ?>

                                </div>

                            </td>

                            <td><?= number_format($durasi, 0) ?> jam</td>

                            <td><?= number_format((int)$row['target_per_jam_snapshot']) ?></td>

                            <td class="fw-semibold">

                                <?= number_format((int)$row['target_planning']) ?>

                            </td>

                            <td>

                                <span class="badge <?= e(labelStatusPlanning($row['status'])) ?>">

                                    <?= e($row['status']) ?>

                                </span>

                            </td>

                            <td>

                                <?php if ($row['status'] === 'TERJADWAL'): ?>

                                    <form method="post"

                                          onsubmit="return confirm('Batalkan planning ini?')">

                                        <input type="hidden" name="csrf_token"

                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden" name="aksi" value="batal">

                                        <input type="hidden" name="id"

                                               value="<?= (int)$row['id'] ?>">

                                        <button type="submit" class="btn btn-sm btn-outline-danger">

                                            <i class="bi bi-x-circle me-1"></i> Batal

                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="text-muted small">-</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>



    <div class="small-muted text-center mt-4">

        Laporan Produksi &middot; Planning Produksi

    </div>

</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

(() => {

    const tanggal = document.getElementById('tanggal_produksi');

    const shift = document.getElementById('shift');

    const mulai = document.getElementById('waktu_mulai');

    const selesai = document.getElementById('waktu_selesai');

    const part = document.getElementById('part_id');



    if (!tanggal || !shift || !mulai || !selesai || !part) return;



    function formatLocalDateTime(date) {

        const pad = n => String(n).padStart(2, '0');

        return date.getFullYear() + '-' +

            pad(date.getMonth() + 1) + '-' +

            pad(date.getDate()) + 'T' +

            pad(date.getHours()) + ':' + pad(date.getMinutes());

    }



    function batasShift() {

        if (!tanggal.value || !shift.value) return null;



        const awal = new Date(tanggal.value + 'T00:00:00');

        let jamMulai = 7;

        let menitMulai = 30;

        let jamSelesai = 15;

        let menitSelesai = 30;



        if (shift.value === '2') {

            jamMulai = 15;

            jamSelesai = 23;

        } else if (shift.value === '3') {

            jamMulai = 23;

            jamSelesai = 7;

            awal.setHours(23, 30, 0, 0);

            const akhir = new Date(awal);

            akhir.setDate(akhir.getDate() + 1);

            akhir.setHours(7, 30, 0, 0);

            return { awal, akhir };

        }



        awal.setHours(jamMulai, menitMulai, 0, 0);

        const akhir = new Date(tanggal.value + 'T00:00:00');

        akhir.setHours(jamSelesai, menitSelesai, 0, 0);

        return { awal, akhir };

    }



    function isiWaktuDefault() {

        const batas = batasShift();

        if (!batas) return;



        mulai.value = formatLocalDateTime(batas.awal);

        const satuJam = new Date(batas.awal);

        satuJam.setHours(satuJam.getHours() + 1);

        selesai.value = formatLocalDateTime(satuJam);



        hitungTarget();

    }



    function hitungTarget() {

        const pilihan = part.options[part.selectedIndex];

        const targetJam = pilihan ? Number(pilihan.dataset.target || 0) : 0;



        document.getElementById('targetJamPreview').textContent =

            targetJam ? targetJam.toLocaleString('id-ID') : '-';



        if (!mulai.value || !selesai.value) {

            document.getElementById('durasiPreview').textContent = '-';

            document.getElementById('targetPlanningPreview').textContent = '-';

            return;

        }



        const awal = new Date(mulai.value);

        const akhir = new Date(selesai.value);

        const selisih = (akhir - awal) / 3600000;



        if (selisih > 0 && Number.isInteger(selisih)) {

            document.getElementById('durasiPreview').textContent = selisih + ' jam';

            document.getElementById('targetPlanningPreview').textContent =

                (targetJam * selisih).toLocaleString('id-ID');

        } else {

            document.getElementById('durasiPreview').textContent = 'Periksa waktu';

            document.getElementById('targetPlanningPreview').textContent = '-';

        }

    }



    tanggal.addEventListener('change', isiWaktuDefault);

    shift.addEventListener('change', isiWaktuDefault);

    part.addEventListener('change', hitungTarget);

    mulai.addEventListener('change', hitungTarget);

    selesai.addEventListener('change', hitungTarget);



    isiWaktuDefault();

})();

</script>


<script>
document.addEventListener('DOMContentLoaded', function () {

    // ==================================================
    // FORM BUAT PLANNING BARU
    // ==================================================
    function hubungkanLineMesin(idLine, idMesin, modeFilter) {
        const lineSelect = document.getElementById(idLine);
        const mesinSelect = document.getElementById(idMesin);

        if (!lineSelect || !mesinSelect) return;

        // Simpan semua opsi mesin sebelum dropdown diubah
        const semuaMesin = Array.from(mesinSelect.options)
            .filter(function (option) {
                return option.value !== '';
            })
            .map(function (option) {
                return {
                    value: option.value,
                    text: option.textContent.trim(),
                    lineId: option.dataset.line
                };
            });

        function filterMesin() {
            const lineDipilih = lineSelect.value;
            const mesinSebelumnya = mesinSelect.value;

            // Kosongkan daftar mesin
            mesinSelect.innerHTML = '';

            // Teks opsi pertama berbeda untuk form dan filter
            const placeholder = document.createElement('option');
            placeholder.value = '';

            if (modeFilter) {
                placeholder.textContent = 'Semua Mesin';
            } else {
                placeholder.textContent = lineDipilih
                    ? 'Pilih mesin'
                    : 'Pilih line terlebih dahulu';
            }

            mesinSelect.appendChild(placeholder);

            // Tambahkan mesin sesuai line yang dipilih
            semuaMesin.forEach(function (mesin) {
                const cocok = modeFilter && lineDipilih === ''
                    ? true
                    : mesin.lineId === lineDipilih;

                if (!cocok) return;

                const option = document.createElement('option');
                option.value = mesin.value;
                option.textContent = mesin.text;
                option.dataset.line = mesin.lineId;

                mesinSelect.appendChild(option);
            });

            // Pertahankan pilihan mesin jika masih sesuai
            const pilihanMasihValid = Array.from(mesinSelect.options)
                .some(function (option) {
                    return option.value === mesinSebelumnya;
                });

            mesinSelect.value = pilihanMasihValid
                ? mesinSebelumnya
                : '';

            // Pada form planning, mesin harus dipilih setelah line.
            // Pada filter daftar planning, mesin boleh "Semua Mesin".
            mesinSelect.disabled = !modeFilter && lineDipilih === '';
        }

        lineSelect.addEventListener('change', filterMesin);

        // Jalankan saat halaman pertama kali dibuka
        filterMesin();
    }

    // Dropdown pada form Buat Planning Baru
    hubungkanLineMesin('line_id', 'mesin_id', false);

    // Dropdown pada filter Daftar Planning
    hubungkanLineMesin(
        'filter_line_id',
        'filter_mesin_id',
        true
    );

});
</script>

</body>

</html>