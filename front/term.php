<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// ── AJAX: verificar hash de documento ────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'verify_hash') {
    header('Content-Type: application/json');

    $hash = trim($_GET['hash'] ?? '');
    if (empty($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
        echo json_encode(['valid' => false, 'error' => 'Hash inválido.']);
        exit;
    }

    global $DB;
    $hash = strtolower($hash);

    $joins = [
        'glpi_computers AS c' => ['ON' => ['t' => 'computers_id', 'c' => 'id']],
        'glpi_users AS u'     => ['ON' => ['t' => 'users_id',     'u' => 'id']],
        'glpi_entities AS e'  => ['ON' => ['t' => 'entities_id',  'e' => 'id']],
        'glpi_users AS i'     => ['ON' => ['t' => 'issuer_id',    'i' => 'id']],
    ];
    $select = [
        't.id', 't.status', 't.date_creation', 't.date_signed',
        'c.name AS computer_name', 'c.serial',
        'u.firstname', 'u.realname', 'u.name AS username',
        'e.completename AS entity',
        'i.firstname AS issuer_firstname', 'i.realname AS issuer_realname', 'i.name AS issuer_username',
    ];

    // Tenta primeiro pelo hash do termo de responsabilidade
    $it  = $DB->request(['SELECT' => $select, 'FROM' => ['glpi_plugin_termos_terms AS t'],
                         'LEFT JOIN' => $joins, 'WHERE' => ['t.pdf_hash' => $hash], 'LIMIT' => 1]);
    $row = $it->current();
    $docType = 'Termo de Responsabilidade';

    // Se não achou, tenta pelo hash do termo de devolução
    if (!$row) {
        $it  = $DB->request(['SELECT' => $select, 'FROM' => ['glpi_plugin_termos_terms AS t'],
                             'LEFT JOIN' => $joins, 'WHERE' => ['t.devolution_pdf_hash' => $hash], 'LIMIT' => 1]);
        $row = $it->current();
        $docType = 'Termo de Devolução';
    }

    if (!$row) {
        echo json_encode(['valid' => false, 'msg' => 'Nenhum documento encontrado com este hash. O arquivo pode ter sido adulterado ou não pertence a este sistema.']);
        exit;
    }

    $nomeResponsavel = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? '')) ?: ($row['username'] ?? '-');
    $nomeEmissor     = trim(($row['issuer_firstname'] ?? '') . ' ' . ($row['issuer_realname'] ?? '')) ?: ($row['issuer_username'] ?? '-');

    echo json_encode([
        'valid'          => true,
        'doc_type'       => $docType,
        'id'             => (int)$row['id'],
        'status'         => $row['status'],
        'computer'       => $row['computer_name'] ?? '-',
        'serial'         => $row['serial'] ?? '-',
        'entity'         => $row['entity'] ?? '-',
        'responsavel'    => $nomeResponsavel,
        'emissor'        => $nomeEmissor,
        'date_creation'  => $row['date_creation'] ? date('d/m/Y H:i', strtotime($row['date_creation'])) : '-',
        'date_signed'    => $row['date_signed']   ? date('d/m/Y H:i', strtotime($row['date_signed']))   : '-',
        'hash'           => $hash,
    ]);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

$msg_success = '';
$msg_error   = '';

if (($_GET['msg'] ?? '') === 'link_sent') {
    $msg_success = 'Termo criado e link de assinatura enviado por e-mail com sucesso!';
}

// ── Ações em massa (POST) ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $ids    = array_map('intval', $_POST['ids'] ?? []);
    $action = $_POST['bulk_action'];

    if (!empty($ids)) {
        if ($action === 'delete' && Session::haveRight('config', READ)) {
            foreach ($ids as $delId) {
                $t = new PluginTermosTerm();
                if ($t->getFromDB($delId)) {
                    if (!empty($t->fields['pdf_path']) && file_exists($t->fields['pdf_path'])) {
                        unlink($t->fields['pdf_path']);
                    }
                    $t->delete(['id' => $delId], true);
                }
            }
            $msg_success = count($ids) . ' termo(s) excluído(s).';
        } elseif ($action === 'send_link') {
            $ok = $err = 0;
            foreach ($ids as $lid) {
                try {
                    $t = new PluginTermosTerm();
                    if ($t->getFromDB($lid) && $t->fields['status'] === 'pending') {
                        PluginTermosTerm::sendSignEmail($lid);
                        $ok++;
                    }
                } catch (\Throwable $e) { $err++; }
            }
            $msg_success = "Link enviado para {$ok} termo(s).";
            if ($err) $msg_error = "{$err} envio(s) falharam.";
        } elseif ($action === 'resend_email') {
            $ok = $err = 0;
            foreach ($ids as $rid) {
                try {
                    $t = new PluginTermosTerm();
                    if ($t->getFromDB($rid) && $t->fields['status'] === 'signed') {
                        PluginTermosTerm::sendTermEmail($rid);
                        $ok++;
                    }
                } catch (\Throwable $e) { $err++; }
            }
            $msg_success = "E-mail reenviado para {$ok} termo(s).";
            if ($err) $msg_error = "{$err} envio(s) falharam.";
        }
    }
}

// Processa exclusão (apenas super-admin)
if (isset($_GET['delete']) && Session::haveRight('config', READ)) {
    $delId = (int)$_GET['delete'];
    $term  = new PluginTermosTerm();
    if ($term->getFromDB($delId)) {
        if (!empty($term->fields['pdf_path']) && file_exists($term->fields['pdf_path'])) {
            unlink($term->fields['pdf_path']);
        }
        $term->delete(['id' => $delId], true);
    }
    header('Location: term.php');
    exit;
}

// Processa reenvio de email (termo de responsabilidade assinado)
if (isset($_GET['resend'])) {
    $resendId = (int)$_GET['resend'];
    try {
        PluginTermosTerm::sendTermEmail($resendId);
        $msg_success = 'E-mail reenviado com sucesso!';
    } catch (\Throwable $e) {
        $msg_error = 'Erro ao reenviar: ' . $e->getMessage();
    }
}

// Envia link de assinatura remota (termo pendente)
if (isset($_GET['send_link'])) {
    $linkId = (int)$_GET['send_link'];
    try {
        PluginTermosTerm::sendSignEmail($linkId);
        $msg_success = 'Link de assinatura enviado por e-mail com sucesso!';
    } catch (\Throwable $e) {
        $msg_error = 'Erro ao enviar link: ' . $e->getMessage();
    }
}

Html::header('Termos de Responsabilidade', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');

global $DB;

$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');
$start         = (int)($_GET['start'] ?? 0);
$limit         = 20;

// Monta condições WHERE
$where = [];
if (in_array($status_filter, ['pending', 'signed', 'revoked'])) {
    $where['t.status'] = $status_filter;
}
if (!empty($search)) {
    $s = '%' . $search . '%';
    $where[] = [
        'OR' => [
            'c.name'       => ['LIKE', $s],
            'c.serial'     => ['LIKE', $s],
            'c.otherserial'=> ['LIKE', $s],
            'u.firstname'  => ['LIKE', $s],
            'u.realname'   => ['LIKE', $s],
            'u.name'       => ['LIKE', $s],
            'e.completename' => ['LIKE', $s],
        ],
    ];
}

// Total para paginação
$countIterator = $DB->request([
    'COUNT'     => 'total',
    'FROM'      => ['glpi_plugin_termos_terms AS t'],
    'LEFT JOIN' => [
        'glpi_computers AS c' => ['ON' => ['t' => 'computers_id', 'c' => 'id']],
        'glpi_users AS u'     => ['ON' => ['t' => 'users_id',     'u' => 'id']],
        'glpi_entities AS e'  => ['ON' => ['t' => 'entities_id',  'e' => 'id']],
    ],
    'WHERE' => $where,
]);
$total = (int)($countIterator->current()['total'] ?? 0);

// Registros da página
$iterator = $DB->request([
    'SELECT' => [
        't.id',
        't.status',
        't.item_type',
        't.date_creation',
        't.date_signed',
        't.issuer_id',
        'c.name   AS computer_name',
        'c.serial AS computer_serial',
        'p.name   AS phone_name',
        'p.serial AS phone_serial',
        'u.firstname',
        'u.realname',
        'u.name AS username',
        'e.completename AS entity',
        'i.firstname AS issuer_firstname',
        'i.realname  AS issuer_realname',
        'i.name      AS issuer_username',
    ],
    'FROM'      => ['glpi_plugin_termos_terms AS t'],
    'LEFT JOIN' => [
        'glpi_computers AS c' => ['ON' => ['t' => 'computers_id', 'c' => 'id']],
        'glpi_phones AS p'    => ['ON' => ['t' => 'phones_id',    'p' => 'id']],
        'glpi_users AS u'     => ['ON' => ['t' => 'users_id',     'u' => 'id']],
        'glpi_entities AS e'  => ['ON' => ['t' => 'entities_id',  'e' => 'id']],
        'glpi_users AS i'     => ['ON' => ['t' => 'issuer_id',    'i' => 'id']],
    ],
    'WHERE'   => $where,
    'ORDERBY' => ['t.date_creation DESC'],
    'START'   => $start,
    'LIMIT'   => $limit,
]);

$isAdmin = Session::haveRight('config', READ);

// URL base preservando filtros
$queryParams = [];
if (!empty($status_filter)) $queryParams['status'] = $status_filter;
if (!empty($search))        $queryParams['search'] = $search;
$baseUrl = 'term.php?' . (!empty($queryParams) ? http_build_query($queryParams) . '&' : '');

echo '<div class="container-fluid" style="padding:20px;">';
echo '<h2><i class="fas fa-file-signature"></i> Termos de Responsabilidade</h2>';

if (!empty($msg_success)) {
    echo '<div class="alert alert-success" style="margin:10px 0;">' . htmlspecialchars($msg_success) . '</div>';
}
if (!empty($msg_error)) {
    echo '<div class="alert alert-danger" style="margin:10px 0;">' . htmlspecialchars($msg_error) . '</div>';
}

// Barra de filtros + busca + botões
echo '<form method="GET" action="term.php">';
echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:15px; flex-wrap:wrap;">';

// Campo de busca
echo '<input type="text" name="search" value="' . htmlspecialchars($search) . '" '
   . 'placeholder="🔍 Buscar por nome, serial, usuário, setor..." '
   . 'class="form-control" style="width:320px;" '
   . 'oninput="clearTimeout(window._st); window._st=setTimeout(()=>this.form.submit(),600)">';

// Filtro status
echo '<select name="status" onchange="this.form.submit()" class="form-control" style="width:160px;">';
echo '<option value="">Todos os status</option>';
foreach (['pending' => 'Pendentes', 'signed' => 'Assinados', 'revoked' => 'Revogados'] as $val => $label) {
    $sel = ($status_filter === $val) ? 'selected' : '';
    echo "<option value=\"{$val}\" {$sel}>{$label}</option>";
}
echo '</select>';

echo '<button type="submit" class="btn btn-secondary">Buscar</button>';

if (!empty($search) || !empty($status_filter)) {
    echo '<a href="term.php" class="btn btn-outline-secondary">✕ Limpar</a>';
}

echo '</div>';
echo '</form>';

// Botões de ação
echo '<div style="display:flex; gap:10px; margin-bottom:15px; flex-wrap:wrap;">';
echo '<a href="term.form.php" class="btn btn-primary"><i class="fas fa-plus"></i> Novo Termo</a>';
echo '<a href="term.batch.php" class="btn btn-success"><i class="fas fa-paper-plane"></i> Enviar Lote</a>';
echo '<button type="button" class="btn btn-info" onclick="openVerifyModal()">'
   . '<i class="fas fa-shield-alt"></i> Verificar Documento</button>';
if ($isAdmin) {
    echo '<a href="settings.php" class="btn btn-secondary"><i class="fas fa-cog"></i> Configurações</a>';
}
echo '</div>';

// Total encontrado
echo '<p style="color:#666; font-size:0.9em; margin-bottom:5px;">';
echo $total . ' termo(s) encontrado(s)';
if (!empty($search)) echo ' para <strong>"' . htmlspecialchars($search) . '"</strong>';
echo '</p>';

// Paginador topo
Html::printPager($start, $total, $baseUrl . 'start=', $limit);

// Barra de ações em massa (aparece ao selecionar)
echo '<div id="bulk-bar" style="display:none; align-items:center; gap:10px; flex-wrap:wrap;
      background:#e8f0fe; border:1px solid #b6ccfe; border-radius:6px;
      padding:10px 14px; margin:10px 0;">
  <span id="bulk-count" style="font-weight:600; color:#0d47a1;"></span>
  <button type="button" onclick="bulkAction(\'send_link\')" class="btn btn-sm btn-primary">🔗 Enviar Link (pendentes)</button>
  <button type="button" onclick="bulkAction(\'resend_email\')" class="btn btn-sm btn-info">📧 Reenviar E-mail (assinados)</button>'
. ($isAdmin ? '<button type="button" onclick="bulkAction(\'delete\')" class="btn btn-sm btn-danger">🗑️ Excluir selecionados</button>' : '')
. '<button type="button" onclick="clearSelection()" class="btn btn-sm btn-outline-secondary">✕ Limpar seleção</button>
</div>';

// Form da tabela (ações em massa)
echo '<form id="bulk-form" method="POST" action="term.php?' . htmlspecialchars(http_build_query(array_filter(['status' => $status_filter, 'search' => $search, 'start' => $start]))) . '">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlspecialchars(Session::getNewCSRFToken()) . '">';
echo '<input type="hidden" name="bulk_action" id="bulk_action_input" value="">';

// Tabela
echo '<div style="background:#fff; border-radius:6px; border:1px solid #dee2e6; overflow-x:auto; margin-top:10px; -webkit-overflow-scrolling:touch;">';
echo '<table class="table table-bordered table-hover" style="margin:0; background:#fff; min-width:900px;">';
echo '<thead style="background:#f8f9fa;">
<tr>
    <th style="width:36px; text-align:center;"><input type="checkbox" id="chk-all" title="Selecionar todos"></th>
    <th style="width:45px; white-space:nowrap;">#</th>
    <th style="white-space:nowrap;">Tipo</th>
    <th style="white-space:nowrap;">Equipamento</th>
    <th style="white-space:nowrap;">Série</th>
    <th style="white-space:nowrap;">Responsável</th>
    <th style="white-space:nowrap;">Emitido por</th>
    <th style="white-space:nowrap;">Setor</th>
    <th style="white-space:nowrap;">Criado em</th>
    <th style="white-space:nowrap;">Assinado em</th>
    <th style="white-space:nowrap; width:110px;">Status</th>
    <th style="white-space:nowrap;">Ações</th>
</tr></thead><tbody>';

$count = 0;
foreach ($iterator as $row) {
    $count++;
    $nomeCompleto = htmlspecialchars(trim($row['firstname'] . ' ' . $row['realname']) ?: $row['username']);
    $assinado     = $row['date_signed']   ? date('d/m/Y H:i', strtotime($row['date_signed']))   : '-';
    $criado       = $row['date_creation'] ? date('d/m/Y H:i', strtotime($row['date_creation'])) : '-';
    $isPhone      = ($row['item_type'] ?? 'Computer') === 'Phone';
    $equipNome    = $isPhone ? ($row['phone_name']   ?? '-') : ($row['computer_name']   ?? '-');
    $equipSerial  = $isPhone ? ($row['phone_serial'] ?? '-') : ($row['computer_serial'] ?? '-');
    $tipoLabel    = $isPhone ? '📱 Celular' : '💻 Computador';

    echo '<tr style="background:#fff;" data-status="' . htmlspecialchars($row['status']) . '">';
    echo '<td style="text-align:center;"><input type="checkbox" class="row-chk" name="ids[]" value="' . (int)$row['id'] . '"></td>';
    echo '<td style="white-space:nowrap;">' . (int)$row['id'] . '</td>';
    echo '<td style="white-space:nowrap; font-size:.85em;">' . $tipoLabel . '</td>';
    echo '<td style="white-space:nowrap;">' . htmlspecialchars($equipNome) . '</td>';
    echo '<td style="white-space:nowrap;">' . htmlspecialchars($equipSerial) . '</td>';
    echo '<td style="white-space:nowrap;">' . $nomeCompleto . '</td>';
    $issuerNome = htmlspecialchars(trim(($row['issuer_firstname'] ?? '') . ' ' . ($row['issuer_realname'] ?? '')) ?: ($row['issuer_username'] ?? '-'));
    echo '<td style="white-space:nowrap;">' . $issuerNome . '</td>';
    echo '<td style="white-space:nowrap;">' . htmlspecialchars($row['entity'] ?? '-') . '</td>';
    echo '<td style="white-space:nowrap;">' . $criado . '</td>';
    echo '<td style="white-space:nowrap;">' . $assinado . '</td>';
    echo '<td style="white-space:nowrap;">' . PluginTermosTerm::getStatusLabel($row['status']) . '</td>';
    echo '<td style="white-space:nowrap;">';

    if ($row['status'] === 'pending') {
        echo '<a href="sign.php?id=' . (int)$row['id'] . '" class="btn btn-sm btn-warning">✍️ Assinar</a> ';
        echo '<a href="' . $baseUrl . 'send_link=' . (int)$row['id'] . '" class="btn btn-sm btn-primary" '
           . 'onclick="return confirm(\'Enviar link de assinatura por e-mail para o responsável?\');">'
           . '🔗 Enviar Link</a> ';
    }

    if ($row['status'] === 'signed') {
        echo '<a href="download.php?id=' . (int)$row['id'] . '" class="btn btn-sm btn-success">📄 PDF</a> ';
        echo '<a href="' . $baseUrl . 'resend=' . (int)$row['id'] . '" class="btn btn-sm btn-info" '
           . 'onclick="return confirm(\'Reenviar o PDF por e-mail para o responsável?\');">'
           . '📧 Email</a> ';
        echo '<a href="devolution.php?id=' . (int)$row['id'] . '" class="btn btn-sm btn-secondary" '
           . 'onclick="return confirm(\'Gerar Termo de Devolução? O status será alterado para Revogado.\');">'
           . '📦 Devolução</a> ';
    }

    if ($row['status'] === 'revoked') {
        echo '<a href="devolution.php?id=' . (int)$row['id'] . '" class="btn btn-sm btn-success">📄 PDF Dev.</a> ';
        echo '<a href="devolution.php?id=' . (int)$row['id'] . '" class="btn btn-sm btn-info">'
           . '📧 Email Dev.</a> ';
    }

    if ($isAdmin) {
        echo '<a href="' . $baseUrl . 'delete=' . (int)$row['id'] . '" '
           . 'class="btn btn-sm btn-danger" '
           . 'onclick="return confirm(\'Tem certeza que deseja excluir este termo? Esta ação não pode ser desfeita.\');">'
           . '🗑️</a>';
    }

    echo '</td>';
    echo '</tr>';
}

if ($count === 0) {
    echo '<tr><td colspan="9" class="text-center" style="padding:30px; color:#666;">';
    echo empty($search) ? 'Nenhum termo encontrado.' : 'Nenhum resultado para <strong>"' . htmlspecialchars($search) . '"</strong>.';
    echo '</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</form>';

// Paginador rodapé
Html::printPager($start, $total, $baseUrl . 'start=', $limit);

echo '</div>';

echo '
<script>
(function() {
    var chkAll = document.getElementById("chk-all");
    var bar    = document.getElementById("bulk-bar");
    var count  = document.getElementById("bulk-count");

    function getChecked() {
        return document.querySelectorAll(".row-chk:checked");
    }

    function updateBar() {
        var checked = getChecked();
        if (checked.length > 0) {
            bar.style.display = "flex";
            count.textContent = checked.length + " selecionado(s)";
        } else {
            bar.style.display = "none";
        }
        chkAll.indeterminate = checked.length > 0 && checked.length < document.querySelectorAll(".row-chk").length;
        chkAll.checked = checked.length > 0 && checked.length === document.querySelectorAll(".row-chk").length;
    }

    chkAll.addEventListener("change", function() {
        document.querySelectorAll(".row-chk").forEach(function(c) { c.checked = chkAll.checked; });
        updateBar();
    });

    document.querySelectorAll(".row-chk").forEach(function(c) {
        c.addEventListener("change", updateBar);
    });

    window.clearSelection = function() {
        document.querySelectorAll(".row-chk").forEach(function(c) { c.checked = false; });
        chkAll.checked = false;
        updateBar();
    };

    window.bulkAction = function(action) {
        var checked = getChecked();
        if (!checked.length) return;
        var msgs = {
            "delete":       "Excluir os " + checked.length + " termo(s) selecionados? Esta ação não pode ser desfeita.",
            "send_link":    "Enviar link de assinatura para os " + checked.length + " termo(s) pendentes selecionados?",
            "resend_email": "Reenviar e-mail para os " + checked.length + " termo(s) assinados selecionados?"
        };
        if (!confirm(msgs[action] || "Confirmar ação em massa?")) return;
        document.getElementById("bulk_action_input").value = action;
        document.getElementById("bulk-form").submit();
    };
})();
</script>
';

// ── Modal: Verificar documento ───────────────────────────────────────────────
echo '
<div id="verifyModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
     z-index:9999; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:8px; width:520px; max-width:95vw;
              box-shadow:0 8px 32px rgba(0,0,0,.3); overflow:hidden;">

    <!-- Header -->
    <div style="background:#0d6efd; color:#fff; padding:16px 20px;
                display:flex; align-items:center; justify-content:space-between;">
      <span style="font-size:1.05em; font-weight:600;">
        <i class="fas fa-shield-alt"></i>&nbsp; Verificar Autenticidade do Documento
      </span>
      <button onclick="closeVerifyModal()"
              style="background:none; border:none; color:#fff; font-size:1.4em; cursor:pointer; line-height:1;">
        &times;
      </button>
    </div>

    <!-- Body -->
    <div style="padding:24px;">

      <!-- Área de upload drag-and-drop -->
      <div id="verifyDropzone"
           onclick="document.getElementById(\'verifyFile\').click()"
           style="border:2px dashed #adb5bd; border-radius:8px; padding:32px 20px;
                  text-align:center; cursor:pointer; transition:.2s; background:#f8f9fa;"
           ondragover="event.preventDefault(); this.style.borderColor=\'#0d6efd\';"
           ondragleave="this.style.borderColor=\'#adb5bd\';"
           ondrop="handleVerifyDrop(event)">
        <div style="font-size:2.5em; color:#6c757d;">📄</div>
        <div style="margin-top:8px; color:#495057; font-weight:500;">
          Arraste o PDF aqui ou clique para selecionar
        </div>
        <div style="font-size:.8em; color:#6c757d; margin-top:4px;">
          Termo de Responsabilidade ou Termo de Devolução
        </div>
        <input type="file" id="verifyFile" accept=".pdf" style="display:none"
               onchange="handleVerifyFile(this.files[0])">
      </div>

      <!-- Progresso / resultado -->
      <div id="verifyStatus" style="margin-top:16px; display:none;"></div>
    </div>

    <!-- Footer -->
    <div style="padding:12px 20px; background:#f8f9fa; border-top:1px solid #dee2e6;
                display:flex; justify-content:flex-end; gap:8px;">
      <button onclick="resetVerifyModal()" id="verifyBtnReset"
              class="btn btn-outline-secondary btn-sm" style="display:none;">
        🔄 Verificar outro
      </button>
      <button onclick="closeVerifyModal()" class="btn btn-secondary btn-sm">Fechar</button>
    </div>
  </div>
</div>

<script>
function openVerifyModal() {
    resetVerifyModal();
    document.getElementById("verifyModal").style.display = "flex";
}
function closeVerifyModal() {
    document.getElementById("verifyModal").style.display = "none";
}
function resetVerifyModal() {
    document.getElementById("verifyDropzone").style.display = "";
    document.getElementById("verifyStatus").style.display   = "none";
    document.getElementById("verifyStatus").innerHTML       = "";
    document.getElementById("verifyBtnReset").style.display = "none";
    document.getElementById("verifyFile").value             = "";
}

function handleVerifyDrop(e) {
    e.preventDefault();
    document.getElementById("verifyDropzone").style.borderColor = "#adb5bd";
    const file = e.dataTransfer.files[0];
    if (file) handleVerifyFile(file);
}

async function handleVerifyFile(file) {
    if (!file || file.type !== "application/pdf") {
        showVerifyStatus("error", "❌ Por favor, selecione um arquivo PDF válido.");
        return;
    }

    // Esconde dropzone, mostra progresso
    document.getElementById("verifyDropzone").style.display = "none";
    showVerifyStatus("loading",
        "<div style=\'text-align:center; padding:10px;\'>"
      + "<div style=\'font-size:1.8em;\'>⏳</div>"
      + "<div style=\'margin-top:8px; font-weight:500;\'>Calculando hash SHA-256...</div>"
      + "<div style=\'font-size:.8em; color:#6c757d;\'>" + escHtml(file.name) + "</div>"
      + "</div>"
    );

    try {
        // Calcula SHA-256 via Web Crypto API (nativo no navegador)
        const buffer = await file.arrayBuffer();
        const hashBuf = await crypto.subtle.digest("SHA-256", buffer);
        const hash = Array.from(new Uint8Array(hashBuf))
                         .map(b => b.toString(16).padStart(2, "0"))
                         .join("");

        showVerifyStatus("loading",
            "<div style=\'text-align:center; padding:10px;\'>"
          + "<div style=\'font-size:1.8em;\'>🔍</div>"
          + "<div style=\'margin-top:8px; font-weight:500;\'>Consultando base de dados...</div>"
          + "</div>"
        );

        const resp = await fetch("term.php?action=verify_hash&hash=" + encodeURIComponent(hash));
        const json = await resp.json();

        document.getElementById("verifyBtnReset").style.display = "";

        if (!json.valid) {
            showVerifyStatus("error",
                "<div style=\'border-left:4px solid #dc3545; padding:12px 16px; background:#fff5f5; border-radius:4px;\'>"
              + "<div style=\'font-size:1.4em;\'>❌</div>"
              + "<div style=\'font-weight:600; color:#dc3545; margin:6px 0 4px;\'>Documento inválido</div>"
              + "<div style=\'font-size:.9em; color:#495057;\'>"
              + escHtml(json.msg || json.error || "Hash não encontrado na base de dados.")
              + "</div>"
              + "<div style=\'margin-top:10px; font-size:.75em; color:#6c757d; word-break:break-all;\'>"
              + "SHA-256: " + escHtml(hash)
              + "</div>"
              + "</div>"
            );
        } else {
            const statusLabels = { pending: "⏳ Pendente", signed: "✅ Assinado", revoked: "🔴 Revogado" };
            const statusColors = { pending: "#ffc107", signed: "#198754", revoked: "#6c757d" };
            const st = json.status;

            showVerifyStatus("success",
                "<div style=\'border-left:4px solid #198754; padding:12px 16px; background:#f0fff4; border-radius:4px;\'>"
              + "<div style=\'font-size:1.4em;\'>✅</div>"
              + "<div style=\'font-weight:600; color:#198754; margin:6px 0 10px;\'>Documento autêntico e íntegro</div>"
              + "<table style=\'width:100%; font-size:.88em; border-collapse:collapse;\'>"
              + row2("Tipo",           "<strong>" + escHtml(json.doc_type) + "</strong>")
              + row2("Nº do Termo",    "#" + json.id)
              + row2("Status",         "<span style=\'color:" + (statusColors[st]||"#333") + "; font-weight:600;\'>" + escHtml(statusLabels[st]||st) + "</span>")
              + row2("Computador",     escHtml(json.computer))
              + row2("Número de série",escHtml(json.serial))
              + row2("Setor",          escHtml(json.entity))
              + row2("Responsável",    escHtml(json.responsavel))
              + row2("Emitido por",    escHtml(json.emissor))
              + row2("Criado em",      escHtml(json.date_creation))
              + row2("Assinado em",    escHtml(json.date_signed))
              + "</table>"
              + "<div style=\'margin-top:10px; font-size:.72em; color:#6c757d; word-break:break-all;\'>"
              + "SHA-256: " + escHtml(json.hash)
              + "</div>"
              + "</div>"
            );
        }
    } catch (err) {
        showVerifyStatus("error", "❌ Erro ao processar o arquivo: " + escHtml(err.message));
        document.getElementById("verifyBtnReset").style.display = "";
    }
}

function row2(label, value) {
    return "<tr>"
         + "<td style=\'padding:3px 8px 3px 0; color:#6c757d; white-space:nowrap; vertical-align:top;\'>" + label + ":</td>"
         + "<td style=\'padding:3px 0; font-weight:500;\'>" + value + "</td>"
         + "</tr>";
}
function escHtml(str) {
    return String(str ?? "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
}
function showVerifyStatus(type, html) {
    const el = document.getElementById("verifyStatus");
    el.style.display = "";
    el.innerHTML = html;
}

// Fecha modal ao clicar fora
document.getElementById("verifyModal").addEventListener("click", function(e) {
    if (e.target === this) closeVerifyModal();
});
</script>
';
// ─────────────────────────────────────────────────────────────────────────────

Html::footer();
