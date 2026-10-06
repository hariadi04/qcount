<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode tidak diizinkan.');
}
verify_csrf();
$_SESSION = [];
session_regenerate_id(true);
set_flash('success', 'Anda sudah keluar.');
redirect_to('/index.php');
