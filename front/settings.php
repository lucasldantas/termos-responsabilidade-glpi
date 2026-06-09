<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight('config', READ)) {
    Html::displayErrorAndDie('Acesso negado.');
}

$configFile = __DIR__ . '/../config/settings.json';

$defaults = [
    // Campos de Dados do Equipamento
    'eq_computador'     => true,
    'eq_serie'          => true,
    'eq_patrimonio'     => true,
    'eq_setor'          => true,
    // Campos de Responsável
    'resp_nome'         => true,
    'resp_login'        => true,
    'resp_telefone'     => true,
    // Texto do termo de responsabilidade
    'termos_texto'      => implode("\n", [
        "Recebi o equipamento acima em perfeito estado de funcionamento e conservação.",
        "Comprometo-me a zelar pela guarda, conservação e uso adequado, utilizando-o exclusivamente para fins profissionais.",
        "Estou ciente de que não devo instalar softwares não autorizados ou acessar conteúdos inadequados.",
        "Comunicarei imediatamente ao setor de TI qualquer avaria, perda, furto ou roubo.",
        "Serei responsabilizado por danos causados por mau uso, negligência ou extravio.",
    ]),
    'rodape_texto'      => 'As partes concordam que este termo é assinado por meio eletrônico, reconhecendo sua validade jurídica conforme art. 10, § 2º da MP nº 2.200-2/2001 e Lei nº 14.063/2020.',
    // Texto do termo de devolução
    'devol_texto'       => "Pelo presente instrumento, a empresa declara ter recebido em devolução o equipamento acima identificado, anteriormente sob responsabilidade do(a) colaborador(a) identificado(a) neste documento.\n\nO equipamento foi devolvido e inspecionado pelo setor de TI, encerrando-se assim todas as obrigações e responsabilidades do(a) colaborador(a) referentes a este equipamento, salvo eventuais danos identificados na vistoria de recebimento.\n\nEste documento anula o Termo de Responsabilidade anteriormente firmado.",
    'devol_rodape'      => 'Documento emitido pelo setor de TI.',
    'devol_entregue_por'    => 'Entregue por:',
    'devol_recebido_por'    => 'Recebido por (TI):',
    // Assinatura remota
    'token_validity_days'   => 7,
    'email_assunto'         => 'Assine seu Termo de Responsabilidade - {computador}',
    'email_incluir_logo'    => true,
    'email_corpo'           => implode("\n\n", [
        'Olá, <strong>{nome}</strong>!',
        'Você recebeu o equipamento abaixo e precisa assinar o <strong>Termo de Responsabilidade</strong>.',
        'Após a assinatura, uma cópia do documento será enviada para este e-mail.',
        '<em>Se não conseguir clicar no botão, copie e cole o link abaixo no seu navegador.</em>',
    ]),
];

$config = file_exists($configFile)
    ? array_merge($defaults, json_decode(file_get_contents($configFile), true) ?? [])
    : $defaults;

$logoFile = __DIR__ . '/../config/logo.png';
$logoMsg  = '';

if (($_GET['logo_saved'] ?? '') === '1')   $logoMsg = 'Logo atualizada com sucesso!';
if (($_GET['logo_removed'] ?? '') === '1') $logoMsg = 'Logo removida.';

// Encontra logo atual (qualquer extensão)
$logoAtual = '';
foreach (['png','jpg','jpeg','gif','webp'] as $ext) {
    $candidate = __DIR__ . '/../config/logo.' . $ext;
    if (file_exists($candidate)) { $logoAtual = $candidate; break; }
}

// Campos disponíveis do GLPI
$availableComputerFields = [
    'computer_name' => 'Nome do Computador',
    'serial'        => 'Número de Série',
    'patrimonio'    => 'Patrimônio',
    'entity'        => 'Setor/Entidade',
    'model'         => 'Modelo',
    'manufacturer'  => 'Fabricante',
];
$availableUserFields = [
    'fullname'            => 'Nome Completo (Nome + Sobrenome)',
    'firstname'           => 'Nome',
    'realname'            => 'Sobrenome',
    'username'            => 'Login GLPI',
    'phone'               => 'Telefone',
    'title'               => 'Título',
    'registration_number' => 'Número Administrativo',
    'category'            => 'Categoria',
];

// Defaults dos campos dinâmicos
$defaultComputerFields = [
    ['enabled' => true,  'label' => 'Computador',       'field' => 'computer_name'],
    ['enabled' => true,  'label' => 'Número de Série',  'field' => 'serial'],
    ['enabled' => true,  'label' => 'Patrimônio',       'field' => 'patrimonio'],
    ['enabled' => true,  'label' => 'Setor',            'field' => 'entity'],
];
$defaultUserFields = [
    ['enabled' => true,  'label' => 'Nome Completo',    'field' => 'fullname'],
    ['enabled' => true,  'label' => 'Login',            'field' => 'username'],
    ['enabled' => true,  'label' => 'Telefone',         'field' => 'phone'],
];

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Processa campos dinâmicos do equipamento
    $compFields = [];
    foreach ($_POST['comp_label'] ?? [] as $i => $label) {
        $field = $_POST['comp_field'][$i] ?? '';
        if (!empty($field)) {
            $compFields[] = [
                'enabled' => isset($_POST['comp_enabled'][$i]),
                'label'   => trim($label),
                'field'   => $field,
            ];
        }
    }
    // Processa campos dinâmicos do usuário
    $userFields = [];
    foreach ($_POST['user_label'] ?? [] as $i => $label) {
        $field = $_POST['user_field'][$i] ?? '';
        if (!empty($field)) {
            $userFields[] = [
                'enabled' => isset($_POST['user_enabled'][$i]),
                'label'   => trim($label),
                'field'   => $field,
            ];
        }
    }

    $config = [
        'fields_computer'      => $compFields ?: $defaultComputerFields,
        'fields_user'          => $userFields ?: $defaultUserFields,
        'termos_texto'         => trim($_POST['termos_texto']         ?? ''),
        'rodape_texto'         => trim($_POST['rodape_texto']         ?? ''),
        'devol_texto'          => trim($_POST['devol_texto']          ?? ''),
        'devol_rodape'         => trim($_POST['devol_rodape']         ?? ''),
        'devol_entregue_por'   => trim($_POST['devol_entregue_por']   ?? ''),
        'devol_recebido_por'   => trim($_POST['devol_recebido_por']   ?? ''),
        'token_validity_days'  => max(1, (int)($_POST['token_validity_days'] ?? 7)),
        'email_assunto'        => trim($_POST['email_assunto']        ?? ''),
        'email_corpo'          => trim($_POST['email_corpo']          ?? ''),
    ];
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $saved = true;
}

// Carrega campos dinâmicos com fallback para defaults
$compFields = $config['fields_computer'] ?? $defaultComputerFields;
$userFields = $config['fields_user']     ?? $defaultUserFields;

Html::header('Configurações - Termos de Responsabilidade', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');
?>

<div class="container" style="max-width:750px; margin:40px auto;">
    <h2><i class="fas fa-cog"></i> Configurações do Termo</h2>

    <?php if ($saved): ?>
        <div class="alert alert-success">✅ Configurações salvas com sucesso!</div>
    <?php endif; ?>
    <?php if (!empty($logoMsg)): ?>
        <div class="alert <?= str_contains($logoMsg,'sucesso') ? 'alert-success' : 'alert-danger' ?>"><?= htmlspecialchars($logoMsg) ?></div>
    <?php endif; ?>

    <!-- Logo da Empresa -->
    <div style="border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; background:#fff;">
        <h4 style="margin-top:0; color:#0d6efd; border-bottom:1px solid #eee; padding-bottom:8px;">
            <i class="fas fa-image"></i> Logo da Empresa nos PDFs
        </h4>
        <?php if (!empty($logoAtual)): ?>
            <div style="margin-bottom:12px;">
                <img src="logo_serve.php?<?= time() ?>"
                     style="max-height:70px; max-width:280px; border:1px solid #dee2e6; border-radius:4px; padding:6px; background:#f8f9fa; display:block; margin-bottom:8px;">
                <span style="color:#198754; font-size:0.88em;">✅ Logo cadastrada</span>
            </div>
        <?php else: ?>
            <p style="color:#888; font-size:0.88em; margin-bottom:10px;">Nenhuma logo cadastrada. Os PDFs serão gerados sem logo.</p>
        <?php endif; ?>
        <a href="logo_upload.php" class="btn btn-secondary">
            <i class="fas fa-upload"></i> Gerenciar Logo
        </a>
    </div>

    <form method="POST" action="" enctype="multipart/form-data">
        <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>

        <!-- Campos do Equipamento -->
        <div style="border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; background:#fff;">
            <h4 style="margin-top:0; color:#0d6efd; border-bottom:1px solid #eee; padding-bottom:8px;">
                <i class="fas fa-desktop"></i> Campos — Equipamento
            </h4>
            <p class="text-muted" style="font-size:0.83em; margin-bottom:10px;">
                Defina quais campos do computador aparecem no termo. Você pode renomear o label e reordenar.
            </p>
            <table style="width:100%; border-collapse:collapse;" id="table-comp">
                <thead>
                    <tr style="font-size:0.83em; color:#555; border-bottom:1px solid #eee;">
                        <th style="width:40px; padding:4px;">Ativo</th>
                        <th style="padding:4px;">Label no PDF</th>
                        <th style="padding:4px;">Campo do GLPI</th>
                        <th style="width:36px;"></th>
                    </tr>
                </thead>
                <tbody id="comp-body">
                <?php foreach ($compFields as $i => $cf): ?>
                <tr class="field-row">
                    <td style="text-align:center;">
                        <input type="checkbox" name="comp_enabled[<?= $i ?>]" <?= $cf['enabled'] ? 'checked' : '' ?>>
                    </td>
                    <td style="padding:3px;">
                        <input type="text" name="comp_label[<?= $i ?>]" value="<?= htmlspecialchars($cf['label']) ?>"
                               class="form-control form-control-sm" style="width:100%;">
                    </td>
                    <td style="padding:3px;">
                        <select name="comp_field[<?= $i ?>]" class="form-control form-control-sm">
                            <?php foreach ($availableComputerFields as $fk => $fn): ?>
                                <option value="<?= $fk ?>" <?= $cf['field']===$fk?'selected':'' ?>><?= $fn ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-danger">✕</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" onclick="addRow('comp','comp-body')" class="btn btn-sm btn-secondary" style="margin-top:8px;">
                ➕ Adicionar campo
            </button>
        </div>

        <!-- Campos do Usuário -->
        <div style="border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; background:#fff;">
            <h4 style="margin-top:0; color:#0d6efd; border-bottom:1px solid #eee; padding-bottom:8px;">
                <i class="fas fa-user"></i> Campos — Responsável
            </h4>
            <p class="text-muted" style="font-size:0.83em; margin-bottom:10px;">
                Defina quais dados do usuário aparecem no termo.
            </p>
            <table style="width:100%; border-collapse:collapse;" id="table-user">
                <thead>
                    <tr style="font-size:0.83em; color:#555; border-bottom:1px solid #eee;">
                        <th style="width:40px; padding:4px;">Ativo</th>
                        <th style="padding:4px;">Label no PDF</th>
                        <th style="padding:4px;">Campo do GLPI</th>
                        <th style="width:36px;"></th>
                    </tr>
                </thead>
                <tbody id="user-body">
                <?php foreach ($userFields as $i => $uf): ?>
                <tr class="field-row">
                    <td style="text-align:center;">
                        <input type="checkbox" name="user_enabled[<?= $i ?>]" <?= $uf['enabled'] ? 'checked' : '' ?>>
                    </td>
                    <td style="padding:3px;">
                        <input type="text" name="user_label[<?= $i ?>]" value="<?= htmlspecialchars($uf['label']) ?>"
                               class="form-control form-control-sm" style="width:100%;">
                    </td>
                    <td style="padding:3px;">
                        <select name="user_field[<?= $i ?>]" class="form-control form-control-sm">
                            <?php foreach ($availableUserFields as $fk => $fn): ?>
                                <option value="<?= $fk ?>" <?= $uf['field']===$fk?'selected':'' ?>><?= $fn ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-danger">✕</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" onclick="addRow('user','user-body')" class="btn btn-sm btn-secondary" style="margin-top:8px;">
                ➕ Adicionar campo
            </button>
        </div>

        <!-- Texto Termo de Responsabilidade -->
        <div style="border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; background:#fff;">
            <h4 style="margin-top:0; color:#0d6efd; border-bottom:1px solid #eee; padding-bottom:8px;">
                <i class="fas fa-file-signature"></i> Termo de Responsabilidade — Cláusulas
            </h4>
            <p class="text-muted" style="font-size:0.85em;">Uma cláusula por linha. Cada linha vira um item numerado.</p>
            <textarea name="termos_texto" rows="8" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:0.95em;"><?= htmlspecialchars($config['termos_texto']) ?></textarea>

            <label style="margin-top:12px; display:block;"><strong>Rodapé (base legal):</strong></label>
            <textarea name="rodape_texto" rows="2" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:0.95em; margin-top:5px;"><?= htmlspecialchars($config['rodape_texto']) ?></textarea>
        </div>

        <!-- Texto Termo de Devolução -->
        <div style="border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; background:#fff;">
            <h4 style="margin-top:0; color:#6c757d; border-bottom:1px solid #eee; padding-bottom:8px;">
                <i class="fas fa-box"></i> Termo de Devolução — Texto
            </h4>
            <p class="text-muted" style="font-size:0.85em;">
                Variáveis disponíveis: <code>{nome}</code>, <code>{computador}</code>, <code>{serial}</code>, <code>{data_assinatura}</code>, <code>{numero_termo}</code>, <code>{data_hoje}</code>, <code>{emissor}</code>
            </p>
            <textarea name="devol_texto" rows="8" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:0.95em;"><?= htmlspecialchars($config['devol_texto']) ?></textarea>

            <label style="margin-top:12px; display:block;"><strong>Rodapé:</strong></label>
            <textarea name="devol_rodape" rows="2" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:0.95em; margin-top:5px;"><?= htmlspecialchars($config['devol_rodape']) ?></textarea>

            <div style="display:flex; gap:15px; margin-top:12px;">
                <div style="flex:1;">
                    <label><strong>Label "Entregue por":</strong></label>
                    <input type="text" name="devol_entregue_por" value="<?= htmlspecialchars($config['devol_entregue_por']) ?>"
                        style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; margin-top:4px;">
                </div>
                <div style="flex:1;">
                    <label><strong>Label "Recebido por":</strong></label>
                    <input type="text" name="devol_recebido_por" value="<?= htmlspecialchars($config['devol_recebido_por']) ?>"
                        style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; margin-top:4px;">
                </div>
            </div>
        </div>

        <!-- E-mail de Assinatura -->
        <div style="border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; background:#fff;">
            <h4 style="margin-top:0; color:#0d6efd; border-bottom:1px solid #eee; padding-bottom:8px;">
                <i class="fas fa-envelope"></i> E-mail de Solicitação de Assinatura
            </h4>
            <p class="text-muted" style="font-size:0.85em; margin-bottom:14px;">
                Variáveis disponíveis: <code>{nome}</code>, <code>{computador}</code>, <code>{serial}</code>,
                <code>{setor}</code>, <code>{link}</code>, <code>{dias_validade}</code>.<br>
                Use HTML básico para formatação: <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;br&gt;</code>.
            </p>

            <!-- Assunto -->
            <div style="margin-bottom:14px;">
                <label><strong>Assunto do e-mail:</strong></label>
                <input type="text" name="email_assunto"
                       value="<?= htmlspecialchars($config['email_assunto'] ?? '') ?>"
                       class="form-control" style="margin-top:5px;">
            </div>

            <!-- Corpo -->
            <div style="margin-bottom:14px;">
                <label><strong>Texto do e-mail:</strong></label>
                <p class="text-muted" style="font-size:0.82em; margin:3px 0 6px;">
                    Escreva o corpo da mensagem. A tabela com os dados do equipamento e o botão de assinatura são adicionados automaticamente.
                </p>
                <textarea name="email_corpo" rows="7"
                          style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:0.95em; font-family:inherit;"
                          placeholder="Ex: Olá, {nome}! Você recebeu o equipamento abaixo..."><?= htmlspecialchars($config['email_corpo'] ?? '') ?></textarea>
            </div>

            <!-- Preview -->
            <div style="margin-top:8px;">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleEmailPreview()">
                    👁 Visualizar prévia do e-mail
                </button>
                <div id="email-preview" style="display:none; margin-top:14px; border:1px solid #dee2e6;
                     border-radius:6px; overflow:hidden; background:#f8f9fa; padding:12px;">
                    <p style="font-size:0.8em; color:#888; margin-bottom:8px;">— Prévia aproximada —</p>
                    <div id="email-preview-body" style="background:#fff; padding:16px; border-radius:4px; font-family:Arial,sans-serif; font-size:0.9em;"></div>
                </div>
            </div>
        </div>

        <!-- Assinatura Remota -->
        <div style="border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; background:#fff;">
            <h4 style="margin-top:0; color:#0d6efd; border-bottom:1px solid #eee; padding-bottom:8px;">
                <i class="fas fa-link"></i> Assinatura Remota — Link por E-mail
            </h4>
            <p class="text-muted" style="font-size:0.85em;">
                Quando você clica em <strong>🔗 Enviar Link</strong> na lista de termos, o usuário recebe um e-mail
                com um link público para assinar sem precisar de login no GLPI.
            </p>
            <div style="margin-top:12px;">
                <label><strong>Validade do link de assinatura:</strong></label>
                <select name="token_validity_days" class="form-control" style="width:200px; margin-top:5px;">
                    <?php foreach ([1 => '1 dia', 3 => '3 dias', 7 => '7 dias (padrão)', 15 => '15 dias', 30 => '30 dias'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= ($config['token_validity_days'] ?? 7) == $v ? 'selected' : '' ?>>
                            <?= $l ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted" style="display:block; margin-top:4px;">
                    Após este prazo o link expira e um novo deverá ser gerado.
                </small>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Salvar Configurações
        </button>
        <a href="term.php" class="btn btn-secondary">Voltar</a>
    </form>
</div>

<script>
// Dados dos campos disponíveis (para geração dinâmica de rows)
const compFieldOptions = <?= json_encode($availableComputerFields, JSON_UNESCAPED_UNICODE) ?>;
const userFieldOptions = <?= json_encode($availableUserFields,    JSON_UNESCAPED_UNICODE) ?>;

function removeRow(btn) {
    btn.closest('tr').remove();
    reindexRows('comp', 'comp-body');
    reindexRows('user', 'user-body');
}

function reindexRows(prefix, bodyId) {
    const rows = document.getElementById(bodyId).querySelectorAll('tr.field-row');
    rows.forEach(function(row, i) {
        row.querySelector('input[type=checkbox]').name  = prefix + '_enabled[' + i + ']';
        row.querySelector('input[type=text]').name      = prefix + '_label['   + i + ']';
        row.querySelector('select').name                = prefix + '_field['   + i + ']';
    });
}

// Campos configurados (injetados pelo PHP para uso na prévia)
const PREVIEW_COMP_FIELDS = <?= json_encode($compFields, JSON_UNESCAPED_UNICODE) ?>;
const PREVIEW_USER_FIELDS = <?= json_encode($userFields, JSON_UNESCAPED_UNICODE) ?>;

// Valores de exemplo para prévia
const PREVIEW_DATA = {
    computer_name: 'NOTEBOOK-001', serial: 'SN123456', patrimonio: '32055',
    entity: 'Administrativo', model: 'ThinkPad X1', manufacturer: 'Lenovo',
    fullname: 'João da Silva', firstname: 'João', realname: 'da Silva',
    username: 'joaosilva', phone: '(11) 99999-9999',
    title: 'Analista', registration_number: '12345', category: 'TI',
};

function resolvePreviewField(field) {
    return PREVIEW_DATA[field] || '-';
}

function toggleEmailPreview() {
    const box  = document.getElementById('email-preview');
    const body = document.getElementById('email-preview-body');
    if (box.style.display === 'none') {
        const texto = document.querySelector('textarea[name="email_corpo"]').value;

        // Substitui variáveis por valores de exemplo
        const vars = {
            '{nome}': '<strong>João da Silva</strong>',
            '{computador}': 'NOTEBOOK-001',
            '{serial}': 'SN123456',
            '{setor}': 'Administrativo',
            '{link}': '#',
            '{dias_validade}': '7',
        };
        let html = texto;
        for (const [k, v] of Object.entries(vars)) {
            html = html.split(k).join(v);
        }

        // Tabela dinâmica com os campos configurados — separada por seção
        function buildSection(title, fields) {
            let rows = '';
            let alt = false;
            fields.forEach(function(f) {
                if (!f.enabled) return;
                const bg = alt ? ' style="background:#f8f9fa;"' : '';
                rows += '<tr' + bg + '>'
                    + '<td style="padding:6px;font-weight:bold;width:40%;">' + f.label + ':</td>'
                    + '<td style="padding:6px;">' + resolvePreviewField(f.field) + '</td>'
                    + '</tr>';
                alt = !alt;
            });
            if (!rows) return '';
            return '<p style="font-weight:700;font-size:0.82em;text-transform:uppercase;letter-spacing:.05em;color:#0d6efd;margin:14px 0 4px;">' + title + '</p>'
                + '<table style="width:100%;border-collapse:collapse;margin-bottom:10px;">' + rows + '</table>';
        }
        const tabela = buildSection('Equipamento', PREVIEW_COMP_FIELDS)
                     + buildSection('Responsável', PREVIEW_USER_FIELDS);

        body.innerHTML =
            '<h2 style="color:#0d6efd;margin-bottom:12px;">Termo de Responsabilidade</h2>' +
            '<p>' + html.replace(/\n\n/g,'</p><p>').replace(/\n/g,'<br>') + '</p>' +
            tabela +
            '<div style="margin:18px 0;"><a href="#" style="background:#0d6efd;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;">Clique aqui para assinar o termo</a></div>' +
            '<p style="color:#666;font-size:0.85em;">Este link é válido por <strong>7 dia(s)</strong> a partir do envio deste e-mail.</p>';
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

function addRow(prefix, bodyId) {
    const opts  = prefix === 'comp' ? compFieldOptions : userFieldOptions;
    const tbody = document.getElementById(bodyId);
    const i     = tbody.querySelectorAll('tr.field-row').length;

    let optHtml = '';
    for (const [val, lbl] of Object.entries(opts)) {
        optHtml += '<option value="' + val + '">' + lbl + '</option>';
    }

    const tr = document.createElement('tr');
    tr.className = 'field-row';
    tr.innerHTML =
        '<td style="text-align:center;">'
      +   '<input type="checkbox" name="' + prefix + '_enabled[' + i + ']" checked>'
      + '</td>'
      + '<td style="padding:3px;">'
      +   '<input type="text" name="' + prefix + '_label[' + i + ']" value="" class="form-control form-control-sm" style="width:100%;" placeholder="Label">'
      + '</td>'
      + '<td style="padding:3px;">'
      +   '<select name="' + prefix + '_field[' + i + ']" class="form-control form-control-sm">'
      +   optHtml
      +   '</select>'
      + '</td>'
      + '<td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-danger">✕</button></td>';
    tbody.appendChild(tr);
}
</script>

<?php Html::footer(); ?>
