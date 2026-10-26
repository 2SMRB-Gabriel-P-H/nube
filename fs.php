<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/share.php';
require_once __DIR__ . '/crypto.php';

define('USERS_ROOT', __DIR__ . '/files');
define('CACHE_ROOT', __DIR__ . '/cache');
define('NUBE_SALT', 'nub3_zevanba_2026_secreto_local');

/** Cuota por defecto por usuario: 15 GB (decimal). */
define('CUOTA_BYTES', 15000000000);

if (!is_dir(USERS_ROOT)) { @mkdir(USERS_ROOT, 0777, true); }
if (!is_dir(CACHE_ROOT)) { @mkdir(CACHE_ROOT, 0777, true); }

/* ---- identidad del usuario actual ---- */

function uidDeToken(string $token): int
{
    $partes = explode('.', $token, 2);
    if (count($partes) !== 2) {
        return 0;
    }
    $uid = (int) $partes[0];
    return $uid > 0 ? $uid : 0;
}

/** Usuario autenticado (quien realiza la acción). */
function accesoActual(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE && !empty($_COOKIE[session_name()])) {
        iniciarSesion();
    }
    return (int) ($_SESSION['uid'] ?? 0);
}

/** Dueño del espacio que se está accediendo: token > ?o= > sesión. */
function propietarioActual(): int
{
    if (!empty($_GET['t'])) {
        $u = uidDeToken((string) $_GET['t']);
        if ($u > 0) {
            return $u;
        }
    }
    $o = 0;
    if (isset($_GET['o'])) {
        $o = (int) $_GET['o'];
    } elseif (isset($_POST['o'])) {
        $o = (int) $_POST['o'];
    }
    if ($o > 0) {
        return $o;
    }
    return accesoActual();
}

$GLOBALS['NUBE_ACCESO'] = accesoActual();
$GLOBALS['NUBE_UID'] = propietarioActual();
define('NUBE_ROOT', USERS_ROOT . '/' . carpetaUsuario($GLOBALS['NUBE_UID']));
if ($GLOBALS['NUBE_UID'] > 0 && !is_dir(NUBE_ROOT)) { @mkdir(NUBE_ROOT, 0777, true); }

/** Nivel de permiso (0-3) del usuario actual sobre $rel del espacio abierto. */
function permisoEn(string $rel): int
{
    $owner = (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $acceso = (int) ($GLOBALS['NUBE_ACCESO'] ?? 0);
    if ($owner <= 0) {
        return 0;
    }
    if ($acceso === 0 || $acceso === $owner) {
        return PERM_EDICION;
    }
    return permisoEfectivo($owner, $rel, $acceso);
}

function puedeLeer(string $rel): bool { return permisoEn($rel) >= PERM_LECTURA; }
function puedeEscribir(string $rel): bool { return permisoEn($rel) >= PERM_ESCRITURA; }
function puedeEditar(string $rel): bool { return permisoEn($rel) >= PERM_EDICION; }

/** Dueño de la raíz compartida que cubre $rel (o cadena vacía si es propio). */
function raizAccesible(string $rel): string
{
    $owner = (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $acceso = (int) ($GLOBALS['NUBE_ACCESO'] ?? 0);
    if ($acceso <= 0 || $acceso === $owner) {
        return '';
    }
    $raiz = raizCompartida($owner, $rel, $acceso);
    return $raiz ?? '';
}

function denegar(): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No tienes permisos para esta acción.']);
    exit;
}

function requierePermiso(string $rel, int $minimo): void
{
    if (permisoEn($rel) < $minimo) {
        denegar();
    }
}

/** Id del dueño cuando se navega el espacio compartido de otra persona (0 si es propio). */
function ctxOwner(): int
{
    $owner  = (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $acceso = (int) ($GLOBALS['NUBE_ACCESO'] ?? 0);
    return ($owner > 0 && $acceso > 0 && $owner !== $acceso) ? $owner : 0;
}

/** Añade &o=<dueño> a una URL interna cuando corresponde. */
function enlace(string $url): string
{
    $o = ctxOwner();
    if ($o <= 0) {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'o=' . $o;
}

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('relClean')) {
    function relClean(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        $rel = (string) preg_replace('#/+#', '/', $rel);
        $rel = trim($rel, '/');
        if ($rel === '') {
            return '';
        }
        $out = [];
        foreach (explode('/', $rel) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $p;
        }
        return implode('/', $out);
    }
}

function pathOf(string $rel): string
{
    $rel = relClean($rel);
    return $rel === '' ? NUBE_ROOT : NUBE_ROOT . '/' . $rel;
}

function validName(string $name): bool
{
    return $name !== '' && $name !== '.' && $name !== '..'
        && !str_contains($name, '/') && !str_contains($name, "\0")
        && mb_strlen($name) <= 200;
}

function relOf(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $root = str_replace('\\', '/', NUBE_ROOT);
    if (str_starts_with($path, $root)) {
        return ltrim(substr($path, strlen($root)), '/');
    }
    return $path;
}

function dentroDelRoot(string $rel): bool
{
    $abs = pathOf($rel);
    if (!file_exists($abs)) {
        return true;
    }
    $real = realpath($abs);
    $root = realpath(NUBE_ROOT);
    if ($real === false || $root === false) {
        return false;
    }
    return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
}

function tokenFor(string $rel, ?int $uid = null): string
{
    $uid = $uid ?? (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $sig = substr(hash_hmac('sha256', $uid . '|' . relClean($rel), NUBE_SALT), 0, 24);
    return $uid . '.' . $sig;
}

function extDe(string $archivo): string
{
    return strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
}

function sizeHumano(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    $b = (float) $bytes;
    while ($b >= 1000 && $i < count($u) - 1) { $b /= 1000; $i++; }
    return ($i === 0 ? (string) (int) $b : number_format($b, 2)) . ' ' . $u[$i];
}

/** Convierte un valor tipo php.ini ("40M", "2G") a bytes. */
function pesoIni(string $valor): int
{
    $valor = trim($valor);
    if ($valor === '') return 0;
    $num  = (float) $valor;
    $suf  = strtolower(substr($valor, -1));
    switch ($suf) {
        case 'g': $num *= 1024 * 1024 * 1024; break;
        case 'm': $num *= 1024 * 1024; break;
        case 'k': $num *= 1024; break;
    }
    return (int) $num;
}

/** Usuarios sin cuota. */
function usuariosIlimitados(): array { return ['zevanba']; }

function usuarioIlimitado(?string $usuario): bool
{
    if ($usuario === null || $usuario === '') return false;
    return in_array(mb_strtolower($usuario), usuariosIlimitados(), true);
}

/** ¿El usuario $u tiene cuota ilimitada? */
function cuotaIlimitadaPara(array $u): bool
{
    return usuarioIlimitado((string) ($u['usuario'] ?? ''));
}

/** Cuota en bytes del dueño $uid (0 = ilimitado). */
function cuotaDe(int $uid): int
{
    $u = usuarioPorId($uid);
    if (!$u) return 0;
    if (cuotaIlimitadaPara($u)) return 0;
    return CUOTA_BYTES;
}

/** Espacio total ocupado por el usuario $uid. */
function usoEspacioDe(int $uid): int
{
    if ($uid <= 0) return 0;
    $dir = USERS_ROOT . '/' . carpetaUsuario($uid);
    if (!is_dir($dir)) return 0;
    $total = 0;
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($rit as $f) {
        if ($f->isFile()) $total += tamanoPlano($f->getPathname());
    }
    return $total;
}

/** Tamaño total (bytes) de un archivo o carpeta. */
function tamanoDe(string $path): int
{
    if (is_file($path)) return tamanoPlano($path);
    if (!is_dir($path)) return 0;
    $total = 0;
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($rit as $f) {
        if ($f->isFile()) $total += tamanoPlano($f->getPathname());
    }
    return $total;
}

/** Espacio total ocupado por todos los usuarios (itera los directorios). */
function usoTotalNube(): int
{
    $total = 0;
    if (!is_dir(USERS_ROOT)) return 0;
    foreach (scandir(USERS_ROOT) ?: [] as $carpeta) {
        if ($carpeta === '.' || $carpeta === '..') continue;
        $ruta = USERS_ROOT . '/' . $carpeta;
        if (!is_dir($ruta)) continue;
        $total += tamanoDe($ruta);
    }
    return $total;
}

/** ¿Caben $extra bytes más dentro de la cuota de $uid? */
function cabeEnCuota(int $uid, int $extra = 0): bool
{
    $cuota = cuotaDe($uid);
    if ($cuota <= 0) return true;
    return usoEspacioDe($uid) + max(0, $extra) <= $cuota;
}

/** Bytes aún disponibles en la cuota de $uid (0 = ilimitado). */
function restanteCuota(int $uid): int
{
    $cuota = cuotaDe($uid);
    if ($cuota <= 0) return 0;
    return max(0, $cuota - usoEspacioDe($uid));
}

function extImagen(): array { return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif']; }
function extVideo(): array { return ['mp4', 'webm', 'ogv', 'mov', 'm4v']; }
function extAudio(): array { return ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus']; }
function extTexto(): array { return ['txt', 'log', 'md', 'markdown', 'csv', 'tsv', 'xml', 'json', 'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx', 'php', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'sql', 'yml', 'yaml', 'ini', 'conf', 'sh', 'rb', 'go', 'rs', 'env', 'gitignore', 'htaccess'];}
function extOfficeWord(): array { return ['doc', 'docx', 'odt', 'rtf', 'txt_odt', 'fodt', 'ott', 'epub']; }
function extOfficeCell(): array { return ['xls', 'xlsx', 'ods', 'csv_ods', 'fods', 'ots']; }
function extOfficeSlide(): array { return ['ppt', 'pptx', 'odp', 'fodp', 'otp']; }
function extPdf(): array { return ['pdf']; }

function categoria(string $ext): string
{
    if (in_array($ext, extImagen(), true)) return 'imagen';
    if (in_array($ext, extVideo(), true)) return 'video';
    if (in_array($ext, extAudio(), true)) return 'audio';
    if (in_array($ext, extPdf(), true)) return 'pdf';
    if (in_array($ext, extTexto(), true)) return 'texto';
    if (in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'odt_', 'fodt', 'ott'], true)) return 'word';
    if (in_array($ext, ['xls', 'xlsx', 'ods', 'fods', 'ots'], true)) return 'cell';
    if (in_array($ext, ['ppt', 'pptx', 'odp', 'fodp', 'otp'], true)) return 'slide';
    if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'], true)) return 'comprimido';
    return 'otro';
}

function icono(string $cat): string
{
    return [
        'imagen'     => '🖼️',
        'video'      => '🎬',
        'audio'      => '🎵',
        'pdf'        => '📕',
        'texto'      => '📄',
        'word'       => '📝',
        'cell'       => '📊',
        'slide'      => '📽️',
        'comprimido' => '🗜️',
        'carpeta'    => '📁',
        'otro'       => '📦',
    ][$cat] ?? '📦';
}

function esEditableOnlyOffice(string $ext): bool
{
    return in_array($ext, array_merge(extOfficeWord(), extOfficeCell(), extOfficeSlide()), true);
}

function esEditableTexto(string $ext): bool
{
    return in_array($ext, extTexto(), true);
}

function tipoOnlyOffice(string $ext): string
{
    if (in_array($ext, ['xls', 'xlsx', 'ods', 'fods', 'ots'], true)) return 'cell';
    if (in_array($ext, ['ppt', 'pptx', 'odp', 'fodp', 'otp'], true)) return 'slide';
    return 'word';
}

function segundosATexto(int $seg): string
{
    if ($seg < 60) return 'hace ' . $seg . ' s';
    if ($seg < 3600) return 'hace ' . intdiv($seg, 60) . ' min';
    if ($seg < 86400) return 'hace ' . intdiv($seg, 3600) . ' h';
    return 'hace ' . intdiv($seg, 86400) . ' d';
}

/**
 * Lista el contenido de una carpeta relativa.
 * @return array<int,array{nombre:string,rel:string,es_dir:bool,size:int,mtime:int,ext:string}>
 */
function listar(string $rel): array
{
    $abs = pathOf($rel);
    if (!is_dir($abs)) {
        return [];
    }
    $items = [];
    foreach (scandir($abs) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ruta = $abs . '/' . $f;
        $esDir = is_dir($ruta);
        $items[] = [
            'nombre' => $f,
            'rel'    => ($rel === '' ? '' : $rel . '/') . $f,
            'es_dir' => $esDir,
            'size'   => $esDir ? 0 : tamanoPlano($ruta),
            'mtime'  => (int) filemtime($ruta),
            'ext'    => $esDir ? '' : extDe($f),
        ];
    }
    usort($items, function ($a, $b) {
        if ($a['es_dir'] !== $b['es_dir']) return $a['es_dir'] ? -1 : 1;
        return strcasecmp($a['nombre'], $b['nombre']);
    });
    return $items;
}

function rmrf(string $path): bool
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..') continue;
            rmrf($path . '/' . $f);
        }
        return @rmdir($path);
    }
    return @unlink($path);
}

function nombreUnico(string $dir, string $nombre): string
{
    $destino = $dir . '/' . $nombre;
    if (!file_exists($destino)) return $nombre;
    $info = pathinfo($nombre);
    $base = $info['filename'];
    $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i = 1;
    do {
        $candidato = $base . ' (' . $i . ')' . $ext;
        $destino = $dir . '/' . $candidato;
        $i++;
    } while (file_exists($destino));
    return $candidato;
}

function limpiarNombre(string $nombre): string
{
    $nombre = basename($nombre);
    $nombre = (string) preg_replace('/[^\p{L}\p{N}._\- ()]+/u', '_', $nombre);
    $nombre = (string) preg_replace('/\s+/', ' ', $nombre);
    $nombre = trim($nombre, '._ ');
    if ($nombre === '') $nombre = 'archivo';
    return mb_substr($nombre, 0, 180);
}

function urlDe(string $rel): string
{
    return 'raw.php?p=' . rawurlencode($rel) . '&t=' . tokenFor($rel);
}

function urlDescarga(string $rel): string
{
    return 'raw.php?p=' . rawurlencode($rel) . '&download=1&t=' . tokenFor($rel);
}
