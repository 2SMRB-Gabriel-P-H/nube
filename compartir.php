<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function jok(array $extra = []): void
{
    echo json_encode(['ok' => true] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

if (accesoActual() <= 0) {
    jerr('Sesión expirada', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jerr('Método no permitido', 405);
}
if (!csrfOk((string) ($_POST['csrf'] ?? ''))) {
    jerr('Token de seguridad inválido', 403);
}
if (ctxOwner() > 0) {
    jerr('No puedes compartir archivos de otra persona', 403);
}

$miId   = accesoActual();
$accion = (string) ($_POST['accion'] ?? 'listar');
$rel    = relClean((string) ($_POST['p'] ?? ''));

if ($rel === '') {
    jerr('Selecciona un archivo o carpeta para compartir');
}
if (!file_exists(pathOf($rel)) || !dentroDelRoot($rel)) {
    jerr('El elemento no existe');
}

switch ($accion) {
    case 'listar':
        jok(['compartidos' => compartidosDe($miId, $rel)]);

    case 'buscar':
        $q = (string) ($_POST['usuario'] ?? '');
        $u = usuarioPorLogin($q);
        if ($u === null) {
            jerr('No existe ningún usuario con ese nombre o correo');
        }
        if ((int) $u['id'] === $miId) {
            jerr('No puedes compartir contigo mismo');
        }
        jok(['usuario' => $u]);

    case 'agregar':
        $u = usuarioPorLogin((string) ($_POST['usuario'] ?? ''));
        if ($u === null) {
            jerr('No existe ningún usuario con ese nombre o correo');
        }
        if ((int) $u['id'] === $miId) {
            jerr('No puedes compartir contigo mismo');
        }
        $permiso = (int) ($_POST['permiso'] ?? PERM_LECTURA);
        compartirCon($miId, $rel, (int) $u['id'], $permiso);
        jok(['compartidos' => compartidosDe($miId, $rel)]);

    case 'quitar':
        $invitado = (int) ($_POST['invitado'] ?? 0);
        if ($invitado <= 0) {
            jerr('Usuario no válido');
        }
        quitarCompartido($miId, $rel, $invitado);
        jok(['compartidos' => compartidosDe($miId, $rel)]);

    case 'publico_crear':
        $ownerId = (int) ($GLOBALS['NUBE_UID'] ?? $miId);
        if ($ownerId <= 0) $ownerId = $miId;
        $relLimpio = relClean($rel);
        $abs = USERS_ROOT . '/' . carpetaUsuario($ownerId) . '/' . ltrim($relLimpio, '/');
        if (!is_file($abs)) {
            $abs = pathOf($relLimpio !== '' ? $relLimpio : $rel);
        }
        if (!is_file($abs)) { jerr('Elemento no existe'); }
        $token = bin2hex(random_bytes(32));
        $pass = trim((string)($_POST['pass'] ?? ''));
        $passHash = $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : null;
        $expira = 0;
        if (!empty($_POST['expira_dias'])) {
            $d = (int)$_POST['expira_dias'];
            if ($d > 0) $expira = time() + $d * 86400;
        }
        $maxd = !empty($_POST['max_descargas']) ? (int)$_POST['max_descargas'] : null;
        if ($maxd !== null && $maxd < 1) $maxd = null;
        $st = bd()->prepare('INSERT INTO enlaces_publicos (propietario_uid,ruta_relativa,es_carpeta,token,pass_hash,expira_en,max_descargas,descargas,permitir_vista,permitir_descarga,revocado,creado_en) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$ownerId, $relLimpio, is_dir($abs) ? 1 : 0, $token, $passHash, $expira ?: null, $maxd, 0, 1, 1, 0, time()]);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        jok(['ok' => true, 'url' => $base . '/public.php?t=' . $token]);

    case 'publico_listar':
        $st = bd()->prepare('SELECT id,token,ruta_relativa,pass_hash,expira_en,max_descargas,descargas,revocado,creado_en FROM enlaces_publicos WHERE propietario_uid = ? AND ruta_relativa = ? ORDER BY id DESC');
        $st->execute([$miId, $rel]);
        $out = [];
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $out[] = [
                'id' => (int)$e['id'],
                'url' => $base . '/public.php?t=' . $e['token'],
                'protegido' => !empty($e['pass_hash']),
                'expira_en' => $e['expira_en'] ? date('Y-m-d H:i', (int)$e['expira_en']) : null,
                'max_descargas' => $e['max_descargas'],
                'descargas' => (int)$e['descargas'],
                'revocado' => (int)$e['revocado'],
                'creado_en' => $e['creado_en'] ? date('Y-m-d H:i', (int)$e['creado_en']) : null,
            ];
        }
        jok(['ok' => true, 'enlaces' => $out]);

    case 'publico_revocar':
        $eid = (int)($_POST['enlace_id'] ?? 0);
        $st = bd()->prepare('UPDATE enlaces_publicos SET revocado = 1 WHERE id = ? AND propietario_uid = ?');
        $st->execute([$eid, $miId]);
        jok(['ok' => true]);

    case 'publico_eliminar':
        $eid = (int)($_POST['enlace_id'] ?? 0);
        $st = bd()->prepare('DELETE FROM enlaces_publicos WHERE id = ? AND propietario_uid = ?');
        $st->execute([$eid, $miId]);
        jok(['ok' => true]);

    default:
        jerr('Acción desconocida');
}

