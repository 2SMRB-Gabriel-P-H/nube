<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

header('Content-Type: application/json; charset=utf-8');

if (usuarioActual() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$p = relClean((string) ($_GET['p'] ?? ''));
if ($p === '' || !puedeEditar($p) || !esEditableOnlyOffice(extDe($p))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso para guardar']);
    exit;
}

/* La clave identifica la revisión cargada. Se firma desde office.php y se
   conserva en el navegador durante toda esa sesión, aunque haya forcesaves. */
$key = (string) ($_POST['key'] ?? '');
$firma = (string) ($_POST['firma'] ?? '');
$uidDocumento = (int) $GLOBALS['NUBE_UID'];
$firmaEsperada = hash_hmac('sha256', $uidDocumento . '|' . $p . '|' . $key, NUBE_SALT);
if (!preg_match('/^[a-f0-9]{32}$/', $key) || !hash_equals($firmaEsperada, $firma)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sesión de documento no válida']);
    exit;
}

$cuerpo = json_encode(['c' => 'forcesave', 'key' => $key]);
$candidatos = [
    'http://127.0.0.1:8080/command',
    'http://127.0.0.1:8080/coauthoring/CommandService.ashx',
];

$respuesta = null;
foreach ($candidatos as $url) {
    if (!function_exists('curl_init')) {
        break;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $out  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out !== false && $code === 200) {
        $respuesta = json_decode((string) $out, true);
        break;
    }
}

if (is_array($respuesta) && array_key_exists('error', $respuesta)) {
    $err = (int) $respuesta['error'];
    echo json_encode(['ok' => ($err === 0 || $err === 4), 'error' => $err]);
    return;
}

echo json_encode(['ok' => false, 'error' => 'sin respuesta del servidor de documentos']);
