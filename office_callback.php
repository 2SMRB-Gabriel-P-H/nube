<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

header('Content-Type: application/json');

function responder(int $error = 0, string $msg = ''): void
{
    echo json_encode(['error' => $error, 'message' => $msg]);
    exit;
}

$p     = relClean((string) ($_GET['p'] ?? ''));
$token = (string) ($_GET['t'] ?? '');

$uidTok = uidDeToken($token);
if ($p === '' || $uidTok <= 0 || !hash_equals(tokenFor($p, $uidTok), $token)) {
    responder(1, 'token inválido');
}

$abs = pathOf($p);
if (!dentroDelRoot($p) || !is_file($abs)) {
    responder(1, 'archivo no encontrado');
}

$raw  = file_get_contents('php://input');
$body = json_decode((string) $raw, true);

if (!is_array($body)) {
    responder(1, 'json inválido');
}

$status = (int) ($body['status'] ?? 0);
$url    = (string) ($body['url'] ?? '');

$log = __DIR__ . '/cache/callback.log';
@file_put_contents($log, date('c') . ' p=' . $p . ' status=' . $status . ' url=' . $url . "\n", FILE_APPEND);

if (($status === 2 || $status === 6) && $url !== '') {
    $fetch = preg_replace('~^https?://[^/]+~', 'http://127.0.0.1:8080', $url);

    $datos = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($fetch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $datos = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 60]]);
        $datos = @file_get_contents($fetch, false, $ctx);
    }

    if ($datos === false) {
        responder(1, 'no se pudo descargar del document server');
    }

    if (!escribirArchivo($abs, $datos, $uidTok)) {
        responder(1, 'no se pudo guardar el documento');
    }
    @touch($abs);
    responder(0);
}

responder(0);
