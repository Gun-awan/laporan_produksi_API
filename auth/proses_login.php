
<?php
session_start();
require_once __DIR__ . '/../config/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php");
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Username dan password wajib diisi.';
    header("Location: ../index.php");
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, nama, username, password, role, status
         FROM users
         WHERE username = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (
        !$user ||
        $user['status'] !== 'AKTIF' ||
        !password_verify($password, $user['password'])
    ) {
        $_SESSION['login_error'] =
            'Username atau password salah, atau akun tidak aktif.';

        header("Location: ../index.php");
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['nama'] = $user['nama'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    header("Location: ../dashboard/index.php");
    exit;

} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Terjadi kesalahan saat login. Silakan periksa log aplikasi.');
}