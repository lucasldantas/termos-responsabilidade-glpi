<?php

function plugin_termosresponsabilidade_install() {
    // Tabela criada manualmente via SQL
    return true;
}

function plugin_termosresponsabilidade_uninstall() {
    global $DB;
    // Usa doQuery que é permitido internamente
    $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_termos_terms`');
    return true;
}
