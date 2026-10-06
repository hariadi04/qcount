<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$volunteer = require_role('relawan');
$tpsList = db()->query('SELECT t.id,t.kode,t.nama,t.alamat,EXISTS(SELECT 1 FROM hasil_tps h WHERE h.tps_id=t.id) AS sudah_diisi FROM tps t ORDER BY t.kode')->fetchAll();
$candidates = db()->query('SELECT id,nomor_urut,nama,foto_path FROM calon_kades ORDER BY nomor_urut')->fetchAll();
$selectedTpsId = filter_var($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['tps_id'] ?? null) : ($_GET['tps_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedTpsId = $selectedTpsId === false ? null : $selectedTpsId;
$tps = null;
foreach ($tpsList as $item) {
    if ((int) $item['id'] === $selectedTpsId) {
        $tps = $item;
        break;
    }
}

$requestedEdit = $_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['mode'] ?? '') === 'edit';
$isEdit = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'edit';
$report = null;
if ($tps !== null && (bool) $tps['sudah_diisi']) {
    $statement = db()->prepare('SELECT h.id,h.foto_path,h.relawan_id,u.nama AS relawan_nama FROM hasil_tps h JOIN users u ON u.id=h.relawan_id WHERE h.tps_id=?');
    $statement->execute([$tps['id']]);
    $report = $statement->fetch() ?: null;
}
$isOwnReport = $report !== null && (int) $report['relawan_id'] === (int) $volunteer['id'];

if ($requestedEdit && !$isOwnReport) {
    set_flash('error', 'Anda hanya dapat mengedit laporan TPS yang Anda input.');
    redirect_to('/relawan.php');
}
if ($isEdit && (!$isOwnReport || $tps === null)) {
    set_flash('error', 'Anda hanya dapat mengedit laporan TPS yang Anda input.');
    redirect_to('/relawan.php');
}

$savedVotes = [];
if ($report !== null) {
    $statement = db()->prepare('SELECT calon_id,jumlah_suara FROM hasil_suara WHERE hasil_tps_id=?');
    $statement->execute([$report['id']]);
    foreach ($statement->fetchAll() as $row) {
        $savedVotes[(int) $row['calon_id']] = (int) $row['jumlah_suara'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ($tps === null || (!$isEdit && (bool) $tps['sudah_diisi'])) {
        set_flash('error', 'Pilih TPS yang belum diisi atau edit laporan milik Anda.');
        redirect_to('/relawan.php');
    }
    if ($candidates === []) {
        set_flash('error', 'Admin belum menambahkan calon.');
        redirect_to('/relawan.php?tps_id=' . $tps['id']);
    }

    $submittedVotes = $_POST['suara'] ?? null;
    $expectedIds = array_map(static fn(array $candidate): string => (string) $candidate['id'], $candidates);
    if (!is_array($submittedVotes) || count($submittedVotes) !== count($expectedIds) || array_diff($expectedIds, array_map('strval', array_keys($submittedVotes))) !== []) {
        set_flash('error', 'Isi jumlah suara untuk setiap calon.');
        redirect_to('/relawan.php?tps_id=' . $tps['id'] . ($isEdit ? '&mode=edit' : ''));
    }

    $votes = [];
    foreach ($candidates as $candidate) {
        $candidateId = (string) $candidate['id'];
        $value = $submittedVotes[$candidateId] ?? null;
        if (!is_string($value) || !preg_match('/^\d{1,10}$/D', $value) || (int) $value > 4294967295) {
            set_flash('error', 'Jumlah suara harus bilangan bulat nol atau lebih.');
            redirect_to('/relawan.php?tps_id=' . $tps['id'] . ($isEdit ? '&mode=edit' : ''));
        }
        $votes[(int) $candidate['id']] = (int) $value;
    }

    try {
        $newPhotoPath = store_photo_upload($_FILES['foto'] ?? null);
    } catch (RuntimeException $error) {
        set_flash('error', $error->getMessage());
        redirect_to('/relawan.php?tps_id=' . $tps['id'] . ($isEdit ? '&mode=edit' : ''));
    }
    if (!$isEdit && $newPhotoPath === null) {
        set_flash('error', 'Foto bukti hasil wajib diunggah.');
        redirect_to('/relawan.php?tps_id=' . $tps['id']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT id,relawan_id,foto_path FROM hasil_tps WHERE tps_id=? FOR UPDATE');
        $statement->execute([$tps['id']]);
        $lockedReport = $statement->fetch();

        if ($isEdit) {
            if ($lockedReport === false || (int) $lockedReport['relawan_id'] !== (int) $volunteer['id']) {
                throw new RuntimeException('Laporan bukan milik Anda atau sudah dihapus.');
            }
            $resultId = (int) $lockedReport['id'];
            $previousVotes = [];
            $statement = $pdo->prepare('SELECT calon_id,jumlah_suara FROM hasil_suara WHERE hasil_tps_id=? FOR UPDATE');
            $statement->execute([$resultId]);
            foreach ($statement->fetchAll() as $previousVote) {
                $previousVotes[(int) $previousVote['calon_id']] = (int) $previousVote['jumlah_suara'];
            }
            $photoPath = $newPhotoPath ?? $lockedReport['foto_path'];
            if ($newPhotoPath !== null) {
                $statement = $pdo->prepare('UPDATE hasil_tps SET foto_path=? WHERE id=?');
                $statement->execute([$photoPath, $resultId]);
            }
            $statement = $pdo->prepare('DELETE FROM hasil_suara WHERE hasil_tps_id=?');
            $statement->execute([$resultId]);
        } else {
            if ($lockedReport !== false) {
                throw new RuntimeException('TPS sudah memiliki hasil. Anda hanya dapat mengedit laporan milik sendiri.');
            }
            $statement = $pdo->prepare('INSERT INTO hasil_tps (tps_id,relawan_id,foto_path) VALUES (?,?,?)');
            $statement->execute([$tps['id'], $volunteer['id'], $newPhotoPath]);
            $resultId = (int) $pdo->lastInsertId();
            $photoPath = $newPhotoPath;
            $previousVotes = [];
        }

        $insertVote = $pdo->prepare('INSERT INTO hasil_suara (hasil_tps_id,calon_id,jumlah_suara) VALUES (?,?,?)');
        foreach ($votes as $candidateId => $amount) {
            $insertVote->execute([$resultId, $candidateId, $amount]);
        }
        $statement = $pdo->prepare('INSERT INTO hasil_riwayat (hasil_tps_id,relawan_id,diubah_oleh,data_suara,data_sebelumnya,foto_path,alasan) VALUES (?,?,?,?,?,?,?)');
        $statement->execute([
            $resultId,
            $volunteer['id'],
            $volunteer['id'],
            json_encode($votes, JSON_THROW_ON_ERROR),
            $previousVotes === [] ? null : json_encode($previousVotes, JSON_THROW_ON_ERROR),
            $photoPath,
            $isEdit ? 'Koreksi relawan pada laporan miliknya' : 'Input awal relawan',
        ]);
        $pdo->commit();
        set_flash('success', $isEdit ? 'Perubahan hasil berhasil disimpan.' : 'Data suara berhasil diinput dan dikirim ke admin.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newPhotoPath !== null && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $newPhotoPath)) {
            unlink(UPLOAD_DIR . DIRECTORY_SEPARATOR . $newPhotoPath);
        }
        error_log((string) $error);
        set_flash('error', $error instanceof RuntimeException ? $error->getMessage() : 'Hasil belum tersimpan. Hubungi admin.');
        redirect_to('/relawan.php?tps_id=' . $tps['id'] . ($isEdit ? '&mode=edit' : ''));
    }
    redirect_to('/relawan.php?tps_id=' . $tps['id'] . '&success=1');
}

$availableTps = array_values(array_filter($tpsList, static fn(array $item): bool => !(bool) $item['sudah_diisi']));
$submittedTps = array_values(array_filter($tpsList, static fn(array $item): bool => (bool) $item['sudah_diisi']));
$resultId = $report === null ? null : (int) $report['id'];
$photoPath = $report['foto_path'] ?? null;
$savedVoteTotal = array_sum($savedVotes);
$pieColors = ['#187b5d', '#d27832', '#3477a9', '#a2466b', '#7861a8', '#aa8a25'];
$pieStops = [];
$pieLegend = [];
$pieCursor = 0.0;
foreach ($candidates as $index => $candidate) {
    $amount = (int) ($savedVotes[(int) $candidate['id']] ?? 0);
    $share = $savedVoteTotal > 0 ? ($amount / $savedVoteTotal) * 100 : 0;
    $next = $pieCursor + $share;
    $color = $pieColors[$index % count($pieColors)];
    if ($share > 0) {
        $pieStops[] = $color . ' ' . number_format($pieCursor, 2, '.', '') . '% ' . number_format($next, 2, '.', '') . '%';
    }
    $pieLegend[] = ['label' => 'No. ' . $candidate['nomor_urut'] . ' · ' . $candidate['nama'], 'amount' => $amount, 'share' => $share, 'color' => $color];
    $pieCursor = $next;
}
$pieBackground = $savedVoteTotal > 0 ? 'conic-gradient(' . implode(', ', $pieStops) . ')' : '#e5ebe7';

page_start('Input suara');
?>
<header class="page-heading"><div><div class="eyebrow">Relawan</div><h1>Input suara</h1></div></header>
<?php if ($tps !== null && $report !== null && !$requestedEdit): ?>
    <section class="section"><div class="status-mark">✓</div><h2><?= ($_GET['success'] ?? '') === '1' ? 'Data berhasil disimpan' : 'Data sudah dikirim' ?></h2><p class="muted">TPS <?= e($tps['kode']) ?> · <?= e($tps['nama']) ?></p><p>Ringkasan angka suara yang tersimpan:</p>
    <?php if ($savedVoteTotal > 0): ?><div class="pie-layout"><div class="pie-chart" role="img" aria-label="Grafik pie hasil TPS <?= e($tps['kode']) ?>" style="background: <?= e($pieBackground) ?>"></div><ul class="pie-legend"><?php foreach ($pieLegend as $item): ?><li><span class="legend-dot" style="background: <?= e($item['color']) ?>"></span><span><?= e($item['label']) ?></span><strong><?= number_format($item['amount'], 0, ',', '.') ?> · <?= number_format($item['share'], 1, ',', '.') ?>%</strong></li><?php endforeach; ?></ul></div><?php endif; ?>
    <ul class="submitted-votes"><?php foreach ($candidates as $candidate): ?><li><span class="candidate-option"><?php if ($candidate['foto_path'] !== null && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $candidate['foto_path'])): ?><img class="candidate-photo-small" src="<?= APP_BASE_PATH ?>/candidate_photo.php?id=<?= e($candidate['id']) ?>" alt=""><?php endif; ?><span class="candidate-name">No. <?= e($candidate['nomor_urut']) ?> · <?= e($candidate['nama']) ?></span></span><strong><?= number_format((int) ($savedVotes[(int) $candidate['id']] ?? 0), 0, ',', '.') ?></strong></li><?php endforeach; ?></ul>
    <?php if ($photoPath !== null): ?><a href="<?= APP_BASE_PATH ?>/photo.php?id=<?= e($resultId) ?>" target="_blank" rel="noopener">Lihat foto bukti</a><?php endif; ?>
    <?php if ($isOwnReport): ?><p><a class="button" href="<?= APP_BASE_PATH ?>/relawan.php?tps_id=<?= e($tps['id']) ?>&mode=edit">Edit input saya</a></p><?php else: ?><p class="muted small">Input ini dibuat relawan lain dan hanya dapat dikoreksi oleh pemiliknya atau admin.</p><?php endif; ?></section>
<?php elseif ($tps !== null && $report !== null && $requestedEdit && $isOwnReport): ?>
    <section class="section"><div class="eyebrow">Edit input · TPS <?= e($tps['kode']) ?></div><h2><?= e($tps['nama']) ?></h2><p class="muted">Perubahan angka disimpan ke riwayat. Foto baru opsional.</p>
        <?php if ($candidates === []): ?><p class="empty-state">Belum ada calon. Hubungi admin.</p><?php else: ?>
        <form class="simple-vote-form" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="mode" value="edit"><input type="hidden" name="tps_id" value="<?= e($tps['id']) ?>">
            <div class="vote-fields"><?php foreach ($candidates as $candidate): ?><label class="vote-field"><span class="candidate-option"><span class="vote-number"><?= e($candidate['nomor_urut']) ?></span><?php if ($candidate['foto_path'] !== null && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $candidate['foto_path'])): ?><img class="candidate-photo-small" src="<?= APP_BASE_PATH ?>/candidate_photo.php?id=<?= e($candidate['id']) ?>" alt=""><?php endif; ?><span class="candidate-name"><?= e($candidate['nama']) ?></span></span><input class="vote-input" type="number" name="suara[<?= e($candidate['id']) ?>]" min="0" max="4294967295" step="1" inputmode="numeric" value="<?= e($savedVotes[(int) $candidate['id']] ?? 0) ?>" required></label><?php endforeach; ?></div>
            <label>Ganti foto bukti (opsional)<input type="file" name="foto" accept="image/jpeg,image/png,image/webp"></label>
            <button type="submit">Simpan perubahan</button><a href="<?= APP_BASE_PATH ?>/relawan.php?tps_id=<?= e($tps['id']) ?>">Batal</a>
        </form><?php endif; ?>
    </section>
<?php else: ?>
    <section class="section"><h2>Pilih TPS kosong <span class="count-badge"><?= count($availableTps) ?></span></h2>
        <?php if ($availableTps === []): ?><p class="empty-state">Semua TPS sudah memiliki laporan. Hubungi admin jika ada TPS tambahan.</p><?php else: ?>
        <form class="simple-vote-form" method="get"><label for="tps_id">TPS yang belum diisi</label><select id="tps_id" name="tps_id" required><option value="">Pilih TPS kosong</option><?php foreach ($availableTps as $option): ?><option value="<?= e($option['id']) ?>" <?= $tps !== null && (int) $option['id'] === (int) $tps['id'] ? 'selected' : '' ?>>TPS <?= e($option['kode']) ?> · <?= e($option['nama']) ?></option><?php endforeach; ?></select><button type="submit">Lanjut isi suara</button></form>
        <?php endif; ?>
    </section>
    <?php if ($tps !== null && $report === null): ?>
        <section class="section vote-section"><div class="eyebrow">TPS <?= e($tps['kode']) ?></div><h2><?= e($tps['nama']) ?></h2>
        <?php if ($candidates === []): ?><p class="empty-state">Belum ada calon. Hubungi admin.</p><?php else: ?>
        <form class="simple-vote-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="tps_id" value="<?= e($tps['id']) ?>"><div class="vote-fields">
            <?php foreach ($candidates as $candidate): ?><label class="vote-field"><span class="candidate-option"><span class="vote-number"><?= e($candidate['nomor_urut']) ?></span><?php if ($candidate['foto_path'] !== null && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $candidate['foto_path'])): ?><img class="candidate-photo-small" src="<?= APP_BASE_PATH ?>/candidate_photo.php?id=<?= e($candidate['id']) ?>" alt=""><?php endif; ?><span class="candidate-name"><?= e($candidate['nama']) ?></span></span><input class="vote-input" type="number" name="suara[<?= e($candidate['id']) ?>]" min="0" max="4294967295" step="1" inputmode="numeric" placeholder="0" required aria-label="Jumlah suara <?= e($candidate['nama']) ?>"></label><?php endforeach; ?>
        </div><label>Foto formulir hasil<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" required></label><p class="muted small">Foto JPG, PNG, atau WebP maksimal 10 MB.</p><button type="submit">Kirim hasil TPS</button></form><?php endif; ?></section>
    <?php endif; ?>
<?php endif; ?>
<?php if ($submittedTps !== []): ?><section class="section"><h2>TPS sudah dikirim</h2><div class="submitted-tps-list"><?php foreach ($submittedTps as $submitted): ?><div><span>TPS <?= e($submitted['kode']) ?> · <?= e($submitted['nama']) ?></span><?php $submittedOwner = false; if ($tps !== null && (int)$tps['id'] === (int)$submitted['id']) { $submittedOwner = $isOwnReport; } ?><strong><?= $submittedOwner ? 'Input saya' : 'Terkirim' ?></strong></div><?php endforeach; ?></div></section><?php endif; ?>
<?php page_end(); ?>
