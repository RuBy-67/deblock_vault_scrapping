-- Optimisations d'index pour base existante (compatible MySQL/MariaDB sans IF NOT EXISTS sur ADD INDEX)
-- Le script vérifie l'existence de l'index avant de l'ajouter.

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'raw_transfers'
    AND index_name = 'idx_rt_tx_hash'
);
SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE raw_transfers ADD INDEX idx_rt_tx_hash (tx_hash)',
  'SELECT ''idx_rt_tx_hash déjà présent'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'classified_events'
    AND index_name = 'idx_class_raw_cp'
);
SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE classified_events ADD INDEX idx_class_raw_cp (raw_transfer_id, counterparty)',
  'SELECT ''idx_class_raw_cp déjà présent'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
