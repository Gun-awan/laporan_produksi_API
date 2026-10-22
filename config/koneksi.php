
<?php

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = 'localhost';
$user = 'root';
$password = '';
$database = 'laporan_produksi';

try {
    $conn = new mysqli(
        $host,
        $user,
        $password,
        $database
    );

    $conn->set_charset('utf8mb4');

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());

    http_response_code(500);
    exit('Koneksi database gagal. Silakan periksa konfigurasi.');
}