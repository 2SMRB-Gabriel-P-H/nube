<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

$rel   = (string) ($_GET['p'] ?? '');
$token = (string) ($_GET['t'] ?? '');
$desc  = isset($_GET['download']);

$uidSesion = accesoActual();
$uidTok    = uidDeToken($token);
$tokenOk   = $uidTok > 0 && hash_equals(tokenFor($rel, $uidTok), $token);

if (!$tokenOk && ($uidSesion <= 0 || permisoEn($rel) < PERM_LECTURA)) {
    http_response_code(403);
    exit('Enlace no válido.');
}

$abs = pathOf($rel);
if (!is_file($abs) || !dentroDelRoot($rel)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$ext = extDe($abs);
$mimes = [
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain; charset=utf-8',
    'log'  => 'text/plain; charset=utf-8',
    'md'   => 'text/plain; charset=utf-8',
    'csv'  => 'text/csv; charset=utf-8',
    'xml'  => 'application/xml',
    'json' => 'application/json',
    'html' => 'text/html; charset=utf-8',
    'htm'  => 'text/html; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'js'   => 'text/javascript; charset=utf-8',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif'  => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    'bmp'  => 'image/bmp', 'ico' => 'image/x-icon', 'avif' => 'image/avif',
    'mp4'  => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
    'mov'  => 'video/quicktime', 'm4v' => 'video/x-m4v',
    'mp3'  => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
    'oga'  => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
    'flac' => 'audio/flac', 'opus' => 'audio/opus',
];
$mime = $mimes[$ext] ?? (function_exists('mime_content_type') ? (mime_content_type($abs) ?: 'application/octet-stream') : 'application/octet-stream');

$nombre = basename($abs);
$size = tamanoPlano($abs);

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Accept-Ranges: none');
if ($desc) {
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nombre) . '"');
} else {
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $nombre) . '"');
}
header('Cache-Control: private, no-store');

servirArchivo($abs, $tokenOk ? $uidTok : $uidSesion);
