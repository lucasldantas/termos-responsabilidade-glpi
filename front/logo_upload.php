<?php
/**
 * Endpoint dedicado para upload da logo.
 * Página separada para evitar interceptação do $_FILES pelo Symfony/GLPI 11.
 */

include('../../../inc/includes.php');
Session::checkLoginUser();

if (!Session::haveRight('config', READ)) {
    Html::displayErrorAndDie('Acesso negado.');
}

$configDir = __DIR__ . '/../config/';
$msg       = '';
$error     = '';

// Remove logo
if (isset($_GET['remove'])) {
    foreach (glob($configDir . 'logo.*') ?: [] as $f) { @unlink($f); }
    header('Location: settings.php?logo_removed=1');
    exit;
}

// Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Nenhum arquivo selecionado.';
    } elseif ($_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
        $erros = [1=>'Arquivo muito grande (php.ini)',2=>'Arquivo muito grande (form)',3=>'Upload incompleto',6=>'Sem pasta temp',7=>'Falha ao gravar'];
        $error = 'Erro no upload: ' . ($erros[$_FILES['logo_file']['error']] ?? 'Código ' . $_FILES['logo_file']['error']);
    } else {
        $tmp  = $_FILES['logo_file']['tmp_name'];
        $type = mime_content_type($tmp);
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($allowed[$type])) {
            $error = 'Formato inválido. Use PNG, JPG, GIF ou WEBP.';
        } else {
            foreach (glob($configDir . 'logo.*') ?: [] as $f) { @unlink($f); }
            $dest = $configDir . 'logo.' . $allowed[$type];
            if (move_uploaded_file($tmp, $dest)) {
                header('Location: settings.php?logo_saved=1');
                exit;
            } else {
                $error = 'Falha ao mover arquivo. Verifique permissões da pasta config/.';
            }
        }
    }
}

Html::header('Upload de Logo', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');

// Logo atual
$logoAtual = '';
foreach (['png','jpg','jpeg','gif','webp'] as $ext) {
    if (file_exists($configDir . 'logo.' . $ext)) { $logoAtual = $ext; break; }
}
?>

<div class="container" style="max-width:600px; margin:40px auto;">
    <h3><i class="fas fa-image"></i> Logo da Empresa</h3>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($logoAtual)): ?>
        <div style="background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:16px; margin-bottom:16px;">
            <p><strong>Logo atual:</strong></p>
            <img src="logo_serve.php?<?= time() ?>"
                 style="max-height:80px; max-width:300px; border:1px solid #eee; padding:6px; background:#f8f9fa; border-radius:4px;">
            <br>
            <a href="logo_upload.php?remove=1" onclick="return confirm('Remover a logo?')"
               class="btn btn-sm btn-danger" style="margin-top:10px;">🗑️ Remover Logo</a>
        </div>
    <?php endif; ?>

    <div style="background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:20px;">
        <form method="POST" action="logo_upload.php" enctype="multipart/form-data">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <label><strong>Selecionar arquivo:</strong></label>
            <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp"
                   class="form-control" style="margin:8px 0 6px;">
            <small class="text-muted">PNG, JPG, GIF ou WEBP. Recomendado: PNG com fundo transparente, altura ~60px.</small>
            <br><br>
            <button type="submit" class="btn btn-primary">📤 Enviar Logo</button>
            <a href="settings.php" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php Html::footer(); ?>
