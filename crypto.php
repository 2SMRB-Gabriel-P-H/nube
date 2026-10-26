<?php
declare(strict_types=1);

/*
 * Cifrado en reposo por usuario.
 *
 * - Cada usuario tiene una clave de datos (DEK) aleatoria de 32 bytes,
 *   guardada cifrada (envuelta) en la tabla `usuarios` con una clave
 *   maestra que vive fuera de la web y del disco de datos.
 * - El contenido de los archivos se cifra con AES-256-GCM por bloques de
 *   1 MiB, de forma que se pueda cifrar/descifrar en streaming.
 *
 * Formato: MAGIC(8) + nonce(8) + tamanoPlano(8) + bloques
 *   bloque = longitud(4, big-endian) + (cifrado + tag(16))
 *   nonce de bloque = nonce(8) + contador(4, big-endian)
 */

const NUBE_MAGIC  = 'NUBEENC1';
const NUBE_BLOQUE = 1048576;

/** Clave maestra (32 bytes). */
function claveMaestra(): string
{
    static $clave = null;
    if ($clave !== null) {
        return $clave;
    }

    $env = getenv('NUBE_MASTER_KEY');
    if (is_string($env) && $env !== '') {
        $bin = base64_decode($env, true);
        if ($bin !== false && strlen($bin) >= 32) {
            return $clave = substr($bin, 0, 32);
        }
    }

    $ruta = getenv('NUBE_MASTER_KEY_FILE') ?: '/etc/nube/nube_master.key';
    if (!is_file($ruta) || !is_readable($ruta)) {
        throw new RuntimeException('No se puede leer la clave maestra de Nube.');
    }
    $contenido = @file_get_contents($ruta);
    $bin = is_string($contenido) ? base64_decode(trim($contenido), true) : false;
    if ($bin === false || strlen($bin) < 32) {
        throw new RuntimeException('La clave maestra de Nube no tiene un formato válido.');
    }
    return $clave = substr($bin, 0, 32);
}

/** Clave de datos del usuario $uid (se genera y guarda si no existe). */
function claveUsuario(int $uid): string
{
    static $cache = [];
    if ($uid <= 0) {
        return '';
    }
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }

    $pdo = bd();
    $st = $pdo->prepare('SELECT clave_cifrado FROM usuarios WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch();
    $b64 = $row ? (string) ($row['clave_cifrado'] ?? '') : '';

    if ($b64 !== '') {
        $dek = desenvolverDato($b64, claveMaestra());
        if ($dek !== null && strlen($dek) >= 32) {
            return $cache[$uid] = substr($dek, 0, 32);
        }
        throw new RuntimeException('La clave de cifrado de este usuario no se puede validar.');
    }

    $dek = random_bytes(32);
    $st = $pdo->prepare('UPDATE usuarios SET clave_cifrado = ? WHERE id = ?');
    $st->execute([envolverDato($dek, claveMaestra()), $uid]);
    return $cache[$uid] = $dek;
}

function envolverDato(string $dato, string $clave): string
{
    $nonce = random_bytes(12);
    $tag   = '';
    $ct = openssl_encrypt($dato, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) {
        return '';
    }
    return base64_encode($nonce . $tag . $ct);
}

function desenvolverDato(string $b64, string $clave): ?string
{
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 28) {
        return null;
    }
    $nonce = substr($bin, 0, 12);
    $tag   = substr($bin, 12, 16);
    $ct    = substr($bin, 28);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $nonce, $tag);
    return $pt === false ? null : $pt;
}

/** ¿El manejador apunta a un archivo cifrado? (no cambia la posición) */
function esFlujoCifrado($fh): bool
{
    $pos = ftell($fh);
    $cab = fread($fh, 8);
    if ($pos !== false) {
        fseek($fh, $pos);
    }
    return $cab === NUBE_MAGIC;
}

function esCifradoArchivo(string $abs): bool
{
    if (!is_file($abs)) {
        return false;
    }
    $fh = @fopen($abs, 'rb');
    if ($fh === false) {
        return false;
    }
    $ok = esFlujoCifrado($fh);
    fclose($fh);
    return $ok;
}

/** Cifra el flujo $in en $out. */
function cifrarFlujo($in, $out, string $clave): bool
{
    $nonce = random_bytes(8);
    $stat  = fstat($in);
    $size  = ($stat && isset($stat['size'])) ? (int) $stat['size'] : 0;

    if (fwrite($out, NUBE_MAGIC . $nonce . pack('J', $size)) === false) {
        return false;
    }

    $i = 0;
    while (!feof($in)) {
        $bloque = fread($in, NUBE_BLOQUE);
        if ($bloque === false) {
            return false;
        }
        if ($bloque === '') {
            break;
        }
        $iv = $nonce . pack('N', $i);
        $tag = '';
        $ct = openssl_encrypt($bloque, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            return false;
        }
        $sellado = $ct . $tag;
        if (fwrite($out, pack('N', strlen($sellado)) . $sellado) === false) {
            return false;
        }
        $i++;
    }
    return true;
}

/** Descifra el flujo $in en $out. */
function descifrarFlujo($in, $out, string $clave): bool
{
    $cab = '';
    while (strlen($cab) < 24 && !feof($in)) {
        $parte = fread($in, 24 - strlen($cab));
        if ($parte === false) {
            return false;
        }
        $cab .= $parte;
    }
    if (strlen($cab) < 24 || substr($cab, 0, 8) !== NUBE_MAGIC) {
        return false;
    }
    $nonce = substr($cab, 8, 8);

    $i = 0;
    while (true) {
        $lenRaw = '';
        while (strlen($lenRaw) < 4 && !feof($in)) {
            $parte = fread($in, 4 - strlen($lenRaw));
            if ($parte === false) {
                return false;
            }
            $lenRaw .= $parte;
        }
        if ($lenRaw === '') {
            break;
        }
        if (strlen($lenRaw) < 4) {
            return false;
        }
        $len = (int) unpack('N', $lenRaw)[1];
        if ($len < 16 || $len > NUBE_BLOQUE + 4096) {
            return false;
        }

        $sellado = '';
        while (strlen($sellado) < $len && !feof($in)) {
            $parte = fread($in, $len - strlen($sellado));
            if ($parte === false) {
                return false;
            }
            $sellado .= $parte;
        }
        if (strlen($sellado) !== $len) {
            return false;
        }

        $tag = substr($sellado, -16);
        $ct  = substr($sellado, 0, -16);
        $iv  = $nonce . pack('N', $i);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            return false;
        }
        if (fwrite($out, $pt) === false) {
            return false;
        }
        $i++;
    }
    return true;
}

/** Tamaño real (en claro) de un archivo, cifrado o no. */
function tamanoPlano(string $abs): int
{
    if (!is_file($abs)) {
        return 0;
    }
    $fh = @fopen($abs, 'rb');
    if ($fh === false) {
        return 0;
    }
    $cab = fread($fh, 24);
    fclose($fh);
    if (strlen($cab) === 24 && substr($cab, 0, 8) === NUBE_MAGIC) {
        return (int) unpack('J', substr($cab, 16, 8))[1];
    }
    return (int) @filesize($abs);
}

/** Lee el contenido en claro de un archivo (cifrado o no). */
function leerArchivo(string $abs, ?int $uid = null): string
{
    if (!is_file($abs)) {
        return '';
    }
    $fh = @fopen($abs, 'rb');
    if ($fh === false) {
        return '';
    }
    if (!esFlujoCifrado($fh)) {
        $data = stream_get_contents($fh);
        fclose($fh);
        return (string) $data;
    }

    $uid   = $uid ?? (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $clave = claveUsuario($uid);
    if ($clave === '') {
        fclose($fh);
        return '';
    }

    $out = fopen('php://temp', 'w+b');
    $ok  = $out !== false && descifrarFlujo($fh, $out, $clave);
    fclose($fh);
    if (!$ok) {
        if ($out !== false) { fclose($out); }
        return '';
    }
    rewind($out);
    $data = stream_get_contents($out);
    fclose($out);
    return (string) $data;
}

/** Escribe contenido cifrado de forma atómica. */
function escribirArchivo(string $abs, string $data, ?int $uid = null): bool
{
    $uid   = $uid ?? (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $clave = claveUsuario($uid);

    $in = fopen('php://temp', 'w+b');
    fwrite($in, $data);
    rewind($in);

    $ok = false;
    if ($clave === '') {
        $tmp = $abs . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $data) !== false && @rename($tmp, $abs)) {
            $ok = true;
        } else {
            @unlink($tmp);
        }
    } else {
        $tmp = $abs . '.enc' . bin2hex(random_bytes(4));
        $out = @fopen($tmp, 'wb');
        if ($out !== false) {
            $ok = cifrarFlujo($in, $out, $clave);
            fclose($out);
            if (!$ok || !@rename($tmp, $abs)) { @unlink($tmp); $ok = false; }
        }
    }
    fclose($in);
    if ($ok) {
        @chmod($abs, 0666);
    }
    return $ok;
}

/** Copia/cifra un archivo de origen a $destino sin cargarlo en memoria. */
function cifrarArchivoOrigen(string $origen, string $destino, ?int $uid = null): bool
{
    $uid   = $uid ?? (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $clave = claveUsuario($uid);

    if ($clave === '') {
        $ok = @copy($origen, $destino);
        if ($ok) { @chmod($destino, 0666); }
        return $ok;
    }

    $in = @fopen($origen, 'rb');
    if ($in === false) {
        return false;
    }
    $tmp = $destino . '.enc' . bin2hex(random_bytes(4));
    $out = @fopen($tmp, 'wb');
    if ($out === false) {
        fclose($in);
        return false;
    }
    $ok = cifrarFlujo($in, $out, $clave);
    fclose($in);
    fclose($out);
    if (!$ok || !@rename($tmp, $destino)) {
        @unlink($tmp);
        return false;
    }
    @chmod($destino, 0666);
    return true;
}

/** Envía a la salida el contenido en claro de un archivo. */
function servirArchivo(string $abs, ?int $uid = null): void
{
    $uid = $uid ?? (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $fh  = @fopen($abs, 'rb');
    if ($fh === false) {
        return;
    }
    if (!esFlujoCifrado($fh)) {
        fclose($fh);
        readfile($abs);
        return;
    }
    $clave = claveUsuario($uid);
    $out   = fopen('php://output', 'wb');
    if ($clave !== '' && $out !== false) {
        descifrarFlujo($fh, $out, $clave);
        fclose($out);
    }
    fclose($fh);
}

/**
 * Devuelve una ruta a un archivo en claro (temp) para procesos externos
 * (LibreOffice/OnlyOffice). Si ya estaba en claro, devuelve la ruta original.
 */
function materializarPlano(string $abs, ?int $uid = null, string $ext = ''): ?string
{
    if (!is_file($abs)) {
        return null;
    }
    $uid = $uid ?? (int) ($GLOBALS['NUBE_UID'] ?? 0);
    $fh  = @fopen($abs, 'rb');
    if ($fh === false) {
        return null;
    }
    if (!esFlujoCifrado($fh)) {
        fclose($fh);
        return $abs;
    }
    $clave = claveUsuario($uid);
    if ($clave === '') {
        fclose($fh);
        return null;
    }
    $tmp = sys_get_temp_dir() . '/nube_' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
    $out = @fopen($tmp, 'wb');
    if ($out === false) {
        fclose($fh);
        return null;
    }
    $ok = descifrarFlujo($fh, $out, $clave);
    fclose($fh);
    fclose($out);
    if (!$ok) {
        @unlink($tmp);
        return null;
    }
    return $tmp;
}
