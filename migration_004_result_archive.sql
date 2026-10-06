ALTER TABLE hasil_tps
    ADD COLUMN diarsipkan_pada TIMESTAMP NULL DEFAULT NULL AFTER foto_path,
    ADD COLUMN diarsipkan_oleh INT UNSIGNED NULL AFTER diarsipkan_pada,
    ADD COLUMN alasan_arsip VARCHAR(1000) NULL AFTER diarsipkan_oleh,
    ADD KEY idx_hasil_arsip (diarsipkan_pada),
    ADD CONSTRAINT fk_hasil_arsip_admin FOREIGN KEY (diarsipkan_oleh) REFERENCES users (id) ON DELETE RESTRICT;
