CREATE TABLE IF NOT EXISTS tps (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kode VARCHAR(20) NOT NULL,
    nama VARCHAR(120) NOT NULL,
    alamat VARCHAR(255) NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tps_kode (kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama VARCHAR(120) NOT NULL,
    username VARCHAR(40) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'relawan') NOT NULL,
    tps_id INT UNSIGNED NULL,
    aktif TINYINT(1) NOT NULL DEFAULT 1,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    CONSTRAINT fk_users_tps FOREIGN KEY (tps_id) REFERENCES tps (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calon_kades (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nomor_urut SMALLINT UNSIGNED NOT NULL,
    nama VARCHAR(120) NOT NULL,
    foto_path VARCHAR(64) NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calon_nomor_urut (nomor_urut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hasil_tps (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tps_id INT UNSIGNED NOT NULL,
    relawan_id INT UNSIGNED NOT NULL,
    foto_path VARCHAR(64) NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    diperbarui_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hasil_tps (tps_id),
    CONSTRAINT fk_hasil_tps_tps FOREIGN KEY (tps_id) REFERENCES tps (id) ON DELETE RESTRICT,
    CONSTRAINT fk_hasil_tps_relawan FOREIGN KEY (relawan_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hasil_suara (
    hasil_tps_id INT UNSIGNED NOT NULL,
    calon_id INT UNSIGNED NOT NULL,
    jumlah_suara INT UNSIGNED NOT NULL,
    PRIMARY KEY (hasil_tps_id, calon_id),
    CONSTRAINT fk_hasil_suara_hasil FOREIGN KEY (hasil_tps_id) REFERENCES hasil_tps (id) ON DELETE CASCADE,
    CONSTRAINT fk_hasil_suara_calon FOREIGN KEY (calon_id) REFERENCES calon_kades (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hasil_riwayat (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hasil_tps_id INT UNSIGNED NOT NULL,
    relawan_id INT UNSIGNED NOT NULL,
    diubah_oleh INT UNSIGNED NOT NULL,
    data_suara LONGTEXT NOT NULL,
    data_sebelumnya LONGTEXT NULL,
    foto_path VARCHAR(64) NULL,
    alasan VARCHAR(1000) NULL,
    disimpan_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_riwayat_hasil_waktu (hasil_tps_id, disimpan_pada),
    KEY idx_riwayat_pengubah (diubah_oleh),
    CONSTRAINT fk_riwayat_hasil FOREIGN KEY (hasil_tps_id) REFERENCES hasil_tps (id) ON DELETE CASCADE,
    CONSTRAINT fk_riwayat_relawan FOREIGN KEY (relawan_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_riwayat_pengubah FOREIGN KEY (diubah_oleh) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
