<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

header('Content-Type: application/json');

if (usuarioActual() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$p = relClean((string) ($_POST['p'] ?? ''));
$contenido = (string) ($_POST['contenido'] ?? '');

if ($p === '') {
    echo json_encode(['ok' => false, 'error' => 'Ruta no válida']);
    exit;
}

if (!puedeEditar($p)) {
    echo json_encode(['ok' => false, 'error' => 'No tienes permiso para editar este archivo']);
    exit;
}

$ext = extDe($p);
if (!esEditableTexto($ext)) {
    echo json_encode(['ok' => false, 'error' => 'Tipo de archivo no editable']);
    exit;
}

$abs = pathOf($p);
$dir = dirname($abs);
if (!is_dir($dir) || !dentroDelRoot(dirname($p))) {
    echo json_encode(['ok' => false, 'error' => 'Carpeta no válida']);
    exit;
}

$owner = (int) ($GLOBALS['NUBE_UID'] ?? 0);
$cuota = cuotaDe($owner);
if ($cuota > 0) {
    $viejo = tamanoPlano($abs);
    $delta = strlen($contenido) - $viejo;
    if ($delta > 0 && usoEspacioDe($owner) + $delta > $cuota) {
        echo json_encode(['ok' => false, 'error' => 'Sin espacio en la cuota (' . sizeHumano($cuota) . ')']);
        exit;
    }
}

if (!escribirArchivo($abs, $contenido, $owner)) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo escribir el archivo']);
    exit;
}

echo json_encode(['ok' => true, 'mtime' => filemtime($abs)]);
