<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginTermosMenu extends CommonGLPI {

    public static function getTypeName($nb = 0) {
        return 'Termos de Responsabilidade';
    }

    public static function getMenuName() {
        return 'Termos de Responsabilidade';
    }

    public static function getMenuContent() {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/termosresponsabilidade/front/term.php',
            'icon'  => 'fas fa-file-signature',
            'links' => [
                'search' => '/plugins/termosresponsabilidade/front/term.php',
                'add'    => '/plugins/termosresponsabilidade/front/term.form.php',
            ],
        ];

        return $menu;
    }
}
