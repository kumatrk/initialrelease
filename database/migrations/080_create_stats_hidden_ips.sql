-- Account-wide IPs omitted from stats views / aggregates (not traffic blocking).
CREATE TABLE IF NOT EXISTS stats_hidden_ips (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stats_hidden_ips_ip (ip),
    KEY idx_stats_hidden_ips_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
