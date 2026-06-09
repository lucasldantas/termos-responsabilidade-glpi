<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginTermosAjax {

    public static function sign() {
        header('Content-Type: application/json');

        if (!isset($_SESSION['glpiID'])) {
            echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
            exit;
        }

        $input     = json_decode(file_get_contents('php://input'), true);
        $terms_id  = (int)($input['terms_id'] ?? 0);
        $signature = trim($input['signature'] ?? '');

        if (!$terms_id || empty($signature)) {
            echo json_encode(['success' => false, 'error' => 'Dados inválidos.']);
            exit;
        }

        try {
            PluginTermosTerm::signTerm($terms_id, $signature);

            $term = new PluginTermosTerm();
            $term->getFromDB($terms_id);

            echo json_encode([
                'success' => true,
                'pdf_url' => '/plugins/termosresponsabilidade/front/download.php?id=' . $terms_id,
                'hash'    => $term->fields['pdf_hash'],
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }

        exit;
    }
}
