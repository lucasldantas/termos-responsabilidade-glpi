<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    Html::displayErrorAndDie('ID de termo inválido.');
}

$term = new PluginTermosTerm();
if (!$term->getFromDB($id)) {
    Html::displayErrorAndDie('Termo não encontrado.');
}

if ($term->fields['status'] === 'signed') {
    header('Location: download.php?id=' . $id);
    exit;
}

// Processa o POST da assinatura
$error   = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['signature_data'])) {
    try {
        #PluginTermosTerm::signTerm($id, $_POST['signature_data']);
        #header('Location: download.php?id=' . $id);

	PluginTermosTerm::signTerm($id, $_POST['signature_data']);

// Tenta enviar email, mas não bloqueia se falhar
try {
    PluginTermosTerm::sendTermEmail($id);
} catch (\Exception $e) {
    // Silencioso — email é opcional
}

header('Location: download.php?id=' . $id);
	    
	    
	exit;
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
}

$data         = PluginTermosTerm::getComputerWithUser((int)$term->fields['computers_id']);
$nomeCompleto = trim(($data['firstname'] ?? '') . ' ' . ($data['realname'] ?? '')) ?: ($data['username'] ?? '');

// Configurações dinâmicas
$configFile = __DIR__ . '/../config/settings.json';
$config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?? []) : [];

$compFields = $config['fields_computer'] ?? [
    ['enabled' => true, 'label' => 'Computador',      'field' => 'computer_name'],
    ['enabled' => true, 'label' => 'Número de Série', 'field' => 'serial'],
    ['enabled' => true, 'label' => 'Patrimônio',      'field' => 'patrimonio'],
    ['enabled' => true, 'label' => 'Setor',            'field' => 'entity'],
];
$userFields = $config['fields_user'] ?? [
    ['enabled' => true, 'label' => 'Nome',     'field' => 'fullname'],
    ['enabled' => true, 'label' => 'Login',    'field' => 'username'],
    ['enabled' => true, 'label' => 'Telefone', 'field' => 'phone'],
];
$clausulas = array_filter(array_map('trim', explode("\n", $config['termos_texto'] ?? '')));
if (empty($clausulas)) {
    $clausulas = [
        'Recebi o equipamento acima em perfeito estado de funcionamento e conservação.',
        'Comprometo-me a zelar pela guarda, conservação e uso adequado, utilizando-o exclusivamente para fins profissionais.',
        'Estou ciente de que não devo instalar softwares não autorizados ou acessar conteúdos inadequados.',
        'Comunicarei imediatamente ao setor de TI qualquer avaria, perda, furto ou roubo.',
        'Serei responsabilizado por danos causados por mau uso, negligência ou extravio.',
    ];
}
$rodape = $config['rodape_texto']
    ?? 'As partes concordam que este termo é assinado por meio eletrônico, reconhecendo sua validade jurídica conforme art. 10, § 2º da MP nº 2.200-2/2001 e Lei nº 14.063/2020.';

// Resolve valor de campo dinâmico
$resolveField = function (string $field) use ($data, $nomeCompleto): string {
    if ($field === 'fullname') return $nomeCompleto ?: '-';
    return (string)($data[$field] ?? '-');
};

Html::header('Assinar Termo de Responsabilidade', $_SERVER['PHP_SELF'], 'tools', 'PluginTermosMenu');
?>

<style>
.termos-container { max-width:750px; margin:30px auto; padding:0 20px; font-family:Arial,sans-serif; }
.termos-container h2 { border-bottom:2px solid #0d6efd; padding-bottom:10px; margin-bottom:20px; }
.termos-card { background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.07); }
.termos-card h4 { margin-top:0; color:#0d6efd; font-size:1em; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:12px; }
.termos-table { width:100%; border-collapse:collapse; }
.termos-table th, .termos-table td { padding:6px 8px; text-align:left; border-bottom:1px solid #f0f0f0; }
.termos-table th { width:35%; color:#555; font-weight:600; font-size:.9em; }
.termos-lista { padding-left:20px; line-height:1.8; font-size:.95em; }
.termos-aviso { background:#f8f9fa; border-left:3px solid #0d6efd; padding:8px 12px; margin-top:12px; color:#555; }
.signature-wrapper { border:2px solid #333; border-radius:4px; background:#fff; display:inline-block; max-width:100%; overflow:hidden; }
#signature-pad { display:block; max-width:100%; touch-action:none; }
.alert { padding:12px 16px; border-radius:4px; margin-top:10px; }
.alert-danger { background:#f8d7da; color:#842029; border:1px solid #f5c2c7; }
</style>

<div class="termos-container">
    <h2><i class="fas fa-file-signature"></i> Termo de Responsabilidade</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="termos-card">
        <h4>Dados do Equipamento</h4>
        <table class="termos-table">
            <?php foreach ($compFields as $cf):
                if (!($cf['enabled'] ?? true)) continue; ?>
            <tr>
                <th><?= htmlspecialchars($cf['label']) ?></th>
                <td><?= htmlspecialchars($resolveField($cf['field'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="termos-card">
        <h4>Responsável</h4>
        <table class="termos-table">
            <?php foreach ($userFields as $uf):
                if (!($uf['enabled'] ?? true)) continue; ?>
            <tr>
                <th><?= htmlspecialchars($uf['label']) ?></th>
                <td><?= htmlspecialchars($resolveField($uf['field'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="termos-card">
        <h4>Termos e Condições</h4>
        <ol class="termos-lista">
            <?php foreach ($clausulas as $c): ?>
                <li><?= htmlspecialchars($c) ?></li>
            <?php endforeach; ?>
        </ol>
        <p class="termos-aviso">
            <small><em><?= htmlspecialchars($rodape) ?></em></small>
        </p>
    </div>

    <div class="termos-card">
        <h4>Assinatura Eletrônica</h4>
        <p>Desenhe sua assinatura no campo abaixo:</p>
        <div class="signature-wrapper">
            <canvas id="signature-pad" width="600" height="200"></canvas>
        </div>
        <div style="margin-top:8px;">
            <button type="button" id="btn-clear" class="btn btn-secondary btn-sm">🗑️ Limpar</button>
        </div>

        <form method="POST" action="sign.php?id=<?= $id ?>" id="form-sign" style="margin-top:20px;">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>
            <input type="hidden" name="signature_data" id="signature_data">
            <button type="button" id="btn-sign" class="btn btn-primary">✅ Confirmar e Assinar</button>
            <a href="term.php" class="btn btn-secondary">Cancelar</a>
        </form>

        <div id="termos-msg" style="margin-top:15px;"></div>
    </div>
</div>

<script>
/*!
 * Signature Pad v4.1.7 | https://github.com/szimek/signature_pad
 * (c) 2023 Szymon Nowak | Released under the MIT license
 */
!function(t,e){"object"==typeof exports&&"undefined"!=typeof module?module.exports=e():"function"==typeof define&&define.amd?define(e):(t="undefined"!=typeof globalThis?globalThis:t||self).SignaturePad=e()}(this,(function(){"use strict";class t{constructor(t,e,i,s){if(isNaN(t)||isNaN(e))throw new Error(`Point is invalid: (${t}, ${e})`);this.x=+t,this.y=+e,this.pressure=i||0,this.time=s||Date.now()}distanceTo(t){return Math.sqrt(Math.pow(this.x-t.x,2)+Math.pow(this.y-t.y,2))}equals(t){return this.x===t.x&&this.y===t.y&&this.pressure===t.pressure&&this.time===t.time}velocityFrom(t){return this.time!==t.time?this.distanceTo(t)/(this.time-t.time):0}}class e{static fromPoints(t,i){const s=this.calculateControlPoints(t[0],t[1],t[2]).c2,n=this.calculateControlPoints(t[1],t[2],t[3]).c1;return new e(t[1],s,n,t[2],i.start,i.end)}static calculateControlPoints(e,i,s){const n=e.x-i.x,o=e.y-i.y,h=i.x-s.x,r=i.y-s.y,a=(e.x+i.x)/2,c=(e.y+i.y)/2,d=(i.x+s.x)/2,l=(i.y+s.y)/2,u=Math.sqrt(n*n+o*o),v=Math.sqrt(h*h+r*r),_=v/(u+v),p=d+(a-d)*_,m=l+(c-l)*_,g=i.x-p,w=i.y-m;return{c1:new t(a+g,c+w),c2:new t(d+g,l+w)}}constructor(t,e,i,s,n,o){this.startPoint=t,this.control2=e,this.control1=i,this.endPoint=s,this.startWidth=n,this.endWidth=o}length(){let t,e,i=0;for(let s=0;s<=10;s+=1){const n=s/10,o=this.point(n,this.startPoint.x,this.control1.x,this.control2.x,this.endPoint.x),h=this.point(n,this.startPoint.y,this.control1.y,this.control2.y,this.endPoint.y);if(s>0){const s=o-t,n=h-e;i+=Math.sqrt(s*s+n*n)}t=o,e=h}return i}point(t,e,i,s,n){return e*(1-t)*(1-t)*(1-t)+3*i*(1-t)*(1-t)*t+3*s*(1-t)*t*t+n*t*t*t}}class i{constructor(){try{this._et=new EventTarget}catch(t){this._et=document}}addEventListener(t,e,i){this._et.addEventListener(t,e,i)}dispatchEvent(t){return this._et.dispatchEvent(t)}removeEventListener(t,e,i){this._et.removeEventListener(t,e,i)}}class s extends i{constructor(t,e={}){super(),this.canvas=t,this._drawingStroke=!1,this._isEmpty=!0,this._lastPoints=[],this._data=[],this._lastVelocity=0,this._lastWidth=0,this._handleMouseDown=t=>{1===t.buttons&&this._strokeBegin(t)},this._handleMouseMove=t=>{this._strokeMoveUpdate(t)},this._handleMouseUp=t=>{1===t.buttons&&this._strokeEnd(t)},this._handleTouchStart=t=>{if(t.cancelable&&t.preventDefault(),1===t.targetTouches.length){const e=t.changedTouches[0];this._strokeBegin(e)}},this._handleTouchMove=t=>{t.cancelable&&t.preventDefault();const e=t.targetTouches[0];this._strokeMoveUpdate(e)},this._handleTouchEnd=t=>{if(t.target===this.canvas){t.cancelable&&t.preventDefault();const e=t.changedTouches[0];this._strokeEnd(e)}},this._handlePointerStart=t=>{t.preventDefault(),this._strokeBegin(t)},this._handlePointerMove=t=>{this._strokeMoveUpdate(t)},this._handlePointerEnd=t=>{this._drawingStroke&&(t.preventDefault(),this._strokeEnd(t))},this.velocityFilterWeight=e.velocityFilterWeight||.7,this.minWidth=e.minWidth||.5,this.maxWidth=e.maxWidth||2.5,this.throttle="throttle"in e?e.throttle:16,this.minDistance="minDistance"in e?e.minDistance:5,this.dotSize=e.dotSize||0,this.penColor=e.penColor||"black",this.backgroundColor=e.backgroundColor||"rgba(0,0,0,0)",this.compositeOperation=e.compositeOperation||"source-over",this._strokeMoveUpdate=this.throttle?function(t,e=250){let i,s,n,o=0,h=null;const r=()=>{o=Date.now(),h=null,i=t.apply(s,n),h||(s=null,n=[])};return function(...a){const c=Date.now(),d=e-(c-o);return s=this,n=a,d<=0||d>e?(h&&(clearTimeout(h),h=null),o=c,i=t.apply(s,n),h||(s=null,n=[])):h||(h=window.setTimeout(r,d)),i}}(s.prototype._strokeUpdate,this.throttle):s.prototype._strokeUpdate,this._ctx=t.getContext("2d"),this.clear(),this.on()}clear(){const{_ctx:t,canvas:e}=this;t.fillStyle=this.backgroundColor,t.clearRect(0,0,e.width,e.height),t.fillRect(0,0,e.width,e.height),this._data=[],this._reset(this._getPointGroupOptions()),this._isEmpty=!0}fromDataURL(t,e={}){return new Promise(((i,s)=>{const n=new Image,o=e.ratio||window.devicePixelRatio||1,h=e.width||this.canvas.width/o,r=e.height||this.canvas.height/o,a=e.xOffset||0,c=e.yOffset||0;this._reset(this._getPointGroupOptions()),n.onload=()=>{this._ctx.drawImage(n,a,c,h,r),i()},n.onerror=t=>{s(t)},n.crossOrigin="anonymous",n.src=t,this._isEmpty=!1}))}toDataURL(t="image/png",e){return"image/svg+xml"===t?("object"!=typeof e&&(e=void 0),`data:image/svg+xml;base64,${btoa(this.toSVG(e))}`):("number"!=typeof e&&(e=void 0),this.canvas.toDataURL(t,e))}on(){this.canvas.style.touchAction="none",this.canvas.style.msTouchAction="none",this.canvas.style.userSelect="none";const t=/Macintosh/.test(navigator.userAgent)&&"ontouchstart"in document;window.PointerEvent&&!t?this._handlePointerEvents():(this._handleMouseEvents(),"ontouchstart"in window&&this._handleTouchEvents())}off(){this.canvas.style.touchAction="auto",this.canvas.style.msTouchAction="auto",this.canvas.style.userSelect="auto",this.canvas.removeEventListener("pointerdown",this._handlePointerStart),this.canvas.removeEventListener("pointermove",this._handlePointerMove),this.canvas.ownerDocument.removeEventListener("pointerup",this._handlePointerEnd),this.canvas.removeEventListener("mousedown",this._handleMouseDown),this.canvas.removeEventListener("mousemove",this._handleMouseMove),this.canvas.ownerDocument.removeEventListener("mouseup",this._handleMouseUp),this.canvas.removeEventListener("touchstart",this._handleTouchStart),this.canvas.removeEventListener("touchmove",this._handleTouchMove),this.canvas.removeEventListener("touchend",this._handleTouchEnd)}isEmpty(){return this._isEmpty}fromData(t,{clear:e=!0}={}){e&&this.clear(),this._fromData(t,this._drawCurve.bind(this),this._drawDot.bind(this)),this._data=this._data.concat(t)}toData(){return this._data}_getPointGroupOptions(t){return{penColor:t&&"penColor"in t?t.penColor:this.penColor,dotSize:t&&"dotSize"in t?t.dotSize:this.dotSize,minWidth:t&&"minWidth"in t?t.minWidth:this.minWidth,maxWidth:t&&"maxWidth"in t?t.maxWidth:this.maxWidth,velocityFilterWeight:t&&"velocityFilterWeight"in t?t.velocityFilterWeight:this.velocityFilterWeight,compositeOperation:t&&"compositeOperation"in t?t.compositeOperation:this.compositeOperation}}_strokeBegin(t){if(!this.dispatchEvent(new CustomEvent("beginStroke",{detail:t,cancelable:!0})))return;this._drawingStroke=!0;const e=this._getPointGroupOptions(),i=Object.assign(Object.assign({},e),{points:[]});this._data.push(i),this._reset(e),this._strokeUpdate(t)}_strokeUpdate(t){if(!this._drawingStroke)return;if(0===this._data.length)return void this._strokeBegin(t);this.dispatchEvent(new CustomEvent("beforeUpdateStroke",{detail:t}));const e=t.clientX,i=t.clientY,s=void 0!==t.pressure?t.pressure:void 0!==t.force?t.force:0,n=this._createPoint(e,i,s),o=this._data[this._data.length-1],h=o.points,r=h.length>0&&h[h.length-1],a=!!r&&n.distanceTo(r)<=this.minDistance,c=this._getPointGroupOptions(o);if(!r||!r||!a){const t=this._addPoint(n,c);r?t&&this._drawCurve(t,c):this._drawDot(n,c),h.push({time:n.time,x:n.x,y:n.y,pressure:n.pressure})}this.dispatchEvent(new CustomEvent("afterUpdateStroke",{detail:t}))}_strokeEnd(t){this._drawingStroke&&(this._strokeUpdate(t),this._drawingStroke=!1,this.dispatchEvent(new CustomEvent("endStroke",{detail:t})))}_handlePointerEvents(){this._drawingStroke=!1,this.canvas.addEventListener("pointerdown",this._handlePointerStart),this.canvas.addEventListener("pointermove",this._handlePointerMove),this.canvas.ownerDocument.addEventListener("pointerup",this._handlePointerEnd)}_handleMouseEvents(){this._drawingStroke=!1,this.canvas.addEventListener("mousedown",this._handleMouseDown),this.canvas.addEventListener("mousemove",this._handleMouseMove),this.canvas.ownerDocument.addEventListener("mouseup",this._handleMouseUp)}_handleTouchEvents(){this.canvas.addEventListener("touchstart",this._handleTouchStart),this.canvas.addEventListener("touchmove",this._handleTouchMove),this.canvas.addEventListener("touchend",this._handleTouchEnd)}_reset(t){this._lastPoints=[],this._lastVelocity=0,this._lastWidth=(t.minWidth+t.maxWidth)/2,this._ctx.fillStyle=t.penColor,this._ctx.globalCompositeOperation=t.compositeOperation}_createPoint(e,i,s){const n=this.canvas.getBoundingClientRect();return new t(e-n.left,i-n.top,s,(new Date).getTime())}_addPoint(t,i){const{_lastPoints:s}=this;if(s.push(t),s.length>2){3===s.length&&s.unshift(s[0]);const t=this._calculateCurveWidths(s[1],s[2],i),n=e.fromPoints(s,t);return s.shift(),n}return null}_calculateCurveWidths(t,e,i){const s=i.velocityFilterWeight*e.velocityFrom(t)+(1-i.velocityFilterWeight)*this._lastVelocity,n=this._strokeWidth(s,i),o={end:n,start:this._lastWidth};return this._lastVelocity=s,this._lastWidth=n,o}_strokeWidth(t,e){return Math.max(e.maxWidth/(t+1),e.minWidth)}_drawCurveSegment(t,e,i){const s=this._ctx;s.moveTo(t,e),s.arc(t,e,i,0,2*Math.PI,!1),this._isEmpty=!1}_drawCurve(t,e){const i=this._ctx,s=t.endWidth-t.startWidth,n=2*Math.ceil(t.length());i.beginPath(),i.fillStyle=e.penColor;for(let i=0;i<n;i+=1){const o=i/n,h=o*o,r=h*o,a=1-o,c=a*a,d=c*a;let l=d*t.startPoint.x;l+=3*c*o*t.control1.x,l+=3*a*h*t.control2.x,l+=r*t.endPoint.x;let u=d*t.startPoint.y;u+=3*c*o*t.control1.y,u+=3*a*h*t.control2.y,u+=r*t.endPoint.y;const v=Math.min(t.startWidth+r*s,e.maxWidth);this._drawCurveSegment(l,u,v)}i.closePath(),i.fill()}_drawDot(t,e){const i=this._ctx,s=e.dotSize>0?e.dotSize:(e.minWidth+e.maxWidth)/2;i.beginPath(),this._drawCurveSegment(t.x,t.y,s),i.closePath(),i.fillStyle=e.penColor,i.fill()}_fromData(e,i,s){for(const n of e){const{points:e}=n,o=this._getPointGroupOptions(n);if(e.length>1)for(let s=0;s<e.length;s+=1){const n=e[s],h=new t(n.x,n.y,n.pressure,n.time);0===s&&this._reset(o);const r=this._addPoint(h,o);r&&i(r,o)}else this._reset(o),s(e[0],o)}}toSVG({includeBackgroundColor:t=!1}={}){const e=this._data,i=Math.max(window.devicePixelRatio||1,1),s=this.canvas.width/i,n=this.canvas.height/i,o=document.createElementNS("http://www.w3.org/2000/svg","svg");if(o.setAttribute("xmlns","http://www.w3.org/2000/svg"),o.setAttribute("xmlns:xlink","http://www.w3.org/1999/xlink"),o.setAttribute("viewBox",`0 0 ${s} ${n}`),o.setAttribute("width",s.toString()),o.setAttribute("height",n.toString()),t&&this.backgroundColor){const t=document.createElement("rect");t.setAttribute("width","100%"),t.setAttribute("height","100%"),t.setAttribute("fill",this.backgroundColor),o.appendChild(t)}return this._fromData(e,((t,{penColor:e})=>{const i=document.createElement("path");if(!(isNaN(t.control1.x)||isNaN(t.control1.y)||isNaN(t.control2.x)||isNaN(t.control2.y))){const s=`M ${t.startPoint.x.toFixed(3)},${t.startPoint.y.toFixed(3)} C ${t.control1.x.toFixed(3)},${t.control1.y.toFixed(3)} ${t.control2.x.toFixed(3)},${t.control2.y.toFixed(3)} ${t.endPoint.x.toFixed(3)},${t.endPoint.y.toFixed(3)}`;i.setAttribute("d",s),i.setAttribute("stroke-width",(2.25*t.endWidth).toFixed(3)),i.setAttribute("stroke",e),i.setAttribute("fill","none"),i.setAttribute("stroke-linecap","round"),o.appendChild(i)}}),((t,{penColor:e,dotSize:i,minWidth:s,maxWidth:n})=>{const h=document.createElement("circle"),r=i>0?i:(s+n)/2;h.setAttribute("r",r.toString()),h.setAttribute("cx",t.x.toString()),h.setAttribute("cy",t.y.toString()),h.setAttribute("fill",e),o.appendChild(h)})),o.outerHTML}}return s}));

(function () {
    const canvas = document.getElementById('signature-pad');
    const pad    = new SignaturePad(canvas, {
        backgroundColor: 'rgb(255, 255, 255)',
        penColor: 'rgb(0, 0, 100)',
    });

    function resizeCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const w = canvas.offsetWidth;
        canvas.width  = w * ratio;
        canvas.height = 200 * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();
    }
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    document.getElementById('btn-clear').addEventListener('click', () => pad.clear());

    document.getElementById('btn-sign').addEventListener('click', function () {
        if (pad.isEmpty()) {
            alert('Por favor, assine antes de continuar.');
            return;
        }
        document.getElementById('signature_data').value = pad.toDataURL('image/png');
        document.getElementById('form-sign').submit();
    });
})();
</script>

<?php Html::footer(); ?>
