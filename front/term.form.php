<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// Endpoint AJAX para buscar email do usuário
if (isset($_GET['get_user_email']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    global $DB;
    $email = '';
    $emailIter = $DB->request([
        'SELECT' => ['email'],
        'FROM'   => 'glpi_useremails',
        'WHERE'  => ['users_id' => (int)$_GET['id'], 'is_default' => 1],
        'LIMIT'  => 1,
    ]);
    foreach ($emailIter as $erow) { $email = $erow['email']; }
    if (empty($email)) {
        $emailIter2 = $DB->request([
            'SELECT' => ['email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => (int)$_GET['id']],
            'LIMIT'  => 1,
        ]);
        foreach ($emailIter2 as $erow) { $email = $erow['email']; }
    }
    echo json_encode(['email' => $email]);
    exit;
}

// Endpoint AJAX para buscar dados atuais do computador
if (isset($_GET['get_computer']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    global $DB;
    $iter = $DB->request([
        'SELECT' => ['glpi_computers.users_id', 'glpi_computers.states_id',
                     'glpi_users.name AS username', 'glpi_users.firstname', 'glpi_users.realname'],
        'FROM'   => 'glpi_computers',
        'LEFT JOIN' => [
            'glpi_users' => ['ON' => ['glpi_computers' => 'users_id', 'glpi_users' => 'id']],
        ],
        'WHERE'  => ['glpi_computers.id' => (int)$_GET['id']],
        'LIMIT'  => 1,
    ]);
    foreach ($iter as $row) {
        // Busca o email padrão do usuário
        $email = '';
        if (!empty($row['users_id'])) {
            $emailIter = $DB->request([
                'SELECT' => ['email'],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => ['users_id' => (int)$row['users_id'], 'is_default' => 1],
                'LIMIT'  => 1,
            ]);
            foreach ($emailIter as $erow) { $email = $erow['email']; }
            if (empty($email)) {
                $emailIter2 = $DB->request([
                    'SELECT' => ['email'],
                    'FROM'   => 'glpi_useremails',
                    'WHERE'  => ['users_id' => (int)$row['users_id']],
                    'LIMIT'  => 1,
                ]);
                foreach ($emailIter2 as $erow) { $email = $erow['email']; }
            }
        }
        $row['email'] = $email;
        echo json_encode($row);
        exit;
    }
    echo json_encode(['users_id' => 0, 'states_id' => 0, 'email' => '']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['computers_id'])) {
    try {
        $computers_id = (int)$_POST['computers_id'];
        $users_id     = (int)($_POST['users_id'] ?? 0);
        $states_id    = (int)($_POST['states_id'] ?? 0);
        $action       = $_POST['action'] ?? 'sign';
        $customEmail  = trim($_POST['custom_email'] ?? '');

        if ($computers_id) {
            $computer   = new Computer();
            $updateData = ['id' => $computers_id];
            if ($users_id > 0)  $updateData['users_id']  = $users_id;
            if ($states_id > 0) $updateData['states_id'] = $states_id;
            if (count($updateData) > 1) $computer->update($updateData);
        }

        $newId = PluginTermosTerm::createTerm($computers_id);

        if ($action === 'email') {
            PluginTermosTerm::sendSignEmail($newId, $customEmail);
            header('Location: term.php?msg=link_sent');
            exit;
        }

        header('Location: sign.php?id=' . $newId);
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

Html::header('Novo Termo de Responsabilidade', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');

global $DB;

// Todos os computadores
$iterComp = $DB->request([
    'SELECT'  => ['id', 'name', 'serial'],
    'FROM'    => 'glpi_computers',
    'WHERE'   => ['is_deleted' => 0],
    'ORDERBY' => ['name ASC'],
]);
$computers = [];
foreach ($iterComp as $row) {
    $label = $row['name'];
    if (!empty($row['serial'])) {
        $label .= ' (S/N: ' . $row['serial'] . ')';
    }
    $computers[(int)$row['id']] = $label;
}

// Todos os usuários ativos
$iterUsers = $DB->request([
    'SELECT'  => ['id', 'name', 'firstname', 'realname'],
    'FROM'    => 'glpi_users',
    'WHERE'   => ['is_deleted' => 0, 'is_active' => 1],
    'ORDERBY' => ['realname ASC', 'firstname ASC'],
]);
$users = [];
foreach ($iterUsers as $row) {
    $nome = trim($row['firstname'] . ' ' . $row['realname']) ?: $row['name'];
    $users[(int)$row['id']] = $nome . ' (' . $row['name'] . ')';
}

// Status disponíveis
$iterStatus = $DB->request([
    'SELECT'  => ['id', 'name'],
    'FROM'    => 'glpi_states',
    'ORDERBY' => ['name ASC'],
]);
$statuses = [];
foreach ($iterStatus as $row) {
    $statuses[(int)$row['id']] = $row['name'];
}
?>

<div class="container" style="max-width:700px; margin:40px auto;">
    <h2><i class="fas fa-file-signature"></i> Gerar Novo Termo</h2>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" id="form-novo-termo">
        <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>

        <div class="form-group mb-3" style="margin-bottom:15px;">
            <label for="computers_id"><strong>Computador:</strong></label>
            <select name="computers_id" id="computers_id" class="form-control select2" required style="width:100%;">
                <option value="">-- Digite o nome ou serial --</option>
                <?php foreach ($computers as $cid => $label): ?>
                    <option value="<?= $cid ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group mb-3" style="margin-bottom:15px;">
            <label for="users_id"><strong>Usuário Responsável:</strong></label>
            <select name="users_id" id="users_id" class="form-control select2" style="width:100%;">
                <option value="">-- Manter usuário atual da máquina --</option>
                <?php foreach ($users as $uid => $label): ?>
                    <option value="<?= $uid ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Se selecionado, atualiza o usuário vinculado à máquina no GLPI.</small>
        </div>

        <div class="form-group mb-3" style="margin-bottom:20px;">
            <label for="states_id"><strong>Status da Máquina:</strong></label>
            <select name="states_id" id="states_id" class="form-control select2" style="width:100%;">
                <option value="">-- Manter status atual --</option>
                <?php foreach ($statuses as $sid => $label): ?>
                    <option value="<?= $sid ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Se selecionado, atualiza o status da máquina no GLPI.</small>
        </div>

        <!-- Campo de email (visível apenas ao clicar em Enviar por Email) -->
        <div id="email-section" style="display:none; margin-bottom:20px; padding:15px;
             background:#f0f7ff; border:1px solid #b8d4f5; border-radius:6px;">
            <label for="custom_email"><strong>📧 Enviar link para o e-mail:</strong></label>
            <input type="email" name="custom_email" id="custom_email" class="form-control"
                   placeholder="Selecione um computador para carregar o e-mail..."
                   style="margin-top:6px;">
            <small id="email-hint" class="text-muted" style="margin-top:4px; display:block;">
                Selecione o computador acima para carregar o e-mail do responsável.
            </small>
        </div>

        <input type="hidden" name="action" id="form-action" value="sign">

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-warning" onclick="submitAction('sign')"
                    style="font-size:1em;">
                ✍️ Assinar Agora
            </button>
            <button type="button" class="btn btn-primary" onclick="toggleEmail()"
                    id="btn-email" style="font-size:1em;">
                📧 Enviar por E-mail
            </button>
            <a href="term.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<script>
$(document).ready(function () {
    $('#computers_id').select2({
        placeholder: 'Digite o nome ou serial do computador...',
        allowClear: true,
        width: '100%',
        language: {
            noResults: function () { return 'Nenhum computador encontrado.'; },
            searching: function ()  { return 'Buscando...'; },
        },
    });
    $('#users_id').select2({
        placeholder: 'Digite o nome do usuário...',
        allowClear: true,
        width: '100%',
        language: {
            noResults: function () { return 'Nenhum usuário encontrado.'; },
            searching: function ()  { return 'Buscando...'; },
        },
    });
    $('#states_id').select2({
        placeholder: 'Selecione o status...',
        allowClear: true,
        width: '100%',
    });

    // Ao selecionar computador, pré-carrega usuário e status atuais
    $('#computers_id').on('change', function () {
        const cid = $(this).val();
        if (!cid) {
            $('#users_id').val(null).trigger('change');
            $('#states_id').val(null).trigger('change');
            return;
        }

        $.getJSON(window.location.pathname + '?get_computer=1&id=' + cid, function (data) {
            if (data.users_id && data.users_id > 0) {
                $('#users_id').val(data.users_id).trigger('change');
            } else {
                $('#users_id').val(null).trigger('change');
            }
            if (data.states_id && data.states_id > 0) {
                $('#states_id').val(data.states_id).trigger('change');
            } else {
                $('#states_id').val(null).trigger('change');
            }
            // Preenche o campo de email com o email cadastrado
            updateEmailHint(data.email || '', !!data.email);
        });
    });

    // Ao selecionar usuário manualmente, atualiza o email
    $('#users_id').on('change', function () {
        const uid = $(this).val();
        if (!uid) { updateEmailHint('', false); return; }
        $.getJSON(window.location.pathname + '?get_user_email=1&id=' + uid, function (data) {
            updateEmailHint(data.email || '', !!data.email);
        });
    });

    function updateEmailHint(email, found) {
        const emailField = document.getElementById('custom_email');
        const emailHint  = document.getElementById('email-hint');
        if (!emailField) return;
        if (found) {
            emailField.value       = email;
            emailField.placeholder = email;
            if (emailHint) emailHint.innerHTML = '✅ E-mail cadastrado no GLPI: <strong>' + email + '</strong>';
        } else {
            emailField.value       = '';
            emailField.placeholder = 'Usuário sem e-mail no GLPI — informe aqui';
            if (emailHint) emailHint.innerHTML = '⚠️ Este usuário <strong>não tem e-mail cadastrado</strong> no GLPI. Informe um e-mail abaixo.';
        }
    }
});
</script>

<script>
function toggleEmail() {
    const section = document.getElementById('email-section');
    const btn     = document.getElementById('btn-email');
    const visible = section.style.display !== 'none';
    if (visible) {
        section.style.display = 'none';
        btn.textContent = '📧 Enviar por E-mail';
        btn.onclick = toggleEmail;
    } else {
        section.style.display = 'block';
        document.getElementById('custom_email').focus();
        btn.textContent = '✉️ Confirmar Envio';
        btn.onclick = function() { submitAction('email'); };
    }
}
function submitAction(action) {
    document.getElementById('form-action').value = action;
    document.getElementById('form-novo-termo').submit();
}
</script>

<?php Html::footer(); ?>
