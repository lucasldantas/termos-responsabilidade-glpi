-- ============================================================
-- Plugin: Termos de Responsabilidade para GLPI 11
-- Arquivo: sql/install.sql
-- Descrição: Cria a tabela principal do plugin
-- ============================================================

CREATE TABLE IF NOT EXISTS `glpi_plugin_termos_terms` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `computers_id`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `users_id`         INT UNSIGNED    NOT NULL DEFAULT 0,
    `entities_id`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `issuer_id`        INT UNSIGNED             DEFAULT NULL  COMMENT 'ID do usuário GLPI que emitiu o termo',
    `status`           ENUM('pending','signed','revoked') NOT NULL DEFAULT 'pending',
    `signature_data`   LONGTEXT                 DEFAULT NULL  COMMENT 'Assinatura em base64 (PNG)',
    `pdf_path`         VARCHAR(512)             DEFAULT NULL  COMMENT 'Caminho absoluto do PDF gerado',
    `pdf_hash`         VARCHAR(64)              DEFAULT NULL  COMMENT 'SHA-256 do PDF para auditoria',
    `sign_token`       VARCHAR(64)              DEFAULT NULL  COMMENT 'Token único para assinatura remota',
    `token_expires_at` DATETIME                 DEFAULT NULL  COMMENT 'Expiração do token de assinatura',
    `date_creation`    DATETIME                 DEFAULT NULL,
    `date_signed`      DATETIME                 DEFAULT NULL,
    `signer_ip`            VARCHAR(45)              DEFAULT NULL  COMMENT 'IP do signatário',
    `signer_agent`         VARCHAR(512)             DEFAULT NULL  COMMENT 'User-Agent do signatário',
    `devolution_date`      DATETIME                 DEFAULT NULL  COMMENT 'Data do termo de devolução',
    `devolution_pdf_path`  VARCHAR(512)             DEFAULT NULL  COMMENT 'Caminho do PDF de devolução',
    `devolution_pdf_hash`  VARCHAR(64)              DEFAULT NULL  COMMENT 'SHA-256 do PDF de devolução',
    PRIMARY KEY (`id`),
    KEY `computers_id`         (`computers_id`),
    KEY `users_id`             (`users_id`),
    KEY `entities_id`          (`entities_id`),
    KEY `issuer_id`            (`issuer_id`),
    KEY `status`               (`status`),
    KEY `sign_token`           (`sign_token`),
    KEY `pdf_hash`             (`pdf_hash`),
    KEY `devolution_pdf_hash`  (`devolution_pdf_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
