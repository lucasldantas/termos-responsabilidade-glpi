<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// ── AJAX: email do usuário ────────────────────────────────────────────────────
if (isset($_GET['get_user_email'])) {
    header('Content-Type: application/json');
    global $DB;
    $uid   = (int)($_GET['id'] ?? 0);
    $email = '';
    foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails',
                           'WHERE' => ['users_id' => $uid, 'is_default' => 1], 'LIMIT' => 1]) as $r) {
        $email = $r['email'];
    }
    if (empty($email)) {
        foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails',
                               'WHERE' => ['users_id' => $uid], 'LIMIT' => 1]) as $r) {
            $email = $r['email'];
        }
    }
    echo json_encode(['email' => $email]);
    exit;
}

// ── AJAX: dados do computador ─────────────────────────────────────────────────
if (isset($_GET['get_computer'])) {
    header('Content-Type: application/json');
    global $DB;
    $cid = (int)($_GET['id'] ?? 0);
    foreach ($DB->request([
        'SELECT'    => ['glpi_computers.users_id', 'glpi_computers.states_id',
                        'glpi_users.name AS username', 'glpi_users.firstname', 'glpi_users.realname'],
        'FROM'      => 'glpi_computers',
        'LEFT JOIN' => ['glpi_users' => ['ON' => ['glpi_computers' => 'users_id', 'glpi_users' => 'id']]],
        'WHERE'     => ['glpi_computers.id' => $cid],
        'LIMIT'     => 1,
    ]) as $row) {
        $email = '';
        if (!empty($row['users_id'])) {
            foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails',
                                   'WHERE' => ['users_id' => $row['users_id'], 'is_default' => 1], 'LIMIT' => 1]) as $r) {
                $email = $r['email'];
            }
            if (empty($email)) {
                foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails',
                                       'WHERE' => ['users_id' => $row['users_id']], 'LIMIT' => 1]) as $r) {
                    $email = $r['email'];
                }
            }
        }
        $row['email'] = $email;
        echo json_encode($row);
        exit;
    }
    echo json_encode(['users_id' => 0, 'states_id' => 0, 'email' => '']);
    exit;
}

// ── AJAX: dados do celular ────────────────────────────────────────────────────
if (isset($_GET['get_phone'])) {
    header('Content-Type: application/json');
    global $DB;
    $pid = (int)($_GET['id'] ?? 0);
    foreach ($DB->request([
        'SELECT'    => ['glpi_phones.users_id', 'glpi_phones.states_id',
                        'glpi_users.name AS username', 'glpi_users.firstname', 'glpi_users.realname'],
        'FROM'      => 'glpi_phones',
        'LEFT JOIN' => ['glpi_users' => ['ON' => ['glpi_phones' => 'users_id', 'glpi_users' => 'id']]],
        'WHERE'     => ['glpi_phones.id' => $pid],
        'LIMIT'     => 1,
    ]) as $row) {
        $email = '';
        if (!empty($row['users_id'])) {
            foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails',
                                   'WHERE' => ['users_id' => $row['users_id'], 'is_default' => 1], 'LIMIT' => 1]) as $r) {
                $email = $r['email'];
            }
            if (empty($email)) {
                foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails',
                                       'WHERE' => ['users_id' => $row['users_id']], 'LIMIT' => 1]) as $r) {
                    $email = $r['email'];
                }
            }
        }
        $row['email'] = $email;
        echo json_encode($row);
        exit;
    }
    echo json_encode(['users_id' => 0, 'states_id' => 0, 'email' => '']);
    exit;
}

// ── POST: criar termo ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_type'])) {
    try {
        $item_type   = $_POST['item_type'] === 'Phone' ? 'Phone' : 'Computer';
        $computers_id = (int)($_POST['computers_id'] ?? 0);
        $phones_id    = (int)($_POST['phones_id']    ?? 0);
        $lines_id     = (int)($_POST['lines_id']     ?? 0);
        $users_id     = (int)($_POST['users_id']     ?? 0);
        $states_id    = (int)($_POST['states_id']    ?? 0);
        $action       = $_POST['action']             ?? 'sign';
        $customEmail  = trim($_POST['custom_email']  ?? '');

        // Atualiza usuário/status no inventário GLPI
        if ($item_type === 'Computer' && $computers_id > 0) {
            $upd = ['id' => $computers_id];
            if ($users_id  > 0) $upd['users_id']  = $users_id;
            if ($states_id > 0) $upd['states_id'] = $states_id;
            if (count($upd) > 1) (new Computer())->update($upd);
        } elseif ($item_type === 'Phone' && $phones_id > 0) {
            $upd = ['id' => $phones_id];
            if ($users_id  > 0) $upd['users_id']  = $users_id;
            if ($states_id > 0) $upd['states_id'] = $states_id;
            if (count($upd) > 1) (new Phone())->update($upd);

            // Vincula a linha ao celular em glpi_items_lines
            if ($lines_id > 0) {
                global $DB;
                // Remove vínculo anterior deste celular com qualquer linha
                $DB->delete('glpi_items_lines', ['itemtype' => 'Phone', 'items_id' => $phones_id]);
                // Cria novo vínculo
                $DB->insert('glpi_items_lines', [
                    'lines_id'  => $lines_id,
                    'itemtype'  => 'Phone',
                    'items_id'  => $phones_id,
                ]);
            }
        }

        $newId = PluginTermosTerm::createTerm($computers_id, $item_type, $phones_id, $lines_id);

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

// Computadores
$iterComp = $DB->request(['SELECT' => ['id', 'name', 'serial'], 'FROM' => 'glpi_computers',
                          'WHERE' => ['is_deleted' => 0], 'ORDERBY' => ['name ASC']]);
$computers = [];
foreach ($iterComp as $row) {
    $label = $row['name'];
    if (!empty($row['serial'])) $label .= ' (S/N: ' . $row['serial'] . ')';
    $computers[(int)$row['id']] = $label;
}

// Celulares
$iterPhones = $DB->request(['SELECT' => ['id', 'name', 'serial'], 'FROM' => 'glpi_phones',
                            'WHERE' => ['is_deleted' => 0], 'ORDERBY' => ['name ASC']]);
$phones = [];
foreach ($iterPhones as $row) {
    $label = $row['name'];
    if (!empty($row['serial'])) $label .= ' (S/N: ' . $row['serial'] . ')';
    $phones[(int)$row['id']] = $label;
}

// Linhas telefônicas
$iterLines = $DB->request(['SELECT' => ['id', 'name', 'caller_num'], 'FROM' => 'glpi_lines',
                           'WHERE' => ['is_deleted' => 0], 'ORDERBY' => ['name ASC']]);
$lines = [];
foreach ($iterLines as $row) {
    $label = $row['name'];
    if (!empty($row['caller_num'])) $label .= ' (' . $row['caller_num'] . ')';
    $lines[(int)$row['id']] = $label;
}

// Usuários
$iterUsers = $DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users',
                           'WHERE' => ['is_deleted' => 0, 'is_active' => 1],
                           'ORDERBY' => ['realname ASC', 'firstname ASC']]);
$users = [];
foreach ($iterUsers as $row) {
    $nome = trim($row['firstname'] . ' ' . $row['realname']) ?: $row['name'];
    $users[(int)$row['id']] = $nome . ' (' . $row['name'] . ')';
}

// Status
$iterStatus = $DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_states', 'ORDERBY' => ['name ASC']]);
$statuses = [];
foreach ($iterStatus as $row) { $statuses[(int)$row['id']] = $row['name']; }
?>

<div class="container" style="max-width:700px; margin:40px auto;">
    <h2><i class="fas fa-file-signature"></i> Gerar Novo Termo</h2>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" id="form-novo-termo">
        <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
        <input type="hidden" name="item_type" id="item_type" value="Computer">

        <!-- ── Tipo de equipamento ── -->
        <div class="form-group mb-3" style="margin-bottom:18px;">
            <label><strong>Tipo de Equipamento:</strong></label>
            <div style="display:flex; gap:12px; margin-top:8px;">
                <label style="display:flex; align-items:center; gap:6px; cursor:pointer;
                              padding:10px 18px; border:2px solid #0d6efd; border-radius:6px;
                              background:#e7f1ff; font-weight:600;" id="lbl-computer">
                    <input type="radio" name="_item_type_radio" value="Computer" checked
                           onchange="switchType('Computer')">
                    💻 Computador
                </label>
                <label style="display:flex; align-items:center; gap:6px; cursor:pointer;
                              padding:10px 18px; border:2px solid #dee2e6; border-radius:6px;
                              background:#fff; font-weight:600;" id="lbl-phone">
                    <input type="radio" name="_item_type_radio" value="Phone"
                           onchange="switchType('Phone')">
                    📱 Celular
                </label>
            </div>
        </div>

        <!-- ── Computador ── -->
        <div id="section-computer">
            <div class="form-group mb-3" style="margin-bottom:15px;">
                <label for="computers_id"><strong>Computador:</strong></label>
                <select name="computers_id" id="computers_id" class="form-control select2" style="width:100%;">
                    <option value="">-- Digite o nome ou serial --</option>
                    <?php foreach ($computers as $cid => $label): ?>
                        <option value="<?= $cid ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- ── Celular ── -->
        <div id="section-phone" style="display:none;">
            <div class="form-group mb-3" style="margin-bottom:15px;">
                <label for="phones_id"><strong>Celular:</strong></label>
                <select name="phones_id" id="phones_id" class="form-control select2" style="width:100%;">
                    <option value="">-- Digite o nome ou serial --</option>
                    <?php foreach ($phones as $pid => $label): ?>
                        <option value="<?= $pid ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-3" style="margin-bottom:15px;">
                <label for="lines_id"><strong>Linha Telefônica:</strong>
                    <small class="text-muted" style="font-weight:400;"> (opcional)</small>
                </label>
                <select name="lines_id" id="lines_id" class="form-control select2" style="width:100%;">
                    <option value="">-- Selecione a linha (opcional) --</option>
                    <?php foreach ($lines as $lid => $label): ?>
                        <option value="<?= $lid ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Quando informada, aparece no Termo de Responsabilidade.</small>
            </div>
        </div>

        <!-- ── Usuário responsável ── -->
        <div class="form-group mb-3" style="margin-bottom:15px;">
            <label for="users_id"><strong>Usuário Responsável:</strong></label>
            <select name="users_id" id="users_id" class="form-control select2" style="width:100%;">
                <option value="">-- Manter usuário atual do equipamento --</option>
                <?php foreach ($users as $uid => $label): ?>
                    <option value="<?= $uid ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Se selecionado, atualiza o usuário vinculado no GLPI.</small>
        </div>

        <!-- ── Status ── -->
        <div class="form-group mb-3" style="margin-bottom:20px;">
            <label for="states_id"><strong>Status do Equipamento:</strong></label>
            <select name="states_id" id="states_id" class="form-control select2" style="width:100%;">
                <option value="">-- Manter status atual --</option>
                <?php foreach ($statuses as $sid => $label): ?>
                    <option value="<?= $sid ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Se selecionado, atualiza o status no GLPI.</small>
        </div>

        <!-- ── E-mail ── -->
        <div id="email-section" style="display:none; margin-bottom:20px; padding:15px;
             background:#f0f7ff; border:1px solid #b8d4f5; border-radius:6px;">
            <label for="custom_email"><strong>📧 Enviar link para o e-mail:</strong></label>
            <input type="email" name="custom_email" id="custom_email" class="form-control"
                   placeholder="Selecione um equipamento para carregar o e-mail..."
                   style="margin-top:6px;">
            <small id="email-hint" class="text-muted" style="margin-top:4px; display:block;">
                Selecione o equipamento acima para carregar o e-mail do responsável.
            </small>
        </div>

        <input type="hidden" name="action" id="form-action" value="sign">

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-warning" onclick="submitAction('sign')"
                    style="font-size:1em;">✍️ Assinar Agora</button>
            <button type="button" class="btn btn-primary" onclick="toggleEmail()"
                    id="btn-email" style="font-size:1em;">📧 Enviar por E-mail</button>
            <a href="term.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<script>
$(document).ready(function () {
    const s2opts = (ph) => ({
        placeholder: ph, allowClear: true, width: '100%',
        language: { noResults: () => 'Nenhum resultado.', searching: () => 'Buscando...' },
    });

    $('#computers_id').select2(s2opts('Digite o nome ou serial do computador...'));
    $('#phones_id').select2(s2opts('Digite o nome ou serial do celular...'));
    $('#lines_id').select2(s2opts('Selecione a linha telefônica...'));
    $('#users_id').select2(s2opts('Digite o nome do usuário...'));
    $('#states_id').select2(s2opts('Selecione o status...'));

    // Computador selecionado → preenche usuário/status/email
    $('#computers_id').on('change', function () {
        const cid = $(this).val();
        if (!cid) { clearUser(); return; }
        $.getJSON(window.location.pathname + '?get_computer=1&id=' + cid, function (data) {
            prefillUser(data);
        });
    });

    // Celular selecionado → preenche usuário/status/email
    $('#phones_id').on('change', function () {
        const pid = $(this).val();
        if (!pid) { clearUser(); return; }
        $.getJSON(window.location.pathname + '?get_phone=1&id=' + pid, function (data) {
            prefillUser(data);
        });
    });

    // Usuário alterado manualmente → atualiza email
    $('#users_id').on('change', function () {
        const uid = $(this).val();
        if (!uid) { updateEmailHint('', false); return; }
        $.getJSON(window.location.pathname + '?get_user_email=1&id=' + uid, function (data) {
            updateEmailHint(data.email || '', !!data.email);
        });
    });

    function prefillUser(data) {
        if (data.users_id > 0) {
            $('#users_id').val(data.users_id).trigger('change');
        } else {
            clearUser();
        }
        if (data.states_id > 0) {
            $('#states_id').val(data.states_id).trigger('change');
        } else {
            $('#states_id').val(null).trigger('change');
        }
        updateEmailHint(data.email || '', !!data.email);
    }
    function clearUser() {
        $('#users_id').val(null).trigger('change');
        $('#states_id').val(null).trigger('change');
        updateEmailHint('', false);
    }
    function updateEmailHint(email, found) {
        const f = document.getElementById('custom_email');
        const h = document.getElementById('email-hint');
        if (!f) return;
        if (found) {
            f.value = email; f.placeholder = email;
            if (h) h.innerHTML = '✅ E-mail cadastrado no GLPI: <strong>' + email + '</strong>';
        } else {
            f.value = ''; f.placeholder = 'Usuário sem e-mail — informe aqui';
            if (h) h.innerHTML = '⚠️ Este usuário <strong>não tem e-mail cadastrado</strong>. Informe abaixo.';
        }
    }
});

function switchType(type) {
    document.getElementById('item_type').value = type;

    const isPhone = type === 'Phone';
    document.getElementById('section-computer').style.display = isPhone ? 'none' : '';
    document.getElementById('section-phone').style.display    = isPhone ? ''     : 'none';

    // Estilo dos radio buttons
    document.getElementById('lbl-computer').style.borderColor  = isPhone ? '#dee2e6' : '#0d6efd';
    document.getElementById('lbl-computer').style.background   = isPhone ? '#fff'    : '#e7f1ff';
    document.getElementById('lbl-phone').style.borderColor     = isPhone ? '#0d6efd' : '#dee2e6';
    document.getElementById('lbl-phone').style.background      = isPhone ? '#e7f1ff' : '#fff';

    // Limpa selects do tipo anterior
    if (isPhone) {
        $('#computers_id').val(null).trigger('change');
    } else {
        $('#phones_id').val(null).trigger('change');
        $('#lines_id').val(null).trigger('change');
    }
    $('#users_id').val(null).trigger('change');
    $('#states_id').val(null).trigger('change');
    document.getElementById('email-section').style.display = 'none';
    document.getElementById('btn-email').textContent = '📧 Enviar por E-mail';
}

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
