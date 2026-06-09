<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$msg_success = '';
$msg_error   = '';

if (($_GET['msg'] ?? '') === 'link_sent') {
    $msg_success = 'Termo criado e link de assinatura enviado por e-mail com sucesso!';
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
        't.date_creation',
        't.date_signed',
        't.issuer_id',
        'c.name AS computer_name',
        'c.serial',
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

// Tabela
echo '<div style="background:#fff; border-radius:6px; border:1px solid #dee2e6; overflow:hidden; margin-top:10px;">';
echo '<table class="table table-bordered table-hover" style="margin:0; background:#fff;">';
echo '<thead style="background:#f8f9fa;">
<tr>
    <th style="width:50px;">#</th>
    <th>Computador</th>
    <th>Série</th>
    <th>Responsável</th>
    <th>Emitido por</th>
    <th>Setor</th>
    <th>Criado em</th>
    <th>Assinado em</th>
    <th style="width:100px;">Status</th>
    <th>Ações</th>
</tr></thead><tbody>';

$count = 0;
foreach ($iterator as $row) {
    $count++;
    $nomeCompleto = htmlspecialchars(trim($row['firstname'] . ' ' . $row['realname']) ?: $row['username']);
    $assinado     = $row['date_signed']   ? date('d/m/Y H:i', strtotime($row['date_signed']))   : '-';
    $criado       = $row['date_creation'] ? date('d/m/Y H:i', strtotime($row['date_creation'])) : '-';

    echo '<tr style="background:#fff;">';
    echo '<td>' . (int)$row['id'] . '</td>';
    echo '<td>' . htmlspecialchars($row['computer_name'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($row['serial'] ?? '-') . '</td>';
    echo '<td>' . $nomeCompleto . '</td>';
    $issuerNome = htmlspecialchars(trim(($row['issuer_firstname'] ?? '') . ' ' . ($row['issuer_realname'] ?? '')) ?: ($row['issuer_username'] ?? '-'));
    echo '<td>' . $issuerNome . '</td>';
    echo '<td>' . htmlspecialchars($row['entity'] ?? '-') . '</td>';
    echo '<td>' . $criado . '</td>';
    echo '<td>' . $assinado . '</td>';
    echo '<td>' . PluginTermosTerm::getStatusLabel($row['status']) . '</td>';
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

// Paginador rodapé
Html::printPager($start, $total, $baseUrl . 'start=', $limit);

echo '</div>';

Html::footer();
