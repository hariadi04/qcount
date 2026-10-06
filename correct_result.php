<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$admin = require_role('admin');
$resultId = filter_var($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['result_id'] ?? null) : ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($resultId === false) {
    set_flash('error', 'Laporan hasil tidak ditemukan.');
    redirect_to('/admin.php');
}

$candidates = db()->query('SELECT id, nomor_urut, nama FROM calon_kades ORDER BY nomor_urut')->fetchAll();
$candidateLabels = [];
foreach ($candidates as $candidate) {
    $candidateLabels[(int) $candidate['id']] = 'No. ' . $candidate['nomor_urut'] . ' ' . $candidate['nama'];
}

$formatSnapshot = static function (?string $snapshot) use ($candidateLabels): string {
    if ($snapshot === null || $snapshot === '') {
        return 'Belum ada data sebelumnya';
    }
    try {
        $votes = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return 'Data snapshot tidak dapat dibaca';
    }
    $parts = [];
    foreach ($votes as $candidateId => $count) {
        $label = $candidateLabels[(int) $candidateId] ?? 'Calon dihapus';
        $parts[] = $label . ': ' . number_format((int) $count, 0, ',', '.') . ' suara';
    }
    return $parts === [] ? 'Belum ada data sebelumnya' : implode(' · ', $parts);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reason = trim((string) ($_POST['alasan'] ?? ''));
    if (strlen($reason) < 5 || strlen($reason) > 1000) {
        set_flash('error', 'Alasan koreksi wajib diisi, minimal 5 karakter dan maksimal 1.000 karakter.');
        redirect_to('/correct_result.php?id=' . $resultId);
    }

    $submittedVotes = $_POST['suara'] ?? null;
    $expectedIds = array_map(static fn(array $candidate): string => (string) $candidate['id'], $candidates);
    if ($candidates === [] || !is_array($submittedVotes) || count($submittedVotes) !== count($expectedIds) || array_diff($expectedIds, array_map('strval', array_keys($submittedVotes))) !== []) {
        set_flash('error', 'Masukkan jumlah suara untuk semua calon.');
        redirect_to('/correct_result.php?id=' . $resultId);
    }

    $votes = [];
    foreach ($candidates as $candidate) {
        $candidateId = (string) $candidate['id'];
        $value = $submittedVotes[$candidateId] ?? null;
        if (!is_string($value) || !preg_match('/^\d{1,10}$/D', $value) || (int) $value > 4294967295) {
            set_flash('error', 'Jumlah suara harus berupa bilangan bulat nol atau lebih.');
            redirect_to('/correct_result.php?id=' . $resultId);
        }
        $votes[(int) $candidate['id']] = (int) $value;
    }

    $newPhotoPath = null;
    try {
        $newPhotoPath = store_photo_upload($_FILES['foto'] ?? null);
    } catch (RuntimeException $error) {
        set_flash('error', $error->getMessage());
        redirect_to('/correct_result.php?id=' . $resultId);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT h.id, h.tps_id, h.relawan_id, h.foto_path, t.kode AS tps_kode, t.nama AS tps_nama FROM hasil_tps h JOIN tps t ON t.id = h.tps_id WHERE h.id = ? FOR UPDATE');
        $statement->execute([$resultId]);
        $result = $statement->fetch();
        if ($result === false) {
            throw new RuntimeException('Laporan hasil tidak ditemukan.');
        }

        $photoPath = $newPhotoPath ?? $result['foto_path'];
        if (!is_string($photoPath) || !is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $photoPath)) {
            throw new RuntimeException('Foto bukti belum tersedia atau filenya hilang. Unggah foto perhitungan untuk membandingkan sebelum koreksi.');
        }

        $statement = $pdo->prepare('SELECT calon_id, jumlah_suara FROM hasil_suara WHERE hasil_tps_id = ? FOR UPDATE');
        $statement->execute([$resultId]);
        $previousVotes = [];
        foreach ($statement->fetchAll() as $previousVote) {
            $previousVotes[(int) $previousVote['calon_id']] = (int) $previousVote['jumlah_suara'];
        }

        $statement = $pdo->prepare('DELETE FROM hasil_suara WHERE hasil_tps_id = ?');
        $statement->execute([$resultId]);
        $insertVote = $pdo->prepare('INSERT INTO hasil_suara (hasil_tps_id, calon_id, jumlah_suara) VALUES (?, ?, ?)');
        foreach ($votes as $candidateId => $amount) {
            $insertVote->execute([$resultId, $candidateId, $amount]);
        }

        $statement = $pdo->prepare('UPDATE hasil_tps SET foto_path = ? WHERE id = ?');
        $statement->execute([$photoPath, $resultId]);
        $statement = $pdo->prepare('INSERT INTO hasil_riwayat (hasil_tps_id, relawan_id, diubah_oleh, data_suara, data_sebelumnya, foto_path, alasan) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $resultId,
            $result['relawan_id'],
            $admin['id'],
            json_encode($votes, JSON_THROW_ON_ERROR),
            $previousVotes === [] ? null : json_encode($previousVotes, JSON_THROW_ON_ERROR),
            $photoPath,
            $reason,
        ]);
        $pdo->commit();
        set_flash('success', 'Hasil TPS berhasil dikoreksi. Nilai lama, nilai baru, alasan, foto, dan pelaku tersimpan di riwayat.');
        redirect_to('/correct_result.php?id=' . $resultId);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newPhotoPath !== null && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $newPhotoPath)) {
            unlink(UPLOAD_DIR . DIRECTORY_SEPARATOR . $newPhotoPath);
        }
        error_log((string) $error);
        set_flash('error', $error instanceof RuntimeException ? $error->getMessage() : 'Koreksi tidak berhasil disimpan. Coba lagi atau hubungi administrator.');
        redirect_to('/correct_result.php?id=' . $resultId);
    }
}

$statement = db()->prepare('SELECT h.id, h.relawan_id, h.foto_path, h.diperbarui_pada, t.kode AS tps_kode, t.nama AS tps_nama, u.nama AS nama_relawan FROM hasil_tps h JOIN tps t ON t.id = h.tps_id JOIN users u ON u.id = h.relawan_id WHERE h.id = ?');
$statement->execute([$resultId]);
$result = $statement->fetch();
if ($result === false) {
    set_flash('error', 'Laporan hasil tidak ditemukan.');
    redirect_to('/admin.php');
}

$statement = db()->prepare('SELECT calon_id, jumlah_suara FROM hasil_suara WHERE hasil_tps_id = ?');
$statement->execute([$resultId]);
$savedVotes = [];
foreach ($statement->fetchAll() as $row) {
    $savedVotes[(int) $row['calon_id']] = (int) $row['jumlah_suara'];
}
$statement = db()->prepare('SELECT r.data_suara, r.data_sebelumnya, r.foto_path, r.alasan, r.disimpan_pada, actor.nama AS actor_name, actor.role AS actor_role FROM hasil_riwayat r JOIN users actor ON actor.id = r.diubah_oleh WHERE r.hasil_tps_id = ? ORDER BY r.id DESC LIMIT 30');
$statement->execute([$resultId]);
$history = $statement->fetchAll();

page_start('Koreksi hasil TPS');
?>
<div class="eyebrow">Panel admin · <?= e($result['tps_kode']) ?></div>
<h1>Koreksi hasil TPS</h1>
<p class="muted"><?= e($result['tps_nama']) ?> · Input relawan: <?= e($result['nama_relawan']) ?> · Terakhir diperbarui: <?= e($result['diperbarui_pada']) ?></p>
<div class="grid correction-grid">
    <section class="section evidence-panel">
        <h2>Foto bukti perhitungan</h2>
        <?php if (is_string($result['foto_path']) && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $result['foto_path'])): ?>
            <a href="<?= APP_BASE_PATH ?>/photo.php?id=<?= e($result['id']) ?>" target="_blank" rel="noopener"><img class="evidence-image" src="<?= APP_BASE_PATH ?>/photo.php?id=<?= e($result['id']) ?>" alt="Foto bukti hasil perhitungan TPS <?= e($result['tps_kode']) ?>"></a>
        <?php else: ?>
            <p class="notice error">Foto belum tersedia untuk laporan ini. Unggah foto perhitungan pada form koreksi sebelum menyimpan.</p>
        <?php endif; ?>
    </section>
    <section class="section">
        <h2>Angka hasil saat ini</h2>
        <div class="table-wrap"><table><thead><tr><th>Calon</th><th>Suara tersimpan</th></tr></thead><tbody>
            <?php foreach ($candidates as $candidate): ?><tr><td>No. <?= e($candidate['nomor_urut']) ?> · <?= e($candidate['nama']) ?></td><td><?= number_format((int) ($savedVotes[(int) $candidate['id']] ?? 0), 0, ',', '.') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<section class="section">
    <h2>Perbarui hasil</h2>
    <p class="muted">Bandingkan angka dengan foto bukti terlebih dahulu. Setiap koreksi memerlukan alasan dan akan tercatat di riwayat.</p>
    <form class="form-stack" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="result_id" value="<?= e($result['id']) ?>">
        <div class="table-wrap"><table><thead><tr><th>No. calon</th><th>Nama calon</th><th>Suara sekarang</th><th>Suara koreksi</th></tr></thead><tbody>
        <?php foreach ($candidates as $candidate): ?><tr><td><?= e($candidate['nomor_urut']) ?></td><td><?= e($candidate['nama']) ?></td><td><?= number_format((int) ($savedVotes[(int) $candidate['id']] ?? 0), 0, ',', '.') ?></td><td><input class="num" type="number" name="suara[<?= e($candidate['id']) ?>]" min="0" max="4294967295" step="1" value="<?= e($savedVotes[(int) $candidate['id']] ?? 0) ?>" required aria-label="Jumlah suara koreksi untuk <?= e($candidate['nama']) ?>"></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <label>Alasan koreksi<textarea name="alasan" rows="3" maxlength="1000" minlength="5" required></textarea></label>
        <label>Ganti atau lampirkan foto bukti (JPG, PNG, WebP; maksimal 10 MB)
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" <?= is_string($result['foto_path']) && is_file(UPLOAD_DIR . DIRECTORY_SEPARATOR . $result['foto_path']) ? '' : 'required' ?>>
        </label>
        <button type="submit">Simpan koreksi hasil</button>
    </form>
</section>
<section class="section">
    <h2>Riwayat perubahan</h2>
    <?php if ($history === []): ?><p class="muted">Belum ada riwayat.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Pelaku</th><th>Alasan</th><th>Sebelum</th><th>Sesudah</th></tr></thead><tbody>
    <?php foreach ($history as $entry): ?><tr><td><?= e($entry['disimpan_pada']) ?></td><td><?= e($entry['actor_name']) ?> (<?= e($entry['actor_role']) ?>)</td><td><?= e($entry['alasan'] ?? 'Riwayat lama') ?></td><td><?= e($formatSnapshot($entry['data_sebelumnya'])) ?></td><td><?= e($formatSnapshot($entry['data_suara'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<p><a href="<?= APP_BASE_PATH ?>/admin.php">Kembali ke dashboard admin</a></p>
<?php page_end(); ?>
