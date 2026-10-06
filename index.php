<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$user = current_user();
if ($user !== null) {
    redirect_to($user['role'] === 'admin' ? '/admin.php' : '/relawan.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $statement = db()->prepare('SELECT id, password_hash FROM users WHERE username = ? AND aktif = 1');
    $statement->execute([$username]);
    $account = $statement->fetch();

    if ($account !== false && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        redirect_to('/');
    }

    set_flash('error', 'Username atau kata sandi tidak cocok.');
    redirect_to('/index.php');
}

page_start('Masuk');
$adminExists = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
?>
<section class="narrow">
    <div class="eyebrow">Sistem pencatatan</div>
    <h1>Masuk ke Pilkades 2026</h1>
    <p class="muted">Gunakan akun yang dibuat oleh administrator.</p>
    <form class="panel form-stack" method="post">
        <?= csrf_field() ?>
        <label>Username<input name="username" autocomplete="username" required maxlength="40"></label>
        <label>Kata sandi<input type="password" name="password" autocomplete="current-password" required></label>
        <button type="submit">Masuk</button>
    </form>
    <?php if (!$adminExists): ?><p class="muted small"><a href="<?= APP_BASE_PATH ?>/setup.php">Buat akun admin pertama</a></p><?php endif; ?>
</section>
<?php page_end(); ?>
