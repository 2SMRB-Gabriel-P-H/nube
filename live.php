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

$raw = (string) file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) {
    $in = $_POST;
}

if (!csrfOk((string) ($in['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
    exit;
}

$p     = relClean((string) ($_GET['p'] ?? ($in['p'] ?? '')));
$accion = (string) ($in['accion'] ?? 'sync');
$uid   = accesoActual();

if ($p === '' || !puedeEditar($p) || !esEditableTexto(extDe($p))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso para editar en vivo']);
    exit;
}

$abs = pathOf($p);
if (!is_dir(dirname($abs)) || !dentroDelRoot($p)) {
    echo json_encode(['ok' => false, 'error' => 'Ruta no válida']);
    exit;
}

$nombre = (string) ((usuarioPorId($uid)['usuario'] ?? 'Usuario'));

$dir = CACHE_ROOT . '/live';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
$estadoArchivo = $dir . '/' . md5((int) $GLOBALS['NUBE_UID'] . '|' . $p) . '.json';

const LIVE_MAX_OPS   = 200;
const LIVE_PRUNE_SEG = 30;

if (!function_exists('aplicarOp')) {
    function aplicarOp(string $s, array $op): string
    {
        $pos = max(0, (int) ($op['pos'] ?? 0));
        $del = max(0, (int) ($op['del'] ?? 0));
        $ins = (string) ($op['ins'] ?? '');
        return mb_substr($s, 0, $pos, 'UTF-8') . $ins . mb_substr($s, $pos + $del, null, 'UTF-8');
    }
}

function estadoVacio(string $abs): array
{
    $contenido = is_file($abs) ? leerArchivo($abs) : '';
    return ['version' => 0, 'content' => $contenido, 'ops' => [], 'users' => []];
}

/**
 * Ejecuta $fn($estado) con el estado bloqueado y persistido.
 * $fn puede modificar $estado por referencia y devolver el arreglo de respuesta.
 */
function liveConEstado(string $archivo, string $abs, callable $fn): array
{
    $fh = @fopen($archivo, 'c+');
    if ($fh === false) {
        return ['ok' => false, 'error' => 'No se pudo abrir el estado'];
    }
    flock($fh, LOCK_EX);
    $contenido = stream_get_contents($fh);
    $estado = json_decode((string) $contenido, true);
    if (!is_array($estado) || !isset($estado['version'])) {
        $estado = estadoVacio($abs);
    }
    if (!isset($estado['ops']) || !is_array($estado['ops'])) { $estado['ops'] = []; }
    if (!isset($estado['users']) || !is_array($estado['users'])) { $estado['users'] = []; }

    $resp = $fn($estado);

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $resp;
}

function podarUsuarios(array &$estado): void
{
    $ahora = time();
    foreach ($estado['users'] as $k => $u) {
        if (($ahora - (int) ($u['ts'] ?? 0)) > LIVE_PRUNE_SEG) {
            unset($estado['users'][$k]);
        }
    }
}

/** Registra la selección del usuario (en coordenadas de su versión). */
function asentarUsuario(array &$estado, int $uid, string $nombre, $sel, int $v): void
{
    $s = is_array($sel) ? [(int) ($sel[0] ?? 0), (int) ($sel[1] ?? 0)] : [0, 0];
    $estado['users'][(string) $uid] = [
        'name' => $nombre,
        'sel'  => $s,
        'v'    => $v,
        'ts'   => time(),
    ];
}

if ($accion === 'salir') {
    $resp = liveConEstado($estadoArchivo, $abs, function (array &$estado) use ($uid) {
        unset($estado['users'][(string) $uid]);
        return ['ok' => true];
    });
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'push') {
    $base = (int) ($in['base'] ?? -1);
    $op   = $in['op'] ?? null;
    $sel  = $in['sel'] ?? [0, 0];

    if (!is_array($op) || !isset($op['pos'], $op['del'], $op['ins'])
        || mb_strlen((string) $op['ins'], 'UTF-8') > 1000000) {
        echo json_encode(['ok' => false, 'error' => 'Operación inválida']);
        exit;
    }

    $resp = liveConEstado($estadoArchivo, $abs, function (array &$estado) use ($base, $op, $uid, $nombre, $sel, $abs) {
        podarUsuarios($estado);
        asentarUsuario($estado, $uid, $nombre, $sel, $base);

        if ($base !== (int) $estado['version']) {
            return ['ok' => false, 'version' => (int) $estado['version']];
        }

        $estado['content'] = aplicarOp((string) $estado['content'], $op);
        $estado['version'] = (int) $estado['version'] + 1;
        $opGuardada = [
            'v'   => (int) $estado['version'],
            'uid' => $uid,
            'pos' => max(0, (int) $op['pos']),
            'del' => max(0, (int) $op['del']),
            'ins' => (string) $op['ins'],
        ];
        $estado['ops'][] = $opGuardada;
        while (count($estado['ops']) > LIVE_MAX_OPS) {
            array_shift($estado['ops']);
        }

        escribirArchivo($abs, (string) $estado['content'], (int) $GLOBALS['NUBE_UID']);

        return ['ok' => true, 'version' => (int) $estado['version'], 'op' => $opGuardada];
    });

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

/* accion = sync (por defecto) */
$since = (int) ($in['since'] ?? -1);
$sel   = $in['sel'] ?? [0, 0];

$resp = liveConEstado($estadoArchivo, $abs, function (array &$estado) use ($since, $sel, $uid, $nombre) {
    podarUsuarios($estado);
    asentarUsuario($estado, $uid, $nombre, $sel, min($since, (int) $estado['version']));

    $minv = (int) $estado['version'] - count($estado['ops']);

    if ($since < $minv) {
        return [
            'ok'      => true,
            'reset'   => true,
            'version' => (int) $estado['version'],
            'content' => (string) $estado['content'],
            'ops'     => $estado['ops'],
            'users'   => $estado['users'],
            'uid'     => $uid,
        ];
    }

    $ops = [];
    foreach ($estado['ops'] as $op) {
        if ((int) $op['v'] > $since) { $ops[] = $op; }
    }

    return [
        'ok'      => true,
        'version' => (int) $estado['version'],
        'ops'     => $ops,
        'users'   => $estado['users'],
        'uid'     => $uid,
    ];
});

echo json_encode($resp, JSON_UNESCAPED_UNICODE);
