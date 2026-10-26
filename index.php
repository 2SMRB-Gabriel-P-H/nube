<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

$usuario = requiereLogin();

$p    = relClean((string) ($_GET['p'] ?? ''));
$cat  = (string) ($_GET['cat'] ?? '');
$r    = (string) ($_GET['r'] ?? '');
$modo = $r === 'recientes' ? 'recientes'
      : ($r === 'compartidos' ? 'compartidos'
      : ($cat !== '' ? 'tipo' : 'carpeta'));

$ownerId  = (int) ($GLOBALS['NUBE_UID'] ?? 0);
$miId     = (int) ($GLOBALS['NUBE_ACCESO'] ?? 0);
$esPropio = ($ownerId > 0 && $ownerId === $miId);
$ctxOwner = ctxOwner();

$raizShare = $esPropio ? '' : (raizCompartida($ownerId, $p, $miId) ?? '');

if (!$esPropio && $modo === 'carpeta' && ($p === '' || raizCompartida($ownerId, $p, $miId) === null)) {
    header('Location: index.php?r=compartidos');
    exit;
}

$permCtx     = $esPropio ? PERM_EDICION : permisoEn($p);
$puedeEscCtx = $permCtx >= PERM_ESCRITURA;
$puedeEdiCtx = $permCtx >= PERM_EDICION;

$numCompartidos = count(compartidosConmigo($miId));

$titulos = [
    'imagen' => 'Imágenes', 'texto' => 'Archivos de texto', 'word' => 'Documentos',
    'cell' => 'Hojas de cálculo', 'slide' => 'Presentaciones', 'pdf' => 'PDF',
    'audio' => 'Audio', 'video' => 'Vídeo', 'comprimido' => 'Comprimidos',
];

function listarTodo(?string $catFiltro = null): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(NUBE_ROOT, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = relOf($f->getPathname());
        if (permisoEn($rel) < PERM_LECTURA) continue;
        $ext = extDe($f->getFilename());
        if ($catFiltro !== null && categoria($ext) !== $catFiltro) continue;
        $out[] = [
            'nombre' => $f->getFilename(),
            'rel'    => $rel,
            'es_dir' => false,
            'size'   => tamanoPlano($f->getPathname()),
            'mtime'  => (int) $f->getMTime(),
            'ext'    => $ext,
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

if ($modo === 'compartidos') {
    $items = [];
    $abs = NUBE_ROOT;
} elseif ($modo === 'recientes') {
    $items = array_slice(listarTodo(), 0, 200);
    $abs = NUBE_ROOT;
} elseif ($modo === 'tipo') {
    $items = listarTodo($cat);
    $abs = NUBE_ROOT;
} else {
    $abs = pathOf($p);
    if (!is_dir($abs) || !dentroDelRoot($p)) { $p = ''; $abs = NUBE_ROOT; }
    $items = listar($p);
    $p = relClean($p);
}

$msg  = (string) ($_GET['msg'] ?? '');
$tipo = ($_GET['tipo'] ?? 'ok') === 'err' ? 'err' : 'ok';

$pRelativo = $p;
if (!$esPropio && $raizShare !== '' && str_starts_with($p, $raizShare)) {
    $pRelativo = ltrim(substr($p, strlen($raizShare)), '/');
}
$partes = $pRelativo === '' ? [] : explode('/', $pRelativo);
$migas = [];
$acum = (!$esPropio && $raizShare !== '') ? $raizShare : '';
foreach ($partes as $parte) {
    $acum = $acum === '' ? $parte : $acum . '/' . $parte;
    $migas[] = ['nombre' => $parte, 'rel' => $acum];
}

function recFolders(string $rel, int $nivel = 0, array &$out = []): array
{
    $abs = pathOf($rel);
    if (!is_dir($abs)) return $out;
    foreach (scandir($abs) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ruta = $abs . '/' . $f;
        if (is_dir($ruta)) {
            $sub = ($rel === '' ? '' : $rel . '/') . $f;
            if (puedeEscribir($sub)) {
                $out[] = ['rel' => $sub, 'nombre' => $f, 'nivel' => $nivel];
            }
            recFolders($sub, $nivel + 1, $out);
        }
    }
    return $out;
}
$todasCarpetas = recFolders('');

$totalBytes = 0;
$totalArchivos = 0;
$rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(NUBE_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($rit as $f) {
    if (!$f->isFile()) continue;
    $rel = relOf($f->getPathname());
    if (permisoEn($rel) < PERM_LECTURA) continue;
    $totalBytes += tamanoPlano($f->getPathname());
    $totalArchivos++;
}

/* Espacio libre del disco donde vive la web. */
$discoPunto = is_dir(NUBE_ROOT) ? NUBE_ROOT : __DIR__;
$discoLibreRaw = @disk_free_space($discoPunto);
$discoOk    = ($discoLibreRaw !== false);
$discoLibre = $discoOk ? (int) $discoLibreRaw : 0;
$discoPct   = ($totalBytes + $discoLibre) > 0 ? min(100, (int) round($totalBytes / ($totalBytes + $discoLibre) * 100)) : 0;

/* Cuota del usuario dueño del espacio abierto (0 = ilimitado). */
$cuotaBytes   = cuotaDe((int) ($GLOBALS['NUBE_UID'] ?? 0));
$cuotaPct     = $cuotaBytes > 0 ? min(100, (int) round($totalBytes / $cuotaBytes * 100)) : 0;
$cuotaRestant = $cuotaBytes > 0 ? max(0, $cuotaBytes - $totalBytes) : 0;

function abrirDe(array $it): string
{
    if ($it['es_dir']) return enlace('index.php?p=' . rawurlencode($it['rel']));
    if (puedeEditar($it['rel'])) {
        if (esEditableOnlyOffice($it['ext'])) return enlace('office.php?p=' . rawurlencode($it['rel']));
        if (esEditableTexto($it['ext'])) return enlace('textedit.php?p=' . rawurlencode($it['rel']));
    }
    return enlace('view.php?p=' . rawurlencode($it['rel']));
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nube · <?= $modo === 'compartidos' ? 'Compartido conmigo' : ($modo === 'recientes' ? 'Recientes' : ($modo === 'tipo' ? h($titulos[$cat] ?? $cat) : ($p === '' ? 'Mi espacio' : h(basename($p))))) ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%231a73e8' d='M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z'/></svg>">
<script>(function(){var t;try{t=localStorage.getItem('nubeTema');}catch(e){t=null;}document.documentElement.setAttribute('data-tema', t==='oscuro'?'oscuro':'claro');})();</script>
<style>
    * { box-sizing: border-box; }
    :root { --azul:#1a73e8; --azul2:#1765cc; --gris:#5f6368; --borde:#dadce0; --hover:#f1f3f4; --panel:#f8f9fa; }
    body { margin:0; font-family:'Segoe UI',Roboto,Arial,sans-serif; color:#202124; background:#fff; }
    a { color:inherit; text-decoration:none; }
    .topbar { display:flex; align-items:center; gap:14px; padding:8px 16px; border-bottom:1px solid var(--borde); position:sticky; top:0; background:#fff; z-index:20; }
    .logo { display:flex; align-items:center; gap:8px; font-size:1.25rem; font-weight:600; color:var(--gris); }
    .logo svg { width:26px; height:26px; }
    .buscador-top { flex:1; max-width:720px; background:#eaf1fb; border-radius:8px; display:flex; align-items:center; padding:0 14px; height:44px; }
    .buscador-top input { border:none; background:transparent; outline:none; width:100%; font-size:.95rem; }
    .buscador-top:focus-within { background:#fff; box-shadow:0 1px 6px rgba(32,33,36,.28); }
    .avatar { width:34px; height:34px; border-radius:50%; background:var(--azul); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; border:none; cursor:pointer; font-family:inherit; font-size:.95rem; text-transform:uppercase; flex-shrink:0; }
    .layout { display:flex; min-height:calc(100vh - 62px); }
    .sidebar { width:250px; padding:12px 8px; flex-shrink:0; }
    .nuevo { display:inline-flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--borde); border-radius:24px; padding:10px 20px; font-weight:600; font-size:.9rem; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,.12); margin-bottom:16px; }
    .nuevo:hover { background:var(--hover); }
    .nav-item { display:flex; align-items:center; gap:14px; padding:8px 16px; border-radius:0 20px 20px 0; color:#3c4043; font-size:.9rem; font-weight:500; cursor:pointer; margin-right:8px; }
    .nav-item:hover { background:var(--hover); }
    .nav-item.activo { background:#e8f0fe; color:var(--azul); }
    .almacenamiento { position:sticky; bottom:0; margin:14px 16px 14px 8px; padding:14px; background:#fff; border:1px solid var(--borde); border-radius:14px; box-shadow:0 -6px 14px -10px rgba(0,0,0,.25); }
    .alm-titulo { font-size:.72rem; color:#9aa0a6; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
    .alm-texto { font-size:.86rem; color:#3c4043; margin-bottom:8px; }
    .alm-texto b { color:var(--azul); }
    .alm-texto .inf { font-size:1.15rem; font-weight:700; color:var(--azul); vertical-align:-1px; }
    .alm-pista { height:6px; background:#e8eaed; border-radius:6px; overflow:hidden; }
    .alm-fill { height:100%; width:100%; background:linear-gradient(90deg,#34a853,#8ab4f8); border-radius:6px; }
    .alm-sub { font-size:.72rem; color:#9aa0a6; margin-top:8px; }
    .contenido { flex:1; padding:0 24px 40px; min-width:0; }
    .barra { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid var(--borde); position:sticky; top:61px; background:#fff; z-index:10; }
    .migas { font-size:1.35rem; font-weight:400; color:#3c4043; display:flex; align-items:center; gap:2px; flex-wrap:wrap; }
    .migas a { padding:2px 8px; border-radius:6px; }
    .migas a:hover { background:var(--hover); }
    .migas .sep { color:#9aa0a6; }
    .der { margin-left:auto; display:flex; align-items:center; gap:8px; }
    .icono-btn { width:40px; height:40px; border:none; background:transparent; border-radius:50%; cursor:pointer; font-size:1.05rem; color:var(--gris); display:flex; align-items:center; justify-content:center; }
    .icono-btn:hover { background:var(--hover); }
    .icono-btn.activo { background:#e8f0fe; color:var(--azul); }
    select.orden { border:1px solid var(--borde); border-radius:6px; padding:7px 10px; font-size:.85rem; color:#3c4043; background:#fff; }
    .grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); margin-top:14px; }
    .tarjeta { border:1px solid transparent; border-radius:12px; background:#fff; overflow:hidden; transition:.12s; position:relative; cursor:pointer; }
    .tarjeta:hover { box-shadow:0 1px 3px rgba(0,0,0,.2); border-color:var(--borde); }
    .tarjeta.dragover { border-color:var(--azul); background:#e8f0fe; }
    .tarjeta.seleccion, .lista tr.seleccion td { background:#e8f0fe !important; }
    .tarjeta .cabeza { height:120px; display:flex; align-items:center; justify-content:center; background:var(--panel); overflow:hidden; pointer-events:none; }
    .tarjeta .cabeza img { width:100%; height:100%; object-fit:cover; }
    .tarjeta .cabeza .emoji { font-size:3rem; }
    .tarjeta .pie { display:flex; align-items:center; gap:8px; padding:8px 10px; }
    .tarjeta .pie .ic { font-size:1.2rem; pointer-events:none; }
    .tarjeta .pie .txt { min-width:0; flex:1; pointer-events:none; }
    .tarjeta .pie .nom { font-size:.82rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .tarjeta .pie .meta { font-size:.72rem; color:var(--gris); }
    .kebab-box { position:absolute; top:6px; right:6px; display:flex; gap:4px; opacity:0; }
    .tarjeta:hover .kebab-box, .tarjeta.sel .kebab-box, .selActiva .kebab-box { opacity:1; }
    .chk { position:absolute; top:8px; left:8px; width:22px; height:22px; opacity:0; }
    .tarjeta:hover .chk, .lista tr:hover .chk, .selActiva .chk, .chk:checked { opacity:1; }
    .chk input { width:20px; height:20px; accent-color:var(--azul); cursor:pointer; }
    .kebab { width:34px; height:34px; border:none; background:transparent; border-radius:50%; cursor:pointer; font-size:1rem; color:var(--gris); }
    .kebab:hover { background:rgba(0,0,0,.08); }
    .lista { width:100%; border-collapse:collapse; margin-top:6px; }
    .lista thead th { text-align:left; font-size:.78rem; color:var(--gris); font-weight:500; padding:8px 12px; border-bottom:1px solid var(--borde); }
    .lista td { padding:8px 12px; border-bottom:1px solid #f1f3f4; font-size:.85rem; vertical-align:middle; }
    .lista tr:hover td { background:var(--hover); }
    .lista tr { cursor:pointer; }
    .col-nom { display:flex; align-items:center; gap:12px; }
    .col-nom .emoji { font-size:1.3rem; }
    .col-nom img { width:26px; height:26px; object-fit:cover; border-radius:4px; }
    .lista .kebab-cell { text-align:right; }
    .vacio { text-align:center; color:var(--gris); padding:70px 0; }
    .vacio .em { font-size:3.4rem; display:block; margin-bottom:10px; }
    .menu { position:fixed; background:#fff; border:1px solid var(--borde); border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,.2); padding:6px 0; min-width:210px; z-index:95; display:none; }
    .menu.abierto { display:block; }
    .menu button { display:flex; align-items:center; gap:12px; width:100%; border:none; background:none; padding:9px 16px; font-size:.85rem; text-align:left; cursor:pointer; color:#3c4043; }
    .menu button:hover { background:var(--hover); }
    .menu button.peligro { color:#d93025; }
    .menu hr { border:none; border-top:1px solid var(--borde); margin:6px 0; }
    .modal-fondo { position:fixed; inset:0; background:rgba(0,0,0,.4); display:none; align-items:center; justify-content:center; z-index:80; }
    .modal-fondo.abierto { display:flex; }
    .modal { background:#fff; border-radius:12px; padding:22px; width:430px; max-width:92vw; box-shadow:0 8px 30px rgba(0,0,0,.3); }
    .modal h3 { margin:0 0 14px; font-size:1.05rem; font-weight:500; }
    .modal input[type=text], .modal select { width:100%; padding:10px 12px; border:1px solid var(--borde); border-radius:6px; font-size:.9rem; margin-bottom:16px; background:#fff; }
    .modal .fila { display:flex; justify-content:flex-end; gap:8px; }
    .btn { border:none; border-radius:6px; padding:9px 18px; font-size:.88rem; font-weight:600; cursor:pointer; }
    .btn.primario { background:var(--azul); color:#fff; } .btn.primario:hover { background:var(--azul2); }
    .btn.texto { background:transparent; color:var(--azul); } .btn.texto:hover { background:#e8f0fe; }
    .toast { position:fixed; left:50%; bottom:28px; transform:translateX(-50%); background:#323232; color:#fff; padding:12px 20px; border-radius:6px; font-size:.88rem; z-index:100; box-shadow:0 4px 14px rgba(0,0,0,.3); opacity:0; transition:opacity .3s, bottom .3s; pointer-events:none; }
    .toast.ver { opacity:1; bottom:40px; } .toast.err { background:#b3261e; }
    .drop-overlay { position:fixed; inset:0; background:rgba(26,115,232,.12); border:3px dashed var(--azul); display:none; align-items:center; justify-content:center; z-index:90; font-size:1.3rem; color:var(--azul); font-weight:600; }
    .drop-overlay.ver { display:flex; }
    input[type=file].oculto { display:none; }
    .sel-barra { display:none; align-items:center; gap:12px; padding:10px 14px; background:#e8f0fe; border-radius:10px; margin-top:12px; position:sticky; top:112px; z-index:9; }
    .sel-barra.ver { display:flex; }
    .sel-barra b { color:#174ea6; }
    .chip { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid var(--borde); border-radius:20px; padding:7px 14px; font-size:.84rem; font-weight:600; cursor:pointer; }
    .chip:hover { background:var(--hover); }
    .chip.rojo { color:#d93025; }
    :root[data-tema="oscuro"] { color-scheme:dark; }
    [data-tema="oscuro"] .topbar, [data-tema="oscuro"] .barra { background:#1b1c1f; border-color:#3a3d41; }
    html[data-tema="oscuro"] body { background:#1b1c1f; color:#e8eaed; }
    [data-tema="oscuro"] .logo { color:#e8eaed; }
    [data-tema="oscuro"] .nuevo { background:#26282c; color:#c9cdd2; border-color:#3a3d41; }
    [data-tema="oscuro"] .nav-item { color:#c9cdd2; }
    [data-tema="oscuro"] .nav-item:hover { background:#2f3135; }
    [data-tema="oscuro"] .nav-item.activo { background:#22364b; color:#8ab4f8; }
    [data-tema="oscuro"] .buscador-top { background:#2a2d31; }
    [data-tema="oscuro"] .buscador-top input { color:#e8eaed; }
    [data-tema="oscuro"] .buscador-top:focus-within { background:#26282c; }
    [data-tema="oscuro"] .almacenamiento { background:#26282c; border-color:#3a3d41; }
    [data-tema="oscuro"] .alm-titulo, [data-tema="oscuro"] .alm-sub { color:#9aa0a6; }
    [data-tema="oscuro"] .alm-texto { color:#c9cdd2; }
    [data-tema="oscuro"] .alm-pista { background:#3a3d41; }
    [data-tema="oscuro"] .migas { color:#c9cdd2; }
    [data-tema="oscuro"] .migas .sep { color:#9aa0a6; }
    [data-tema="oscuro"] .migas a:hover, [data-tema="oscuro"] .icono-btn:hover { background:#2f3135; }
    [data-tema="oscuro"] .icono-btn { color:#9aa0a6; }
    [data-tema="oscuro"] .icono-btn.activo { background:#22364b; color:#8ab4f8; }
    [data-tema="oscuro"] select.orden { background:#26282c; color:#c9cdd2; border-color:#3a3d41; }
    [data-tema="oscuro"] .tarjeta { background:#26282c; }
    [data-tema="oscuro"] .tarjeta:hover { border-color:#3a3d41; }
    [data-tema="oscuro"] .tarjeta.dragover, [data-tema="oscuro"] .tarjeta.seleccion, [data-tema="oscuro"] .lista tr.seleccion td { background:#22364b !important; }
    [data-tema="oscuro"] .tarjeta .cabeza { background:#202124; }
    [data-tema="oscuro"] .tarjeta .pie .meta { color:#9aa0a6; }
    [data-tema="oscuro"] .kebab { color:#9aa0a6; }
    [data-tema="oscuro"] .kebab:hover { background:rgba(255,255,255,.12); }
    [data-tema="oscuro"] .lista thead th { color:#9aa0a6; border-color:#3a3d41; }
    [data-tema="oscuro"] .lista td { border-color:#2f3135; }
    [data-tema="oscuro"] .lista tr:hover td { background:#2f3135; }
    [data-tema="oscuro"] .vacio { color:#9aa0a6; }
    [data-tema="oscuro"] .menu { background:#26282c; border-color:#3a3d41; }
    [data-tema="oscuro"] .menu button { color:#c9cdd2; }
    [data-tema="oscuro"] .menu button:hover { background:#2f3135; }
    [data-tema="oscuro"] .menu hr { border-color:#3a3d41; }
    [data-tema="oscuro"] .modal { background:#26282c; color:#e8eaed; }
    [data-tema="oscuro"] .modal input[type=text], [data-tema="oscuro"] .modal select { background:#1f2124; color:#e8eaed; border-color:#3a3d41; }
    [data-tema="oscuro"] .btn.texto:hover { background:#22364b; }
    [data-tema="oscuro"] .sel-barra { background:#22364b; color:#e8eaed; }
    [data-tema="oscuro"] .sel-barra b { color:#8ab4f8; }
    [data-tema="oscuro"] .chip { background:#26282c; border-color:#3a3d41; color:#c9cdd2; }
    [data-tema="oscuro"] .chip:hover { background:#2f3135; }
    .solo-movil { display:none; }
    .sidebar-fondo { display:none; }
    @media (max-width: 820px) {
        .topbar { gap:8px; padding:8px 10px; }
        .logo span { display:none; }
        .logo svg { width:24px; height:24px; }
        .buscador-top { height:40px; padding:0 10px; }
        .solo-movil { display:flex; }
        .layout { display:block; min-height:calc(100vh - 56px); }
        .sidebar { position:fixed; top:0; left:0; bottom:0; width:280px; max-width:82vw; z-index:70; padding:14px 8px; background:#fff; transform:translateX(-100%); transition:transform .25s ease; overflow-y:auto; box-shadow:2px 0 18px rgba(0,0,0,.25); }
        [data-tema="oscuro"] .sidebar { background:#1b1c1f; }
        .sidebar.abierto { transform:none; }
        .sidebar-fondo { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:69; }
        .sidebar-fondo.abierto { display:block; }
        .contenido { padding:0 12px 40px; }
        .barra { position:static; top:auto; }
        .migas { font-size:1.1rem; }
        .grid { grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px; }
        .tarjeta .cabeza { height:100px; }
        .tarjeta .cabeza .emoji { font-size:2.4rem; }
        .tarjeta .kebab-box, .tarjeta .chk, .lista tr .chk { opacity:1; }
        .lista { font-size:.82rem; }
        .lista thead th:nth-child(3), .lista td:nth-child(3),
        .lista thead th:nth-child(4), .lista td:nth-child(4) { display:none; }
        .lista td, .lista thead th { padding:8px 8px; }
        .sel-barra { position:static; top:auto; flex-wrap:wrap; }
        .vacio { padding:50px 0; }
    }
</style>
</head>
<body>
<div class="topbar">
    <button class="icono-btn solo-movil" id="btnMenu" type="button" title="Menú" aria-label="Menú">☰</button>
    <div class="logo">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.2 19.5h9.6a4.3 4.3 0 0 0 .5-8.57A5.8 5.8 0 0 0 6.8 9.3 4.4 4.4 0 0 0 7.2 19.5z" fill="#1a73e8"/><path d="M12 16.4V9.9m-2.6 2.6L12 9.9l2.6 2.6" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Nube</span>
    </div>
    <form class="buscador-top" onsubmit="return false;"><span style="color:#5f6368;margin-right:8px;">🔍</span><input type="text" id="buscar" placeholder="Buscar…"></form>
    <button class="icono-btn" id="btnTema" type="button" title="Cambiar tema" aria-label="Cambiar tema" style="margin-left:auto;">🌙</button>
    <button class="avatar" id="avatar" type="button" title="<?= h($usuario['usuario']) ?>"><?= h(mb_substr((string) $usuario['usuario'], 0, 1)) ?></button>
    <div class="menu" id="menuUsuario" style="min-width:240px;">
        <div style="padding:10px 16px 12px;border-bottom:1px solid var(--borde);">
            <div style="font-weight:600;"><?= h($usuario['usuario']) ?></div>
            <div style="font-size:.78rem;color:var(--gris);"><?= h($usuario['email']) ?></div>
        </div>
        <?php if (esAdmin($usuario)): ?><button type="button" onclick="location.href='admin.php'">👤 Usuarios</button><?php endif; ?>
        <button type="button" onclick="location.href='logout.php'">🚪 Cerrar sesión</button>
    </div>
</div>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <?php if ($puedeEscCtx): ?><button class="nuevo" id="btnNuevo">➕ Nuevo</button><?php endif; ?>
        <div class="nav-item <?= ($modo==='carpeta' && $esPropio) ? 'activo':'' ?>" onclick="location.href='<?= h($esPropio ? 'index.php' : enlace('index.php?p=' . rawurlencode($raizShare))) ?>'">🗂️ <?= $esPropio ? 'Mi espacio' : 'Carpeta compartida' ?></div>
        <div class="nav-item <?= $modo==='compartidos' ? 'activo':'' ?>" onclick="location.href='index.php?r=compartidos'">👥 Compartido conmigo<?= $numCompartidos ? ' (' . $numCompartidos . ')' : '' ?></div>
        <div class="nav-item <?= $modo==='recientes' ? 'activo':'' ?>" onclick="location.href='<?= h(enlace('index.php?r=recientes')) ?>'">🕒 Recientes</div>
        <div style="margin:10px 16px 4px; font-size:.72rem; color:#9aa0a6; text-transform:uppercase; letter-spacing:.5px;">Tipos</div>
        <?php foreach (['imagen'=>'🖼️ Imágenes','texto'=>'📄 Texto','word'=>'📝 Documentos','cell'=>'📊 Hojas','slide'=>'📽️ Presentaciones','pdf'=>'📕 PDF','audio'=>'🎵 Audio','video'=>'🎬 Vídeo'] as $k=>$v): ?>
            <div class="nav-item <?= ($modo==='tipo' && $cat===$k) ? 'activo':'' ?>" onclick="location.href='<?= h(enlace('index.php?cat=' . $k)) ?>'"><?= $v ?></div>
        <?php endforeach; ?>
        <div class="almacenamiento">
            <div class="alm-titulo">Almacenamiento</div>
            <?php if ($cuotaBytes > 0): ?>
                <div class="alm-texto"><b><?= sizeHumano($totalBytes) ?></b> de <b><?= sizeHumano($cuotaBytes) ?></b></div>
                <div class="alm-pista"><div class="alm-fill" style="width:<?= $cuotaPct ?>%"></div></div>
                <div class="alm-sub"><?= $totalArchivos ?> archivo(s) · <?= sizeHumano($cuotaRestant) ?> disponibles</div>
            <?php elseif ($discoOk): ?>
                <div class="alm-texto"><b><?= sizeHumano($totalBytes) ?></b> usados · <b><?= sizeHumano($discoLibre) ?></b> libres</div>
                <div class="alm-pista"><div class="alm-fill" style="width:<?= $discoPct ?>%"></div></div>
                <div class="alm-sub"><?= $totalArchivos ?> archivo(s) · <?= sizeHumano($discoLibre) ?> libres hasta llenar el disco</div>
            <?php else: ?>
                <div class="alm-texto"><b><?= sizeHumano($totalBytes) ?></b> usados</div>
                <div class="alm-sub"><?= $totalArchivos ?> archivo(s) · espacio libre desconocido</div>
            <?php endif; ?>
        </div>
    </aside>
    <div class="sidebar-fondo" id="sidebarFondo"></div>

    <main class="contenido">
        <div class="barra">
            <div class="migas">
                <?php if ($modo === 'compartidos'): ?>
                    👥 Compartido conmigo
                <?php elseif ($modo === 'carpeta'): ?>
                    <?php if ($esPropio): ?>
                        <a href="index.php">Mi espacio</a>
                    <?php else: ?>
                        <a href="<?= h(enlace('index.php?p=' . rawurlencode($raizShare))) ?>"><?= h(basename($raizShare) ?: 'Compartido') ?></a>
                    <?php endif; ?>
                    <?php foreach ($migas as $m): ?><span class="sep">/</span><a href="<?= h(enlace('index.php?p=' . rawurlencode($m['rel']))) ?>"><?= h($m['nombre']) ?></a><?php endforeach; ?>
                <?php elseif ($modo === 'recientes'): ?>
                    🕒 Recientes
                <?php else: ?>
                    <?= h($titulos[$cat] ?? 'Filtro') ?>
                <?php endif; ?>
            </div>
            <div class="der">
                <button class="icono-btn activo" id="vistaGrid" title="Cuadrícula">▦</button>
                <button class="icono-btn" id="vistaLista" title="Lista">☰</button>
                <select class="orden" id="ordenar"><option value="nombre">Nombre</option><option value="fecha">Última modificación</option><option value="tamanio">Tamaño</option></select>
            </div>
        </div>

        <div class="sel-barra" id="selBarra">
            <b id="selCount">0 seleccionados</b>
            <span class="chip" id="selDescargar">⬇️ Descargar</span>
            <span class="chip" id="selMover">📁 Mover</span>
            <span class="chip rojo" id="selEliminar">🗑️ Eliminar</span>
            <span class="chip" id="selQuitar" style="margin-left:auto;">✖ Descartar</span>
        </div>

        <?php if ($modo === 'compartidos'): ?>
            <?php $comps = compartidosConmigo($miId); ?>
            <?php if (!$comps): ?>
                <div class="vacio"><span class="em">👥</span>Todavía no compartieron nada contigo.</div>
            <?php else: ?>
                <table class="lista">
                    <thead><tr><th style="width:55%">Nombre</th><th>Compartido por</th><th>Permiso</th><th>Desde</th></tr></thead>
                    <tbody>
                    <?php foreach ($comps as $c):
                        $esDirC  = is_dir(pathOf($c['ruta']));
                        $catC    = $esDirC ? 'carpeta' : categoria(extDe($c['ruta']));
                        $urlC    = $esDirC
                                 ? 'index.php?p=' . rawurlencode($c['ruta']) . '&o=' . (int) $c['propietario_id']
                                 : 'view.php?p=' . rawurlencode($c['ruta']) . '&o=' . (int) $c['propietario_id'];
                    ?>
                        <tr style="cursor:pointer;" onclick="location.href='<?= h($urlC) ?>'">
                            <td><div class="col-nom"><span class="emoji"><?= icono($catC) ?></span><span title="<?= h($c['ruta']) ?>"><?= h(basename($c['ruta'])) ?></span></div></td>
                            <td style="color:#5f6368;"><?= h($c['propietario']) ?></td>
                            <td><?= h(permisoEtiqueta((int) $c['permiso'])) ?></td>
                            <td><?= h(date('d/m/Y H:i', strtotime((string) $c['creado_en']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif (!$items): ?>
            <div class="vacio"><span class="em">🗂️</span><?= $modo==='carpeta' ? 'Esta carpeta está vacía. Usa «Nuevo» o arrastra archivos aquí.' : 'No hay archivos aquí.' ?></div>
        <?php else: ?>
            <div id="zonaGrid" class="grid">
                <?php foreach ($items as $it):
                    $cat2 = $it['es_dir'] ? 'carpeta' : categoria($it['ext']);
                    $src = ($cat2 === 'imagen') ? urlDe($it['rel']) : '';
                    $abrir = abrirDe($it);
                    $padre = $it['es_dir'] ? '' : (dirname($it['rel']) === '.' ? 'Mi espacio' : dirname($it['rel']));
                    $meta = ($modo !== 'carpeta' && !$it['es_dir']) ? h($padre) . ' · ' : '';
                ?>
                <div class="tarjeta" data-rel="<?= h($it['rel']) ?>" data-nombre="<?= h(mb_strtolower($it['nombre'])) ?>" data-cat="<?= $cat2 ?>" data-ext="<?= h($it['ext']) ?>" data-dir="<?= $it['es_dir']?'1':'0' ?>" data-tam="<?= $it['size'] ?>" data-fecha="<?= $it['mtime'] ?>" data-abrir="<?= h($abrir) ?>" draggable="<?= $it['es_dir']?'false':'true' ?>">
                    <div class="chk"><input type="checkbox" class="chk-input"></div>
                    <div class="kebab-box"><button class="kebab" title="Opciones">⋮</button></div>
                    <div class="cabeza"><?php if ($src): ?><img src="<?= h($src) ?>" alt="" loading="lazy"><?php else: ?><span class="emoji"><?= icono($cat2) ?></span><?php endif; ?></div>
                    <div class="pie">
                        <span class="ic"><?= icono($cat2) ?></span>
                        <div class="txt"><div class="nom" title="<?= h($it['nombre']) ?>"><?= h($it['nombre']) ?></div>
                        <div class="meta"><?= $it['es_dir'] ? 'Carpeta' : $meta . h(sizeHumano($it['size'])) . ' · ' . h(segundosATexto(time() - $it['mtime'])) ?></div></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <table class="lista" id="zonaLista" style="display:none;">
                <thead><tr><th style="width:20px"></th><th style="width:55%">Nombre</th><th>Ubicación</th><th>Última modificación</th><th>Tamaño</th><th style="width:48px"></th></tr></thead>
                <tbody>
                <?php foreach ($items as $it):
                    $cat2 = $it['es_dir'] ? 'carpeta' : categoria($it['ext']);
                    $src = ($cat2 === 'imagen') ? urlDe($it['rel']) : '';
                    $abrir = abrirDe($it);
                    $padre = $it['es_dir'] ? '—' : (dirname($it['rel']) === '.' ? 'Mi espacio' : dirname($it['rel']));
                ?>
                    <tr data-rel="<?= h($it['rel']) ?>" data-nombre="<?= h(mb_strtolower($it['nombre'])) ?>" data-cat="<?= $cat2 ?>" data-ext="<?= h($it['ext']) ?>" data-dir="<?= $it['es_dir']?'1':'0' ?>" data-tam="<?= $it['size'] ?>" data-fecha="<?= $it['mtime'] ?>" data-abrir="<?= h($abrir) ?>" draggable="<?= $it['es_dir']?'false':'true' ?>">
                        <td><div class="chk"><input type="checkbox" class="chk-input"></div></td>
                        <td><div class="col-nom"><?php if ($src): ?><img src="<?= h($src) ?>" alt=""><?php else: ?><span class="emoji"><?= icono($cat2) ?></span><?php endif; ?><span title="<?= h($it['nombre']) ?>"><?= h($it['nombre']) ?></span></div></td>
                        <td style="color:#5f6368;"><?= h($padre) ?></td>
                        <td><?= h(date('d/m/Y H:i', $it['mtime'])) ?></td>
                        <td><?= $it['es_dir'] ? '—' : h(sizeHumano($it['size'])) ?></td>
                        <td class="kebab-cell"><div class="kebab-box" style="position:static;opacity:1;"><button class="kebab">⋮</button></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="vacio" id="sinResultados" style="display:none;"><span class="em">🔍</span>Sin resultados.</div>
        <?php endif; ?>
    </main>
</div>

<div class="menu" id="menuKebab"></div>
<div class="modal-fondo" id="modalFondo"><div class="modal" id="modalCaja"></div></div>
<input type="file" id="inputSubir" class="oculto" multiple>
<input type="file" id="inputSubirCarpeta" class="oculto" multiple webkitdirectory>
<div class="drop-overlay" id="dropOverlay">Suelta para subir a <?= $modo==='carpeta' ? (h(basename($p)) ?: 'Mi espacio') : 'Mi espacio' ?></div>
<div class="toast" id="toast"></div>

<script>
const MODO = <?= json_encode($modo) ?>;
const CARPETA_ACTUAL = MODO === 'carpeta' ? <?= json_encode($p) ?> : '';
const CTX_O = <?= (int) $ctxOwner ?>;
const ES_PROPIO = <?= $esPropio ? 'true' : 'false' ?>;
const PUEDE_ESCRIBIR = <?= $puedeEscCtx ? 'true' : 'false' ?>;
const PUEDE_EDITAR = <?= $puedeEdiCtx ? 'true' : 'false' ?>;
const CSRF = <?= json_encode(csrfToken()) ?>;
const RAIZ_REL = <?= json_encode($raizShare) ?>;
function conOwner(u){ return CTX_O ? u + (u.includes('?') ? '&' : '?') + 'o=' + CTX_O : u; }
const RUTA_INDEX = conOwner('index.php' + (MODO === 'carpeta' && CARPETA_ACTUAL ? '?p=' + encodeURIComponent(CARPETA_ACTUAL) : (MODO === 'recientes' ? '?r=recientes' : '')));
const CARPETAS = <?= json_encode($todasCarpetas, JSON_UNESCAPED_UNICODE) ?>;
const OFFEXT = <?= json_encode(array_merge(extOfficeWord(), extOfficeCell(), extOfficeSlide())) ?>;
const TXTEXT = <?= json_encode(extTexto()) ?>;
const CUOTA_RESTANTE = <?= $cuotaBytes > 0 ? $cuotaRestant : -1 ?>; // -1 = sin límite

function toast(t, err) { const e=document.getElementById('toast'); e.textContent=t; e.className='toast ver'+(err?' err':''); clearTimeout(e._t); e._t=setTimeout(()=>e.className='toast',3000); }
<?php if ($msg): ?>toast(<?= json_encode($msg) ?>, <?= $tipo==='err'?'true':'false' ?>);<?php endif; ?>

function enviar(datos) {
    if (CTX_O) datos.o = CTX_O;
    datos.csrf = CSRF;
    const f = document.createElement('form'); f.method='POST'; f.action='actions.php';
    for (const k in datos) {
        const v = datos[k];
        if (Array.isArray(v)) { v.forEach(x => { const i=document.createElement('input'); i.type='hidden'; i.name=k+'[]'; i.value=x; f.appendChild(i); }); }
        else { const i=document.createElement('input'); i.type='hidden'; i.name=k; i.value=v; f.appendChild(i); }
    }
    document.body.appendChild(f); f.submit();
}
function escapeHtml(s){return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}

/* ---- menu ---- */
const menu = document.getElementById('menuKebab');
function abrirMenu(x, y, opciones) {
    menu.innerHTML='';
    opciones.forEach(o => {
        if (o==='-') { menu.appendChild(document.createElement('hr')); return; }
        const b=document.createElement('button'); b.textContent=o.txt; if(o.peligro) b.className='peligro';
        b.addEventListener('click',()=>{cerrarMenu();o.fn();}); menu.appendChild(b);
    });
    menu.style.left=Math.min(x, innerWidth-230)+'px'; menu.style.top=Math.min(y, innerHeight-260)+'px'; menu.classList.add('abierto');
}
function cerrarMenu(){menu.classList.remove('abierto');}
document.addEventListener('click',e=>{ if(!menu.contains(e.target)) cerrarMenu(); });
document.addEventListener('scroll',cerrarMenu);

/* ---- seleccion ---- */
const sel = new Set();
function itemsVisibles(){ return document.querySelectorAll('#zonaGrid .tarjeta, #zonaLista tbody tr'); }
function actualizarSel(){
    document.body.classList.toggle('selActiva', sel.size>0);
    const barra=document.getElementById('selBarra');
    if (barra) barra.classList.toggle('ver', sel.size>0);
    const c=document.getElementById('selCount'); if(c) c.textContent=sel.size+' seleccionado'+(sel.size===1?'':'s');
    itemsVisibles().forEach(el=>{
        const on=sel.has(el.dataset.rel);
        el.classList.toggle('seleccion',on);
        const chk=el.querySelector('.chk-input'); if(chk) chk.checked=on;
    });
}
let ultimoIdx=-1;
function toggleSel(el,shiftear){
    const todos=[...itemsVisibles()];
    const idx=todos.indexOf(el);
    if (shiftear && ultimoIdx>=0){
        const [a,b]=[Math.min(idx,ultimoIdx),Math.max(idx,ultimoIdx)];
        for(let i=a;i<=b;i++) sel.add(todos[i].dataset.rel);
    } else {
        const r=el.dataset.rel;
        sel.has(r)?sel.delete(r):sel.add(r);
        ultimoIdx=idx;
    }
    actualizarSel();
}

function opcionesItem(rel,nombre,esDir,ext,abrir){
    const op=[];
    if(esDir) op.push({txt:'📂 Abrir',fn:()=>location.href=abrir});
    else {
        if(PUEDE_EDITAR && (OFFEXT.includes(ext)||TXTEXT.includes(ext))){
            op.push({txt:'✏️ Editar',fn:()=>location.href=conOwner((OFFEXT.includes(ext)?'office.php?p=':'textedit.php?p=')+encodeURIComponent(rel))});
        }
        op.push({txt:'👁️ Ver',fn:()=>location.href=conOwner('view.php?p='+encodeURIComponent(rel))});
        op.push({txt:'⬇️ Descargar',fn:()=>location.href=conOwner('raw.php?p='+encodeURIComponent(rel)+'&download=1')});
    }
    if(ES_PROPIO){
        op.push('-');
        op.push({txt:'🔗 Compartir',fn:()=>compartir(rel,nombre)});
    }
    if(PUEDE_ESCRIBIR){
        op.push('-');
        op.push({txt:'✏️ Cambiar nombre',fn:()=>renombrar(rel,nombre)});
        op.push({txt:'📁 Mover a…',fn:()=>mover([rel],nombre,'move')});
        op.push({txt:'📑 Copiar a…',fn:()=>mover([rel],nombre,'copy')});
        op.push('-');
        op.push({txt:'🗑️ Eliminar',peligro:true,fn:()=>eliminar([rel],nombre)});
    }
    return op;
}

function opcionesMulti(){
    const arr=[...sel];
    const op=[{txt:'⬇️ Descargar '+arr.length,fn:()=>arr.forEach(r=>window.open(conOwner('raw.php?p='+encodeURIComponent(r)+'&download=1'),'_blank'))}];
    if(PUEDE_ESCRIBIR){
        op.push({txt:'📁 Mover '+arr.length+' a…',fn:()=>mover(arr,arr.length+' elementos','move')});
        op.push({txt:'🗑️ Eliminar '+arr.length,peligro:true,fn:()=>eliminar(arr,arr.length+' elementos')});
    }
    return op;
}

/* ---- eventos en items ---- */
itemsVisibles().forEach(el=>{
    const esDir=el.dataset.dir==='1';
    el.addEventListener('click',e=>{
        if(e.target.closest('.chk')||e.target.closest('.kebab')) return;
        if(e.shiftKey){ toggleSel(el,true); return; }
        if(e.ctrlKey||e.metaKey||sel.size>0){ toggleSel(el,false); return; }
        if(esDir && el.dataset.dragover) return;
        location.href=el.dataset.abrir;
    });
    el.addEventListener('dblclick',e=>{ if(!e.target.closest('.chk')&&!e.target.closest('.kebab')) location.href=el.dataset.abrir; });
    el.querySelector('.chk-input').addEventListener('click',e=>{ e.stopPropagation(); toggleSel(el, e.shiftKey); });
    el.querySelector('.kebab').addEventListener('click',e=>{
        e.stopPropagation();
        const r=el.getBoundingClientRect();
        if(sel.has(el.dataset.rel)&&sel.size>1) abrirMenu(r.right-210, r.bottom+4, opcionesMulti());
        else abrirMenu(r.right-210, r.bottom+4, opcionesItem(el.dataset.rel, el.querySelector('.nom')?el.querySelector('.nom').textContent:el.dataset.rel, esDir, el.dataset.ext, el.dataset.abrir));
    });
    el.addEventListener('contextmenu',e=>{
        e.preventDefault(); e.stopPropagation();
        if(!sel.has(el.dataset.rel)){ sel.clear(); sel.add(el.dataset.rel); actualizarSel(); }
        const ops = sel.size>1 ? opcionesMulti() : opcionesItem(el.dataset.rel, el.querySelector('.nom')?el.querySelector('.nom').textContent:el.dataset.rel, esDir, el.dataset.ext, el.dataset.abrir);
        abrirMenu(e.clientX, e.clientY, ops);
    });
    // drag interno
    if(!esDir){
        el.addEventListener('dragstart',e=>{ e.dataTransfer.setData('text/nube-rel',el.dataset.rel.length?el.dataset.rel:' '); e.dataTransfer.setData('text/plain','nube:'+el.dataset.rel); e.dataTransfer.effectAllowed='move'; e.dataTransfer.setData('application/x-nube', JSON.stringify([...sel].length?[...sel]:[el.dataset.rel])); });
    }
    if(esDir){
        el.addEventListener('dragover',e=>{ e.preventDefault(); el.classList.add('dragover'); });
        el.addEventListener('dragleave',()=>el.classList.remove('dragover'));
        el.addEventListener('drop',e=>{
            e.preventDefault(); el.classList.remove('dragover');
            const datos=e.dataTransfer.getData('application/x-nube');
            if(datos){ try{ const arr=JSON.parse(datos); mover(arr, el.dataset.nombre, 'move', el.dataset.rel);}catch(err){} return; }
            if(e.dataTransfer.files.length) subir(e.dataTransfer.files, el.dataset.rel);
        });
    }
});
document.addEventListener('contextmenu',e=>{ if(e.target.closest('.contenido')===null && !e.target.closest('.tarjeta') && !e.target.closest('.lista')) cerrarMenu(); });

/* ---- barra de seleccion ---- */
document.getElementById('selQuitar').onclick=()=>{sel.clear();actualizarSel();};
if(!PUEDE_ESCRIBIR){
    const em=document.getElementById('selEliminar'); if(em) em.style.display='none';
    const mm=document.getElementById('selMover'); if(mm) mm.style.display='none';
}
document.getElementById('selEliminar').onclick=()=>eliminar([...sel],sel.size+' elementos');
document.getElementById('selMover').onclick=()=>mover([...sel],sel.size+' elementos','move');
document.getElementById('selDescargar').onclick=()=>[...sel].forEach(r=>window.open(conOwner('raw.php?p='+encodeURIComponent(r)+'&download=1'),'_blank'));

/* ---- acciones ---- */
function renombrar(rel,nombre){ const n=prompt('Nuevo nombre:',nombre); if(n&&n!==nombre) enviar({action:'rename',p:rel,back:CARPETA_ACTUAL,name:n}); }
function eliminar(arr,nombre){ if(confirm('¿Eliminar '+ (arr.length>1?arr.length+' elementos':'"'+nombre+'"') +' de forma permanente?')){ sel.clear(); enviar({action:'delete',items:arr,back:CARPETA_ACTUAL}); } }
function mover(arr,nombre,accion,destFijo){
    if(arr.length===1 && destFijo===undefined && CARPETAS.length===0){ toast('No hay otras carpetas de destino',true); return; }
    let opts='<option value="'+escapeHtml(RAIZ_REL||'')+'">'+escapeHtml(RAIZ_REL?(RAIZ_REL.split('/').pop()||RAIZ_REL):'Mi espacio')+'</option>';
    CARPETAS.forEach(c=>{ opts+='<option value="'+escapeHtml(c.rel)+'">'+'&nbsp;'.repeat(c.nivel*2)+'📁 '+escapeHtml(c.nombre)+'</option>'; });
    const caja=document.getElementById('modalCaja');
    caja.innerHTML='<h3>'+(accion==='move'?'Mover ':'Copiar ')+escapeHtml(nombre)+'</h3><select id="selDestino">'+opts+'</select><div class="fila"><button class="btn texto" id="mCancelar">Cancelar</button><button class="btn primario" id="mOk">'+(accion==='move'?'Mover':'Copiar')+'</button></div>';
    document.getElementById('modalFondo').classList.add('abierto');
    if(destFijo!==undefined) document.getElementById('selDestino').value=destFijo;
    document.getElementById('mCancelar').onclick=cerrarModal;
    document.getElementById('mOk').onclick=()=>{ const dest=document.getElementById('selDestino').value; sel.clear(); enviar({action:accion,items:arr,back:CARPETA_ACTUAL,dest:dest}); };
}
function cerrarModal(){document.getElementById('modalFondo').classList.remove('abierto');}
document.getElementById('modalFondo').addEventListener('click',e=>{ if(e.target.id==='modalFondo') cerrarModal(); });

/* ---- compartir ---- */
let CMP_REL='';
const PERM_NOMBRE={1:'Lectura',3:'Edición completa'};
function compartir(rel,nombre){
    CMP_REL=rel;
    const caja=document.getElementById('modalCaja');
    caja.innerHTML=
        '<h3>🔗 Compartir "'+escapeHtml(nombre)+'"</h3>'+
        '<div style="margin-bottom:10px"><b>Con usuarios de esta nube</b></div>'+
        '<input type="text" id="cmpUsuario" placeholder="Usuario o correo" autocomplete="off">'+
        '<select id="cmpPermiso">'+
            '<option value="1">Lectura (ver y descargar)</option>'+
            '<option value="3">Edición completa</option>'+
        '</select>'+
        '<div id="cmpLista" style="margin:-6px 0 14px;font-size:.85rem;"></div>'+
        '<div class="fila"><button class="btn texto" id="cmpCerrar">Cerrar</button><button class="btn primario" id="cmpAgregar">Compartir</button></div>'+
        '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--borde)"><b>Enlace público (cualquiera con el enlace)</b>'+
        '<div style="margin-top:8px;display:flex;flex-direction:column;gap:6px">'+
        '<input type="password" id="pubPass" placeholder="Contraseña opcional">'+
        '<input type="number" id="pubDias" placeholder="Caducar en días (opcional)" min="1">'+
        '<input type="number" id="pubMax" placeholder="Límite de descargas (opcional)" min="1">'+
        '</div>'+
        '<div style="margin-top:8px"><button class="btn primario" id="pubCrear">Crear enlace público</button></div>'+
        '<div id="pubLista" style="margin-top:10px;font-size:.85rem"></div></div>';
    document.getElementById('modalFondo').classList.add('abierto');
    document.getElementById('cmpCerrar').onclick=cerrarModal;
    document.getElementById('cmpAgregar').onclick=()=>{
        const u=document.getElementById('cmpUsuario').value.trim();
        if(!u){ toast('Escribe un usuario o correo',true); return; }
        cmpEnviar({accion:'agregar',p:rel,usuario:u,permiso:document.getElementById('cmpPermiso').value});
    };
    document.getElementById('pubCrear').onclick=()=>{
        const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','publico_crear'); fd.append('p',rel);
        fd.append('pass',document.getElementById('pubPass').value);
        fd.append('expira_dias',document.getElementById('pubDias').value);
        fd.append('max_descargas',document.getElementById('pubMax').value);
        fetch('compartir.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
            if(!j.ok){ toast(j.error||'Error',true); return; }
            toast('Enlace público creado');
            navigator.clipboard.writeText(j.url).catch(()=>{});
            pubListar(rel);
        }).catch(()=>toast('Error',true));
    };
    cmpEnviar({accion:'listar',p:rel});
    pubListar(rel);
}
function pubListar(rel){
    const fd=new FormData(); fd.append('csrf',CSRF); fd.append('accion','publico_listar'); fd.append('p',rel);
    fetch('compartir.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
        const c=document.getElementById('pubLista'); if(!c) return;
        if(!j.ok||!j.enlaces||!j.enlaces.length){ c.innerHTML='<div style="color:#5f6368">Sin enlaces públicos</div>'; return; }
        c.innerHTML=j.enlaces.map(e=>{
            if(e.revocado) return '<div style="display:flex;align-items:center;gap:6px;padding:4px 0;border-top:1px solid var(--borde)"><span style="flex:1;color:#5f6368">Revocado</span><button class="btn texto" data-pub-del="'+e.id+'" style="color:#d93025">Eliminar</button></div>';
            return '<div style="display:flex;flex-direction:column;gap:4px;padding:6px 0;border-top:1px solid var(--borde)"><input readonly value="'+escapeHtml(e.url)+'" style="width:100%;padding:6px;border:1px solid var(--borde);border-radius:6px"><div style="display:flex;gap:6px;align-items:center"><button class="btn texto" data-pub-copy="'+escapeHtml(e.url)+'">Copiar</button><button class="btn texto" data-pub-rev="'+e.id+'">Revocar</button><button class="btn texto" data-pub-del="'+e.id+'" style="color:#d93025">Eliminar</button><span style="color:#5f6368;margin-left:auto">'+(e.protegido?'🔒 ':'')+(e.descargas||0)+' descargas'+(e.expira_en?' · caduca '+e.expira_en:'')+'</span></div></div>';
        }).join('');
        c.querySelectorAll('[data-pub-copy]').forEach(b=>b.onclick=()=>{navigator.clipboard.writeText(b.dataset.pubCopy);toast('Copiado');});
        c.querySelectorAll('[data-pub-rev]').forEach(b=>b.onclick=()=>{
            const fd2=new FormData(); fd2.append('csrf',CSRF); fd2.append('accion','publico_revocar'); fd2.append('p',CMP_REL); fd2.append('enlace_id',b.dataset.pubRev);
            fetch('compartir.php',{method:'POST',body:fd2}).then(r=>r.json()).then(()=>pubListar(CMP_REL));
        });
        c.querySelectorAll('[data-pub-del]').forEach(b=>b.onclick=()=>{
            const fd2=new FormData(); fd2.append('csrf',CSRF); fd2.append('accion','publico_eliminar'); fd2.append('p',CMP_REL); fd2.append('enlace_id',b.dataset.pubDel);
            fetch('compartir.php',{method:'POST',body:fd2}).then(r=>r.json()).then(()=>pubListar(CMP_REL));
        });
    });
}
function cmpEnviar(datos){
    const fd=new FormData();
    fd.append('csrf',CSRF);
    for(const k in datos) fd.append(k,datos[k]);
    fetch('compartir.php',{method:'POST',body:fd})
      .then(r=>r.json())
      .then(j=>{
        if(!j.ok){ toast(j.error||'Error',true); return; }
        if(datos.accion==='agregar'){ toast('Compartido con '+datos.usuario); document.getElementById('cmpUsuario').value=''; }
        if(datos.accion==='quitar'){ toast('Acceso retirado'); }
        if(j.compartidos) cmpPintar(j.compartidos);
      })
      .catch(()=>toast('Error de conexión',true));
}
function cmpPintar(arr){
    const c=document.getElementById('cmpLista'); if(!c) return;
    if(!arr.length){ c.innerHTML='<div style="color:#5f6368;padding:4px 0;">Aún no compartido con nadie.</div>'; return; }
    c.innerHTML=arr.map(x=>'<div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-top:1px solid var(--borde);"><span style="flex:1;">'+escapeHtml(x.usuario)+' <span style="color:#5f6368;">· '+escapeHtml(PERM_NOMBRE[x.permiso]||'')+'</span></span><button class="btn texto" data-quitar="'+x.invitado_id+'" style="padding:2px 8px;color:#d93025;">Quitar</button></div>').join('');
    c.querySelectorAll('[data-quitar]').forEach(b=>b.onclick=()=>cmpEnviar({accion:'quitar',p:CMP_REL,invitado:b.dataset.quitar}));
}

/* ---- Nuevo ---- */
const btnNuevo=document.getElementById('btnNuevo');
if(btnNuevo) btnNuevo.onclick=e=>{
    e.stopPropagation();
    const r=e.currentTarget.getBoundingClientRect();
    const opc=[];
    if(PUEDE_ESCRIBIR) opc.push({txt:'📁 Nueva carpeta',fn:crearCarpeta});
    if(PUEDE_EDITAR){
        opc.push({txt:'📄 Documento de texto',fn:crearTexto});
        opc.push({txt:'📝 Documento',fn:()=>nuevoOffice('word','Documento.odt')});
        opc.push({txt:'📊 Hoja de cálculo',fn:()=>nuevoOffice('cell','Hoja.ods')});
        opc.push({txt:'📽️ Presentación',fn:()=>nuevoOffice('slide','Presentación.odp')});
    }
    if(PUEDE_ESCRIBIR){
        opc.push('-');
        opc.push({txt:'⬆️ Subir archivos',fn:()=>document.getElementById('inputSubir').click()});
        opc.push({txt:'📂 Subir carpeta',fn:()=>document.getElementById('inputSubirCarpeta').click()});
    }
    abrirMenu(r.left, r.bottom+4, opc);
};
function crearCarpeta(){ const n=prompt('Nombre de la nueva carpeta:'); if(n) enviar({action:'mkdir',p:CARPETA_ACTUAL,back:CARPETA_ACTUAL,name:n}); }
function crearTexto(){ const n=prompt('Nombre del documento (ej. notas.txt):','Documento.txt'); if(n) enviar({action:'newfile',p:CARPETA_ACTUAL,back:CARPETA_ACTUAL,name:n}); }
function nuevoOffice(type,ej){
    const n=prompt('Nombre del archivo:',ej); if(!n) return;
    const f=document.createElement('form'); f.method='POST'; f.action='newdoc.php';
    [['p',CARPETA_ACTUAL],['name',n],['type',type]].forEach(([k,v])=>{const i=document.createElement('input');i.type='hidden';i.name=k;i.value=v;f.appendChild(i);});
    if(CTX_O){ const i=document.createElement('input'); i.type='hidden'; i.name='o'; i.value=CTX_O; f.appendChild(i); }
    document.body.appendChild(f); toast('Creando documento…'); f.submit();
}

/* ---- vistas / orden / busqueda ---- */
document.getElementById('vistaGrid').onclick=()=>cambiarVista('grid');
document.getElementById('vistaLista').onclick=()=>cambiarVista('lista');
function cambiarVista(v){ const g=document.getElementById('zonaGrid'),l=document.getElementById('zonaLista'); if(!g||!l)return; g.style.display=v==='grid'?'grid':'none'; l.style.display=v==='lista'?'table':'none'; document.getElementById('vistaGrid').classList.toggle('activo',v==='grid'); document.getElementById('vistaLista').classList.toggle('activo',v==='lista'); localStorage.setItem('nubeVista',v); }
if(localStorage.getItem('nubeVista')==='lista') cambiarVista('lista');

const buscar=document.getElementById('buscar');
buscar.addEventListener('input',()=>{ const q=buscar.value.trim().toLowerCase(); let vis=0; itemsVisibles().forEach(el=>{ const ok=el.dataset.nombre.includes(q); el.style.display=ok?'':'none'; if(ok)vis++; }); const sr=document.getElementById('sinResultados'); if(sr) sr.style.display=vis===0?'':'none'; });

document.getElementById('ordenar').addEventListener('change',e=>{ const modo=e.target.value; const cont=document.getElementById('zonaGrid'); if(!cont)return; const exp=el=>modo==='tamanio'?parseInt(el.dataset.tam):modo==='fecha'?parseInt(el.dataset.fecha):el.dataset.nombre; const cards=[...cont.children]; cards.sort((a,b)=>{ const va=exp(a),vb=exp(b); return typeof va==='number'?vb-va:String(va).localeCompare(String(vb)); }); cards.forEach(c=>cont.appendChild(c)); });

/* ---- subir ---- */
document.getElementById('inputSubir').addEventListener('change',e=>subir(e.target.files,CARPETA_ACTUAL));
document.getElementById('inputSubirCarpeta').addEventListener('change',e=>subir(e.target.files,CARPETA_ACTUAL));
const overlay=document.getElementById('dropOverlay'); let dragCont=0;
window.addEventListener('dragenter',e=>{ if([...e.dataTransfer.types].some(t=>t==='Files')){ e.preventDefault(); dragCont++; overlay.classList.add('ver'); } });
window.addEventListener('dragover',e=>e.preventDefault());
window.addEventListener('dragleave',e=>{ if(--dragCont<=0){dragCont=0;overlay.classList.remove('ver');} });
window.addEventListener('drop',e=>{ e.preventDefault(); dragCont=0; overlay.classList.remove('ver'); if(e.dataTransfer.files.length && !e.target.closest('.tarjeta[data-dir="1"]') && !e.target.closest('tr[data-dir="1"]')) subir(e.dataTransfer.files,CARPETA_ACTUAL); });

function subir(files,destino){
    const lista=[...files];
    if(!lista.length) return;
    if(!PUEDE_ESCRIBIR){ toast('No tienes permiso para subir aquí',true); return; }
    toast(lista.length===1 ? 'Subiendo 1 archivo…' : 'Subiendo '+lista.length+' archivos…');
    let i=0, restante=CUOTA_RESTANTE; const errores=[];
    const siguiente=()=>{
        if(i>=lista.length){
            if(errores.length){
                const sep=RUTA_INDEX.includes('?')?'&':'?';
                location.href=RUTA_INDEX+sep+'msg='+encodeURIComponent(errores.join(' · '))+'&tipo=err';
            } else {
                location.href=RUTA_INDEX;
            }
            return;
        }
        const f=lista[i++];
        const nombreRel=f.webkitRelativePath||f.name;
        if(restante>=0 && f.size>restante){
            errores.push(nombreRel+': supera el espacio disponible ('+humano(restante)+')');
            siguiente(); return;
        }
        const fd=new FormData();
        fd.append('archivos[]',f);
        fd.append('rutas[]', nombreRel);
        fd.append('p',destino||'');
        if(CTX_O) fd.append('o',CTX_O);
        const xhr=new XMLHttpRequest(); xhr.open('POST','upload.php');
        xhr.onload=()=>{
            let j=null; try{ j=JSON.parse(xhr.responseText); }catch(err){}
            if(j && (j.errores||[]).length) errores.push(...j.errores);
            else if(!j) errores.push(nombreRel+': respuesta no válida del servidor');
            else if(restante>=0) restante-=f.size;
            if(lista.length>1) toast('Subiendo… '+Math.round(i/lista.length*100)+'%');
            siguiente();
        };
        xhr.onerror=()=>{ errores.push(nombreRel+': error de red'); siguiente(); };
        xhr.send(fd);
    };
    siguiente();
}

function humano(n){
    const u=['B','KB','MB','GB','TB','PB']; let i=0, b=n;
    while(b>=1000 && i<u.length-1){ b/=1000; i++; }
    return (i===0 ? Math.round(b) : b.toFixed(2))+' '+u[i];
}

/* ---- tema ---- */
const btnTema=document.getElementById('btnTema');
function pintarBotonTema(){ const osc=document.documentElement.getAttribute('data-tema')==='oscuro'; btnTema.textContent=osc?'☀️':'🌙'; btnTema.title=osc?'Modo claro':'Modo oscuro'; }
btnTema.addEventListener('click',e=>{ e.stopPropagation(); const osc=document.documentElement.getAttribute('data-tema')==='oscuro'; const nuevo=osc?'claro':'oscuro'; document.documentElement.setAttribute('data-tema',nuevo); try{localStorage.setItem('nubeTema',nuevo);}catch(err){} pintarBotonTema(); });
pintarBotonTema();

/* ---- menu de usuario ---- */
const avatar=document.getElementById('avatar');
const menuUsuario=document.getElementById('menuUsuario');
avatar.addEventListener('click',e=>{
    e.stopPropagation();
    if(menuUsuario.classList.contains('abierto')){ menuUsuario.classList.remove('abierto'); return; }
    menuUsuario.classList.add('abierto');
    const r=avatar.getBoundingClientRect();
    const w=menuUsuario.offsetWidth;
    const h=menuUsuario.offsetHeight;
    const left=Math.max(8, Math.min(r.right-w, window.innerWidth-w-8));
    let top=r.bottom+8;
    if(top+h>window.innerHeight-8) top=Math.max(8, r.top-h-8);
    menuUsuario.style.left=left+'px';
    menuUsuario.style.top=top+'px';
});
document.addEventListener('click',e=>{ if(!menuUsuario.contains(e.target) && !avatar.contains(e.target)) menuUsuario.classList.remove('abierto'); });

/* ---- menu lateral movil ---- */
const btnMenu=document.getElementById('btnMenu');
const sidebar=document.getElementById('sidebar');
const sidebarFondo=document.getElementById('sidebarFondo');
function cerrarDrawer(){ sidebar.classList.remove('abierto'); sidebarFondo.classList.remove('abierto'); }
btnMenu.addEventListener('click',e=>{ e.stopPropagation(); sidebar.classList.toggle('abierto'); sidebarFondo.classList.toggle('abierto'); });
sidebarFondo.addEventListener('click',cerrarDrawer);
sidebar.querySelectorAll('.nav-item').forEach(n=>n.addEventListener('click',cerrarDrawer));
</script>
</body>
</html>
