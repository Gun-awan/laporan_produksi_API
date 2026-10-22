
<?php
require_once __DIR__ . '/config/koneksi.php';

$username = 'leader';
$password = 'leader123';
$nama = 'Leader';
$role = 'leader';

try {
    // Periksa apakah username sudah ada
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        exit(
            "Username admin sudah tersedia. " .
            "Silakan langsung login."
        );
    }

    $stmt->close();

    // Simpan password dalam bentuk hash
    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $stmt = $conn->prepare(
        "INSERT INTO users (nama, username, password, role, status)
         VALUES (?, ?, ?, ?, 'AKTIF')"
    );

    $stmt->bind_param(
        "ssss",
        $nama,
        $username,
        $passwordHash,
        $role
    );

    $stmt->execute();
    $stmt->close();

    echo "Akun admin berhasil dibuat.<br>";
    echo "Username: leader<br>";
    echo "Password: leader123<br><br>";
    echo "PENTING: Hapus file setup_admin.php setelah selesai.";

} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo "Gagal membuat akun admin. Periksa konfigurasi database.";
}