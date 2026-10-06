ALTER TABLE hasil_tps
    ADD COLUMN foto_path VARCHAR(64) NULL AFTER relawan_id;

ALTER TABLE hasil_riwayat
    ADD COLUMN foto_path VARCHAR(64) NULL AFTER data_suara;