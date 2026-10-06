<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (current_user() === null) {
    http_response_code(401);
    exit('Silakan masuk untuk melihat foto bukti.');
}

$resultId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($resultId === false) {
    http_response_code(404);
    exit('Foto tidak ditemukan.');
}

$statement = db()->prepare('SELECT foto_path FROM hasil_tps WHERE id = ?');
$statement->execute([$resultId]);
$filename = $statement->fetchColumn();
if (!is_string($filename) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
    http_response_code(404);
    exit('Foto tidak ditemukan.');
}

$path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('File foto tidak ditemukan.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit('File bukan gambar yang didukung.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="bukti-' . $resultId . '.' . pathinfo($filename, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);