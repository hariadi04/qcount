<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('pilkades2026');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => APP_BASE_PATH . '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $connection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $connection;
}

function store_photo_upload(?array $upload): ?string
{
    if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Foto gagal diunggah. Pastikan ukuran file tidak melebihi 10 MB.');
    }
    if ($upload['size'] < 1 || $upload['size'] > MAX_PHOTO_BYTES) {
        throw new RuntimeException('Ukuran foto maksimal 10 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WebP yang valid.');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Folder penyimpanan foto tidak dapat dibuat. Hubungi administrator server.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($upload['tmp_name'], UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Foto tidak dapat disimpan. Periksa izin folder server.');
    }
    return $filename;
}

function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_to(string $path): never
{
    header('Location: ' . APP_BASE_PATH . $path);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(400);
        exit('Permintaan tidak valid. Muat ulang halaman dan coba lagi.');
    }
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }

    $statement = db()->prepare('SELECT id, nama, username, role, tps_id FROM users WHERE id = ? AND aktif = 1');
    $statement->execute([$userId]);
    $user = $statement->fetch() ?: null;
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function require_role(string $role): array
{
    $user = current_user();
    if ($user === null) {
        redirect_to('/index.php');
    }
    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke halaman ini.');
    }
    return $user;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function page_start(string $title): void
{
    $user = current_user();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' | Pilkades 2026</title>';
    echo '<link rel="stylesheet" href="' . APP_BASE_PATH . '/app.css"></head><body>';
    echo '<header class="topbar"><a class="brand" href="' . APP_BASE_PATH . '/">Pilkades <span>2026</span></a>';
    if ($user !== null) {
        echo '<nav><span class="identity">' . e($user['nama']) . ' · ' . e($user['role']) . '</span>';
        if ($user['role'] === 'admin') {
            echo '<a href="' . APP_BASE_PATH . '/admin.php">Admin</a>';
        } else {
            echo '<a href="' . APP_BASE_PATH . '/relawan.php">Input suara</a>';
        }
        echo '<a href="' . APP_BASE_PATH . '/change_password.php">Ganti kata sandi</a>';
        echo '<form method="post" action="' . APP_BASE_PATH . '/logout.php">' . csrf_field();
        echo '<button class="link-button" type="submit">Keluar</button></form></nav>';
    }
    echo '</header><main class="container">';
    $flash = take_flash();
    if ($flash !== null) {
        echo '<div class="notice ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function page_end(): void
{
    echo '</main></body></html>';
}
