ALTER TABLE hasil_riwayat
    ADD COLUMN diubah_oleh INT UNSIGNED NULL AFTER relawan_id,
    ADD COLUMN alasan VARCHAR(1000) NULL AFTER foto_path;

UPDATE hasil_riwayat
SET diubah_oleh = relawan_id
WHERE diubah_oleh IS NULL;

ALTER TABLE hasil_riwayat
    MODIFY COLUMN diubah_oleh INT UNSIGNED NOT NULL,
    ADD KEY idx_riwayat_pengubah (diubah_oleh),
    ADD CONSTRAINT fk_riwayat_pengubah FOREIGN KEY (diubah_oleh) REFERENCES users (id) ON DELETE RESTRICT;
