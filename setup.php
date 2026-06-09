<?php

define('PLUGIN_TERMOS_VERSION', '1.0.0');

// Autoloader para carregar as classes do plugin
spl_autoload_register(function ($class) {
    if (strpos($class, 'PluginTermos') === 0) {
        // Converte PluginTermosTerm -> term, PluginTermosMenu -> menu, etc.
        $name = strtolower(str_replace('PluginTermos', '', $class));
        $file = __DIR__ . '/inc/' . $name . '.class.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

function plugin_init_termosresponsabilidade() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['termosresponsabilidade'] = true;

    $PLUGIN_HOOKS['menu_toadd']['termosresponsabilidade'] = [
        'tools' => 'PluginTermosMenu',
    ];
}

function plugin_version_termosresponsabilidade() {
    return [
        'name'           => 'Termos de Responsabilidade',
        'version'        => PLUGIN_TERMOS_VERSION,
        'author'         => 'TI',
        'license'        => 'GPL v2+',
        'homepage'       => '',
        'minGlpiVersion' => '10.0.0',
    ];
}

function plugin_termosresponsabilidade_check_prerequisites() {
    return true;
}

function plugin_termosresponsabilidade_check_config() {
    return true;
}
