ALTER TABLE hasil_tps
    DROP FOREIGN KEY fk_hasil_arsip_admin,
    DROP INDEX fk_hasil_arsip_admin,
    DROP INDEX idx_hasil_arsip,
    DROP COLUMN alasan_arsip,
    DROP COLUMN diarsipkan_oleh,
    DROP COLUMN diarsipkan_pada;
