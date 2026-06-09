<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    Html::displayErrorAndDie('ID inválido.');
}

$term = new PluginTermosTerm();
if (!$term->getFromDB($id)) {
    Html::displayErrorAndDie('Termo não encontrado.');
}

// Se veio com ?baixar=1, faz o download direto
if (isset($_GET['baixar'])) {
    if ($term->fields['status'] !== 'signed') {
        Html::displayErrorAndDie('Este termo ainda não foi assinado.');
    }

    $pdfPath = $term->fields['pdf_path'];

    if (empty($pdfPath) || !file_exists($pdfPath)) {
        Html::displayErrorAndDie('Arquivo PDF não encontrado no servidor.');
    }

    $currentHash = hash_file('sha256', $pdfPath);
    if ($currentHash !== $term->fields['pdf_hash']) {
        Html::displayErrorAndDie('⚠️ Integridade do arquivo comprometida.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="termo_responsabilidade_' . $id . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($pdfPath);
    exit;
}

// Busca dados para exibir na tela de confirmação
$data         = PluginTermosTerm::getComputerWithUser((int)$term->fields['computers_id']);
$nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '');
$dataAssinado = $term->fields['date_signed']
    ? date('d/m/Y \à\s H:i', strtotime($term->fields['date_signed']))
    : '-';

Html::header('Termo Assinado', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');
?>

<style>
.confirmacao-container {
    max-width: 600px;
    margin: 60px auto;
    padding: 0 20px;
    text-align: center;
    font-family: Arial, sans-serif;
}
.confirmacao-icone {
    font-size: 4em;
    color: #198754;
    margin-bottom: 15px;
}
.confirmacao-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 25px;
}
.confirmacao-card h2 {
    color: #198754;
    margin-top: 0;
}
.confirmacao-info {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 15px;
    margin: 20px 0;
    text-align: left;
}
.confirmacao-info table { width: 100%; border-collapse: collapse; }
.confirmacao-info th, .confirmacao-info td { padding: 6px 8px; font-size: 0.95em; }
.confirmacao-info th { color: #555; width: 40%; }
.confirmacao-acoes { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
</style>

<div class="confirmacao-container">
    <div class="confirmacao-card">
        <div class="confirmacao-icone">✅</div>
        <h2>Termo Assinado com Sucesso!</h2>
        <p>O termo de responsabilidade foi registrado e está juridicamente válido.</p>

        <div class="confirmacao-info">
            <table>
                <tr><th>Equipamento</th><td><?= htmlspecialchars($data['computer_name'] ?? '-') ?></td></tr>
                <tr><th>Responsável</th><td><?= htmlspecialchars($nomeCompleto) ?></td></tr>
                <tr><th>Assinado em</th><td><?= $dataAssinado ?></td></tr>
                <tr><th>Hash SHA-256</th><td style="font-size:0.75em; word-break:break-all;"><?= htmlspecialchars($term->fields['pdf_hash'] ?? '-') ?></td></tr>
            </table>
        </div>

        <div class="confirmacao-acoes">
            <a href="download.php?id=<?= $id ?>&baixar=1" class="btn btn-success">
                📄 Baixar PDF
            </a>
            <a href="term.php" class="btn btn-primary">
                📋 Voltar aos Termos
            </a>
        </div>
    </div>
</div>

<?php Html::footer(); ?>
