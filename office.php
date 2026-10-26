<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

$usuario = requiereLogin();

$p = relClean((string) ($_GET['p'] ?? ''));
$abs = pathOf($p);

if ($p === '' || !is_file($abs) || !dentroDelRoot($p) || !esEditableOnlyOffice(extDe($p))) {
    http_response_code(404);
    exit('Documento no válido.');
}

if (!puedeEditar($p)) {
    http_response_code(403);
    exit('No tienes permiso para editar este documento.');
}

$ext = extDe($p);
$tipoDoc = tipoOnlyOffice($ext);
$nombre  = basename($p);

/* Una revisión abierta por todos los coeditores. OnlyOffice conserva en
   caché el contenido asociado a la clave; el hash cambia al guardar el
   archivo para que la siguiente sesión cargue siempre la revisión actual. */
$version = @hash_file('sha256', $abs);
if (!is_string($version)) {
    http_response_code(500);
    exit('No se pudo comprobar la versión del documento.');
}
$uidDocumento = (int) $GLOBALS['NUBE_UID'];
$clave = substr(hash('sha256', 'nube|' . $uidDocumento . '|' . $p . '|' . $version), 0, 32);
$firmaAutosave = hash_hmac('sha256', $uidDocumento . '|' . $p . '|' . $clave, NUBE_SALT);
$token = tokenFor($p);

$baseHost = 'http://host.docker.internal';
$appPath  = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($appPath === '.' || $appPath === '\\') { $appPath = ''; }
$rutaContenedor = $appPath !== '' ? $appPath : '/' . basename(__DIR__);
$urlDoc   = $baseHost . $rutaContenedor . '/raw.php?p=' . rawurlencode($p) . '&t=' . $token;
$callback = $baseHost . $rutaContenedor . '/office_callback.php?p=' . rawurlencode($p) . '&t=' . $token;

$hostActual = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
if ($hostActual === '') { $hostActual = 'localhost'; }
$esquema = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$apiDocServer = $esquema . '://' . $hostActual . '/onlyoffice';

$qo       = ctxOwner() > 0 ? '&o=' . ctxOwner() : '';
$volver   = $esquema . '://' . $hostActual . $appPath . '/index.php?p=' . rawurlencode(dirname($p) === '.' ? '' : dirname($p)) . $qo;
$urlAutosave = enlace('office_autosave.php?p=' . rawurlencode($p));

$config = [
    'documentType' => $tipoDoc,
    'document' => [
        'fileType'    => $ext,
        'key'         => $clave,
        'title'       => $nombre,
        'url'         => $urlDoc,
        'permissions' => ['edit' => true, 'download' => true, 'print' => true, 'review' => false],
    ],
    'editorConfig' => [
        'mode'         => 'edit',
        'lang'         => 'es',
        'callbackUrl'  => $callback,
        'user'         => [
            'id'   => (string) ($usuario['id'] ?? 'u' . (int) $GLOBALS['NUBE_UID']),
            'name' => (string) ($usuario['usuario'] ?? 'Usuario'),
        ],
        'customization' => [
            'autosave'      => true,
            'forcesave'     => true,
            'compactHeader' => true,
            'goback'        => ['url' => $volver],
        ],
    ],
    'height' => '100%',
    'width'  => '100%',
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Editar · <?= h($nombre) ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%231a73e8' d='M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z'/></svg>">
<script>(function(){var t;try{t=localStorage.getItem('nubeTema');}catch(e){t=null;}document.documentElement.setAttribute('data-tema', t==='oscuro'?'oscuro':'claro');})();</script>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; height: 100vh; display: flex; flex-direction: column; font-family: 'Segoe UI', Roboto, Arial, sans-serif; background: #f1f3f4; }
    header { display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: #fff; border-bottom: 1px solid #dadce0; flex-wrap: wrap; z-index: 5; }
    .doc { width: 22px; height: 26px; background: #1a73e8; border-radius: 3px; }
    header .nombre { font-size: .95rem; font-weight: 500; color: #202124; }
    header .estado { font-size: .78rem; color: #5f6368; }
    .der { margin-left: auto; display: flex; gap: 8px; }
    .btn { border: none; border-radius: 6px; padding: 8px 16px; font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none; background: #f1f3f4; color: #3c4043; }
    .btn:hover { background: #e8eaed; }
    .btn.azul { background: #1a73e8; color: #fff; } .btn.azul:hover { background: #1765cc; }
    #placeholder { flex: 1; overflow: hidden; background: #fff; }
    #cargando { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #5f6368; font-size: 1rem; gap: 10px; }
    .tema-btn { border: none; background: transparent; border-radius: 50%; width: 38px; height: 38px; cursor: pointer; font-size: 1.05rem; line-height: 1; }
    .tema-btn:hover { background: #f1f3f4; }
    html[data-tema="oscuro"] { color-scheme: dark; }
    html[data-tema="oscuro"] body { background: #1b1c1f; }
    html[data-tema="oscuro"] header { background: #26282c; border-color: #3a3d41; }
    html[data-tema="oscuro"] header .nombre { color: #e8eaed; }
    html[data-tema="oscuro"] header .estado { color: #9aa0a6; }
    html[data-tema="oscuro"] .btn { background: #33363b; color: #c9cdd2; }
    html[data-tema="oscuro"] .btn:hover { background: #3f4247; }
    html[data-tema="oscuro"] .btn.azul { background: #1a73e8; color: #fff; }
    html[data-tema="oscuro"] .btn.azul:hover { background: #1765cc; }
    html[data-tema="oscuro"] .tema-btn { color: #c9cdd2; }
    html[data-tema="oscuro"] .tema-btn:hover { background: #33363b; }
    html[data-tema="oscuro"] #placeholder { background: #1b1c1f; }
    @media (max-width: 600px) {
        header { padding: 8px 10px; gap: 8px; }
        header .nombre { font-size: .85rem; }
        .btn { padding: 7px 10px; font-size: .78rem; }
    }
</style>
</head>
<body>
<header>
    <div class="doc"></div>
    <div class="nombre"><?= h($nombre) ?></div>
    <span class="estado" id="estado"></span>
    <div class="der">
        <button class="tema-btn" id="btnTema" type="button" title="Cambiar tema">🌙</button>
        <a class="btn" href="<?= h(enlace('view.php?p=' . rawurlencode($p))) ?>">👁️ Ver PDF</a>
        <a class="btn" href="<?= h(enlace('index.php?p=' . rawurlencode(dirname($p) === '.' ? '' : dirname($p)))) ?>">← Mis archivos</a>
        <a class="btn azul" href="<?= h(enlace('raw.php?p=' . rawurlencode($p) . '&download=1')) ?>">⬇️ Descargar</a>
    </div>
</header>

<div id="placeholder"></div>

<script src="<?= h($apiDocServer) ?>/web-apps/apps/api/documents/api.js" onerror="document.getElementById('placeholder').innerHTML='<p style=&quot;padding:20px;color:#d93025&quot;>No se pudo cargar OnlyOffice. Revisa que el contenedor esté activo en el puerto 8080.</p>'"></script>
<script>
const config = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const AUTOSAVE = <?= json_encode($urlAutosave) ?>;
const FIRMA_AUTOSAVE = <?= json_encode($firmaAutosave) ?>;
const estadoEl = document.getElementById('estado');

/* Guardado automático: cada vez que cambia el documento pedimos un
   "forcesave" al servidor de documentos, que llama a office_callback.php
   (status 6) y escribe el archivo en disco. */
let tAutoguardado = null;
function programarAutoguardado() {
    clearTimeout(tAutoguardado);
    estadoEl.textContent = 'Cambios sin guardar…';
    tAutoguardado = setTimeout(function () {
        estadoEl.textContent = 'Guardando…';
        fetch(AUTOSAVE, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ key: config.document.key, firma: FIRMA_AUTOSAVE })
        })
            .then(function (r) { return r.json(); })
            .then(function (j) { estadoEl.textContent = (j && j.ok) ? 'Guardado ' + new Date().toLocaleTimeString() : 'Error al guardar'; })
            .catch(function () { estadoEl.textContent = 'Error de conexión'; });
    }, 800);
}

config.events = {
    onDocumentStateChange: function (e) {
        if (e && e.data) { programarAutoguardado(); }
        else { estadoEl.textContent = 'Guardado ' + new Date().toLocaleTimeString(); }
    }
};

let docEditor = null;
function iniciar() {
    if (typeof DocsAPI === 'undefined') { return; }
    docEditor = new DocsAPI.DocEditor('placeholder', config);
}
window.addEventListener('load', iniciar);
const btnTema = document.getElementById('btnTema');
function pintarBotonTema(){ const osc=document.documentElement.getAttribute('data-tema')==='oscuro'; btnTema.textContent=osc?'☀️':'🌙'; btnTema.title=osc?'Modo claro':'Modo oscuro'; }
btnTema.addEventListener('click',()=>{ const osc=document.documentElement.getAttribute('data-tema')==='oscuro'; const nuevo=osc?'claro':'oscuro'; document.documentElement.setAttribute('data-tema',nuevo); try{localStorage.setItem('nubeTema',nuevo);}catch(err){} pintarBotonTema(); });
pintarBotonTema();
</script>
</body>
</html>
