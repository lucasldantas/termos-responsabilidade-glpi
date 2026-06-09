<?php
/**
 * Página PÚBLICA de assinatura remota.
 * Deve ficar em: /var/www/html/glpi/public/term_sign.php
 * URL: https://glpi.empresa.com/term_sign.php?token=XXXX
 *
 * Fica em public/ para ser servido diretamente pelo Apache,
 * contornando o Firewall de autenticação do GLPI 11.
 * O inc/includes.php inicializa o GLPI mas NÃO força login.
 */

// 1. Composer autoloader — carrega todas as classes do GLPI
// NÃO definir GLPI_ROOT aqui: o próprio autoloader do GLPI define em src/autoload/constants.php
require_once dirname(__FILE__) . '/../vendor/autoload.php';

// Garante que GLPI_ROOT está disponível (o autoloader acima já define)
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__FILE__) . '/..');
}

// 2. Autoloader legado do GLPI (CommonDBTM, Computer, User, etc. — classes sem namespace)
if (file_exists(GLPI_ROOT . '/inc/autoload.function.php')) {
    require_once GLPI_ROOT . '/inc/autoload.function.php';
}

// 3. Constantes de caminho necessárias para o GLPI funcionar
if (!defined('GLPI_CONFIG_DIR'))     define('GLPI_CONFIG_DIR',     GLPI_ROOT . '/config');
if (!defined('GLPI_VAR_DIR'))        define('GLPI_VAR_DIR',        GLPI_ROOT . '/files');
if (!defined('GLPI_DOC_DIR'))        define('GLPI_DOC_DIR',        GLPI_ROOT . '/files');
if (!defined('GLPI_PLUGIN_DOC_DIR')) define('GLPI_PLUGIN_DOC_DIR', GLPI_ROOT . '/files/_plugins');
if (!defined('GLPI_LOG_DIR'))        define('GLPI_LOG_DIR',        GLPI_ROOT . '/files/_log');
if (!defined('GLPI_CACHE_DIR'))      define('GLPI_CACHE_DIR',      GLPI_ROOT . '/files/_cache');

// 4. Configuração do banco
if (!file_exists(GLPI_ROOT . '/config/config_db.php')) {
    die('Configuração do banco não encontrada.');
}
require_once GLPI_ROOT . '/config/config_db.php';

// 5. Sessão mínima
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['glpilanguage']))             $_SESSION['glpilanguage']             = 'pt_BR';
if (!isset($_SESSION['glpiactive_entity']))        $_SESSION['glpiactive_entity']        = 0;
if (!isset($_SESSION['glpiactiveentities']))       $_SESSION['glpiactiveentities']       = [0];
if (!isset($_SESSION['glpiactiveentities_string'])) $_SESSION['glpiactiveentities_string'] = '0';
if (!isset($_SESSION['glpishowallentities']))      $_SESSION['glpishowallentities']      = 1;
if (!isset($_SESSION['glpiis_ids_visible']))       $_SESSION['glpiis_ids_visible']       = 0;
if (!isset($_SESSION['glpiactiveprofile']))        $_SESSION['glpiactiveprofile']        = ['id' => 0, 'name' => 'public'];

// 6. Conexão com o banco (config_db.php define a classe DB extends DBmysql com as credenciais)
$DB = new DB();

// 8. Carrega $CFG_GLPI do banco (necessário para GLPIMailer funcionar com SMTP correto)
global $CFG_GLPI;
$CFG_GLPI = [];
try {
    $cfg_it = $DB->request([
        'SELECT' => ['name', 'value'],
        'FROM'   => 'glpi_configs',
        'WHERE'  => ['context' => 'core'],
    ]);
    foreach ($cfg_it as $cfg_row) {
        $CFG_GLPI[$cfg_row['name']] = $cfg_row['value'];
    }
} catch (\Throwable $ignored) {}

// 7. Autoloader do plugin
spl_autoload_register(function ($class) {
    if (strpos($class, 'PluginTermos') === 0) {
        $name = strtolower(str_replace('PluginTermos', '', $class));
        $file = GLPI_ROOT . '/plugins/termosresponsabilidade/inc/' . $name . '.class.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// ── Valida token ──────────────────────────────────────────────────────────────
$token     = trim($_GET['token'] ?? '');
$pageError = '';
$term      = null;
$data      = [];

if (empty($token)) {
    $pageError = 'Link inválido. Nenhum token informado.';
} else {
    try {
        $term = PluginTermosTerm::getTermByToken($token);
        $data = PluginTermosTerm::getItemWithUser($term);
    } catch (\Throwable $e) {
        $pageError = $e->getMessage();
    }
}

// ── Processa assinatura (POST) ────────────────────────────────────────────────
$signError   = '';
$signSuccess = false;

if (!$pageError && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signature_data'])) {
    $sigData = trim($_POST['signature_data'] ?? '');
    $tkPost  = trim($_POST['token'] ?? '');

    if (empty($sigData)) {
        $signError = 'Por favor, desenhe sua assinatura antes de confirmar.';
    } elseif ($tkPost !== $token) {
        $signError = 'Token inválido. Recarregue a página e tente novamente.';
    } else {
        try {
            $term    = PluginTermosTerm::getTermByToken($token);
            $termId  = (int)$term->fields['id'];

            // Gera o PDF
            $data2   = PluginTermosTerm::getItemWithUser($term);
            $pdfGen  = new PluginTermosPdf();
            $pdfPath = $pdfGen->generate($data2, $sigData, $termId, (int)($term->fields['issuer_id'] ?? 0));

            if (!file_exists($pdfPath)) {
                throw new \RuntimeException('Falha ao gerar o PDF.');
            }
            $hash = hash_file('sha256', $pdfPath);

            // Salva diretamente no banco (evita CommonDBTM::update que exige sessão completa)
            $DB->update('glpi_plugin_termos_terms', [
                'status'         => 'signed',
                'signature_data' => $sigData,
                'pdf_path'       => $pdfPath,
                'pdf_hash'       => $hash,
                'date_signed'    => date('Y-m-d H:i:s'),
                'signer_ip'      => $_SERVER['REMOTE_ADDR']   ?? '',
                'signer_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                'sign_token'     => null,
            ], ['id' => $termId]);

            // Tenta enviar PDF por email
            try {
                PluginTermosTerm::sendTermEmail($termId);
            } catch (\Throwable $emailErr) {
                $signError = 'Termo assinado, mas falha ao enviar e-mail: ' . $emailErr->getMessage();
            }

            $signSuccess = true;
        } catch (\Throwable $e) {
            $signError = $e->getMessage();
        }
    }
}

// ── Config ────────────────────────────────────────────────────────────────────
$configFile = GLPI_ROOT . '/plugins/termosresponsabilidade/config/settings.json';
$config     = file_exists($configFile)
    ? (json_decode(file_get_contents($configFile), true) ?? [])
    : [];
$clausulas  = array_filter(array_map('trim', explode("\n", $config['termos_texto'] ?? '')));
$rodape     = $config['rodape_texto']
    ?? 'As partes concordam que este termo é assinado por meio eletrônico, reconhecendo sua validade jurídica conforme art. 10, § 2º da MP nº 2.200-2/2001 e Lei nº 14.063/2020.';

// Campos dinâmicos com defaults
$compFields = $config['fields_computer'] ?? [
    ['enabled' => true, 'label' => 'Computador',     'field' => 'computer_name'],
    ['enabled' => true, 'label' => 'Número de Série', 'field' => 'serial'],
    ['enabled' => true, 'label' => 'Patrimônio',      'field' => 'patrimonio'],
    ['enabled' => true, 'label' => 'Setor',            'field' => 'entity'],
];
$userFields = $config['fields_user'] ?? [
    ['enabled' => true, 'label' => 'Nome',     'field' => 'fullname'],
    ['enabled' => true, 'label' => 'Login',    'field' => 'username'],
    ['enabled' => true, 'label' => 'Telefone', 'field' => 'phone'],
];

// Resolve valor de um campo dinâmico
$resolveField = function (array $row, string $field) use ($data): string {
    if ($field === 'fullname') {
        return trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? ''))
               ?: ($data['username'] ?? '-');
    }
    return (string)($data[$field] ?? '-');
};

$nomeCompleto = !empty($data)
    ? (trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? ''))
    : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termo de Responsabilidade — Assinatura</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; min-height: 100vh; padding: 20px; }
        .page-wrap { max-width: 700px; margin: 0 auto; }
        .header-bar {
            background: #0d6efd; color: #fff;
            padding: 16px 22px; border-radius: 8px 8px 0 0;
        }
        .header-bar h1 { font-size: 1.2em; }
        .header-bar p  { font-size: 0.85em; opacity: .85; margin-top: 3px; }
        .card {
            background: #fff;
            border: 1px solid #dee2e6;
            border-top: none;
            padding: 20px;
        }
        .card:last-of-type { border-radius: 0 0 8px 8px; margin-bottom: 20px; }
        .card h3 {
            font-size: .88em; text-transform: uppercase; letter-spacing: .05em;
            color: #0d6efd; border-bottom: 1px solid #eee;
            padding-bottom: 8px; margin-bottom: 14px;
        }
        table.info { width: 100%; border-collapse: collapse; }
        table.info th { text-align:left; width:38%; color:#555; font-size:.9em; padding:5px 4px; }
        table.info td { font-size:.9em; padding:5px 4px; }
        ol.clausulas { padding-left:20px; line-height:1.7; font-size:.92em; }
        ol.clausulas li { margin-bottom:6px; }
        .aviso {
            background:#f8f9fa; border-left:3px solid #0d6efd;
            padding:8px 12px; margin-top:12px; color:#555; font-size:.82em;
        }
        .signature-wrap {
            width: 100%; overflow: hidden; border-radius: 4px;
        }
        #signature-pad {
            border:2px solid #333; border-radius:4px; background:#fff;
            display:block; width:100%; height:180px;
            cursor:crosshair; touch-action:none; max-width:100%;
        }
        .btn {
            display:inline-block; padding:10px 22px; border:none;
            border-radius:5px; font-size:1em; cursor:pointer;
            text-decoration:none; margin:4px 4px 4px 0;
        }
        .btn-primary   { background:#0d6efd; color:#fff; }
        .btn-secondary { background:#6c757d; color:#fff; }
        .btn:hover { opacity:.88; }
        .alert { padding:12px 16px; border-radius:5px; margin-bottom:14px; font-size:.93em; }
        .alert-danger  { background:#f8d7da; color:#842029; border:1px solid #f5c2c7; }
        .alert-warning { background:#fff3cd; color:#664d03; border:1px solid #ffecb5; }
        .success-wrap  { text-align:center; padding:30px 20px; }
        .success-wrap .icon { font-size:4em; margin-bottom:15px; }
        .success-wrap h2 { color:#198754; margin-bottom:10px; }
        @media(max-width:480px) {
            body { padding:10px; }
            .btn { width:100%; display:block; text-align:center; }
        }
    </style>
</head>
<body>
<div class="page-wrap">

<?php if (!empty($pageError)): ?>

    <div class="header-bar" style="background:#dc3545; border-radius:8px;">
        <h1>⚠️ Link Inválido</h1>
    </div>
    <div class="card" style="border-top:1px solid #dee2e6; border-radius:0 0 8px 8px;">
        <div class="alert alert-danger"><?= htmlspecialchars($pageError) ?></div>
        <p style="color:#666; font-size:.9em; margin-top:8px;">
            Se o link expirou, entre em contato com o setor de TI para solicitar um novo link.
        </p>
    </div>

<?php elseif ($signSuccess): ?>

    <div class="header-bar" style="background:#198754; border-radius:8px;">
        <h1>✅ Termo Assinado</h1>
    </div>
    <div class="card" style="border-top:1px solid #dee2e6; border-radius:0 0 8px 8px;">
        <div class="success-wrap">
            <div class="icon">✅</div>
            <h2>Assinatura registrada com sucesso!</h2>
            <p style="color:#555; margin-bottom:20px;">
                Seu Termo de Responsabilidade foi assinado digitalmente e registrado no sistema.<br>
                Você receberá o PDF por e-mail em breve.
            </p>
            <div style="background:#f8f9fa; border-radius:6px; padding:14px; text-align:left;
                        max-width:400px; margin:0 auto 20px;">
                <table class="info">
                    <tr><th>Equipamento</th><td><?= htmlspecialchars($data['computer_name'] ?? '-') ?></td></tr>
                    <tr><th>Responsável</th><td><?= htmlspecialchars($nomeCompleto) ?></td></tr>
                    <tr><th>Assinado em</th><td><?= date('d/m/Y \à\s H:i') ?></td></tr>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>

    <div class="header-bar">
        <h1>📋 Termo de Responsabilidade</h1>
        <p>Leia com atenção e assine com o dedo ou mouse</p>
    </div>

    <?php if (!empty($signError)): ?>
    <div class="card" style="border-top:1px solid #dee2e6;">
        <div class="alert alert-danger">❌ <?= htmlspecialchars($signError) ?></div>
    </div>
    <?php endif; ?>

    <!-- Dados do equipamento -->
    <div class="card">
        <h3>Dados do Equipamento</h3>
        <table class="info">
            <?php foreach ($compFields as $cf):
                if (!($cf['enabled'] ?? true)) continue; ?>
            <tr>
                <th><?= htmlspecialchars($cf['label']) ?></th>
                <td><?= htmlspecialchars($resolveField($cf, $cf['field'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (($data['item_type'] ?? '') === 'Phone' && ($data['line_number'] ?? '-') !== '-'): ?>
            <tr>
                <th>Linha Telefônica</th>
                <td><?= htmlspecialchars($data['line_number']) ?>
                    <?php if (!empty($data['line_name']) && $data['line_name'] !== $data['line_number']): ?>
                        <span style="color:#6c757d; font-size:.9em;">(<?= htmlspecialchars($data['line_name']) ?>)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- Dados do responsável -->
    <div class="card">
        <h3>Responsável</h3>
        <table class="info">
            <?php foreach ($userFields as $uf):
                if (!($uf['enabled'] ?? true)) continue; ?>
            <tr>
                <th><?= htmlspecialchars($uf['label']) ?></th>
                <td><?= htmlspecialchars($resolveField($uf, $uf['field'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- Cláusulas -->
    <div class="card">
        <h3>Termos e Condições</h3>
        <?php if (!empty($clausulas)): ?>
        <ol class="clausulas">
            <?php foreach ($clausulas as $c): ?>
                <li><?= htmlspecialchars($c) ?></li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
        <div class="aviso"><?= htmlspecialchars($rodape) ?></div>
    </div>

    <!-- Assinatura -->
    <div class="card">
        <h3>Sua Assinatura</h3>
        <p style="font-size:.9em; color:#555; margin-bottom:10px;">
            Desenhe sua assinatura abaixo usando o dedo (celular/tablet) ou o mouse.
        </p>
        <div class="signature-wrap"><canvas id="signature-pad"></canvas></div>
        <div style="margin-top:8px;">
            <button type="button" id="btn-clear" class="btn btn-secondary"
                    style="font-size:.85em; padding:6px 14px;">🗑️ Limpar</button>
        </div>

        <form method="POST" id="form-sign"
              action="term_sign.php?token=<?= htmlspecialchars($token) ?>"
              style="margin-top:18px;">
            <input type="hidden" name="token"          value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="signature_data" id="signature_data" value="">

            <div class="alert alert-warning" style="font-size:.85em; margin-bottom:14px;">
                ⚠️ Ao clicar em <strong>"Confirmar e Assinar"</strong> você concorda com todos os
                termos acima e reconhece a validade jurídica desta assinatura eletrônica
                conforme MP nº 2.200-2/2001 e Lei nº 14.063/2020.
            </div>

            <button type="button" id="btn-sign" class="btn btn-primary" style="font-size:1.05em;">
                ✅ Confirmar e Assinar
            </button>
        </form>
    </div>

<?php endif; ?>
</div>

<script>
/*!
 * Signature Pad v4.1.7 | https://github.com/szimek/signature_pad
 */
!function(t,e){"object"==typeof exports&&"undefined"!=typeof module?module.exports=e():"function"==typeof define&&define.amd?define(e):(t="undefined"!=typeof globalThis?globalThis:t||self).SignaturePad=e()}(this,(function(){"use strict";class t{constructor(t,e,i,s){if(isNaN(t)||isNaN(e))throw new Error(`Point is invalid: (${t}, ${e})`);this.x=+t,this.y=+e,this.pressure=i||0,this.time=s||Date.now()}distanceTo(t){return Math.sqrt(Math.pow(this.x-t.x,2)+Math.pow(this.y-t.y,2))}equals(t){return this.x===t.x&&this.y===t.y&&this.pressure===t.pressure&&this.time===t.time}velocityFrom(t){return this.time!==t.time?this.distanceTo(t)/(this.time-t.time):0}}class e{static fromPoints(t,i){const s=this.calculateControlPoints(t[0],t[1],t[2]).c2,n=this.calculateControlPoints(t[1],t[2],t[3]).c1;return new e(t[1],s,n,t[2],i.start,i.end)}static calculateControlPoints(e,i,s){const n=e.x-i.x,o=e.y-i.y,h=i.x-s.x,r=i.y-s.y,a=(e.x+i.x)/2,c=(e.y+i.y)/2,d=(i.x+s.x)/2,l=(i.y+s.y)/2,u=Math.sqrt(n*n+o*o),v=Math.sqrt(h*h+r*r),_=v/(u+v),p=d+(a-d)*_,m=l+(c-l)*_,g=i.x-p,w=i.y-m;return{c1:new t(a+g,c+w),c2:new t(d+g,l+w)}}constructor(t,e,i,s,n,o){this.startPoint=t,this.control2=e,this.control1=i,this.endPoint=s,this.startWidth=n,this.endWidth=o}length(){let t,e,i=0;for(let s=0;s<=10;s+=1){const n=s/10,o=this.point(n,this.startPoint.x,this.control1.x,this.control2.x,this.endPoint.x),h=this.point(n,this.startPoint.y,this.control1.y,this.control2.y,this.endPoint.y);if(s>0){const s=o-t,n=h-e;i+=Math.sqrt(s*s+n*n)}t=o,e=h}return i}point(t,e,i,s,n){return e*(1-t)*(1-t)*(1-t)+3*i*(1-t)*(1-t)*t+3*s*(1-t)*t*t+n*t*t*t}}class i{constructor(){try{this._et=new EventTarget}catch(t){this._et=document}}addEventListener(t,e,i){this._et.addEventListener(t,e,i)}dispatchEvent(t){return this._et.dispatchEvent(t)}removeEventListener(t,e,i){this._et.removeEventListener(t,e,i)}}class s extends i{constructor(t,e={}){super(),this.canvas=t,this._drawingStroke=!1,this._isEmpty=!0,this._lastPoints=[],this._data=[],this._lastVelocity=0,this._lastWidth=0,this._handleMouseDown=t=>{1===t.buttons&&this._strokeBegin(t)},this._handleMouseMove=t=>{this._strokeMoveUpdate(t)},this._handleMouseUp=t=>{1===t.buttons&&this._strokeEnd(t)},this._handleTouchStart=t=>{if(t.cancelable&&t.preventDefault(),1===t.targetTouches.length){const e=t.changedTouches[0];this._strokeBegin(e)}},this._handleTouchMove=t=>{t.cancelable&&t.preventDefault();const e=t.targetTouches[0];this._strokeMoveUpdate(e)},this._handleTouchEnd=t=>{if(t.target===this.canvas){t.cancelable&&t.preventDefault();const e=t.changedTouches[0];this._strokeEnd(e)}},this._handlePointerStart=t=>{t.preventDefault(),this._strokeBegin(t)},this._handlePointerMove=t=>{this._strokeMoveUpdate(t)},this._handlePointerEnd=t=>{this._drawingStroke&&(t.preventDefault(),this._strokeEnd(t))},this.velocityFilterWeight=e.velocityFilterWeight||.7,this.minWidth=e.minWidth||.5,this.maxWidth=e.maxWidth||2.5,this.throttle="throttle"in e?e.throttle:16,this.minDistance="minDistance"in e?e.minDistance:5,this.dotSize=e.dotSize||0,this.penColor=e.penColor||"black",this.backgroundColor=e.backgroundColor||"rgba(0,0,0,0)",this.compositeOperation=e.compositeOperation||"source-over",this._strokeMoveUpdate=this.throttle?function(t,e=250){let i,s,n,o=0,h=null;const r=()=>{o=Date.now(),h=null,i=t.apply(s,n),h||(s=null,n=[])};return function(...a){const c=Date.now(),d=e-(c-o);return s=this,n=a,d<=0||d>e?(h&&(clearTimeout(h),h=null),o=c,i=t.apply(s,n),h||(s=null,n=[])):h||(h=window.setTimeout(r,d)),i}}(s.prototype._strokeUpdate,this.throttle):s.prototype._strokeUpdate,this._ctx=t.getContext("2d"),this.clear(),this.on()}clear(){const{_ctx:t,canvas:e}=this;t.fillStyle=this.backgroundColor,t.clearRect(0,0,e.width,e.height),t.fillRect(0,0,e.width,e.height),this._data=[],this._reset(this._getPointGroupOptions()),this._isEmpty=!0}fromDataURL(t,e={}){return new Promise(((i,s)=>{const n=new Image,o=e.ratio||window.devicePixelRatio||1,h=e.width||this.canvas.width/o,r=e.height||this.canvas.height/o,a=e.xOffset||0,c=e.yOffset||0;this._reset(this._getPointGroupOptions()),n.onload=()=>{this._ctx.drawImage(n,a,c,h,r),i()},n.onerror=t=>{s(t)},n.crossOrigin="anonymous",n.src=t,this._isEmpty=!1}))}toDataURL(t="image/png",e){return"image/svg+xml"===t?("object"!=typeof e&&(e=void 0),`data:image/svg+xml;base64,${btoa(this.toSVG(e))}`):("number"!=typeof e&&(e=void 0),this.canvas.toDataURL(t,e))}on(){this.canvas.style.touchAction="none",this.canvas.style.msTouchAction="none",this.canvas.style.userSelect="none";const t=/Macintosh/.test(navigator.userAgent)&&"ontouchstart"in document;window.PointerEvent&&!t?this._handlePointerEvents():(this._handleMouseEvents(),"ontouchstart"in window&&this._handleTouchEvents())}off(){this.canvas.style.touchAction="auto",this.canvas.style.msTouchAction="auto",this.canvas.style.userSelect="auto",this.canvas.removeEventListener("pointerdown",this._handlePointerStart),this.canvas.removeEventListener("pointermove",this._handlePointerMove),this.canvas.ownerDocument.removeEventListener("pointerup",this._handlePointerEnd),this.canvas.removeEventListener("mousedown",this._handleMouseDown),this.canvas.removeEventListener("mousemove",this._handleMouseMove),this.canvas.ownerDocument.removeEventListener("mouseup",this._handleMouseUp),this.canvas.removeEventListener("touchstart",this._handleTouchStart),this.canvas.removeEventListener("touchmove",this._handleTouchMove),this.canvas.removeEventListener("touchend",this._handleTouchEnd)}isEmpty(){return this._isEmpty}fromData(t,{clear:e=!0}={}){e&&this.clear(),this._fromData(t,this._drawCurve.bind(this),this._drawDot.bind(this)),this._data=this._data.concat(t)}toData(){return this._data}_getPointGroupOptions(t){return{penColor:t&&"penColor"in t?t.penColor:this.penColor,dotSize:t&&"dotSize"in t?t.dotSize:this.dotSize,minWidth:t&&"minWidth"in t?t.minWidth:this.minWidth,maxWidth:t&&"maxWidth"in t?t.maxWidth:this.maxWidth,velocityFilterWeight:t&&"velocityFilterWeight"in t?t.velocityFilterWeight:this.velocityFilterWeight,compositeOperation:t&&"compositeOperation"in t?t.compositeOperation:this.compositeOperation}}_strokeBegin(t){if(!this.dispatchEvent(new CustomEvent("beginStroke",{detail:t,cancelable:!0})))return;this._drawingStroke=!0;const e=this._getPointGroupOptions(),i=Object.assign(Object.assign({},e),{points:[]});this._data.push(i),this._reset(e),this._strokeUpdate(t)}_strokeUpdate(t){if(!this._drawingStroke)return;if(0===this._data.length)return void this._strokeBegin(t);this.dispatchEvent(new CustomEvent("beforeUpdateStroke",{detail:t}));const e=t.clientX,i=t.clientY,s=void 0!==t.pressure?t.pressure:void 0!==t.force?t.force:0,n=this._createPoint(e,i,s),o=this._data[this._data.length-1],h=o.points,r=h.length>0&&h[h.length-1],a=!!r&&n.distanceTo(r)<=this.minDistance,c=this._getPointGroupOptions(o);if(!r||!r||!a){const t=this._addPoint(n,c);r?t&&this._drawCurve(t,c):this._drawDot(n,c),h.push({time:n.time,x:n.x,y:n.y,pressure:n.pressure})}this.dispatchEvent(new CustomEvent("afterUpdateStroke",{detail:t}))}_strokeEnd(t){this._drawingStroke&&(this._strokeUpdate(t),this._drawingStroke=!1,this.dispatchEvent(new CustomEvent("endStroke",{detail:t})))}_handlePointerEvents(){this._drawingStroke=!1,this.canvas.addEventListener("pointerdown",this._handlePointerStart),this.canvas.addEventListener("pointermove",this._handlePointerMove),this.canvas.ownerDocument.addEventListener("pointerup",this._handlePointerEnd)}_handleMouseEvents(){this._drawingStroke=!1,this.canvas.addEventListener("mousedown",this._handleMouseDown),this.canvas.addEventListener("mousemove",this._handleMouseMove),this.canvas.ownerDocument.addEventListener("mouseup",this._handleMouseUp)}_handleTouchEvents(){this.canvas.addEventListener("touchstart",this._handleTouchStart),this.canvas.addEventListener("touchmove",this._handleTouchMove),this.canvas.addEventListener("touchend",this._handleTouchEnd)}_reset(t){this._lastPoints=[],this._lastVelocity=0,this._lastWidth=(t.minWidth+t.maxWidth)/2,this._ctx.fillStyle=t.penColor,this._ctx.globalCompositeOperation=t.compositeOperation}_createPoint(e,i,s){const n=this.canvas.getBoundingClientRect();return new t(e-n.left,i-n.top,s,(new Date).getTime())}_addPoint(t,i){const{_lastPoints:s}=this;if(s.push(t),s.length>2){3===s.length&&s.unshift(s[0]);const t=this._calculateCurveWidths(s[1],s[2],i),n=e.fromPoints(s,t);return s.shift(),n}return null}_calculateCurveWidths(t,e,i){const s=i.velocityFilterWeight*e.velocityFrom(t)+(1-i.velocityFilterWeight)*this._lastVelocity,n=this._strokeWidth(s,i),o={end:n,start:this._lastWidth};return this._lastVelocity=s,this._lastWidth=n,o}_strokeWidth(t,e){return Math.max(e.maxWidth/(t+1),e.minWidth)}_drawCurveSegment(t,e,i){const s=this._ctx;s.moveTo(t,e),s.arc(t,e,i,0,2*Math.PI,!1),this._isEmpty=!1}_drawCurve(t,e){const i=this._ctx,s=t.endWidth-t.startWidth,n=2*Math.ceil(t.length());i.beginPath(),i.fillStyle=e.penColor;for(let i=0;i<n;i+=1){const o=i/n,h=o*o,r=h*o,a=1-o,c=a*a,d=c*a;let l=d*t.startPoint.x;l+=3*c*o*t.control1.x,l+=3*a*h*t.control2.x,l+=r*t.endPoint.x;let u=d*t.startPoint.y;u+=3*c*o*t.control1.y,u+=3*a*h*t.control2.y,u+=r*t.endPoint.y;const v=Math.min(t.startWidth+r*s,e.maxWidth);this._drawCurveSegment(l,u,v)}i.closePath(),i.fill()}_drawDot(t,e){const i=this._ctx,s=e.dotSize>0?e.dotSize:(e.minWidth+e.maxWidth)/2;i.beginPath(),this._drawCurveSegment(t.x,t.y,s),i.closePath(),i.fillStyle=e.penColor,i.fill()}_fromData(e,i,s){for(const n of e){const{points:e}=n,o=this._getPointGroupOptions(n);if(e.length>1)for(let s=0;s<e.length;s+=1){const n=e[s],h=new t(n.x,n.y,n.pressure,n.time);0===s&&this._reset(o);const r=this._addPoint(h,o);r&&i(r,o)}else this._reset(o),s(e[0],o)}}toSVG({includeBackgroundColor:t=!1}={}){const e=this._data,i=Math.max(window.devicePixelRatio||1,1),s=this.canvas.width/i,n=this.canvas.height/i,o=document.createElementNS("http://www.w3.org/2000/svg","svg");if(o.setAttribute("xmlns","http://www.w3.org/2000/svg"),o.setAttribute("xmlns:xlink","http://www.w3.org/1999/xlink"),o.setAttribute("viewBox",`0 0 ${s} ${n}`),o.setAttribute("width",s.toString()),o.setAttribute("height",n.toString()),t&&this.backgroundColor){const t=document.createElement("rect");t.setAttribute("width","100%"),t.setAttribute("height","100%"),t.setAttribute("fill",this.backgroundColor),o.appendChild(t)}return this._fromData(e,((t,{penColor:e})=>{const i=document.createElement("path");if(!(isNaN(t.control1.x)||isNaN(t.control1.y)||isNaN(t.control2.x)||isNaN(t.control2.y))){const s=`M ${t.startPoint.x.toFixed(3)},${t.startPoint.y.toFixed(3)} C ${t.control1.x.toFixed(3)},${t.control1.y.toFixed(3)} ${t.control2.x.toFixed(3)},${t.control2.y.toFixed(3)} ${t.endPoint.x.toFixed(3)},${t.endPoint.y.toFixed(3)}`;i.setAttribute("d",s),i.setAttribute("stroke-width",(2.25*t.endWidth).toFixed(3)),i.setAttribute("stroke",e),i.setAttribute("fill","none"),i.setAttribute("stroke-linecap","round"),o.appendChild(i)}}),((t,{penColor:e,dotSize:i,minWidth:s,maxWidth:n})=>{const h=document.createElement("circle"),r=i>0?i:(s+n)/2;h.setAttribute("r",r.toString()),h.setAttribute("cx",t.x.toString()),h.setAttribute("cy",t.y.toString()),h.setAttribute("fill",e),o.appendChild(h)})),o.outerHTML}}return s}));

(function () {
    const canvas = document.getElementById('signature-pad');
    if (!canvas) return;

    const pad = new SignaturePad(canvas, {
        backgroundColor: 'rgb(255,255,255)',
        penColor: 'rgb(0,0,100)',
        minWidth: 1,
        maxWidth: 3,
    });

    function resize() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const wrap = canvas.parentElement;
        const w = wrap.clientWidth || 600;
        canvas.width  = w * ratio;
        canvas.height = 180 * ratio;
        canvas.style.width  = w + 'px';
        canvas.style.height = '180px';
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();
    }
    window.addEventListener('resize', resize);
    resize();

    document.getElementById('btn-clear').addEventListener('click', () => pad.clear());

    document.getElementById('btn-sign').addEventListener('click', function () {
        if (pad.isEmpty()) {
            alert('Por favor, desenhe sua assinatura antes de confirmar.');
            return;
        }
        document.getElementById('signature_data').value = pad.toDataURL('image/png');
        this.disabled    = true;
        this.textContent = '⏳ Enviando...';
        document.getElementById('form-sign').submit();
    });
})();
</script>
</body>
</html>
