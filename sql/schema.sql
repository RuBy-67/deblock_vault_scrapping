-- Monitoring noeud EURCV (ERC-20 Transfer logs)
-- MySQL 8+ recommandé (utf8mb4)
-- Créer la base dans phpMyAdmin puis importer ce script (ou préciser USE votredb;)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Métadonnées de synchronisation
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_state (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  key_name        VARCHAR(64)  NOT NULL,
  last_block_scanned BIGINT UNSIGNED NOT NULL DEFAULT 0,
  source          VARCHAR(32)  NOT NULL DEFAULT 'rpc',
  last_error      TEXT NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_key (key_name)
) ENGINE=InnoDB;

INSERT IGNORE INTO sync_state (key_name, last_block_scanned, source)
VALUES ('main', 0, 'rpc');

-- ---------------------------------------------------------------------------
-- Coût gas par transaction (une ligne par tx, partagée si plusieurs logs)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tx_gas (
  tx_hash           CHAR(66) NOT NULL,
  block_number      BIGINT UNSIGNED NOT NULL,
  gas_used          BIGINT UNSIGNED NOT NULL,
  effective_gas_price_wei VARCHAR(80) NOT NULL,
  cost_eth          DECIMAL(38, 18) NOT NULL,
  PRIMARY KEY (tx_hash),
  KEY idx_tx_gas_block (block_number)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Transferts bruts (vérité on-chain)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS raw_transfers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tx_hash         CHAR(66) NOT NULL,
  log_index       INT UNSIGNED NOT NULL,
  block_number    BIGINT UNSIGNED NOT NULL,
  block_time      DATETIME NOT NULL,
  token_contract  CHAR(42) NOT NULL,
  from_addr       CHAR(42) NOT NULL,
  to_addr         CHAR(42) NOT NULL,
  amount_raw      VARCHAR(80) NOT NULL COMMENT 'uint256 as string',
  direction       ENUM('in', 'out') NOT NULL COMMENT 'relatif au noeud surveillé',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tx_log (tx_hash, log_index),
  KEY idx_rt_block_time (block_time),
  KEY idx_rt_from (from_addr),
  KEY idx_rt_to (to_addr),
  KEY idx_rt_direction_time (direction, block_time)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Classification (une ligne par transfert brut)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classified_events (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  raw_transfer_id      BIGINT UNSIGNED NOT NULL,
  counterparty         CHAR(42) NOT NULL,
  event_type           ENUM('interest', 'payment', 'top_up', 'unknown') NOT NULL,
  paired_transfer_id   BIGINT UNSIGNED NULL,
  fee_token_raw        VARCHAR(80) NULL COMMENT 'estimation écart IN/OUT en plus petite unité',
  rule_version         VARCHAR(32) NOT NULL DEFAULT 'v1',
  confidence           TINYINT UNSIGNED NOT NULL DEFAULT 50 COMMENT '0-100',
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_raw (raw_transfer_id),
  KEY idx_class_cp_time (counterparty),
  KEY idx_class_type (event_type),
  CONSTRAINT fk_class_raw FOREIGN KEY (raw_transfer_id) REFERENCES raw_transfers (id) ON DELETE CASCADE,
  CONSTRAINT fk_class_paired FOREIGN KEY (paired_transfer_id) REFERENCES raw_transfers (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Snapshots de solde (optionnel)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_estimates (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  address         CHAR(42) NOT NULL,
  as_of           DATETIME NOT NULL,
  balance_raw     VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_we_addr_time (address, as_of)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
