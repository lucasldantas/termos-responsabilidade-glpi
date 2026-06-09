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

if (!in_array($term->fields['status'], ['signed', 'revoked'])) {
    Html::displayErrorAndDie('Só é possível gerar devolução de termos assinados.');
}

global $DB;

$itemType = $term->fields['item_type'] ?? 'Computer';

// ── 1. Gera PDF PRIMEIRO (antes de qualquer outra ação) ──────────────────────
if (empty($term->fields['devolution_pdf_path']) || !file_exists($term->fields['devolution_pdf_path'])) {
    $data    = PluginTermosTerm::getItemWithUser($term);
    $pdfGen  = new PluginTermosPdf();
    $pdfPath = $pdfGen->generateDevolution($data, $id, $term->fields, (int)($_SESSION['glpiID'] ?? 0));
    $hash    = hash_file('sha256', $pdfPath);

    $DB->update('glpi_plugin_termos_terms', [
        'devolution_date'     => date('Y-m-d H:i:s'),
        'devolution_pdf_path' => $pdfPath,
        'devolution_pdf_hash' => $hash,
        'status'              => 'revoked',
    ], ['id' => $id]);

    // Recarrega com os dados atualizados do banco
    $term->getFromDB($id);
}

// ── 2. Download direto do PDF ─────────────────────────────────────────────────
if (isset($_GET['baixar'])) {
    $pdfPath = $term->fields['devolution_pdf_path'] ?? '';
    if (empty($pdfPath) || !file_exists($pdfPath)) {
        Html::displayErrorAndDie('Arquivo PDF de devolução não encontrado no servidor.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="devolucao_' . $id . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($pdfPath);
    exit;
}

// ── 3. Processa envio de e-mail (POST) ───────────────────────────────────────
$emailMsg = '';
$emailErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    $emailTo = trim($_POST['email_destino'] ?? '');
    if (empty($emailTo) || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
        $emailErr = 'Endereço de e-mail inválido.';
    } else {
        try {
            $data         = PluginTermosTerm::getItemWithUser($term);
            $nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '');
            $pdfPath      = $term->fields['devolution_pdf_path'];

            if (empty($pdfPath) || !file_exists($pdfPath)) {
                throw new \RuntimeException('Arquivo PDF não encontrado no servidor.');
            }

            global $CFG_GLPI;
            $fromEmail = $CFG_GLPI['admin_email']      ?? '';
            $fromName  = $CFG_GLPI['admin_email_name'] ?? 'Sistema GLPI';
            if (empty($fromName)) $fromName = 'Sistema GLPI';

            $mmail = new GLPIMailer();
            if (!empty($fromEmail)) {
                $mmail->setFrom($fromEmail, $fromName);
            }
            $mmail->addAddress($emailTo, '');
            $mmail->Subject = 'Termo de Devolucao - ' . ($data['computer_name'] ?? '');
            $mmail->isHTML(true);
            $mmail->Body = '<p>Ola!</p>'
                . '<p>Segue em anexo o <strong>Termo de Devolucao</strong> do equipamento '
                . '<strong>' . htmlspecialchars($data['computer_name'] ?? '') . '</strong>.</p>'
                . '<p><strong>Responsavel:</strong> ' . htmlspecialchars($nomeCompleto) . '</p>'
                . '<p><em>Este e-mail foi gerado automaticamente pelo sistema GLPI.</em></p>';
            $mmail->AltBody = strip_tags($mmail->Body);
            $mmail->addAttachment($pdfPath, 'devolucao_' . $id . '.pdf');

            if (!$mmail->send()) {
                throw new \RuntimeException($mmail->ErrorInfo);
            }
            $emailMsg = 'E-mail enviado com sucesso para ' . htmlspecialchars($emailTo) . '!';
        } catch (\Throwable $e) {
            $emailErr = 'Erro ao enviar e-mail: ' . $e->getMessage();
        }
    }
}

// ── 4. Processa atualização de inventário (POST) ─────────────────────────────
$inventMsg = '';
$inventErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_inventory'])) {
    try {
        $itemId      = $itemType === 'Phone' ? (int)$term->fields['phones_id'] : (int)$term->fields['computers_id'];
        $newState    = (int)($_POST['states_id']    ?? 0);
        $newLocation = (int)($_POST['locations_id'] ?? 0);
        $newUser     = (int)($_POST['users_id']     ?? 0);

        $updateData = [
            'id'           => $itemId,
            'users_id'     => $newUser,
            'states_id'    => $newState,
            'locations_id' => $newLocation,
        ];

        if (count($updateData) > 1) {
            $item = $itemType === 'Phone' ? new Phone() : new Computer();
            $item->update($updateData);
            $inventMsg = 'Inventário atualizado com sucesso!';
        } else {
            $inventMsg = 'Nenhuma alteração selecionada.';
        }
    } catch (\Throwable $e) {
        $inventErr = 'Erro ao atualizar: ' . $e->getMessage();
    }
}

// ── 5. Dados para exibição ────────────────────────────────────────────────────
$data         = PluginTermosTerm::getItemWithUser($term);
$nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '-');
$dataDevol    = !empty($term->fields['devolution_date'])
    ? date('d/m/Y \à\s H:i', strtotime($term->fields['devolution_date']))
    : date('d/m/Y \à\s H:i');

// Busca e-mail padrão do usuário no GLPI
$emailPadrao = '';
if (!empty($term->fields['users_id'])) {
    $userObj = new User();
    if ($userObj->getFromDB((int)$term->fields['users_id'])) {
        $emailPadrao = $userObj->getDefaultEmail() ?? '';
    }
}

// Dados atuais do item (status, localização e usuário)
$computerRow = [];
$itemTable = $itemType === 'Phone' ? 'glpi_phones' : 'glpi_computers';
$itemId    = $itemType === 'Phone' ? (int)$term->fields['phones_id'] : (int)$term->fields['computers_id'];
$iterComp = $DB->request([
    'SELECT' => ['states_id', 'locations_id', 'users_id'],
    'FROM'   => $itemTable,
    'WHERE'  => ['id' => $itemId],
    'LIMIT'  => 1,
]);
foreach ($iterComp as $r) { $computerRow = $r; }

// Lista de status
$iterStates = $DB->request(['SELECT' => ['id','name'], 'FROM' => 'glpi_states', 'ORDERBY' => ['name ASC']]);
$states = [];
foreach ($iterStates as $r) { $states[(int)$r['id']] = $r['name']; }

// Lista de localizações
$iterLoc = $DB->request(['SELECT' => ['id','completename'], 'FROM' => 'glpi_locations', 'ORDERBY' => ['completename ASC']]);
$locations = [];
foreach ($iterLoc as $r) { $locations[(int)$r['id']] = $r['completename']; }

// Lista de usuários ativos
$iterUsers = $DB->request([
    'SELECT'  => ['id', 'name', 'firstname', 'realname'],
    'FROM'    => 'glpi_users',
    'WHERE'   => ['is_deleted' => 0, 'is_active' => 1],
    'ORDERBY' => ['realname ASC', 'firstname ASC'],
]);
$usersList = [];
foreach ($iterUsers as $r) {
    $nome = trim($r['firstname'] . ' ' . $r['realname']) ?: $r['name'];
    $usersList[(int)$r['id']] = $nome . ' (' . $r['name'] . ')';
}

Html::header('Termo de Devolução', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');
?>

<style>
.devol-wrap  { max-width:640px; margin:40px auto; padding:0 16px; font-family:Arial,sans-serif; }
.devol-card  { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:22px; margin-bottom:18px; box-shadow:0 1px 5px rgba(0,0,0,0.07); }
.devol-card h4 { margin-top:0; padding-bottom:8px; border-bottom:1px solid #eee; }
.devol-table { width:100%; border-collapse:collapse; }
.devol-table th { text-align:left; color:#555; width:42%; padding:5px 4px; font-size:0.92em; }
.devol-table td { padding:5px 4px; font-size:0.92em; word-break:break-all; }
.devol-acoes { display:flex; gap:12px; flex-wrap:wrap; margin-top:14px; }
.alert { padding:11px 15px; border-radius:4px; margin-bottom:14px; }
.alert-success { background:#d1e7dd; color:#0f5132; border:1px solid #badbcc; }
.alert-danger  { background:#f8d7da; color:#842029; border:1px solid #f5c2c7; }
.alert-info    { background:#cfe2ff; color:#084298; border:1px solid #b6d4fe; font-size:0.88em; }
</style>

<div class="devol-wrap">

    <!-- Confirmação -->
    <div class="devol-card">
        <div style="text-align:center; margin-bottom:12px;">
            <div style="font-size:2.8em;">📦</div>
            <h3 style="margin:6px 0;">Termo de Devolução</h3>
            <span style="color:#198754; font-size:0.9em;">✅ Gerado com sucesso</span>
        </div>

        <div style="background:#f8f9fa; border-radius:6px; padding:14px; margin:12px 0;">
            <table class="devol-table">
                <tr><th>Equipamento</th><td><?= htmlspecialchars($data['computer_name'] ?? '-') ?></td></tr>
                <tr><th>Número de Série</th><td><?= htmlspecialchars($data['serial'] ?? '-') ?></td></tr>
                <?php if ($itemType === 'Phone' && ($data['line_number'] ?? '-') !== '-'): ?>
                <tr><th>Linha Telefônica</th><td><?= htmlspecialchars($data['line_number']) ?></td></tr>
                <?php endif; ?>
                <tr><th>Responsável</th><td><?= htmlspecialchars($nomeCompleto) ?></td></tr>
                <tr><th>Data da Devolução</th><td><?= $dataDevol ?></td></tr>
                <tr><th>Hash SHA-256</th><td style="font-size:0.72em;"><?= htmlspecialchars($term->fields['devolution_pdf_hash'] ?? '-') ?></td></tr>
            </table>
        </div>

        <div class="devol-acoes">
            <a href="devolution.php?id=<?= $id ?>&baixar=1" class="btn btn-secondary">
                📄 Baixar PDF
            </a>
            <a href="term.php" class="btn btn-primary">
                📋 Voltar aos Termos
            </a>
        </div>
    </div>

    <!-- Envio por e-mail -->
    <div class="devol-card">
        <h4 style="color:#0d6efd;">📧 Enviar por E-mail</h4>

        <?php if (!empty($emailMsg)): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($emailMsg) ?></div>
        <?php endif; ?>
        <?php if (!empty($emailErr)): ?>
            <div class="alert alert-danger">❌ <?= htmlspecialchars($emailErr) ?></div>
        <?php endif; ?>

        <?php if (!empty($emailPadrao)): ?>
            <div class="alert alert-info">
                📌 E-mail cadastrado do usuário no GLPI: <strong><?= htmlspecialchars($emailPadrao) ?></strong><br>
                Você pode alterar para outro endereço caso o colaborador já não esteja na empresa.
            </div>
        <?php else: ?>
            <div class="alert alert-danger" style="font-size:0.88em;">
                ⚠️ Usuário sem e-mail cadastrado no GLPI. Informe o endereço manualmente.
            </div>
        <?php endif; ?>

        <form method="POST" action="devolution.php?id=<?= $id ?>">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>

            <div style="margin-bottom:14px;">
                <label for="email_destino"><strong>Destinatário:</strong></label>
                <input type="email" id="email_destino" name="email_destino"
                    value="<?= htmlspecialchars($emailPadrao) ?>"
                    placeholder="email@empresa.com"
                    style="display:block; width:100%; margin-top:6px; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:0.95em; box-sizing:border-box;">
            </div>

            <button type="submit" name="send_email" class="btn btn-info">
                📧 Enviar PDF por E-mail
            </button>
        </form>
    </div>

    <!-- Atualização de inventário -->
    <div class="devol-card">
        <h4 style="color:#6c757d;"><?= $itemType === 'Phone' ? '📱' : '🖥️' ?> Deseja alterar o status <?= $itemType === 'Phone' ? 'desse telefone' : 'dessa máquina' ?> no inventário?</h4>

        <?php if (!empty($inventMsg)): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($inventMsg) ?></div>
        <?php endif; ?>
        <?php if (!empty($inventErr)): ?>
            <div class="alert alert-danger">❌ <?= htmlspecialchars($inventErr) ?></div>
        <?php endif; ?>

        <form method="POST" action="devolution.php?id=<?= $id ?>">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>

            <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
                <div style="flex:1; min-width:180px;">
                    <label><strong>Status:</strong></label>
                    <select name="states_id" class="form-control" style="width:100%; margin-top:6px;">
                        <?php foreach ($states as $sid => $slabel): ?>
                            <option value="<?= $sid ?>" <?= ($computerRow['states_id'] ?? 0) == $sid ? 'selected' : '' ?>>
                                <?= htmlspecialchars($slabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1; min-width:180px;">
                    <label><strong>Localização:</strong></label>
                    <select name="locations_id" class="form-control" style="width:100%; margin-top:6px;">
                        <?php foreach ($locations as $lid => $llabel): ?>
                            <option value="<?= $lid ?>" <?= ($computerRow['locations_id'] ?? 0) == $lid ? 'selected' : '' ?>>
                                <?= htmlspecialchars($llabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label><strong>Usuário Responsável:</strong></label>
                <select name="users_id" id="sel-user-devol" class="form-control select2" style="width:100%; margin-top:6px;">
                    <option value="0">— Sem usuário (remover vínculo) —</option>
                    <?php foreach ($usersList as $uid => $ulabel): ?>
                        <option value="<?= $uid ?>" <?= ($computerRow['users_id'] ?? 0) == $uid ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ulabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Selecione vazio para desvincular o usuário da máquina.</small>
            </div>

            <button type="submit" name="update_inventory" class="btn btn-secondary">
                🖥️ Atualizar Inventário
            </button>
        </form>
    </div>

</div>

<script>
$(document).ready(function() {
    $('#sel-user-devol').select2({
        placeholder: '— Sem usuário (remover vínculo) —',
        allowClear: true,
        width: '100%',
        language: {
            noResults: function() { return 'Nenhum usuário encontrado.'; },
            searching: function()  { return 'Buscando...'; },
        },
    });
});
</script>

<?php Html::footer(); ?>
