<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$adminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($adminCount > 0) {
    page_start('Penyiapan selesai');
    echo '<section class="narrow"><h1>Penyiapan selesai</h1><p>Akun admin sudah tersedia.</p>';
    echo '<a class="button" href="' . APP_BASE_PATH . '/index.php">Ke halaman masuk</a></section>';
    page_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['nama'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || strlen($name) > 120 || !preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
        set_flash('error', 'Nama wajib diisi; username harus 3-40 karakter (huruf, angka, titik, garis bawah, atau tanda hubung).');
        redirect_to('/setup.php');
    }
    if (strlen($password) < 4) {
        set_flash('error', 'Kata sandi admin minimal 4 karakter.');
        redirect_to('/setup.php');
    }

    try {
        $statement = db()->prepare("INSERT INTO users (nama, username, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $statement->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        set_flash('success', 'Akun admin berhasil dibuat. Silakan masuk.');
        redirect_to('/index.php');
    } catch (PDOException) {
        set_flash('error', 'Akun tidak dapat dibuat. Pastikan username belum digunakan.');
        redirect_to('/setup.php');
    }
}

page_start('Buat admin');
?>
<section class="narrow">
    <div class="eyebrow">Hanya tersedia sebelum admin pertama dibuat</div>
    <h1>Buat akun admin</h1>
    <p class="muted">Simpan kata sandi dengan aman. Halaman ini tidak dapat membuat admin tambahan setelah akun pertama dibuat.</p>
    <form class="panel form-stack" method="post">
        <?= csrf_field() ?>
        <label>Nama admin<input name="nama" required maxlength="120" autocomplete="name"></label>
        <label>Username<input name="username" required minlength="3" maxlength="40" autocomplete="username"></label>
        <label>Kata sandi<input type="password" name="password" required minlength="4" autocomplete="new-password"></label>
        <button type="submit">Buat admin</button>
    </form>
</section>
<?php page_end(); ?>
