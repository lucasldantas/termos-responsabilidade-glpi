-- ============================================================
-- Migração: adiciona suporte a Celulares e Linhas Telefônicas
-- Execute apenas se o plugin já estiver instalado
-- ============================================================

ALTER TABLE `glpi_plugin_termos_terms`
    ADD COLUMN IF NOT EXISTS `item_type`  VARCHAR(20)   NOT NULL DEFAULT 'Computer'
        COMMENT 'Computer ou Phone' AFTER `status`,
    ADD COLUMN IF NOT EXISTS `phones_id`  INT UNSIGNED  NOT NULL DEFAULT 0
        COMMENT 'ID em glpi_phones (quando item_type=Phone)' AFTER `item_type`,
    ADD COLUMN IF NOT EXISTS `lines_id`   INT UNSIGNED  NOT NULL DEFAULT 0
        COMMENT 'ID em glpi_lines (linha telefônica vinculada)' AFTER `phones_id`,
    ADD KEY IF NOT EXISTS `phones_id` (`phones_id`),
    ADD KEY IF NOT EXISTS `lines_id`  (`lines_id`);
