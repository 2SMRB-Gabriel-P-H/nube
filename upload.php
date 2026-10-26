<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

requiereLogin();

$p = relClean((string) ($_POST['p'] ?? ''));
$destinoDir = pathOf($p);
if (!is_dir($destinoDir) || !dentroDelRoot($p)) {
    $destinoDir = NUBE_ROOT;
    $p = '';
}

header('Content-Type: application/json');

if (!puedeEscribir($p)) {
    echo json_encode(['ok' => 0, 'errores' => ['No tienes permiso para subir archivos aquí']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => 0, 'errores' => ['Método no permitido']], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_FILES['archivos'])) {
    $largo   = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = pesoIni((string) ini_get('post_max_size'));
    if ($largo > 0 && $postMax > 0 && $largo > $postMax) {
        echo json_encode(['ok' => 0, 'errores' => ['El envío (' . sizeHumano($largo) . ') supera el límite del servidor (' . sizeHumano($postMax) . ')']], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => 0, 'errores' => ['No se recibió ningún archivo']], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function uploadErrorTexto(int $code): string
{
    return [
        UPLOAD_ERR_INI_SIZE   => 'supera el tamaño máximo permitido',
        UPLOAD_ERR_FORM_SIZE  => 'supera el tamaño permitido por el formulario',
        UPLOAD_ERR_PARTIAL    => 'subida incompleta',
        UPLOAD_ERR_NO_FILE    => 'no se recibió el archivo',
        UPLOAD_ERR_NO_TMP_DIR => 'falta la carpeta temporal',
        UPLOAD_ERR_CANT_WRITE => 'no se pudo escribir en el disco',
        UPLOAD_ERR_EXTENSION  => 'bloqueado por una extensión de PHP',
    ][$code] ?? ('error ' . $code);
}

$subidos  = 0;
$errores  = [];
$archivos = $_FILES['archivos'];
$rutas    = isset($_POST['rutas']) && is_array($_POST['rutas']) ? array_values($_POST['rutas']) : [];
$total    = count($archivos['name']);

$owner  = (int) ($GLOBALS['NUBE_UID'] ?? 0);
$cuota  = cuotaDe($owner);
$usado  = $cuota > 0 ? usoEspacioDe($owner) : 0;

function relSeguro(string $rel): string
{
    $rel = str_replace('\\', '/', $rel);
    $out = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') continue;
        $seg = (string) preg_replace('/[^\p{L}\p{N}._\- ()]+/u', '_', $seg);
        $seg = trim($seg);
        if ($seg !== '') $out[] = $seg;
    }
    return implode('/', $out);
}

for ($i = 0; $i < $total; $i++) {
    $nombreOriginal = (string) $archivos['name'][$i];
    $error = (int) $archivos['error'][$i];
    $tmp   = (string) $archivos['tmp_name'][$i];
    $size  = (int) $archivos['size'][$i];

    if ($error === UPLOAD_ERR_NO_FILE) continue;
    if ($error !== UPLOAD_ERR_OK) { $errores[] = $nombreOriginal . ': ' . uploadErrorTexto($error); continue; }
    if (!is_uploaded_file($tmp)) { $errores[] = $nombreOriginal . ' inválido'; continue; }

    if ($cuota > 0 && $usado + $size > $cuota) {
        $errores[] = $nombreOriginal . ': sin espacio en la cuota (límite ' . sizeHumano($cuota) . ')';
        continue;
    }

    $rel = relSeguro((string) ($rutas[$i] ?? $nombreOriginal));
    if ($rel === '') $rel = limpiarNombre($nombreOriginal);
    $subDir   = dirname($rel);
    $baseName = basename($rel);
    $targetDir = $destinoDir . ($subDir === '.' || $subDir === '' ? '' : '/' . $subDir);

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true)) {
        $errores[] = $nombreOriginal . ' no se pudo crear la carpeta';
        continue;
    }
    @chmod($targetDir, 0777);

    $limpio  = limpiarNombre($baseName);
    $limpio  = nombreUnico($targetDir, $limpio);
    $destino = $targetDir . '/' . $limpio;

    if (cifrarArchivoOrigen($tmp, $destino, $owner)) {
        @chmod($destino, 0666);
        $usado += $size;
        $subidos++;
    } else {
        $errores[] = $nombreOriginal . ' no se pudo guardar';
    }
}

echo json_encode(['ok' => $subidos, 'errores' => $errores], JSON_UNESCAPED_UNICODE);
