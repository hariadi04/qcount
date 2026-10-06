<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = current_user();
if ($user === null) {
    redirect_to('/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $statement = db()->prepare('SELECT password_hash FROM users WHERE id = ? AND aktif = 1');
    $statement->execute([$user['id']]);
    $passwordHash = $statement->fetchColumn();

    if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
        set_flash('error', 'Kata sandi saat ini tidak cocok.');
        redirect_to('/change_password.php');
    }
    if (strlen($newPassword) < 4) {
        set_flash('error', 'Kata sandi baru minimal 4 karakter.');
        redirect_to('/change_password.php');
    }
    if ($newPassword !== $confirmPassword) {
        set_flash('error', 'Konfirmasi kata sandi baru tidak cocok.');
        redirect_to('/change_password.php');
    }
    if (password_verify($newPassword, $passwordHash)) {
        set_flash('error', 'Kata sandi baru harus berbeda dari kata sandi saat ini.');
        redirect_to('/change_password.php');
    }

    $statement = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND aktif = 1');
    $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    if ($statement->rowCount() !== 1) {
        set_flash('error', 'Kata sandi tidak berhasil diperbarui. Silakan coba lagi.');
        redirect_to('/change_password.php');
    }

    session_regenerate_id(true);
    set_flash('success', 'Kata sandi berhasil diganti.');
    redirect_to($user['role'] === 'admin' ? '/admin.php' : '/relawan.php');
}

page_start('Ganti kata sandi');
?>
<section class="narrow">
    <div class="eyebrow">Pengaturan akun · <?= e($user['role']) ?></div>
    <h1>Ganti kata sandi</h1>
    <p class="muted">Masukkan kata sandi saat ini untuk mengonfirmasi perubahan. Kata sandi baru harus minimal 4 karakter.</p>
    <form class="panel form-stack" method="post">
        <?= csrf_field() ?>
        <label>Kata sandi saat ini<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>Kata sandi baru<input type="password" name="new_password" minlength="4" autocomplete="new-password" required></label>
        <label>Ulangi kata sandi baru<input type="password" name="confirm_password" minlength="4" autocomplete="new-password" required></label>
        <button type="submit">Simpan kata sandi baru</button>
    </form>
</section>
<?php page_end(); ?>
