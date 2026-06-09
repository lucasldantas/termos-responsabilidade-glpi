<?php

include('../../../inc/includes.php');
Session::checkLoginUser();

// ── AJAX: process a single row ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'send_one' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $computers_id = (int)($_POST['computers_id'] ?? 0);
    $users_id     = (int)($_POST['users_id']     ?? 0);
    $customEmail  = trim($_POST['email']          ?? '');

    if (!$computers_id) {
        echo json_encode([
            'status'    => 'skip',
            'msg'       => 'Nenhum computador selecionado.',
            'new_token' => Session::getNewCSRFToken(),
        ]);
        exit;
    }
    try {
        if ($users_id > 0) {
            $computer = new Computer();
            $computer->update(['id' => $computers_id, 'users_id' => $users_id]);
        }
        $newId = PluginTermosTerm::createTerm($computers_id);
        PluginTermosTerm::sendSignEmail($newId, $customEmail);
        $data = PluginTermosTerm::getComputerWithUser($computers_id);
        $nome = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '');
        echo json_encode([
            'status'    => 'ok',
            'msg'       => ($data['computer_name'] ?? '-') . ' → ' . $nome,
            'new_token' => Session::getNewCSRFToken(),
        ]);
    } catch (\Throwable $e) {
        $data = PluginTermosTerm::getComputerWithUser($computers_id);
        echo json_encode([
            'status'    => 'error',
            'msg'       => ($data['computer_name'] ?? "Computador #{$computers_id}") . ': ' . $e->getMessage(),
            'new_token' => Session::getNewCSRFToken(),
        ]);
    }
    exit;
}

Html::header('Envio em Lote — Termos de Responsabilidade', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');

global $DB;

// ── Computadores (com serial) ─────────────────────────────────────────────────
$iterComp = $DB->request([
    'SELECT'  => ['id', 'name', 'serial'],
    'FROM'    => 'glpi_computers',
    'WHERE'   => ['is_deleted' => 0],
    'ORDERBY' => ['name ASC'],
]);
$computers         = [];   // id => label  (used by addRow select builder)
$computersDetailed = [];   // id => {label, name, serial}
foreach ($iterComp as $row) {
    $label = $row['name'];
    if (!empty($row['serial'])) $label .= ' (S/N: ' . $row['serial'] . ')';
    $id = (int)$row['id'];
    $computers[$id]         = $label;
    $computersDetailed[$id] = [
        'label'  => $label,
        'name'   => $row['name'],
        'serial' => $row['serial'] ?? '',
    ];
}

// ── Usuários (com login) ──────────────────────────────────────────────────────
$iterUsers = $DB->request([
    'SELECT'  => ['id', 'name', 'firstname', 'realname'],
    'FROM'    => 'glpi_users',
    'WHERE'   => ['is_deleted' => 0, 'is_active' => 1],
    'ORDERBY' => ['realname ASC', 'firstname ASC'],
]);
$users         = [];   // id => label
$usersDetailed = [];   // id => {label, login}
foreach ($iterUsers as $row) {
    $nome = trim($row['firstname'] . ' ' . $row['realname']) ?: $row['name'];
    $id   = (int)$row['id'];
    $users[$id]         = $nome . ' (' . $row['name'] . ')';
    $usersDetailed[$id] = [
        'label' => $nome . ' (' . $row['name'] . ')',
        'login' => $row['name'],
    ];
}

$computersJson         = json_encode($computers,         JSON_UNESCAPED_UNICODE);
$computersDetailedJson = json_encode($computersDetailed, JSON_UNESCAPED_UNICODE);
$usersJson             = json_encode($users,             JSON_UNESCAPED_UNICODE);
$usersDetailedJson     = json_encode($usersDetailed,     JSON_UNESCAPED_UNICODE);
?>

<div class="container-fluid" style="max-width:1000px; margin:30px auto; padding:0 20px;">
    <h2><i class="fas fa-paper-plane"></i> Envio em Lote de Termos</h2>
    <p class="text-muted" style="margin-bottom:20px;">
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
            <p class="text-muted" style="margin-bottom:12px; font-size:0.92em;">
                Faça upload de um arquivo CSV com as colunas:
                <strong>serial</strong>, <strong>usuario</strong>, <strong>email</strong>.
                O computador é identificado pelo número de série (único).
            </p>
            <div style="margin-bottom:10px;">
                <a id="csv-template-link" href="#" onclick="downloadCsvTemplate(event)"
                   style="font-size:0.88em; color:#0d6efd;">
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

        <div style="background:#fff; border:1px solid #dee2e6; border-radius:6px; overflow:hidden; margin-bottom:15px;">
            <table class="table table-bordered" style="margin:0;" id="batch-table">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th style="width:35%;">Computador</th>
                        <th style="width:28%;">Usuário Responsável</th>
                        <th style="width:28%;">E-mail</th>
                        <th style="width:9%;"></th>
                    </tr>
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
const COMPUTERS_DETAILED = <?= $computersDetailedJson ?>;
const USERS              = <?= $usersJson ?>;
const USERS_DETAILED     = <?= $usersDetailedJson ?>;

// ── Lookup maps for CSV import ────────────────────────────────────────────────
const COMP_BY_SERIAL = {}; // serial (lower) → id
const USER_BY_LOGIN  = {}; // login (lower)  → id

(function buildMaps() {
    for (const [id, info] of Object.entries(COMPUTERS_DETAILED)) {
        if (info.serial) COMP_BY_SERIAL[info.serial.toLowerCase()] = id;
    }
    for (const [id, info] of Object.entries(USERS_DETAILED)) {
        if (info.login) USER_BY_LOGIN[info.login.toLowerCase()] = id;
    }
})();


let rowIndex = 0;

// ── Select builder ────────────────────────────────────────────────────────────
function buildSelect(name, options, placeholder, extraClass) {
    let s = `<select name="${name}" class="form-control ${extraClass}" style="width:100%;">`;
    s += `<option value="">${placeholder}</option>`;
    for (const [id, label] of Object.entries(options)) {
        s += `<option value="${id}">${label.replace(/</g, '&lt;')}</option>`;
    }
    s += '</select>';
    return s;
}

// ── Add a table row ───────────────────────────────────────────────────────────
function addRow(compId, userId, email) {
    const i  = rowIndex++;
    const tr = document.createElement('tr');
    tr.id    = 'row-' + i;
    tr.innerHTML = `
        <td>
            ${buildSelect('batch[' + i + '][computers_id]', COMPUTERS, 'Selecione o computador...', 'sel-comp')}
        </td>
        <td>
            ${buildSelect('batch[' + i + '][users_id]', USERS, 'Usuário atual da máquina...', 'sel-user')}
        </td>
        <td>
            <input type="email" name="batch[${i}][email]" class="form-control inp-email"
                   placeholder="E-mail do usuário..." style="width:100%;">
        </td>
        <td style="text-align:center; vertical-align:middle;">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow('row-${i}')">🗑️</button>
        </td>
    `;
    document.getElementById('batch-body').appendChild(tr);

    const $comp = $(tr).find('.sel-comp');
    const $user = $(tr).find('.sel-user');

    $comp.select2({
        placeholder: 'Selecione o computador...', allowClear: true, width: '100%',
        language: { noResults: () => 'Nenhum encontrado.', searching: () => 'Buscando...' }
    });
    $user.select2({
        placeholder: 'Usuário atual da máquina...', allowClear: true, width: '100%',
        language: { noResults: () => 'Nenhum encontrado.', searching: () => 'Buscando...' }
    });

    $comp.on('change', function() {
        const cid = $(this).val();
        if (!cid) return;
        $.getJSON('term.form.php?get_computer=1&id=' + cid, function(data) {
            if (data.users_id > 0) $user.val(data.users_id).trigger('change');
            const emailInput = tr.querySelector('.inp-email');
            if (data.email) {
                emailInput.value       = data.email;
                emailInput.placeholder = data.email;
            } else {
                emailInput.value       = '';
                emailInput.placeholder = '⚠️ Sem e-mail — informe aqui';
            }
        });
    });

    if (compId) { $comp.val(String(compId)).trigger('change'); }
    if (userId) { $user.val(String(userId)).trigger('change'); }
    if (email)  { tr.querySelector('.inp-email').value = email; }

    return tr;
}

function removeRow(id) {
    const row = document.getElementById(id);
    if (row) row.remove();
    if (document.getElementById('batch-body').children.length === 0) addRow();
}

function resetForm() {
    location.reload();
}

// ── CSV section toggle ────────────────────────────────────────────────────────
function toggleCsvSection() {
    const section = document.getElementById('csv-section');
    const icon    = document.getElementById('csv-toggle-icon');
    const hidden  = section.style.display === 'none';
    section.style.display = hidden ? 'block' : 'none';
    icon.textContent = hidden ? '▲ Recolher' : '▼ Expandir';
}

// ── CSV template download ─────────────────────────────────────────────────────
function downloadCsvTemplate(e) {
    e.preventDefault();
    const content = 'serial,usuario,email\n';
    const blob    = new Blob([content], { type: 'text/csv;charset=utf-8;' });
    const url     = URL.createObjectURL(blob);
    const a       = document.createElement('a');
    a.href        = url;
    a.download    = 'modelo_lote_termos.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── CSV import ────────────────────────────────────────────────────────────────
function importCsv() {
    const fileInput = document.getElementById('csv-file');
    const summary   = document.getElementById('csv-summary');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Selecione um arquivo CSV.');
        return;
    }
    const file   = fileInput.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        const text  = e.target.result;
        const lines = text.split(/\r?\n/).filter(l => l.trim() !== '');
        if (lines.length < 2) {
            summary.style.display = 'block';
            summary.innerHTML = '<span style="color:#dc3545;">Arquivo vazio ou sem dados além do cabeçalho.</span>';
            return;
        }

        // Parse header
        const header    = lines[0].split(',').map(h => h.trim().toLowerCase());
        const colSerial = header.indexOf('serial');
        const colUser   = header.indexOf('usuario');
        const colEmail  = header.indexOf('email');

        // Clear existing rows
        document.getElementById('batch-body').innerHTML = '';
        rowIndex = 0;

        let imported = 0, notFound = 0;

        for (let li = 1; li < lines.length; li++) {
            const cols      = lines[li].split(',').map(c => c.trim());
            const serialRaw = colSerial >= 0 ? (cols[colSerial] || '') : '';
            const userRaw   = colUser   >= 0 ? (cols[colUser]   || '') : '';
            const emailVal  = colEmail  >= 0 ? (cols[colEmail]  || '') : '';

            if (!serialRaw && !userRaw && !emailVal) continue;

            // Resolve computer por serial (único)
            const compId = serialRaw ? (COMP_BY_SERIAL[serialRaw.toLowerCase()] || null) : null;

            // Resolve user por login
            const userId = userRaw ? (USER_BY_LOGIN[userRaw.toLowerCase()] || null) : null;

            if (!compId) notFound++;
            else imported++;

            addRow(compId, userId, emailVal);
        }

        // Ensure at least one row
        if (document.getElementById('batch-body').children.length === 0) addRow();

        summary.style.display = 'block';
        summary.innerHTML =
            `<span style="color:#198754;"><strong>${imported}</strong> linha(s) importada(s)</span>` +
            (notFound > 0
                ? `, <span style="color:#dc3545;"><strong>${notFound}</strong> não encontrada(s)</span>`
                : '');
    };
    reader.readAsText(file, 'UTF-8');
}

// ── AJAX batch sending ────────────────────────────────────────────────────────
async function startSending() {
    const tbody = document.getElementById('batch-body');
    const rows  = Array.from(tbody.querySelectorAll('tr'));

    // Collect row data
    const items = [];
    for (const tr of rows) {
        const compSel  = tr.querySelector('.sel-comp');
        const userSel  = tr.querySelector('.sel-user');
        const emailInp = tr.querySelector('.inp-email');
        items.push({
            tr,
            computers_id: compSel  ? compSel.value  : '',
            users_id:     userSel  ? userSel.value  : '',
            email:        emailInp ? emailInp.value : '',
        });
    }

    const hasComp = items.some(it => it.computers_id);
    if (!hasComp) {
        alert('Selecione ao menos um computador antes de enviar.');
        return;
    }

    // Token começa com o do formulário; cada resposta devolve um novo
    let csrfToken = document.querySelector('input[name="_glpi_csrf_token"]').value;
    const total   = items.length;

    // Show progress section, hide form controls
    document.getElementById('progress-section').style.display = 'block';
    document.getElementById('btn-send-all').disabled = true;
    document.getElementById('btn-add-row').disabled  = true;
    document.getElementById('progress-results').innerHTML   = '';
    document.getElementById('progress-summary').style.display = 'none';
    document.getElementById('progress-actions').style.display = 'none';

    updateProgress(0, total);

    let okCount    = 0;
    let errCount   = 0;
    let skipCount  = 0;

    for (let idx = 0; idx < items.length; idx++) {
        const item = items[idx];
        document.getElementById('progress-title').textContent =
            `Processando ${idx + 1} de ${total}...`;

        let result;
        try {
            const body = new URLSearchParams({
                computers_id:      item.computers_id,
                users_id:          item.users_id,
                email:             item.email,
                _glpi_csrf_token:  csrfToken,
            });
            const resp = await fetch('term.batch.php?action=send_one', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            result = await resp.json();
            // Usa o novo token retornado pelo servidor na próxima requisição
            if (result.new_token) csrfToken = result.new_token;
        } catch (err) {
            result = { status: 'error', msg: 'Erro de rede: ' + err.message };
        }

        addProgressResult(result);
        if (result.status === 'ok')    okCount++;
        else if (result.status === 'error') errCount++;
        else skipCount++;

        updateProgress(idx + 1, total);

        // 500ms delay between requests (skip after last)
        if (idx < items.length - 1) {
            await new Promise(r => setTimeout(r, 500));
        }
    }

    // Done
    document.getElementById('progress-title').textContent = 'Concluído!';
    setProgressBarDone();

    const summaryEl = document.getElementById('progress-summary');
    summaryEl.style.display = 'block';
    summaryEl.innerHTML =
        `<span style="color:#198754;">✅ ${okCount} enviado(s) com sucesso</span>` +
        (errCount  > 0 ? ` &nbsp;|&nbsp; <span style="color:#dc3545;">❌ ${errCount} erro(s)</span>`    : '') +
        (skipCount > 0 ? ` &nbsp;|&nbsp; <span style="color:#856404;">⚠️ ${skipCount} ignorado(s)</span>` : '');

    const actionsEl = document.getElementById('progress-actions');
    actionsEl.style.display = 'flex';
}

function updateProgress(done, total) {
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    const bar = document.getElementById('progress-bar');
    bar.style.width      = pct + '%';
    bar.textContent      = pct + '%';
    bar.setAttribute('aria-valuenow', pct);
}

function setProgressBarDone() {
    const bar = document.getElementById('progress-bar');
    bar.classList.remove('progress-bar-animated', 'progress-bar-striped', 'bg-primary');
    bar.classList.add('bg-success');
}

function addProgressResult(result) {
    const container = document.getElementById('progress-results');
    const div       = document.createElement('div');
    let bg, icon;
    if (result.status === 'ok') {
        bg = '#d1e7dd'; icon = '✅';
    } else if (result.status === 'error') {
        bg = '#f8d7da'; icon = '❌';
    } else {
        bg = '#fff3cd'; icon = '⚠️';
    }
    div.style.cssText = `padding:6px 12px; margin-bottom:5px; border-radius:4px; background:${bg}; font-size:0.9em;`;
    div.textContent   = icon + ' ' + result.msg;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// ── Init: 3 rows on load ──────────────────────────────────────────────────────
addRow(); addRow(); addRow();
</script>

<?php Html::footer(); ?>
