<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (current_user() === null) {
    http_response_code(401);
    exit('Silakan masuk untuk melihat foto calon.');
}

$candidateId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($candidateId === false) {
    http_response_code(404);
    exit('Foto calon tidak ditemukan.');
}

$statement = db()->prepare('SELECT foto_path FROM calon_kades WHERE id=?');
$statement->execute([$candidateId]);
$filename = $statement->fetchColumn();
if (!is_string($filename) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
    http_response_code(404);
    exit('Foto calon tidak ditemukan.');
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
header('Content-Disposition: inline; filename="calon-' . $candidateId . '.' . pathinfo($filename, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
