<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$admin = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add_candidate' || $action === 'update_candidate') {
            $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $number = filter_var($_POST['nomor_urut'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            $name = trim((string) ($_POST['nama'] ?? ''));
            if (($action === 'update_candidate' && $candidateId === false) || $number === false || $name === '' || strlen($name) > 120) {
                throw new RuntimeException('Data calon tidak valid.');
            }
            $newPhoto = store_photo_upload($_FILES['foto'] ?? null);
            try {
                if ($action === 'add_candidate') {
                    $statement = db()->prepare('INSERT INTO calon_kades (nomor_urut, nama, foto_path) VALUES (?, ?, ?)');
                    $statement->execute([$number, $name, $newPhoto]);
                    set_flash('success', 'Calon ditambahkan.');
                } else {
                    $statement = db()->prepare('SELECT foto_path FROM calon_kades WHERE id=?');
                    $statement->execute([$candidateId]);
                    $oldPhoto = $statement->fetchColumn();
                    if ($oldPhoto === false) {
                        throw new RuntimeException('Calon tidak ditemukan.');
                    }
                    if ($newPhoto === null) {
                        $statement = db()->prepare('UPDATE calon_kades SET nomor_urut=?,nama=? WHERE id=?');
                        $statement->execute([$number, $name, $candidateId]);
                    } else {
                        $statement = db()->prepare('UPDATE calon_kades SET nomor_urut=?,nama=?,foto_path=? WHERE id=?');
                        $statement->execute([$number, $name, $newPhoto, $candidateId]);
                    }
                    if (is_string($oldPhoto) && $newPhoto !== null && preg_match('/^[a-f0-9]{32}\\.(jpg|png|webp)$/', $oldPhoto)) {
                        $oldPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $oldPhoto;
                        if (is_file($oldPath)) {
                            unlink($oldPath);
                        }
                    }
                    set_flash('success', 'Data calon diperbarui.');
                }
            } catch (Throwable $error) {
                if ($newPhoto !== null && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $newPhoto)) {
                    unlink(UPLOAD_DIR . DIRECTORY_SEPARATOR . $newPhoto);
                }
                throw $error;
            }
        } elseif ($action === 'delete_candidate') {
            $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($candidateId === false) {
                throw new RuntimeException('Calon tidak valid.');
            }
            if ((int) db()->query('SELECT COUNT(*) FROM hasil_tps')->fetchColumn() > 0) {
                throw new RuntimeException('Calon tidak dapat dihapus setelah hasil suara dicatat.');
            }
            $statement = db()->prepare('SELECT foto_path FROM calon_kades WHERE id=?');
            $statement->execute([$candidateId]);
            $photoPath = $statement->fetchColumn();
            $statement = db()->prepare('DELETE FROM calon_kades WHERE id = ?');
            $statement->execute([$candidateId]);
            if ($statement->rowCount() === 1 && is_string($photoPath) && preg_match('/^[a-f0-9]{32}\\.(jpg|png|webp)$/', $photoPath)) {
                $fullPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $photoPath;
                if (is_file($fullPath)) {
                    unlink($fullPath);
                }
            }
            set_flash($statement->rowCount() === 1 ? 'success' : 'error', $statement->rowCount() === 1 ? 'Calon dihapus.' : 'Calon tidak ditemukan.');
        } elseif ($action === 'add_tps' || $action === 'update_tps') {
            $tpsId = filter_var($_POST['tps_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $code = trim((string) ($_POST['kode'] ?? ''));
            $name = trim((string) ($_POST['nama'] ?? ''));
            $address = trim((string) ($_POST['alamat'] ?? ''));
            if (($action === 'update_tps' && $tpsId === false) || $code === '' || strlen($code) > 20 || $name === '' || strlen($name) > 120 || strlen($address) > 255) {
                throw new RuntimeException('Data TPS tidak valid.');
            }
            if ($action === 'add_tps') {
                $statement = db()->prepare('INSERT INTO tps (kode, nama, alamat) VALUES (?, ?, ?)');
                $statement->execute([$code, $name, $address === '' ? null : $address]);
                set_flash('success', 'TPS ditambahkan.');
            } else {
                $statement = db()->prepare('UPDATE tps SET kode = ?, nama = ?, alamat = ? WHERE id = ?');
                $statement->execute([$code, $name, $address === '' ? null : $address, $tpsId]);
                set_flash('success', 'Data TPS diperbarui.');
            }
        } elseif ($action === 'delete_tps') {
            $tpsId = filter_var($_POST['tps_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tpsId === false) {
                throw new RuntimeException('TPS tidak valid.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT id FROM tps WHERE id = ? FOR UPDATE');
                $statement->execute([$tpsId]);
                if ($statement->fetchColumn() === false) {
                    throw new RuntimeException('TPS tidak ditemukan.');
                }
                $statement = $pdo->prepare('SELECT id FROM hasil_tps WHERE tps_id = ? FOR UPDATE');
                $statement->execute([$tpsId]);
                if ($statement->fetchColumn() !== false) {
                    throw new RuntimeException('TPS dengan hasil suara tidak dapat dihapus. Hapus hasilnya terlebih dahulu.');
                }
                $statement = $pdo->prepare('UPDATE users SET tps_id = NULL WHERE tps_id = ?');
                $statement->execute([$tpsId]);
                $statement = $pdo->prepare('DELETE FROM tps WHERE id = ?');
                $statement->execute([$tpsId]);
                $pdo->commit();
                set_flash('success', 'TPS dihapus.');
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        } elseif ($action === 'add_volunteer' || $action === 'update_volunteer') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $name = trim((string) ($_POST['nama'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (($action === 'update_volunteer' && $userId === false) || $name === '' || strlen($name) > 120 || !preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
                throw new RuntimeException('Data relawan tidak valid.');
            }
            if (($action === 'add_volunteer' && strlen($password) < 4) || ($password !== '' && strlen($password) < 4)) {
                throw new RuntimeException('Kata sandi minimal 4 karakter.');
            }
            if ($action === 'add_volunteer') {
                $statement = db()->prepare("INSERT INTO users (nama, username, password_hash, role) VALUES (?, ?, ?, 'relawan')");
                $statement->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
                set_flash('success', 'Akun relawan dibuat.');
            } else {
                $statement = db()->prepare("UPDATE users SET nama = ?, username = ? WHERE id = ? AND role = 'relawan'");
                $statement->execute([$name, $username, $userId]);
                if ($password !== '') {
                    $statement = db()->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'relawan'");
                    $statement->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                }
                set_flash('success', 'Data relawan diperbarui.');
            }
        } elseif ($action === 'delete_volunteer') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false || $userId === (int) $admin['id']) {
                throw new RuntimeException('Akun relawan tidak valid.');
            }
            $statement = db()->prepare('SELECT (SELECT COUNT(*) FROM hasil_tps WHERE relawan_id = ?) + (SELECT COUNT(*) FROM hasil_riwayat WHERE relawan_id = ? OR diubah_oleh = ?)');
            $statement->execute([$userId, $userId, $userId]);
            if ((int) $statement->fetchColumn() > 0) {
                throw new RuntimeException('Relawan dengan riwayat input tidak dapat dihapus.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare("UPDATE users SET tps_id = NULL WHERE id = ? AND role = 'relawan'");
                $statement->execute([$userId]);
                $statement = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'relawan'");
                $statement->execute([$userId]);
                $deleted = $statement->rowCount() === 1;
                $pdo->commit();
                set_flash($deleted ? 'success' : 'error', $deleted ? 'Relawan dihapus.' : 'Relawan tidak ditemukan.');
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        } elseif ($action === 'deactivate_volunteer') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false || $userId === (int) $admin['id']) {
                throw new RuntimeException('Akun relawan tidak valid.');
            }
            $statement = db()->prepare("UPDATE users SET aktif = 0, tps_id = NULL WHERE id = ? AND role = 'relawan'");
            $statement->execute([$userId]);
            set_flash('success', 'Akun relawan dinonaktifkan.');
        } elseif ($action === 'delete_result') {
            $resultId = filter_var($_POST['result_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($resultId === false) {
                throw new RuntimeException('Laporan tidak valid.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT id FROM hasil_tps WHERE id = ? FOR UPDATE');
                $statement->execute([$resultId]);
                if ($statement->fetchColumn() === false) {
                    throw new RuntimeException('Laporan tidak ditemukan.');
                }
                $statement = $pdo->prepare('SELECT foto_path FROM hasil_riwayat WHERE hasil_tps_id = ? AND foto_path IS NOT NULL UNION SELECT foto_path FROM hasil_tps WHERE id = ? AND foto_path IS NOT NULL');
                $statement->execute([$resultId, $resultId]);
                $photoPaths = array_unique(array_filter($statement->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
                $statement = $pdo->prepare('DELETE FROM hasil_tps WHERE id = ?');
                $statement->execute([$resultId]);
                $pdo->commit();
                foreach ($photoPaths as $photoPath) {
                    if (preg_match('/^[a-f0-9]{32}\\.(jpg|png|webp)$/', $photoPath)) {
                        $fullPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $photoPath;
                        if (is_file($fullPath) && !unlink($fullPath)) {
                            error_log('Could not remove result evidence file: ' . $photoPath);
                        }
                    }
                }
                set_flash('success', 'Laporan, suara, riwayat, dan foto dihapus permanen.');
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
    } catch (RuntimeException $error) {
        set_flash('error', $error->getMessage());
    } catch (PDOException $error) {
        set_flash('error', $error->getCode() === '23000' ? 'Data duplikat atau masih digunakan.' : 'Data gagal disimpan. Periksa koneksi database.');
    }
    redirect_to('/admin.php');
}

$tpsList = db()->query('SELECT t.id, t.kode, t.nama, t.alamat, EXISTS(SELECT 1 FROM hasil_tps h WHERE h.tps_id=t.id) AS has_result FROM tps t ORDER BY t.kode')->fetchAll();
$candidates = db()->query('SELECT id, nomor_urut, nama, foto_path FROM calon_kades ORDER BY nomor_urut')->fetchAll();
$users = db()->query("SELECT u.id, u.nama, u.username, u.role, u.aktif, (EXISTS(SELECT 1 FROM hasil_tps h WHERE h.relawan_id=u.id) OR EXISTS(SELECT 1 FROM hasil_riwayat r WHERE r.relawan_id=u.id OR r.diubah_oleh=u.id)) AS has_history FROM users u ORDER BY u.role,u.nama")->fetchAll();
$results = db()->query('SELECT t.id AS tps_id,t.kode AS tps_kode,t.nama AS tps_nama,EXISTS(SELECT 1 FROM hasil_tps r WHERE r.tps_id=t.id) AS has_any_result,c.id AS calon_id,c.nomor_urut,c.nama AS calon_nama,COALESCE(hs.jumlah_suara,0) AS jumlah_suara,h.id AS hasil_id,h.foto_path,h.diperbarui_pada FROM tps t CROSS JOIN calon_kades c LEFT JOIN hasil_tps h ON h.tps_id=t.id LEFT JOIN hasil_suara hs ON hs.hasil_tps_id=h.id AND hs.calon_id=c.id ORDER BY t.kode,c.nomor_urut')->fetchAll();
$submittedResults = db()->query('SELECT h.id,t.kode AS tps_kode,t.nama AS tps_nama,u.nama AS relawan,h.foto_path,h.diperbarui_pada FROM hasil_tps h JOIN tps t ON t.id=h.tps_id JOIN users u ON u.id=h.relawan_id ORDER BY t.kode')->fetchAll();
$chartRows = db()->query('SELECT c.id,c.nomor_urut,c.nama,COALESCE(SUM(hs.jumlah_suara),0) AS jumlah_suara FROM calon_kades c LEFT JOIN hasil_suara hs ON hs.calon_id=c.id GROUP BY c.id,c.nomor_urut,c.nama ORDER BY c.nomor_urut')->fetchAll();
$submittedTpsCount = (int) db()->query('SELECT COUNT(*) FROM hasil_tps')->fetchColumn();
$totalTpsCount = count($tpsList);
$totalVotes = array_sum(array_map(static fn(array $row): int => (int) $row['jumlah_suara'], $chartRows));
$pieColors = ['#187b5d', '#d27832', '#3477a9', '#a2466b', '#7861a8', '#aa8a25'];
$pieStops = [];
$pieLegend = [];
$pieCursor = 0.0;
foreach ($chartRows as $index => $row) {
    $amount = (int) $row['jumlah_suara'];
    $share = $totalVotes > 0 ? ($amount / $totalVotes) * 100 : 0;
    $next = $pieCursor + $share;
    if ($share > 0) {
        $color = $pieColors[$index % count($pieColors)];
        $pieStops[] = $color . ' ' . number_format($pieCursor, 2, '.', '') . '% ' . number_format($next, 2, '.', '') . '%';
    } else {
        $color = '#c8d2cc';
    }
    $pieLegend[] = ['label' => 'No. ' . $row['nomor_urut'] . ' · ' . $row['nama'], 'amount' => $amount, 'share' => $share, 'color' => $color];
    $pieCursor = $next;
}
$pieBackground = $totalVotes > 0 ? 'conic-gradient(' . implode(', ', $pieStops) . ')' : '#e5ebe7';
$hasReports = (int) db()->query('SELECT COUNT(*) FROM hasil_tps')->fetchColumn() > 0;
$editCandidate = null;
$editCandidateId = filter_var($_GET['edit_candidate'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($editCandidateId !== false && $editCandidateId !== null) {
    $statement = db()->prepare('SELECT id,nomor_urut,nama,foto_path FROM calon_kades WHERE id=?');
    $statement->execute([$editCandidateId]);
    $editCandidate = $statement->fetch() ?: null;
}
$editTps = null;
$editTpsId = filter_var($_GET['edit_tps'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($editTpsId !== false && $editTpsId !== null) {
    $statement = db()->prepare('SELECT id,kode,nama,alamat FROM tps WHERE id=?');
    $statement->execute([$editTpsId]);
    $editTps = $statement->fetch() ?: null;
}
$editVolunteer = null;
$editVolunteerId = filter_var($_GET['edit_volunteer'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($editVolunteerId !== false && $editVolunteerId !== null) {
    $statement = db()->prepare("SELECT id,nama,username FROM users WHERE id=? AND role='relawan'");
    $statement->execute([$editVolunteerId]);
    $editVolunteer = $statement->fetch() ?: null;
}

page_start('Admin');
?>
<header class="page-heading"><div><div class="eyebrow">Panel admin</div><h1>Kelola Pilkades</h1></div></header>
<div class="admin-grid">
    <section class="section"><h2><?= $editCandidate === null ? 'Tambah calon' : 'Edit calon' ?></h2>
        <form class="form-stack" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editCandidate === null ? 'add_candidate' : 'update_candidate' ?>">
            <?php if ($editCandidate !== null): ?><input type="hidden" name="candidate_id" value="<?= e($editCandidate['id']) ?>"><?php endif; ?>
            <label>Nomor urut<input type="number" name="nomor_urut" min="1" max="65535" value="<?= e($editCandidate['nomor_urut'] ?? '') ?>" required></label>
            <label>Nama calon<input name="nama" maxlength="120" value="<?= e($editCandidate['nama'] ?? '') ?>" required></label>
            <?php if ($editCandidate !== null && $editCandidate['foto_path'] !== null): ?><img class="candidate-photo-preview" src="<?= APP_BASE_PATH ?>/candidate_photo.php?id=<?= e($editCandidate['id']) ?>" alt="Foto <?= e($editCandidate['nama']) ?> saat ini"><?php endif; ?>
            <label>Foto calon (opsional, JPG/PNG/WebP, maksimal 10 MB)<input type="file" name="foto" accept="image/jpeg,image/png,image/webp"></label>
            <button type="submit"><?= $editCandidate === null ? 'Tambah calon' : 'Simpan calon' ?></button>
            <?php if ($editCandidate !== null): ?><a href="<?= APP_BASE_PATH ?>/admin.php">Batal edit</a><?php endif; ?>
        </form>
        <?php if ($candidates === []): ?><p class="muted">Belum ada calon.</p><?php else: ?><div class="record-list">
        <?php foreach ($candidates as $candidate): ?><article class="record-row candidate-admin-row"><div><?php if ($candidate['foto_path'] !== null): ?><img class="candidate-photo-admin" src="<?= APP_BASE_PATH ?>/candidate_photo.php?id=<?= e($candidate['id']) ?>" alt="Foto <?= e($candidate['nama']) ?>"><?php else: ?><div class="candidate-photo-placeholder" aria-hidden="true"><?= e($candidate['nomor_urut']) ?></div><?php endif; ?><div class="candidate-copy"><strong>No. <?= e($candidate['nomor_urut']) ?></strong><span><?= e($candidate['nama']) ?></span></div></div><div class="row-actions"><a href="<?= APP_BASE_PATH ?>/admin.php?edit_candidate=<?= e($candidate['id']) ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus calon ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_candidate"><input type="hidden" name="candidate_id" value="<?= e($candidate['id']) ?>"><button class="danger-button" type="submit" <?= $hasReports ? 'disabled title="Tidak dapat menghapus calon setelah laporan dibuat"' : '' ?>>Hapus</button></form></div></article><?php endforeach; ?>
        </div><?php endif; ?>
    </section>

    <section class="section"><h2><?= $editTps === null ? 'Tambah TPS' : 'Edit TPS' ?></h2>
        <form class="form-stack" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editTps === null ? 'add_tps' : 'update_tps' ?>">
            <?php if ($editTps !== null): ?><input type="hidden" name="tps_id" value="<?= e($editTps['id']) ?>"><?php endif; ?>
            <label>Kode TPS<input name="kode" maxlength="20" value="<?= e($editTps['kode'] ?? '') ?>" required></label>
            <label>Nama TPS<input name="nama" maxlength="120" value="<?= e($editTps['nama'] ?? '') ?>" required></label>
            <label>Alamat<input name="alamat" maxlength="255" value="<?= e($editTps['alamat'] ?? '') ?>"></label>
            <button type="submit"><?= $editTps === null ? 'Tambah TPS' : 'Simpan TPS' ?></button>
            <?php if ($editTps !== null): ?><a href="<?= APP_BASE_PATH ?>/admin.php">Batal edit</a><?php endif; ?>
        </form>
        <?php if ($tpsList === []): ?><p class="muted">Belum ada TPS.</p><?php else: ?><div class="record-list tps-list-scroll">
        <?php foreach ($tpsList as $tps): ?><article class="record-row"><div><strong><?= e($tps['kode']) ?> · <?= e($tps['nama']) ?></strong><span><?= $tps['has_result'] ? 'Sudah ada hasil' : 'Belum diinput' ?></span></div><div class="row-actions"><a href="<?= APP_BASE_PATH ?>/admin.php?edit_tps=<?= e($tps['id']) ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus TPS ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_tps"><input type="hidden" name="tps_id" value="<?= e($tps['id']) ?>"><button class="danger-button" type="submit" <?= $tps['has_result'] ? 'disabled' : '' ?>>Hapus</button></form></div></article><?php endforeach; ?>
        </div><?php endif; ?>
    </section>

    <section class="section"><h2><?= $editVolunteer === null ? 'Tambah relawan' : 'Edit relawan' ?></h2>
        <form class="form-stack" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editVolunteer === null ? 'add_volunteer' : 'update_volunteer' ?>">
            <?php if ($editVolunteer !== null): ?><input type="hidden" name="user_id" value="<?= e($editVolunteer['id']) ?>"><?php endif; ?>
            <label>Nama relawan<input name="nama" maxlength="120" value="<?= e($editVolunteer['nama'] ?? '') ?>" required></label>
            <label>Username<input name="username" minlength="3" maxlength="40" value="<?= e($editVolunteer['username'] ?? '') ?>" required></label>
            <label><?= $editVolunteer === null ? 'Kata sandi awal (min. 4 karakter)' : 'Kata sandi baru (opsional)' ?><input type="password" name="password" minlength="4" <?= $editVolunteer === null ? 'required' : '' ?> autocomplete="new-password"></label>
            <button type="submit"><?= $editVolunteer === null ? 'Buat relawan' : 'Simpan relawan' ?></button>
            <?php if ($editVolunteer !== null): ?><a href="<?= APP_BASE_PATH ?>/admin.php">Batal edit</a><?php endif; ?>
        </form>
        <?php foreach ($users as $account): if ($account['role'] !== 'relawan') continue; ?><article class="record-row"><div><strong><?= e($account['nama']) ?></strong><span><?= e($account['username']) ?> · <?= $account['aktif'] ? 'Aktif' : 'Nonaktif' ?></span></div><div class="row-actions"><a href="<?= APP_BASE_PATH ?>/admin.php?edit_volunteer=<?= e($account['id']) ?>">Edit</a><?php if ($account['aktif']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate_volunteer"><input type="hidden" name="user_id" value="<?= e($account['id']) ?>"><button type="submit">Nonaktifkan</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Hapus relawan ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_volunteer"><input type="hidden" name="user_id" value="<?= e($account['id']) ?>"><button class="danger-button" type="submit" <?= $account['has_history'] ? 'disabled' : '' ?>>Hapus</button></form></div></article><?php endforeach; ?>
    </section>

    <section class="section"><div class="section-heading"><div><h2>Perolehan suara</h2><p class="muted">Input masuk dari <?= e($submittedTpsCount) ?> / <?= e($totalTpsCount) ?> TPS</p></div></div>
        <?php if ($totalVotes === 0): ?><p class="empty-state">Belum ada suara yang masuk.</p><?php else: ?><div class="pie-layout"><div class="pie-chart" role="img" aria-label="Grafik lingkaran perolehan suara" style="background: <?= e($pieBackground) ?>"></div><ul class="pie-legend">
        <?php foreach ($pieLegend as $item): ?><li><span class="legend-dot" style="background: <?= e($item['color']) ?>"></span><span><?= e($item['label']) ?></span><strong><?= number_format($item['amount'], 0, ',', '.') ?> · <?= number_format($item['share'], 1, ',', '.') ?>%</strong></li><?php endforeach; ?>
        </ul></div><?php endif; ?>
    </section>
</div>

<section class="section"><h2>Laporan hasil TPS</h2>
    <?php if ($submittedResults === []): ?><p class="empty-state">Belum ada hasil suara.</p><?php else: ?><div class="record-list">
    <?php foreach ($submittedResults as $submittedResult): ?><article class="report-card"><div><strong>TPS <?= e($submittedResult['tps_kode']) ?> · <?= e($submittedResult['tps_nama']) ?></strong><span>Relawan: <?= e($submittedResult['relawan']) ?> · <?= e($submittedResult['diperbarui_pada']) ?></span><span><?= $submittedResult['foto_path'] !== null ? 'Foto bukti tersedia' : 'Belum ada foto bukti' ?></span></div><div class="row-actions"><a class="button" href="<?= APP_BASE_PATH ?>/correct_result.php?id=<?= e($submittedResult['id']) ?>">Edit suara</a><form method="post" onsubmit="return confirm('Hapus permanen laporan, suara, riwayat, dan foto ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_result"><input type="hidden" name="result_id" value="<?= e($submittedResult['id']) ?>"><button class="danger-button" type="submit">Hapus</button></form></div></article><?php endforeach; ?>
    </div><?php endif; ?>
</section>

<section class="section"><h2>Rekap per TPS</h2>
    <?php if ($results === []): ?><p class="empty-state">Tambahkan calon dan TPS untuk melihat rekap.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>TPS</th><th>No.</th><th>Calon</th><th>Suara</th><th>Waktu</th><th>Bukti</th><th>Aksi</th></tr></thead><tbody>
    <?php $shownReports = []; $shownEmptyTps = []; foreach ($results as $row): $tpsId=(int)$row['tps_id']; ?>
        <tr><td><?= e($row['tps_kode']) ?> · <?= e($row['tps_nama']) ?></td><td><?= e($row['nomor_urut']) ?></td><td><?= e($row['calon_nama']) ?></td><td><?= e($row['jumlah_suara']) ?></td><td><?= e($row['diperbarui_pada'] ?? 'Belum diinput') ?></td><td><?php if ($row['hasil_id'] !== null && $row['foto_path'] !== null): ?><a href="<?= APP_BASE_PATH ?>/photo.php?id=<?= e($row['hasil_id']) ?>" target="_blank" rel="noopener">Foto</a><?php else: ?>-<?php endif; ?></td><td><?php
    if ($row['hasil_id'] !== null && !isset($shownReports[(int)$row['hasil_id']])):
        $shownReports[(int)$row['hasil_id']] = true;
    ?>
        <div class="row-actions"><a href="<?= APP_BASE_PATH ?>/correct_result.php?id=<?= e($row['hasil_id']) ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus laporan ini secara permanen?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_result"><input type="hidden" name="result_id" value="<?= e($row['hasil_id']) ?>"><button class="danger-button" type="submit">Hapus hasil</button></form></div>
    <?php elseif (!$row['has_any_result'] && !isset($shownEmptyTps[$tpsId])): $shownEmptyTps[$tpsId]=true; ?>
        <form method="post" onsubmit="return confirm('Hapus TPS kosong ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_tps"><input type="hidden" name="tps_id" value="<?= e($tpsId) ?>"><button class="danger-button" type="submit">Hapus TPS</button></form>
    <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php page_end(); ?>
