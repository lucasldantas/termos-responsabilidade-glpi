<?php

function plugin_termosresponsabilidade_install() {
    global $DB;

    $sqlFile = __DIR__ . '/sql/install.sql';
    if (!file_exists($sqlFile)) {
        return false;
    }

    $sql = file_get_contents($sqlFile);
    // Executa cada statement separado por ;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if (!empty($statement)) {
            $DB->query($statement);
        }
    }

    return true;
}

function plugin_termosresponsabilidade_uninstall() {
    global $DB;
    $DB->query('DROP TABLE IF EXISTS `glpi_plugin_termos_terms`');
    return true;
}
