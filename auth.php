<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $seguro,
    ]);
    session_start();
}

function csrfToken(): string
{
    iniciarSesion();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function csrfOk(?string $token): bool
{
    iniciarSesion();
    return !empty($_SESSION['csrf'])
        && is_string($token)
        && hash_equals((string) $_SESSION['csrf'], $token);
}

function usuarioActual(): ?array
{
    iniciarSesion();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = bd()->prepare('SELECT id, usuario, email, creado_en, es_admin FROM usuarios WHERE id = ?');
    $st->execute([(int) $_SESSION['uid']]);
    $u = $st->fetch();
    return $u ?: null;
}

function requiereLogin(): array
{
    $u = usuarioActual();
    if ($u === null) {
        $destino = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . rawurlencode($destino));
        exit;
    }
    return $u;
}

function requiereAdmin(): array
{
    $u = requiereLogin();
    if (empty($u['es_admin'])) {
        header('Location: index.php');
        exit;
    }
    return $u;
}

/** ¿El usuario es administrador de esta instalación? */
function esAdmin(?array $u = null): bool
{
    $u ??= usuarioActual();
    return $u !== null && !empty($u['es_admin']);
}

/**
 * Cambia la contraseña de un usuario. La puede usar el propio usuario (con su
 * contraseña actual) o un administrador (sin pedir la actual).
 *
 * @return array{ok:bool,error?:string}
 */
function cambiarPassword(int $uid, string $nueva, ?string $actual = null, bool $porAdmin = false): array
{
    if (strlen($nueva) < 6) {
        return ['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.'];
    }
    $st = bd()->prepare('SELECT id, hash FROM usuarios WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) {
        return ['ok' => false, 'error' => 'El usuario no existe.'];
    }
    if (!$porAdmin) {
        if ($actual === null || !password_verify($actual, (string) $u['hash'])) {
            return ['ok' => false, 'error' => 'La contraseña actual no es correcta.'];
        }
    }
    $st = bd()->prepare('UPDATE usuarios SET hash = ? WHERE id = ?');
    $st->execute([password_hash($nueva, PASSWORD_DEFAULT), $uid]);
    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function registrar(string $usuario, string $email, string $pass): array
{
    $usuario = trim($usuario);
    $email   = strtolower(trim($email));

    if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario)) {
        return ['ok' => false, 'error' => 'El usuario debe tener entre 3 y 30 caracteres (letras, números, punto, guion o guion bajo).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        return ['ok' => false, 'error' => 'Correo electrónico no válido.'];
    }
    if (strlen($pass) < 6) {
        return ['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.'];
    }

    $pdo = bd();
    $st = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1');
    $st->execute([$usuario, $email]);
    if ($st->fetch()) {
        return ['ok' => false, 'error' => 'Ya existe un usuario con ese nombre o correo.'];
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
        $st = $pdo->prepare('INSERT INTO usuarios (usuario, email, hash) VALUES (?, ?, ?)');
        $st->execute([$usuario, $email, $hash]);
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'No se pudo crear el usuario.'];
    }

    $id = (int) $pdo->lastInsertId();
    prepararEspacio($id);
    return ['ok' => true, 'id' => $id];
}

function entrar(string $login, string $pass): bool
{
    $login = trim($login);
    $st = bd()->prepare('SELECT id, hash FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1');
    $st->execute([$login, strtolower($login)]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, (string) $u['hash'])) {
        return false;
    }
    iniciarSesion();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    return true;
}

function salir(): void
{
    iniciarSesion();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) ($p['secure'] ?? false), (bool) ($p['httponly'] ?? false));
    }
    session_destroy();
}

/**
 * Nombre de la carpeta del espacio de un usuario: usuario-email.
 * Si el usuario o el correo cambian, renombra la carpeta automáticamente.
 */
function carpetaUsuario(int $uid): string
{
    static $cache = [];

    if ($uid <= 0) {
        return '0';
    }
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }

    $raiz = __DIR__ . '/files';
    $nombre = (string) $uid;
    $guardada = '';

    try {
        $st = bd()->prepare('SELECT usuario, email, carpeta FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $u = $st->fetch();
        if ($u) {
            $base = trim((string) $u['usuario']) . '-' . trim((string) $u['email']);
            $base = (string) preg_replace('/[^A-Za-z0-9._@-]/', '_', $base);
            $base = trim($base, '.');
            if ($base !== '') {
                $nombre = $base;
            }
            $guardada = (string) ($u['carpeta'] ?? '');
        }
    } catch (Throwable $e) {
        // Si falla la consulta se conserva el id numérico como respaldo.
    }

    $destino = $raiz . '/' . $nombre;

    $origen = null;
    if ($guardada !== '' && $guardada !== $nombre && is_dir($raiz . '/' . $guardada)) {
        $origen = $raiz . '/' . $guardada;
    } elseif (is_dir($raiz . '/' . (string) $uid)) {
        $origen = $raiz . '/' . (string) $uid;
    }

    if ($origen !== null && $origen !== $destino && !is_dir($destino)) {
        @rename($origen, $destino);
    }

    if (!is_dir($destino)) {
        @mkdir($destino, 0777, true);
    }
    @chmod($destino, 0777);

    if ($guardada !== $nombre) {
        try {
            $st = bd()->prepare('UPDATE usuarios SET carpeta = ? WHERE id = ?');
            $st->execute([$nombre, $uid]);
        } catch (Throwable $e) {
            // Sin la columna no se recuerda el renombrado, pero la app sigue igual.
        }
    }

    return $cache[$uid] = $nombre;
}

function prepararEspacio(int $uid): void
{
    $dir = __DIR__ . '/files/' . carpetaUsuario($uid);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
}
