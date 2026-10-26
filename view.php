<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';
require __DIR__ . '/convert.php';

requiereLogin();

$p = relClean((string) ($_GET['p'] ?? ''));
$abs = pathOf($p);

if ($p === '' || !is_file($abs) || !dentroDelRoot($p)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

if (!puedeLeer($p)) {
    http_response_code(403);
    exit('No tienes permiso para ver este archivo.');
}

$ext  = extDe($abs);
$size = tamanoPlano($abs);
$cat  = categoria($ext);

$src      = urlDe($p);
$descarga = enlace('raw.php?p=' . rawurlencode($p) . '&download=1');
$editar   = esEditableOnlyOffice($ext) ? enlace('office.php?p=' . rawurlencode($p))
          : (esEditableTexto($ext) ? enlace('textedit.php?p=' . rawurlencode($p)) : '');
if ($editar !== '' && !puedeEditar($p)) {
    $editar = '';
}

$contenido = '';
$pdfUrl = '';
$errorConv = '';
if (in_array($ext, extTexto(), true)) {
    $contenido = $size <= 2 * 1024 * 1024
        ? leerArchivo($abs)
        : '(Archivo grande: descárgalo para verlo completo.)';
} elseif (esEditableOnlyOffice($ext)) {
    $pdfUrl = convertirAPdf($abs, $p) ?? '';
    if ($pdfUrl === '') $errorConv = 'No se pudo generar la vista previa.';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ver · <?= h(basename($p)) ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%231a73e8' d='M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z'/></svg>">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', Roboto, Arial, sans-serif; background: #202124; color: #e8eaed; min-height: 100vh; display: flex; flex-direction: column; }
    header { display: flex; align-items: center; gap: 14px; padding: 10px 18px; background: #2d2e30; border-bottom: 1px solid #3c4043; flex-wrap: wrap; }
    header .info { min-width: 0; }
    header .nom { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 55vw; }
    header .meta { color: #9aa0a6; font-size: .78rem; }
    .acciones { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
    .btn2 { text-decoration: none; background: #3c4043; color: #e8eaed; padding: 8px 14px; border-radius: 6px; font-size: .85rem; font-weight: 600; }
    .btn2:hover { background: #4a4d51; }
    .btn2.azul { background: #1a73e8; } .btn2.azul:hover { background: #1765cc; }
    .visor { flex: 1; display: flex; align-items: center; justify-content: center; padding: 20px; overflow: auto; }
    .visor img { max-width: 100%; max-height: 84vh; border-radius: 8px; background: #fff; }
    .visor video { max-width: 100%; max-height: 84vh; border-radius: 8px; background: #000; }
    .visor audio { width: 620px; max-width: 100%; }
    .visor iframe { width: 100%; height: 85vh; border: none; border-radius: 8px; background: #fff; }
    .caja { width: 100%; max-width: 1000px; max-height: 85vh; overflow: auto; background: #0d1117; border: 1px solid #3c4043; border-radius: 10px; }
    .caja pre { margin: 0; padding: 18px; font-family: Consolas, 'Courier New', monospace; font-size: .85rem; line-height: 1.5; white-space: pre; color: #c9d1d9; }
    .sin { text-align: center; color: #9aa0a6; max-width: 460px; }
    .sin .em { font-size: 4rem; display: block; margin-bottom: 10px; }
    .aviso { background: #4a2a1a; color: #f5c9a6; padding: 8px 14px; font-size: .82rem; text-align: center; }
    @media (max-width: 600px) {
        header { padding: 8px 10px; gap: 8px; }
        header .nom { max-width: 45vw; }
        .btn2 { padding: 7px 10px; font-size: .8rem; }
        .visor { padding: 12px; }
    }
</style>
</head>
<body>
<header>
    <div class="info">
        <div class="nom"><?= h(basename($p)) ?></div>
        <div class="meta"><?= h(sizeHumano($size)) ?> · <?= $ext !== '' ? h(strtoupper($ext)) : 'archivo' ?></div>
    </div>
    <div class="acciones">
        <a class="btn2" href="<?= h(enlace('index.php?p=' . rawurlencode(dirname($p) === '.' ? '' : dirname($p)))) ?>">← Volver</a>
        <?php if ($editar): ?><a class="btn2 azul" href="<?= h($editar) ?>">✏️ Editar</a><?php endif; ?>
        <a class="btn2" href="<?= h($descarga) ?>">⬇️ Descargar</a>
    </div>
</header>

<?php if ($errorConv): ?><div class="aviso"><?= h($errorConv) ?></div><?php endif; ?>

<div class="visor">
    <?php if ($cat === 'imagen'): ?>
        <img src="<?= h($src) ?>" alt="">
    <?php elseif ($cat === 'video'): ?>
        <video controls autoplay preload="metadata" src="<?= h($src) ?>"></video>
    <?php elseif ($cat === 'audio'): ?>
        <audio controls autoplay src="<?= h($src) ?>"></audio>
    <?php elseif ($cat === 'pdf'): ?>
        <iframe src="<?= h($src) ?>#toolbar=1" title="pdf"></iframe>
    <?php elseif ($ext === 'html' || $ext === 'htm'): ?>
        <iframe src="<?= h($src) ?>" title="html" style="background:#fff;"></iframe>
    <?php elseif (in_array($ext, extTexto(), true)): ?>
        <div class="caja"><pre><?= h($contenido) ?></pre></div>
    <?php elseif (esEditableOnlyOffice($ext) && $pdfUrl): ?>
        <iframe src="<?= h($pdfUrl) ?>#toolbar=1" title="doc"></iframe>
    <?php else: ?>
        <div class="sin">
            <span class="em">📦</span>
            <p>Vista previa no disponible para <strong>.<?= h($ext) ?></strong>.</p>
            <a class="btn2 azul" href="<?= h($descarga) ?>">⬇️ Descargar <?= h(basename($p)) ?></a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
