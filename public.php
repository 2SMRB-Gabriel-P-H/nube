<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fs.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';

function p_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    http_response_code(404);
    exit('Enlace no válido.');
}

$pdo = bd();
$st = $pdo->prepare('SELECT * FROM enlaces_publicos WHERE token = ? LIMIT 1');
$st->execute([strtolower($token)]);
$enlace = $st->fetch(PDO::FETCH_ASSOC);
if (!$enlace) {
    http_response_code(404);
    exit('Enlace no encontrado.');
}
if ((int)$enlace['revocado'] === 1) {
    http_response_code(410);
    exit('Este enlace ha sido revocado.');
}
if (!empty($enlace['expira_en']) && (int)$enlace['expira_en'] < time()) {
    http_response_code(410);
    exit('Este enlace ha caducado.');
}
if (!empty($enlace['max_descargas']) && (int)$enlace['descargas'] >= (int)$enlace['max_descargas']) {
    http_response_code(410);
    exit('Se ha alcanzado el límite de descargas de este enlace.');
}

$propId = (int)$enlace['propietario_uid'];
$rutaRel = (string)$enlace['ruta_relativa'];
$esCarpeta = (int)$enlace['es_carpeta'] === 1;
$nombre = basename($rutaRel) !== '' ? basename($rutaRel) : $rutaRel;

if (!empty($enlace['pass_hash'])) {
    iniciarSesion();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfOk($_POST['csrf'] ?? null)) {
            $err = 'La sesión caducó. Vuelve a intentarlo.';
        } else {
            $pass = (string)($_POST['pass'] ?? '');
            if (password_verify($pass, (string)$enlace['pass_hash'])) {
                $_SESSION['pub_'.$token] = true;
            } else {
                $err = 'Contraseña incorrecta.';
            }
        }
    }
    if (empty($_SESSION['pub_'.$token])) {
        ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Descarga protegida</title><style>body{font-family:system-ui,sans-serif;background:#f7f8fa;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}form{background:#fff;border:1px solid #dadce0;border-radius:12px;padding:24px;width:min(320px,90vw)}input{width:100%;padding:10px;border:1px solid #dadce0;border-radius:8px;margin-top:8px}button{margin-top:12px;width:100%;padding:10px;background:#1a73e8;color:#fff;border:0;border-radius:8px;cursor:pointer}.err{color:#d93025;font-size:.9rem;margin-top:8px}</style></head><body><form method="post"><h3 style="margin:0 0 10px">Enlace protegido</h3><div>Introduce la contraseña para acceder a <b><?=p_h($nombre)?></b></div><input type="hidden" name="csrf" value="<?=h(csrfToken())?>"><input type="password" name="pass" placeholder="Contraseña" autofocus required><button>Acceder</button><?php if(!empty($err)):?><div class="err"><?=p_h($err)?></div><?php endif;?></form></body></html><?php
        exit;
    }
}

$accion = (string)($_GET['a'] ?? 'view');
$carpetaProp = carpetaUsuario($propId);
$absProp = USERS_ROOT . '/' . $carpetaProp;
$abs = $absProp . '/' . ltrim($rutaRel, '/');

if ($esCarpeta) {
    http_response_code(400);
    exit('Las carpetas con este enlace público no están soportadas aún.');
}
if (!is_file($abs)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

if ($accion === 'download' && (int)$enlace['permitir_descarga'] === 1) {
    $mime = @mime_content_type($abs) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '\"', $nombre) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (function_exists('leerArchivoRaw')) {
        leerArchivoRaw($abs, $propId);
    } else {
        $st2 = $pdo->prepare('UPDATE enlaces_publicos SET descargas = descargas + 1 WHERE token = ?');
        $st2->execute([strtolower($token)]);
        readfile($abs);
    }
    $st2 = $pdo->prepare('UPDATE enlaces_publicos SET descargas = descargas + 1 WHERE token = ?');
    $st2->execute([strtolower($token)]);
    exit;
}

$mime = @mime_content_type($abs) ?: 'application/octet-stream';
if (strpos($mime, 'image/') === 0 || $mime === 'application/pdf' || strpos($mime, 'text/') === 0) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . str_replace('"', '\"', $nombre) . '"');
    if (function_exists('leerArchivoRaw')) {
        leerArchivoRaw($abs, $propId);
    } else {
        readfile($abs);
    }
    exit;
}

?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=p_h($nombre)?> · Descarga</title><style>body{font-family:system-ui,sans-serif;background:#f7f8fa;margin:0;display:flex;align-items:center;justify-content:center;height:100vh}.card{background:#fff;border:1px solid #dadce0;border-radius:14px;padding:28px;text-align:center;max-width:420px;width:90vw}h1{font-size:1.1rem;margin:0 0 10px;word-break:break-word}p{color:#5f6368;margin:0 0 18px}.btn{display:inline-block;padding:10px 18px;background:#1a73e8;color:#fff;text-decoration:none;border-radius:8px}.btn.sec{background:#f1f3f4;color:#202124;margin-left:8px}</style></head><body><div class="card"><h1><?=p_h($nombre)?></h1><p>Descarga este archivo sin iniciar sesión</p><a class="btn" href="?t=<?=p_h($token)?>&a=download">Descargar</a></div></body></html><?php
exit;
