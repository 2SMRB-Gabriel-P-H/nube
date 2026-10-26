<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

requiereLogin();

$p = relClean((string) ($_GET['p'] ?? ''));
$abs = pathOf($p);
$existe = is_file($abs);
$ext = extDe($p);

if (!puedeEditar($p)) {
    http_response_code(403);
    exit('No tienes permiso para editar este archivo.');
}

if ($existe && !esEditableTexto($ext)) {
    header('Location: view.php?p=' . rawurlencode($p));
    exit;
}

$contenido = $existe ? leerArchivo($abs) : '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Editar · <?= h(basename($p) ?: 'Nuevo documento') ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%231a73e8' d='M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z'/></svg>">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; height: 100vh; display: flex; flex-direction: column; font-family: 'Segoe UI', Roboto, Arial, sans-serif; background: #1e1e1e; color: #d4d4d4; }
    header { display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: #252526; border-bottom: 1px solid #3c3c3c; flex-wrap: wrap; }
    .doc { width: 22px; height: 26px; background: #1a73e8; border-radius: 3px; flex-shrink: 0; }
    header .nombre { font-size: .95rem; font-weight: 500; }
    .estado { font-size: .78rem; color: #9aa0a6; }
    .presentes { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .pres { display: inline-flex; align-items: center; gap: 5px; font-size: .72rem; color: #e8eaed; background: #33363b; border: 1px solid #555; border-radius: 999px; padding: 2px 8px; }
    .pres i { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .der { margin-left: auto; display: flex; align-items: center; gap: 10px; }
    .btn { border: none; border-radius: 6px; padding: 8px 16px; font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn.azul { background: #1a73e8; color: #fff; } .btn.azul:hover { background: #1765cc; }
    .btn.gris { background: #3c3c3c; color: #e8eaed; } .btn.gris:hover { background: #4a4a4a; }
    .editor-wrap { flex: 1; display: flex; overflow: hidden; background: #1e1e1e; }
    .gutter { padding: 14px 10px 14px 16px; text-align: right; color: #6e7681; font-family: Consolas, 'Courier New', monospace; font-size: .85rem; line-height: 1.55; background: #1e1e1e; user-select: none; overflow: hidden; white-space: pre; }
    .edit { position: relative; flex: 1; display: flex; overflow: hidden; }
    .edit textarea {
        flex: 1; border: none; outline: none; resize: none; padding: 14px 18px; background: #1e1e1e; color: #d4d4d4;
        font-family: Consolas, 'Courier New', monospace; font-size: .85rem; line-height: 1.55; white-space: pre; overflow: auto; tab-size: 4;
    }
    #cursores { position: absolute; inset: 0; pointer-events: none; overflow: hidden; z-index: 2; }
    #cursores .cursor { position: absolute; width: 2px; border-radius: 1px; }
    #cursores .tag { position: absolute; transform: translateY(-115%); font-size: 10px; line-height: 1.4; color: #fff; padding: 0 4px; border-radius: 3px; white-space: nowrap; opacity: .95; }
    #cursores .sel { position: absolute; border-radius: 2px; }
    @media (max-width: 600px) {
        header { padding: 8px 10px; gap: 8px; }
        header .nombre { font-size: .85rem; }
        .estado { display: none !important; }
        .presentes { display: none; }
        .btn { padding: 7px 10px; font-size: .8rem; }
        .gutter { padding: 12px 8px 12px 10px; font-size: .78rem; }
        .edit textarea { padding: 12px; font-size: .8rem; }
    }
    #sugerencias {
        display: none; position: absolute; z-index: 5; width: 300px; max-width: calc(100% - 16px);
        max-height: 230px; overflow: auto; background: #252526; color: #d4d4d4;
        border: 1px solid #454545; border-radius: 6px; box-shadow: 0 8px 22px #0008;
        font: 12px/1.35 'Segoe UI', Arial, sans-serif;
    }
    #sugerencias.abierto { display: block; }
    .sug-item { display: flex; align-items: center; gap: 9px; width: 100%; padding: 6px 9px; border: 0; color: inherit; background: transparent; text-align: left; cursor: pointer; }
    .sug-item:hover, .sug-item.activo { background: #094771; }
    .sug-tipo { flex: 0 0 48px; color: #9cdcfe; font: 10px Consolas, monospace; text-transform: uppercase; }
    .sug-label { color: #dcdcaa; font: 12px Consolas, monospace; }
    .sug-detalle { margin-left: auto; color: #858585; white-space: nowrap; }
    .sug-ayuda { padding: 5px 9px; border-top: 1px solid #3c3c3c; color: #858585; font-size: 10px; }
</style>
</head>
<body>
<header>
    <div class="doc"></div>
    <div class="nombre" id="titulo"><?= h(basename($p) ?: 'Nuevo documento') ?></div>
    <span class="estado" id="estado" style="display:none"></span>
    <div class="presentes" id="presentes"></div>
    <div class="der">
        <a class="btn gris" href="<?= h(enlace('index.php?p=' . rawurlencode(dirname($p) === '.' || dirname($p) === '' ? '' : dirname($p)))) ?>">← Volver</a>
        <button class="btn gris" id="btnDescargar">⬇️</button>
        <button class="btn azul" id="btnGuardar">Guardar (Ctrl+S)</button>
    </div>
</header>

<div class="editor-wrap">
    <div class="gutter" id="gutter">1</div>
    <div class="edit">
        <div id="cursores"></div>
        <div id="sugerencias" role="listbox" aria-label="Sugerencias de código"></div>
        <textarea id="editor" spellcheck="false" placeholder="Escribe aquí…"><?= h($contenido) ?></textarea>
    </div>
</div>

<script>
const RUTA = <?= json_encode($p) ?>;
const EXISTE = <?= $existe ? 'true' : 'false' ?>;
const LENGUAJE = <?= json_encode(in_array($ext, ['html', 'htm'], true) ? 'html' : ($ext === 'css' ? 'css' : '')) ?>;
const CTX_O = <?= (int) ctxOwner() ?>;
const CSRF = <?= json_encode(csrfToken()) ?>;
const CONTENIDO_INICIAL = <?= json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const editor = document.getElementById('editor');
const gutter = document.getElementById('gutter');
const estado = document.getElementById('estado');
const capaCursores = document.getElementById('cursores');

/* ---------- utilidades OT (operaciones sobre texto) ---------- */
function applyOp(s, op) {
    if (!op) return s;
    return s.slice(0, op.pos) + op.ins + s.slice(op.pos + op.del);
}
function diff(a, b) {
    if (a === b) return null;
    const n = Math.min(a.length, b.length);
    let p = 0;
    while (p < n && a.charCodeAt(p) === b.charCodeAt(p)) p++;
    let q = 0;
    while (q < n - p && a.charCodeAt(a.length - 1 - q) === b.charCodeAt(b.length - 1 - q)) q++;
    return { pos: p, del: a.length - p - q, ins: b.slice(p, b.length - q), uid: myUid };
}
function mapIndex(x, op) {
    if (x <= op.pos) return x;
    if (x <= op.pos + op.del) return op.pos + op.ins.length;
    return x - op.del + op.ins.length;
}
function unmapIndex(x, op) {
    if (x <= op.pos) return x;
    if (x <= op.pos + op.ins.length) return op.pos;
    return x - op.ins.length + op.del;
}
function applySelection(op, sel) {
    if (!op) return sel.slice();
    return [mapIndex(sel[0], op), mapIndex(sel[1], op)];
}
function unapplySelection(op, sel) {
    if (!op) return sel.slice();
    return [unmapIndex(sel[0], op), unmapIndex(sel[1], op)];
}
function transform(a, b) {
    const aS = a.pos, aE = a.pos + a.del, bS = b.pos, bE = b.pos + b.del;
    const shift = b.ins.length - b.del;
    let nS = aS <= bS ? aS : (aS >= bE ? aS + shift : bS + b.ins.length);
    let nE = aE <= bS ? aE : (aE >= bE ? aE + shift : bS + b.ins.length);
    if (a.del === 0 && b.del === 0 && aS === bS && String(a.uid) > String(b.uid)) {
        nS += b.ins.length; nE += b.ins.length;
    }
    if (nE < nS) nE = nS;
    return { pos: nS, del: nE - nS, ins: a.ins, uid: a.uid };
}

/* ---------- estado colaborativo ---------- */
let content = CONTENIDO_INICIAL;
let version = -1;
let pending = null;
let opsLog = [];
let usuarios = {};
let myUid = 0;
let myContentSel = [0, 0];
let conectado = false;
let busy = false, needPush = false, needSync = false;
let aplicando = false;

/* ---------- autocompletado ligero para HTML y CSS ---------- */
const cajaSugerencias = document.getElementById('sugerencias');
let contextoSugerencias = null;
let indiceSugerencia = 0;
const TAGS_HTML = 'a abbr address article aside audio b blockquote body br button canvas caption code col colgroup data datalist dd del details div dl dt em fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 head header hr html i iframe img input label legend li link main map meta nav ol option p picture pre progress script section select small source span strong style sub summary sup table tbody td template textarea tfoot th thead time title tr track u ul video'.split(' ');
const ATRIBUTOS_HTML = 'accept action alt autocomplete autofocus autoplay charset checked cite class colspan cols content controls data defer disabled download enctype for height hidden href id lang loading loop max maxlength media method min minlength multiple muted name open pattern placeholder poster preload readonly rel required role rows rowspan selected size span src start step style tabindex target title type value width wrap aria-label aria-hidden aria-live'.split(' ');
const BOOLEAN_HTML = new Set('autofocus autoplay checked controls defer disabled hidden loop multiple muted open readonly required selected'.split(' '));
const VALORES_HTML = {
    target: ['_blank', '_self', '_parent', '_top'],
    rel: ['noopener', 'noreferrer', 'nofollow', 'stylesheet', 'icon'],
    type: ['text', 'email', 'password', 'number', 'checkbox', 'radio', 'submit', 'button', 'date', 'file', 'search', 'tel', 'url'],
    method: ['get', 'post'],
    loading: ['lazy', 'eager'],
    autocomplete: ['on', 'off', 'name', 'email', 'username', 'current-password', 'new-password']
};
const PROPIEDADES_CSS = 'align-content align-items align-self animation background background-color background-image background-position background-repeat background-size border border-radius bottom box-shadow box-sizing color column-gap content cursor display flex flex-basis flex-direction flex-flow flex-grow flex-shrink flex-wrap float font font-family font-size font-style font-weight gap grid grid-template-columns grid-template-rows height justify-content left letter-spacing line-height margin margin-bottom margin-left margin-right margin-top max-height max-width min-height min-width object-fit opacity overflow overflow-x overflow-y padding padding-bottom padding-left padding-right padding-top position right text-align text-decoration text-overflow text-transform top transform transition user-select vertical-align visibility white-space width z-index'.split(' ');
const VALORES_CSS = {
    display: ['block', 'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'none', 'table', 'contents'],
    position: ['static', 'relative', 'absolute', 'fixed', 'sticky'],
    'flex-direction': ['row', 'row-reverse', 'column', 'column-reverse'],
    'flex-wrap': ['nowrap', 'wrap', 'wrap-reverse'],
    'justify-content': ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'],
    'align-items': ['stretch', 'center', 'flex-start', 'flex-end', 'baseline'],
    'align-content': ['stretch', 'center', 'flex-start', 'flex-end', 'space-between', 'space-around'],
    'text-align': ['left', 'center', 'right', 'justify', 'start', 'end'],
    'font-weight': ['normal', 'bold', 'lighter', 'bolder', '100', '300', '400', '500', '700', '900'],
    'font-style': ['normal', 'italic', 'oblique'],
    overflow: ['visible', 'hidden', 'scroll', 'auto', 'clip'],
    'overflow-x': ['visible', 'hidden', 'scroll', 'auto', 'clip'],
    'overflow-y': ['visible', 'hidden', 'scroll', 'auto', 'clip'],
    'box-sizing': ['content-box', 'border-box'],
    'object-fit': ['contain', 'cover', 'fill', 'none', 'scale-down'],
    'white-space': ['normal', 'nowrap', 'pre', 'pre-wrap', 'pre-line', 'break-spaces'],
    visibility: ['visible', 'hidden', 'collapse'],
    cursor: ['auto', 'default', 'pointer', 'move', 'not-allowed', 'text', 'grab'],
    'background-repeat': ['no-repeat', 'repeat', 'repeat-x', 'repeat-y', 'space', 'round'],
    'background-size': ['cover', 'contain', 'auto'],
    'text-decoration': ['none', 'underline', 'overline', 'line-through'],
    'text-transform': ['none', 'capitalize', 'uppercase', 'lowercase'],
    'border-style': ['none', 'solid', 'dashed', 'dotted', 'double'],
    'border-radius': ['0', '4px', '8px', '50%'],
    'z-index': ['auto', '0', '1', '10', '100'],
    color: ['#000', '#fff', 'red', 'blue', 'currentColor', 'transparent'],
    'background-color': ['transparent', '#fff', '#000', '#f5f5f5', 'currentColor'],
    'font-family': ['Arial, sans-serif', 'system-ui, sans-serif', 'Georgia, serif', 'monospace'],
    'margin': ['0', 'auto', '1rem'], 'padding': ['0', '0.5rem', '1rem'],
    width: ['auto', '100%', '100vw', 'fit-content', 'max-content'],
    height: ['auto', '100%', '100vh', 'fit-content'],
    'max-width': ['100%', '1200px', 'none'], 'min-width': ['0', '100px'],
    'max-height': ['100%', '100vh', 'none'], 'min-height': ['0', '100vh'],
    opacity: ['0', '0.5', '1'], transition: ['all 0.2s ease', 'opacity 0.2s ease'],
    'align-self': ['auto', 'stretch', 'center', 'flex-start', 'flex-end']
};
const AT_RULES_CSS = ['media', 'supports', 'keyframes', 'font-face', 'import', 'charset', 'container', 'layer', 'page', 'scope'];
const SELECTORES_CSS = ['hover', 'focus', 'focus-visible', 'active', 'visited', 'first-child', 'last-child', 'nth-child()', 'before', 'after', 'checked', 'disabled'];

function opcionesDesde(lista, tipo, prefijo, detalleFn = null) {
    const q = prefijo.toLowerCase();
    return lista.filter(x => !q || x.toLowerCase().startsWith(q)).map(x => ({
        label: x, tipo, detalle: detalleFn ? detalleFn(x) : '', insert: x
    }));
}
function contextoHtml(antes, pos) {
    const iniCom = antes.lastIndexOf('<!--'), finCom = antes.lastIndexOf('-->');
    if (iniCom > finCom) return null;
    const lt = antes.lastIndexOf('<'), gt = antes.lastIndexOf('>');
    if (lt <= gt) return null;
    const frag = antes.slice(lt + 1);
    if (frag.startsWith('!') || frag.startsWith('?')) return null;
    const cierre = frag.startsWith('/');
    const offset = lt + 1 + (cierre ? 1 : 0);
    const cuerpo = cierre ? frag.slice(1) : frag;
    const nombre = cuerpo.match(/^[a-z][\w:-]*/i);
    if (!nombre) {
        if (/^[\w-]*$/.test(cuerpo)) {
            const prefijo = cuerpo;
            return { modo: 'tag', inicio: pos - prefijo.length, fin: pos, prefijo, cierre, candidatos: opcionesDesde(TAGS_HTML, 'etiqueta', prefijo) };
        }
        return null;
    }
    const resto = cuerpo.slice(nombre[0].length);
    const valor = resto.match(/([\w:-]+)\s*=\s*["']?([\w#.-]*)$/);
    if (valor) {
        const attr = valor[1].toLowerCase(), prefijo = valor[2];
        const candidatos = opcionesDesde(VALORES_HTML[attr] || [], 'valor', prefijo);
        return candidatos.length ? { modo: 'valor', inicio: pos - prefijo.length, fin: pos, prefijo, candidatos } : null;
    }
    if (/\s/.test(resto) || resto === '') {
        const m = resto.match(/[\w:-]*$/), prefijo = m ? m[0] : '';
        const inicio = pos - prefijo.length;
        return { modo: resto === '' ? 'tag' : 'atributo', inicio: resto === '' ? offset : inicio, fin: pos, prefijo: resto === '' ? nombre[0] : prefijo,
            cierre, consumeMayor: editor.value[pos] === '>', tagActual: nombre[0],
            candidatos: resto === '' ? opcionesDesde(TAGS_HTML, 'etiqueta', nombre[0]) : opcionesDesde(ATRIBUTOS_HTML, 'atributo', prefijo) };
    }
    return null;
}
function contextoCss(antes, pos) {
    const iniCom = antes.lastIndexOf('/*'), finCom = antes.lastIndexOf('*/');
    if (iniCom > finCom) return null;
    const at = antes.match(/(?:^|\n)\s*@([\w-]*)$/);
    if (at) {
        const prefijo = at[1];
        return { modo: 'at-rule', inicio: pos - prefijo.length, fin: pos, prefijo, candidatos: opcionesDesde(AT_RULES_CSS, 'regla', prefijo) };
    }
    const llave = Math.max(antes.lastIndexOf('{'), antes.lastIndexOf('}'));
    if (antes.lastIndexOf('{') > antes.lastIndexOf('}')) {
        const bloque = antes.slice(antes.lastIndexOf('{') + 1);
        const valor = bloque.match(/(?:^|;)\s*([\w-]+)\s*:\s*([^;{}]*)$/);
        if (valor) {
            const propiedad = valor[1].toLowerCase(), prefijo = valor[2].trimStart();
            const inicio = pos - prefijo.length;
            const lista = VALORES_CSS[propiedad] || ['auto', 'none', 'inherit', 'initial', 'unset', '0', '100%'];
            return { modo: 'valor-css', inicio, fin: pos, prefijo, candidatos: opcionesDesde(lista, 'valor', prefijo) };
        }
        const prop = bloque.match(/(?:^|;)\s*([\w-]*)$/);
        if (prop) {
            const prefijo = prop[1];
            return { modo: 'propiedad', inicio: pos - prefijo.length, fin: pos, prefijo,
                candidatos: opcionesDesde(PROPIEDADES_CSS, 'css', prefijo) };
        }
    } else {
        const pseudo = antes.match(/:([\w-]*)$/);
        if (pseudo) {
            const prefijo = pseudo[1];
            return { modo: 'selector', inicio: pos - prefijo.length, fin: pos, prefijo,
                candidatos: opcionesDesde(SELECTORES_CSS, 'pseudo', prefijo) };
        }
    }
    return null;
}
function ocultarSugerencias() {
    cajaSugerencias.classList.remove('abierto');
    cajaSugerencias.innerHTML = '';
    contextoSugerencias = null;
    indiceSugerencia = 0;
}
function pintarSugerencias(ctx) {
    contextoSugerencias = ctx;
    indiceSugerencia = 0;
    const items = ctx.candidatos.slice(0, 9);
    if (!items.length) { ocultarSugerencias(); return; }
    cajaSugerencias.innerHTML = '';
    items.forEach((item, i) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'sug-item' + (i === 0 ? ' activo' : '');
        b.setAttribute('role', 'option'); b.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
        const tipo = document.createElement('span'); tipo.className = 'sug-tipo'; tipo.textContent = item.tipo;
        const label = document.createElement('span'); label.className = 'sug-label'; label.textContent = item.label;
        b.append(tipo, label);
        if (item.detalle) { const d = document.createElement('span'); d.className = 'sug-detalle'; d.textContent = item.detalle; b.appendChild(d); }
        b.addEventListener('mousedown', e => { e.preventDefault(); aceptarSugerencia(i); });
        cajaSugerencias.appendChild(b);
    });
    const ayuda = document.createElement('div'); ayuda.className = 'sug-ayuda'; ayuda.textContent = '↑ ↓ elegir · Tab/Enter completar · Esc cerrar';
    cajaSugerencias.appendChild(ayuda);
    cajaSugerencias.classList.add('abierto');
    const punto = posPixeles(editor.selectionStart), marco = editor.parentElement;
    const ancho = Math.min(300, Math.max(180, marco.clientWidth - 16));
    cajaSugerencias.style.width = ancho + 'px';
    const x = Math.max(8, Math.min(punto.x, marco.clientWidth - ancho - 8));
    cajaSugerencias.style.left = x + 'px';
    let y = punto.y + punto.h + 4;
    const alto = cajaSugerencias.offsetHeight;
    if (y + alto > marco.clientHeight - 8) y = Math.max(8, punto.y - alto - 4);
    cajaSugerencias.style.top = y + 'px';
}
function contextoCssEnHtml(antes, pos) {
    const cierres = [...antes.matchAll(/<\/style\s*>/gi)];
    const ultimoCierre = cierres.length ? cierres[cierres.length - 1].index : -1;
    const aperturas = [...antes.matchAll(/<style\b[^>]*>/gi)];
    const ultimaApertura = aperturas.length ? aperturas[aperturas.length - 1] : null;
    if (ultimaApertura && ultimaApertura.index > ultimoCierre) {
        const inicioCss = ultimaApertura.index + ultimaApertura[0].length;
        const ctx = contextoCss(antes.slice(inicioCss), pos - inicioCss);
        if (ctx) { ctx.inicio += inicioCss; ctx.fin += inicioCss; return ctx; }
        return null;
    }

    const lt = antes.lastIndexOf('<'), gt = antes.lastIndexOf('>');
    if (lt > gt) {
        const etiqueta = antes.slice(lt + 1);
        const estilo = etiqueta.match(/\bstyle\s*=\s*(["'])([^"']*)$/i);
        if (estilo) {
            const css = estilo[2], inicioCss = pos - css.length;
            const ctx = contextoCss('{' + css, css.length + 1);
            if (ctx) { ctx.inicio += inicioCss - 1; ctx.fin += inicioCss - 1; return ctx; }
        }
    }
    return null;
}
function actualizarSugerencias(forzar = false) {
    if (!LENGUAJE || editor.selectionStart !== editor.selectionEnd) { ocultarSugerencias(); return; }
    const pos = editor.selectionStart, antes = editor.value.slice(0, pos);
    const ctx = LENGUAJE === 'html'
        ? (contextoCssEnHtml(antes, pos) || contextoHtml(antes, pos))
        : contextoCss(antes, pos);
    if (!ctx || (!forzar && ctx.prefijo.length === 0 && !['tag', 'atributo', 'propiedad', 'valor', 'valor-css', 'at-rule'].includes(ctx.modo))) { ocultarSugerencias(); return; }
    pintarSugerencias(ctx);
}
function moverSugerencia(delta) {
    const botones = cajaSugerencias.querySelectorAll('.sug-item');
    if (!botones.length) return;
    indiceSugerencia = (indiceSugerencia + delta + botones.length) % botones.length;
    botones.forEach((b, i) => { b.classList.toggle('activo', i === indiceSugerencia); b.setAttribute('aria-selected', i === indiceSugerencia ? 'true' : 'false'); });
    botones[indiceSugerencia].scrollIntoView({ block: 'nearest' });
}
function aceptarSugerencia(indice = indiceSugerencia) {
    if (!contextoSugerencias) return false;
    const ctx = contextoSugerencias, item = ctx.candidatos[indice];
    if (!item) return false;
    let texto = item.insert, cursor = texto.length, fin = ctx.fin;
    if (ctx.modo === 'tag') {
        const voidTags = new Set(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr']);
        if (ctx.cierre) texto = item.insert + (editor.value[fin] === '>' ? '' : '>');
        else if (voidTags.has(item.insert)) {
            if (editor.value[fin] === '>') fin++;
            texto = item.insert + '>';
        }
        else {
            if (editor.value[fin] === '>') fin++;
            texto = item.insert + '></' + item.insert + '>';
            cursor = item.insert.length + 1;
        }
    } else if (ctx.modo === 'atributo' && !BOOLEAN_HTML.has(item.insert)) {
        texto = item.insert + '=""'; cursor = texto.length - 1;
    } else if (ctx.modo === 'propiedad') {
        if (/^\s*:/.test(editor.value.slice(fin))) { texto = item.insert; cursor = texto.length; }
        else { texto = item.insert + ': '; cursor = texto.length; }
    }
    editor.setRangeText(texto, ctx.inicio, fin, 'end');
    editor.setSelectionRange(ctx.inicio + cursor, ctx.inicio + cursor);
    ocultarSugerencias();
    alEscribir();
    return true;
}

function colorDe(uid) {
    const s = String(uid);
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return 'hsl(' + (h % 360) + ' 70% 55%)';
}
function escapar(s) {
    return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

/* ---------- red ---------- */
async function api(accion, extra) {
    const url = 'live.php?p=' + encodeURIComponent(RUTA) + (CTX_O ? ('&o=' + CTX_O) : '');
    const body = Object.assign({ accion: accion, csrf: CSRF }, extra);
    try {
        const r = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin'
        });
        if (!r.ok) return { ok: false, http: r.status };
        return await r.json();
    } catch (e) { return { ok: false }; }
}

async function ciclo() {
    if (busy) return;
    busy = true;
    try {
        if (needSync) {
            needSync = false;
            const j = await api('sync', { since: version, sel: myContentSel });
            aplicarSync(j);
        }
        if (needPush && pending) {
            needPush = false;
            const op = { pos: pending.pos, del: pending.del, ins: pending.ins, uid: myUid };
            const j = await api('push', { base: version, op: op, sel: myContentSel });
            if (j.ok) {
                content = applyOp(content, op);
                version = j.version;
                if (j.op) opsLog.push(j.op);
                pending = diff(content, editor.value);
                if (pending) needPush = true;
                marcarEstado();
            } else if (j.error) {
                if (estado) estado.style.display = 'none';
            } else {
                needSync = true;
            }
        }
    } finally {
        busy = false;
        if ((needPush && pending) || needSync) setTimeout(ciclo, 60);
    }
}

function aplicarSync(j) {
    if (!j || j.ok === false) {
        if (j && j.http === 401 && estado) estado.style.display = 'none';
        return;
    }
    if (j.uid) myUid = j.uid;

    if (j.reset) {
        const teniaPend = pending !== null;
        content = j.content;
        version = j.version;
        opsLog = (j.ops || []).slice();
        if (teniaPend) {
            pending = diff(content, editor.value);
        } else {
            pending = null;
            editor.value = content;
            myContentSel = [0, 0];
        }
        render();
    } else if (j.ops && j.ops.length) {
        for (const op of j.ops) {
            if (pending) pending = transform(pending, op);
            myContentSel = applySelection(op, myContentSel);
            content = applyOp(content, op);
            version = op.v;
            opsLog.push(op);
        }
        if (opsLog.length > 600) opsLog = opsLog.slice(-600);
        render();
    }

    usuarios = j.users || {};
    conectado = true;
    pintarPresentes();
    pintarCursores();
    marcarEstado();
}

/* ---------- render ---------- */
function render() {
    const val = pending ? applyOp(content, pending) : content;
    if (editor.value !== val) {
        const sl = editor.scrollLeft, st = editor.scrollTop;
        editor.value = val;
        editor.scrollLeft = sl; editor.scrollTop = st;
    }
    const s = pending ? applySelection(pending, myContentSel) : myContentSel;
    aplicando = true;
    try { editor.setSelectionRange(s[0], s[1]); } catch (e) {}
    aplicando = false;
    pintarGutter();
}

let sucio = false;
function marcarEstado() {
    const n = Object.keys(usuarios).length;
    if (!estado) return;
    estado.style.display = 'none';
}
function marcarSucio() { sucio = true; marcarEstado(); }

function pintarGutter() {
    const n = editor.value.split('\n').length;
    let s = '';
    for (let i = 1; i <= n; i++) s += i + '\n';
    gutter.textContent = s;
}

function pintarPresentes() {
    const el = document.getElementById('presentes');
    if (!el) return;
    const out = [];
    for (const uid in usuarios) {
        const u = usuarios[uid];
        out.push('<span class="pres"><i style="background:' + colorDe(uid) + '"></i>' +
            escapar(u.name || ('Usuario ' + uid)) + (String(uid) === String(myUid) ? ' (tú)' : '') + '</span>');
    }
    el.innerHTML = out.join('');
}

/* ---------- cursores remotos ---------- */
let charW = 0;
function medirChar() {
    const cs = getComputedStyle(editor);
    const span = document.createElement('span');
    span.style.position = 'absolute';
    span.style.visibility = 'hidden';
    span.style.whiteSpace = 'pre';
    span.style.fontFamily = cs.fontFamily;
    span.style.fontSize = cs.fontSize;
    span.style.fontWeight = cs.fontWeight;
    span.style.letterSpacing = cs.letterSpacing;
    span.textContent = new Array(101).join('0');
    document.body.appendChild(span);
    charW = span.getBoundingClientRect().width / 100;
    document.body.removeChild(span);
}
function posPixeles(index) {
    const cs = getComputedStyle(editor);
    const padL = parseFloat(cs.paddingLeft) || 0;
    const padT = parseFloat(cs.paddingTop) || 0;
    let lh = parseFloat(cs.lineHeight);
    if (!lh || isNaN(lh)) lh = (parseFloat(cs.fontSize) || 12) * 1.55;
    const val = editor.value;
    index = Math.max(0, Math.min(index, val.length));
    let line = 0, lastNL = -1;
    for (let i = 0; i < index; i++) if (val.charCodeAt(i) === 10) { line++; lastNL = i; }
    const col = index - (lastNL + 1);
    return {
        x: padL + col * charW - editor.scrollLeft,
        y: padT + line * lh - editor.scrollTop,
        h: lh
    };
}
function pintarCursores() {
    if (!capaCursores) return;
    const cs = getComputedStyle(editor);
    let lh = parseFloat(cs.lineHeight);
    if (!lh || isNaN(lh)) lh = (parseFloat(cs.fontSize) || 12) * 1.55;
    const n = editor.value.length;
    const frag = document.createDocumentFragment();

    for (const uid in usuarios) {
        if (String(uid) === String(myUid)) continue;
        const u = usuarios[uid];
        let sel = (u.sel || [0, 0]).slice();
        const uv = (typeof u.v === 'number') ? u.v : 0;
        if (uv <= version) {
            for (const op of opsLog) if (op.v > uv && op.v <= version) sel = applySelection(op, sel);
        }
        const dsel = pending ? applySelection(pending, sel) : sel;
        const a = Math.max(0, Math.min(dsel[0], n));
        const b = Math.max(0, Math.min(dsel[1], n));
        const color = colorDe(uid);

        if (b > a) {
            const p1 = posPixeles(a), p2 = posPixeles(b);
            const mk = (x, y, w) => {
                const d = document.createElement('div');
                d.className = 'sel';
                d.style.left = x + 'px'; d.style.top = y + 'px';
                d.style.width = w + 'px'; d.style.height = lh + 'px';
                d.style.background = color; d.style.opacity = '0.22';
                return d;
            };
            if (Math.abs(p1.y - p2.y) < 1) frag.appendChild(mk(p1.x, p1.y, Math.max(2, p2.x - p1.x)));
            else { frag.appendChild(mk(p1.x, p1.y, 4000)); frag.appendChild(mk(0, p2.y, Math.max(2, p2.x))); }
        }

        const p = posPixeles(a);
        const bar = document.createElement('div');
        bar.className = 'cursor';
        bar.style.left = p.x + 'px'; bar.style.top = p.y + 'px'; bar.style.height = lh + 'px';
        bar.style.background = color;
        frag.appendChild(bar);

        const tag = document.createElement('div');
        tag.className = 'tag';
        tag.style.left = p.x + 'px'; tag.style.top = p.y + 'px'; tag.style.background = color;
        tag.textContent = u.name || ('Usuario ' + uid);
        frag.appendChild(tag);
    }
    capaCursores.innerHTML = '';
    capaCursores.appendChild(frag);
}

/* ---------- interacción ---------- */
function leerCaret() {
    if (aplicando) return;
    const dom = [editor.selectionStart, editor.selectionEnd];
    myContentSel = pending ? unapplySelection(pending, dom) : dom;
}
let tPush = null, tSync = null;
function programarPush() { clearTimeout(tPush); tPush = setTimeout(() => { needPush = true; ciclo(); }, 180); }
function programarSync() { clearTimeout(tSync); tSync = setTimeout(() => { needSync = true; ciclo(); }, 280); }

function alEscribir() {
    pintarGutter();
    marcarSucio();
    pending = diff(content, editor.value);
    leerCaret();
    needPush = true;
    programarPush();
}
editor.addEventListener('input', () => { alEscribir(); actualizarSugerencias(); });
editor.addEventListener('scroll', () => { gutter.scrollTop = editor.scrollTop; pintarCursores(); if (contextoSugerencias) actualizarSugerencias(); });
[ 'mouseup', 'click', 'select' ].forEach(ev =>
    editor.addEventListener(ev, () => { leerCaret(); programarSync(); actualizarSugerencias(); }));
editor.addEventListener('keyup', e => {
    leerCaret(); programarSync();
    if (!['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(e.key)) actualizarSugerencias();
});
editor.addEventListener('blur', () => { if (sucio) guardar(); setTimeout(() => { if (!cajaSugerencias.matches(':hover')) ocultarSugerencias(); }, 120); });
editor.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.code === 'Space' && LENGUAJE) {
        e.preventDefault(); actualizarSugerencias(true); return;
    }
    if (cajaSugerencias.classList.contains('abierto')) {
        if (e.key === 'ArrowDown') { e.preventDefault(); moverSugerencia(1); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); moverSugerencia(-1); return; }
        if (e.key === 'Escape') { e.preventDefault(); ocultarSugerencias(); return; }
        if ((e.key === 'Tab' || e.key === 'Enter') && aceptarSugerencia()) { e.preventDefault(); return; }
    }
    if (e.key === 'Tab') {
        e.preventDefault();
        const s = editor.selectionStart, en = editor.selectionEnd;
        editor.value = editor.value.slice(0, s) + '    ' + editor.value.slice(en);
        editor.selectionStart = editor.selectionEnd = s + 4;
        alEscribir(); actualizarSugerencias();
    }
});
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); guardar(); }
});
window.addEventListener('resize', () => { medirChar(); pintarCursores(); });

/* ---------- guardar manual (respaldo) ---------- */
async function guardar() {
    estado.textContent = 'Guardando…';
    const fd = new FormData();
    fd.append('p', RUTA);
    fd.append('contenido', editor.value);
    if (CTX_O) fd.append('o', CTX_O);
    try {
        const r = await fetch('textsave.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) { sucio = false; marcarEstado(); }
        else { estado.textContent = 'Error: ' + (j.error || 'no se pudo guardar'); }
    } catch (err) { estado.textContent = 'Error de conexión'; }
}
document.getElementById('btnGuardar').onclick = guardar;
document.getElementById('btnDescargar').onclick = () => {
    location.href = 'raw.php?p=' + encodeURIComponent(RUTA) + '&download=1' + (CTX_O ? '&o=' + CTX_O : '');
};

window.addEventListener('beforeunload', e => {
    try {
        const url = 'live.php?p=' + encodeURIComponent(RUTA) + (CTX_O ? ('&o=' + CTX_O) : '');
        const data = new Blob([JSON.stringify({ accion: 'salir', csrf: CSRF })], { type: 'application/json' });
        navigator.sendBeacon(url, data);
    } catch (err) {}
    if (sucio) { e.preventDefault(); e.returnValue = ''; }
});

/* ---------- arranque ---------- */
medirChar();
pintarGutter();
needSync = true;
ciclo();
setInterval(() => { needSync = true; ciclo(); }, 1200);
setInterval(() => { if (sucio && (!conectado || !pending)) guardar(); }, 10000);
</script>
</body>
</html>
