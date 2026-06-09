<?php

include('../../../inc/includes.php');
Session::checkLoginUser();

// ── AJAX: linha associada ao celular ─────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_phone_line') {
    header('Content-Type: application/json');
    global $DB;
    $pid = (int)($_GET['id'] ?? 0);
    $lines_id = 0;
    foreach ($DB->request(['SELECT' => ['lines_id'], 'FROM' => 'glpi_items_lines',
                           'WHERE' => ['itemtype' => 'Phone', 'items_id' => $pid], 'LIMIT' => 1]) as $r) {
        $lines_id = (int)$r['lines_id'];
    }
    echo json_encode(['lines_id' => $lines_id]);
    exit;
}

// ── AJAX: process a single row ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'send_one' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    global $DB;

    $item_type    = ($_POST['item_type'] ?? '') === 'Phone' ? 'Phone' : 'Computer';
    $computers_id = (int)($_POST['computers_id'] ?? 0);
    $phones_id    = (int)($_POST['phones_id']    ?? 0);
    $lines_id     = (int)($_POST['lines_id']     ?? 0);
    $users_id     = (int)($_POST['users_id']     ?? 0);
    $states_id    = (int)($_POST['states_id']    ?? 0);
    $customEmail  = trim($_POST['email']          ?? '');

    $itemId = $item_type === 'Phone' ? $phones_id : $computers_id;

    if (!$itemId) {
        echo json_encode([
            'status'    => 'skip',
            'msg'       => 'Nenhum equipamento selecionado.',
            'new_token' => Session::getNewCSRFToken(),
        ]);
        exit;
    }

    try {
        if ($item_type === 'Phone') {
            $upd = ['id' => $phones_id];
            if ($users_id  > 0) $upd['users_id']  = $users_id;
            if ($states_id > 0) $upd['states_id'] = $states_id;
            if (count($upd) > 1) (new Phone())->update($upd);

            if ($lines_id > 0) {
                $DB->delete('glpi_items_lines', ['itemtype' => 'Phone', 'items_id' => $phones_id]);
                $DB->insert('glpi_items_lines', ['lines_id' => $lines_id, 'itemtype' => 'Phone', 'items_id' => $phones_id]);
            }

            $newId = PluginTermosTerm::createTerm(0, 'Phone', $phones_id, $lines_id);
            PluginTermosTerm::sendSignEmail($newId, $customEmail);
            $data  = PluginTermosTerm::getPhoneWithUser($phones_id, $lines_id);
        } else {
            $upd = ['id' => $computers_id];
            if ($users_id  > 0) $upd['users_id']  = $users_id;
            if ($states_id > 0) $upd['states_id'] = $states_id;
            if (count($upd) > 1) (new Computer())->update($upd);

            $newId = PluginTermosTerm::createTerm($computers_id);
            PluginTermosTerm::sendSignEmail($newId, $customEmail);
            $data  = PluginTermosTerm::getComputerWithUser($computers_id);
        }

        $nome = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '');
        echo json_encode([
            'status'    => 'ok',
            'msg'       => ($data['computer_name'] ?? '-') . ' → ' . $nome,
            'new_token' => Session::getNewCSRFToken(),
        ]);
    } catch (\Throwable $e) {
        echo json_encode([
            'status'    => 'error',
            'msg'       => "#{$itemId}: " . $e->getMessage(),
            'new_token' => Session::getNewCSRFToken(),
        ]);
    }
    exit;
}

Html::header('Envio em Lote — Termos de Responsabilidade', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');

global $DB;

// ── Computadores ──────────────────────────────────────────────────────────────
$iterComp = $DB->request(['SELECT' => ['id', 'name', 'serial'], 'FROM' => 'glpi_computers',
                          'WHERE' => ['is_deleted' => 0], 'ORDERBY' => ['name ASC']]);
$computers = []; $computersDetailed = [];
foreach ($iterComp as $row) {
    $label = $row['name'];
    if (!empty($row['serial'])) $label .= ' (S/N: ' . $row['serial'] . ')';
    $id = (int)$row['id'];
    $computers[$id]         = $label;
    $computersDetailed[$id] = ['label' => $label, 'name' => $row['name'], 'serial' => $row['serial'] ?? ''];
}

// ── Celulares ─────────────────────────────────────────────────────────────────
$iterPhones = $DB->request(['SELECT' => ['id', 'name', 'serial'], 'FROM' => 'glpi_phones',
                            'WHERE' => ['is_deleted' => 0], 'ORDERBY' => ['name ASC']]);
$phones = []; $phonesDetailed = [];
foreach ($iterPhones as $row) {
    $label = $row['name'];
    if (!empty($row['serial'])) $label .= ' (S/N: ' . $row['serial'] . ')';
    $id = (int)$row['id'];
    $phones[$id]         = $label;
    $phonesDetailed[$id] = ['label' => $label, 'name' => $row['name'], 'serial' => $row['serial'] ?? ''];
}

// ── Linhas telefônicas ────────────────────────────────────────────────────────
$iterLines = $DB->request(['SELECT' => ['id', 'name', 'caller_num'], 'FROM' => 'glpi_lines',
                           'WHERE' => ['is_deleted' => 0], 'ORDERBY' => ['name ASC']]);
$lines = [];
foreach ($iterLines as $row) {
    $label = $row['name'];
    if (!empty($row['caller_num'])) $label .= ' (' . $row['caller_num'] . ')';
    $lines[(int)$row['id']] = $label;
}

// ── Usuários ──────────────────────────────────────────────────────────────────
$iterUsers = $DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users',
                           'WHERE' => ['is_deleted' => 0, 'is_active' => 1],
                           'ORDERBY' => ['realname ASC', 'firstname ASC']]);
$users = []; $usersDetailed = [];
foreach ($iterUsers as $row) {
    $nome = trim($row['firstname'] . ' ' . $row['realname']) ?: $row['name'];
    $id   = (int)$row['id'];
    $users[$id]         = $nome . ' (' . $row['name'] . ')';
    $usersDetailed[$id] = ['label' => $nome . ' (' . $row['name'] . ')', 'login' => $row['name']];
}

// ── Status ────────────────────────────────────────────────────────────────────
$iterStates = $DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_states', 'ORDERBY' => ['name ASC']]);
$states = [];
foreach ($iterStates as $row) { $states[(int)$row['id']] = $row['name']; }

$computersJson      = json_encode($computers,         JSON_UNESCAPED_UNICODE);
$computersDetJson   = json_encode($computersDetailed, JSON_UNESCAPED_UNICODE);
$phonesJson         = json_encode($phones,            JSON_UNESCAPED_UNICODE);
$phonesDetJson      = json_encode($phonesDetailed,    JSON_UNESCAPED_UNICODE);
$linesJson          = json_encode($lines,             JSON_UNESCAPED_UNICODE);
$usersJson          = json_encode($users,             JSON_UNESCAPED_UNICODE);
$usersDetailedJson  = json_encode($usersDetailed,     JSON_UNESCAPED_UNICODE);
$statesJson         = json_encode($states,            JSON_UNESCAPED_UNICODE);
?>

<div class="container-fluid" style="max-width:1400px; margin:30px auto; padding:0 20px;">
    <h2><i class="fas fa-paper-plane"></i> Envio em Lote de Termos</h2>

    <!-- ── Seletor de tipo ───────────────────────────────────────────────────── -->
    <div style="display:flex; gap:10px; margin-bottom:20px;">
        <label id="lbl-computer" onclick="switchMode('computer')"
               style="display:flex; align-items:center; gap:8px; cursor:pointer;
                      padding:10px 22px; border:2px solid #0d6efd; border-radius:6px;
                      background:#e7f1ff; font-weight:600; user-select:none;">
            💻 Computadores
        </label>
        <label id="lbl-phone" onclick="switchMode('phone')"
               style="display:flex; align-items:center; gap:8px; cursor:pointer;
                      padding:10px 22px; border:2px solid #dee2e6; border-radius:6px;
                      background:#fff; font-weight:600; user-select:none;">
            📱 Celulares
        </label>
    </div>

    <p id="batch-desc" class="text-muted" style="margin-bottom:20px;">
        Adicione os computadores e responsáveis. Um link de assinatura será enviado por e-mail para cada um.
    </p>

    <!-- ── CSV Import Section ───────────────────────────────────────────────── -->
    <div style="background:#fff; border:1px solid #dee2e6; border-radius:6px; margin-bottom:20px; overflow:hidden;">
        <div id="csv-toggle-header" onclick="toggleCsvSection()"
             style="display:flex; align-items:center; justify-content:space-between;
                    padding:12px 18px; cursor:pointer; background:#f8f9fa; user-select:none;">
            <span style="font-weight:600; font-size:1em;">📂 Importar via CSV</span>
            <span id="csv-toggle-icon" style="font-size:0.85em; color:#6c757d;">▼ Expandir</span>
        </div>
        <div id="csv-section" style="display:none; padding:18px;">
            <p id="csv-desc" class="text-muted" style="margin-bottom:12px; font-size:0.92em;">
                Colunas: <strong>serial</strong>, <strong>usuario</strong>, <strong>email</strong>.
                O equipamento é identificado pelo número de série.
            </p>
            <div style="margin-bottom:10px;">
                <a href="#" onclick="downloadCsvTemplate(event)" style="font-size:0.88em; color:#0d6efd;">
                    ⬇ Baixar modelo CSV
                </a>
            </div>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="file" id="csv-file" accept=".csv,.txt"
                       style="border:1px solid #ced4da; border-radius:4px; padding:5px 8px; font-size:0.92em;">
                <button type="button" class="btn btn-secondary" onclick="importCsv()">Importar</button>
            </div>
            <div id="csv-summary" style="margin-top:10px; font-size:0.9em; display:none;"></div>
        </div>
    </div>

    <!-- ── Batch Table ───────────────────────────────────────────────────────── -->
    <form id="form-batch">
        <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>

        <div style="background:#fff; border:1px solid #dee2e6; border-radius:6px; overflow-x:auto; margin-bottom:15px;">
            <table class="table table-bordered" style="margin:0; min-width:900px;" id="batch-table">
                <thead id="batch-head" style="background:#f8f9fa;">
                    <!-- gerado pelo JS via renderHeader() -->
                </thead>
                <tbody id="batch-body">
                    <!-- linhas geradas pelo JS -->
                </tbody>
            </table>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button type="button" onclick="addRow()" class="btn btn-secondary" id="btn-add-row">
                ➕ Adicionar Linha
            </button>
            <button type="button" class="btn btn-primary" style="font-size:1.05em;"
                    id="btn-send-all" onclick="startSending()">
                📧 Enviar Todos os Links
            </button>
            <a href="term.php" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>

    <!-- ── Progress Section ─────────────────────────────────────────────────── -->
    <div id="progress-section" style="display:none; margin-top:25px;
         background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:20px;">
        <h5 id="progress-title" style="margin-bottom:14px;">Processando...</h5>
        <div class="progress" style="height:22px; margin-bottom:16px; border-radius:4px;">
            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 role="progressbar" style="width:0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                0%
            </div>
        </div>
        <div id="progress-results" style="max-height:300px; overflow-y:auto; margin-bottom:14px;"></div>
        <div id="progress-summary" style="display:none; font-weight:600; margin-bottom:12px;"></div>
        <div id="progress-actions" style="display:none; display:flex; gap:10px; flex-wrap:wrap;">
            <a href="term.php" class="btn btn-secondary">← Voltar para a lista</a>
            <button type="button" class="btn btn-primary" onclick="resetForm()">+ Novo Lote</button>
        </div>
    </div>
</div>

<script>
// ── Data injected by PHP ──────────────────────────────────────────────────────
const COMPUTERS          = <?= $computersJson ?>;
const COMPUTERS_DETAILED = <?= $computersDetJson ?>;
const PHONES             = <?= $phonesJson ?>;
const PHONES_DETAILED    = <?= $phonesDetJson ?>;
const LINES              = <?= $linesJson ?>;
const USERS              = <?= $usersJson ?>;
const USERS_DETAILED     = <?= $usersDetailedJson ?>;
const STATES             = <?= $statesJson ?>;

// ── Lookup maps ───────────────────────────────────────────────────────────────
const COMP_BY_SERIAL  = {};
const PHONE_BY_SERIAL = {};
const USER_BY_LOGIN   = {};

(function buildMaps() {
    for (const [id, info] of Object.entries(COMPUTERS_DETAILED)) {
        if (info.serial) COMP_BY_SERIAL[info.serial.toLowerCase()] = id;
    }
    for (const [id, info] of Object.entries(PHONES_DETAILED)) {
        if (info.serial) PHONE_BY_SERIAL[info.serial.toLowerCase()] = id;
    }
    for (const [id, info] of Object.entries(USERS_DETAILED)) {
        if (info.login) USER_BY_LOGIN[info.login.toLowerCase()] = id;
    }
})();

let rowIndex   = 0;
let currentMode = 'computer'; // 'computer' | 'phone'

// ── Select builder ────────────────────────────────────────────────────────────
function buildSelect(name, options, placeholder, extraClass) {
    let s = `<select name="${name}" class="form-control ${extraClass}" style="width:100%;">`;
    s += `<option value="">${placeholder}</option>`;
    for (const [id, label] of Object.entries(options)) {
        s += `<option value="${id}">${String(label).replace(/</g, '&lt;')}</option>`;
    }
    s += '</select>';
    return s;
}

// ── Mode switch ───────────────────────────────────────────────────────────────
function switchMode(mode) {
    if (currentMode === mode) return;
    currentMode = mode;

    const isPhone = mode === 'phone';

    document.getElementById('lbl-computer').style.borderColor = isPhone ? '#dee2e6' : '#0d6efd';
    document.getElementById('lbl-computer').style.background  = isPhone ? '#fff'    : '#e7f1ff';
    document.getElementById('lbl-phone').style.borderColor    = isPhone ? '#0d6efd' : '#dee2e6';
    document.getElementById('lbl-phone').style.background     = isPhone ? '#e7f1ff' : '#fff';

    document.getElementById('batch-desc').textContent = isPhone
        ? 'Adicione os celulares e responsáveis. Um link de assinatura será enviado por e-mail para cada um.'
        : 'Adicione os computadores e responsáveis. Um link de assinatura será enviado por e-mail para cada um.';

    document.getElementById('csv-desc').innerHTML = isPhone
        ? 'Colunas: <strong>serial</strong>, <strong>linha</strong>, <strong>usuario</strong>, <strong>email</strong>. O celular é identificado pelo número de série.'
        : 'Colunas: <strong>serial</strong>, <strong>usuario</strong>, <strong>email</strong>. O computador é identificado pelo número de série.';

    renderHeader();
    document.getElementById('batch-body').innerHTML = '';
    rowIndex = 0;
    addRow(); addRow(); addRow();
}

// ── Table header ──────────────────────────────────────────────────────────────
function renderHeader() {
    const isPhone = currentMode === 'phone';
    let th = '<tr>';
    if (isPhone) {
        th += '<th style="min-width:200px;">Celular</th>';
        th += '<th style="min-width:160px;">Linha Telefônica</th>';
    } else {
        th += '<th style="min-width:220px;">Computador</th>';
    }
    th += '<th style="min-width:150px;">Status</th>';
    th += '<th style="min-width:200px;">Usuário Responsável</th>';
    th += '<th style="min-width:180px;">E-mail</th>';
    th += '<th style="min-width:50px;"></th>';
    th += '</tr>';
    document.getElementById('batch-head').innerHTML = th;
}

// ── Add a table row ───────────────────────────────────────────────────────────
function addRow(itemId, lineId, userId, email) {
    const i       = rowIndex++;
    const isPhone = currentMode === 'phone';
    const tr      = document.createElement('tr');
    tr.id         = 'row-' + i;
    tr.dataset.type = currentMode;

    let html = '';

    if (isPhone) {
        html += `<td>${buildSelect('batch[${i}][phones_id]',  PHONES, 'Selecione o celular...', 'sel-phone')}</td>`;
        html += `<td>${buildSelect('batch[${i}][lines_id]',   LINES,  'Sem linha / manter...',  'sel-line')}</td>`;
        html += `<input type="hidden" name="batch[${i}][item_type]" value="Phone">`;
    } else {
        html += `<td>${buildSelect('batch[${i}][computers_id]', COMPUTERS, 'Selecione o computador...', 'sel-comp')}</td>`;
        html += `<input type="hidden" name="batch[${i}][item_type]" value="Computer">`;
    }

    html += `<td>${buildSelect('batch[${i}][states_id]', STATES, 'Manter atual...', 'sel-state')}</td>`;
    html += `<td>${buildSelect('batch[${i}][users_id]',  USERS,  'Usuário atual...', 'sel-user')}</td>`;
    html += `<td><input type="email" name="batch[${i}][email]" class="form-control inp-email" placeholder="E-mail do usuário..." style="width:100%;"></td>`;
    html += `<td style="text-align:center; vertical-align:middle;"><button type="button" class="btn btn-sm btn-danger" onclick="removeRow('row-${i}')">🗑️</button></td>`;

    tr.innerHTML = html;
    document.getElementById('batch-body').appendChild(tr);

    const s2 = (ph) => ({ placeholder: ph, allowClear: true, width: '100%',
        language: { noResults: () => 'Nenhum encontrado.', searching: () => 'Buscando...' } });

    const $state = $(tr).find('.sel-state');
    const $user  = $(tr).find('.sel-user');
    $state.select2(s2('Manter atual...'));
    $user.select2(s2('Usuário atual...'));

    function fillFromData(data) {
        if (data.states_id > 0) $state.val(String(data.states_id)).trigger('change');
        else $state.val(null).trigger('change');
        if (data.users_id > 0) $user.val(String(data.users_id)).trigger('change');
        else $user.val(null).trigger('change');
        const emailInput = tr.querySelector('.inp-email');
        if (data.email) { emailInput.value = data.email; emailInput.placeholder = data.email; }
        else            { emailInput.value = ''; emailInput.placeholder = '⚠️ Sem e-mail — informe aqui'; }
    }

    if (isPhone) {
        const $phone = $(tr).find('.sel-phone');
        const $line  = $(tr).find('.sel-line');
        $phone.select2(s2('Selecione o celular...'));
        $line.select2(s2('Sem linha / manter...'));

        $phone.on('change', function() {
            const pid = $(this).val();
            if (!pid) return;
            $.getJSON('term.form.php?get_phone=1&id=' + pid, function(data) {
                fillFromData(data);
                // Pré-preenche a linha associada ao celular
                $.getJSON('term.batch.php?action=get_phone_line&id=' + pid, function(ld) {
                    if (ld.lines_id > 0) $line.val(String(ld.lines_id)).trigger('change');
                });
            });
        });

        if (itemId) $phone.val(String(itemId)).trigger('change');
        if (lineId) $line.val(String(lineId)).trigger('change');
    } else {
        const $comp = $(tr).find('.sel-comp');
        $comp.select2(s2('Selecione o computador...'));
        $comp.on('change', function() {
            const cid = $(this).val();
            if (!cid) return;
            $.getJSON('term.form.php?get_computer=1&id=' + cid, fillFromData);
        });
        if (itemId) $comp.val(String(itemId)).trigger('change');
    }

    if (userId) $user.val(String(userId)).trigger('change');
    if (email)  tr.querySelector('.inp-email').value = email;

    return tr;
}

function removeRow(id) {
    const row = document.getElementById(id);
    if (row) row.remove();
    if (document.getElementById('batch-body').children.length === 0) addRow();
}

function resetForm() { location.reload(); }

// ── CSV section ───────────────────────────────────────────────────────────────
function toggleCsvSection() {
    const section = document.getElementById('csv-section');
    const icon    = document.getElementById('csv-toggle-icon');
    const hidden  = section.style.display === 'none';
    section.style.display = hidden ? 'block' : 'none';
    icon.textContent = hidden ? '▲ Recolher' : '▼ Expandir';
}

function downloadCsvTemplate(e) {
    e.preventDefault();
    const isPhone = currentMode === 'phone';
    const content = isPhone ? 'serial,linha,usuario,email\n' : 'serial,usuario,email\n';
    const blob    = new Blob([content], { type: 'text/csv;charset=utf-8;' });
    const url     = URL.createObjectURL(blob);
    const a       = document.createElement('a');
    a.href = url; a.download = 'modelo_lote_termos.csv';
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
}

function importCsv() {
    const fileInput = document.getElementById('csv-file');
    const summary   = document.getElementById('csv-summary');
    if (!fileInput.files || !fileInput.files.length) { alert('Selecione um arquivo CSV.'); return; }

    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.split(/\r?\n/).filter(l => l.trim() !== '');
        if (lines.length < 2) {
            summary.style.display = 'block';
            summary.innerHTML = '<span style="color:#dc3545;">Arquivo vazio ou sem dados além do cabeçalho.</span>';
            return;
        }

        const isPhone   = currentMode === 'phone';
        const header    = lines[0].split(',').map(h => h.trim().toLowerCase());
        const colSerial = header.indexOf('serial');
        const colLinha  = header.indexOf('linha');
        const colUser   = header.indexOf('usuario');
        const colEmail  = header.indexOf('email');

        document.getElementById('batch-body').innerHTML = '';
        rowIndex = 0;

        let imported = 0, notFound = 0;

        for (let li = 1; li < lines.length; li++) {
            const cols      = lines[li].split(',').map(c => c.trim());
            const serialRaw = colSerial >= 0 ? (cols[colSerial] || '') : '';
            const linhaRaw  = colLinha  >= 0 ? (cols[colLinha]  || '') : '';
            const userRaw   = colUser   >= 0 ? (cols[colUser]   || '') : '';
            const emailVal  = colEmail  >= 0 ? (cols[colEmail]  || '') : '';

            if (!serialRaw && !userRaw && !emailVal) continue;

            const itemId = serialRaw
                ? (isPhone ? (PHONE_BY_SERIAL[serialRaw.toLowerCase()] || null)
                           : (COMP_BY_SERIAL[serialRaw.toLowerCase()]  || null))
                : null;
            const userId = userRaw ? (USER_BY_LOGIN[userRaw.toLowerCase()] || null) : null;

            // Resolve linha pelo nome/número
            let lineId = null;
            if (isPhone && linhaRaw) {
                for (const [id, label] of Object.entries(LINES)) {
                    if (label.toLowerCase().includes(linhaRaw.toLowerCase())) { lineId = id; break; }
                }
            }

            if (!itemId) notFound++; else imported++;
            addRow(itemId, lineId, userId, emailVal);
        }

        if (!document.getElementById('batch-body').children.length) addRow();

        summary.style.display = 'block';
        summary.innerHTML =
            `<span style="color:#198754;"><strong>${imported}</strong> linha(s) importada(s)</span>` +
            (notFound > 0 ? `, <span style="color:#dc3545;"><strong>${notFound}</strong> não encontrada(s)</span>` : '');
    };
    reader.readAsText(fileInput.files[0], 'UTF-8');
}

// ── AJAX batch sending ────────────────────────────────────────────────────────
async function startSending() {
    const tbody = document.getElementById('batch-body');
    const rows  = Array.from(tbody.querySelectorAll('tr'));

    const items = [];
    for (const tr of rows) {
        const type     = tr.dataset.type || 'computer';
        const isPhone  = type === 'phone';
        const stateSel = tr.querySelector('.sel-state');
        const userSel  = tr.querySelector('.sel-user');
        const emailInp = tr.querySelector('.inp-email');
        const item     = {
            tr,
            item_type:    isPhone ? 'Phone' : 'Computer',
            computers_id: '',
            phones_id:    '',
            lines_id:     '',
            states_id:    stateSel ? stateSel.value : '',
            users_id:     userSel  ? userSel.value  : '',
            email:        emailInp ? emailInp.value : '',
        };
        if (isPhone) {
            const phoneSel = tr.querySelector('.sel-phone');
            const lineSel  = tr.querySelector('.sel-line');
            item.phones_id = phoneSel ? phoneSel.value : '';
            item.lines_id  = lineSel  ? lineSel.value  : '';
        } else {
            const compSel  = tr.querySelector('.sel-comp');
            item.computers_id = compSel ? compSel.value : '';
        }
        items.push(item);
    }

    const hasItem = items.some(it => it.computers_id || it.phones_id);
    if (!hasItem) {
        alert('Selecione ao menos um equipamento antes de enviar.');
        return;
    }

    let csrfToken = document.querySelector('input[name="_glpi_csrf_token"]').value;
    const total   = items.length;

    document.getElementById('progress-section').style.display = 'block';
    document.getElementById('btn-send-all').disabled = true;
    document.getElementById('btn-add-row').disabled  = true;
    document.getElementById('progress-results').innerHTML      = '';
    document.getElementById('progress-summary').style.display  = 'none';
    document.getElementById('progress-actions').style.display  = 'none';
    updateProgress(0, total);

    let okCount = 0, errCount = 0, skipCount = 0;

    for (let idx = 0; idx < items.length; idx++) {
        const item = items[idx];
        document.getElementById('progress-title').textContent = `Processando ${idx + 1} de ${total}...`;

        let result;
        try {
            const body = new URLSearchParams({
                item_type:        item.item_type,
                computers_id:     item.computers_id,
                phones_id:        item.phones_id,
                lines_id:         item.lines_id,
                states_id:        item.states_id,
                users_id:         item.users_id,
                email:            item.email,
                _glpi_csrf_token: csrfToken,
            });
            const resp = await fetch('term.batch.php?action=send_one', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            result = await resp.json();
            if (result.new_token) csrfToken = result.new_token;
        } catch (err) {
            result = { status: 'error', msg: 'Erro de rede: ' + err.message };
        }

        addProgressResult(result);
        if (result.status === 'ok')         okCount++;
        else if (result.status === 'error') errCount++;
        else                                skipCount++;

        updateProgress(idx + 1, total);
        if (idx < items.length - 1) await new Promise(r => setTimeout(r, 500));
    }

    document.getElementById('progress-title').textContent = 'Concluído!';
    setProgressBarDone();

    const summaryEl = document.getElementById('progress-summary');
    summaryEl.style.display = 'block';
    summaryEl.innerHTML =
        `<span style="color:#198754;">✅ ${okCount} enviado(s) com sucesso</span>` +
        (errCount  > 0 ? ` &nbsp;|&nbsp; <span style="color:#dc3545;">❌ ${errCount} erro(s)</span>`      : '') +
        (skipCount > 0 ? ` &nbsp;|&nbsp; <span style="color:#856404;">⚠️ ${skipCount} ignorado(s)</span>` : '');

    document.getElementById('progress-actions').style.display = 'flex';
}

function updateProgress(done, total) {
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    const bar = document.getElementById('progress-bar');
    bar.style.width = pct + '%'; bar.textContent = pct + '%';
    bar.setAttribute('aria-valuenow', pct);
}
function setProgressBarDone() {
    const bar = document.getElementById('progress-bar');
    bar.classList.remove('progress-bar-animated', 'progress-bar-striped', 'bg-primary');
    bar.classList.add('bg-success');
}
function addProgressResult(result) {
    const container = document.getElementById('progress-results');
    const div = document.createElement('div');
    const bg  = result.status === 'ok' ? '#d1e7dd' : result.status === 'error' ? '#f8d7da' : '#fff3cd';
    const ico = result.status === 'ok' ? '✅' : result.status === 'error' ? '❌' : '⚠️';
    div.style.cssText = `padding:6px 12px; margin-bottom:5px; border-radius:4px; background:${bg}; font-size:0.9em;`;
    div.textContent = ico + ' ' + result.msg;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// ── Init ──────────────────────────────────────────────────────────────────────
renderHeader();
addRow(); addRow(); addRow();
</script>

<?php Html::footer(); ?>
