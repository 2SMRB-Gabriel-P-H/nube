<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const PERM_LECTURA   = 1;
const PERM_ESCRITURA = 2;
const PERM_EDICION   = 3;

function permisoEtiqueta(int $p): string
{
    return $p >= PERM_EDICION ? 'Edición completa' : 'Lectura';
}

function permisoDescripcion(int $p): string
{
    return $p >= PERM_EDICION
        ? 'Ver, descargar, gestionar archivos y editar el contenido de los documentos.'
        : 'Solo ver y descargar.';
}

function usuarioPorLogin(string $q): ?array
{
    $q = trim($q);
    if ($q === '') {
        return null;
    }
    $st = bd()->prepare('SELECT id, usuario, email FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1');
    $st->execute([$q, strtolower($q)]);
    $u = $st->fetch();
    return $u ?: null;
}

function usuarioPorId(int $id): ?array
{
    $st = bd()->prepare('SELECT id, usuario, email, es_admin FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

/**
 * Nivel de permiso del usuario $acceso sobre $rel del espacio $propietario.
 * 0 = sin acceso, 3 = propietario / control total.
 */
function permisoEfectivo(int $propietario, string $rel, int $acceso): int
{
    if ($acceso <= 0) {
        return 0;
    }
    if ($acceso === $propietario) {
        return PERM_EDICION;
    }
    $rel = relClean($rel);
    $st = bd()->prepare('SELECT ruta, permiso FROM compartidos WHERE propietario_id = ? AND invitado_id = ?');
    $st->execute([$propietario, $acceso]);
    $mejor = 0;
    $largo = -1;
    foreach ($st->fetchAll() as $s) {
        $r = relClean((string) $s['ruta']);
        $coincide = ($rel === $r) || ($r !== '' && str_starts_with($rel, $r . '/'));
        if (!$coincide) {
            continue;
        }
        $l = strlen($r);
        if ($l > $largo) {
            $largo = $l;
            $mejor = (int) $s['permiso'];
        } elseif ($l === $largo) {
            $mejor = max($mejor, (int) $s['permiso']);
        }
    }
    return $mejor;
}

/** Ruta compartida (la más específica) que cubre $rel, o null si no hay acceso. */
function raizCompartida(int $propietario, string $rel, int $acceso): ?string
{
    if ($acceso === $propietario) {
        return '';
    }
    $rel = relClean($rel);
    $st = bd()->prepare('SELECT ruta FROM compartidos WHERE propietario_id = ? AND invitado_id = ?');
    $st->execute([$propietario, $acceso]);
    $mejor = null;
    $largo = -1;
    foreach ($st->fetchAll() as $s) {
        $r = relClean((string) $s['ruta']);
        if ($rel === $r || ($r !== '' && str_starts_with($rel, $r . '/'))) {
            $l = strlen($r);
            if ($l > $largo) {
                $largo = $l;
                $mejor = $r;
            }
        }
    }
    return $mejor;
}

function compartirCon(int $propietario, string $rel, int $invitado, int $permiso): bool
{
    $rel = relClean($rel);
    if ($rel === '' || $invitado <= 0 || $invitado === $propietario) {
        return false;
    }
    $permiso = $permiso >= PERM_EDICION ? PERM_EDICION : PERM_LECTURA;
    $st = bd()->prepare(
        'INSERT INTO compartidos (propietario_id, ruta, ruta_hash, invitado_id, permiso)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE permiso = VALUES(permiso), ruta = VALUES(ruta)'
    );
    return $st->execute([$propietario, $rel, md5($rel), $invitado, $permiso]);
}

function quitarCompartido(int $propietario, string $rel, int $invitado): void
{
    $st = bd()->prepare('DELETE FROM compartidos WHERE propietario_id = ? AND invitado_id = ? AND ruta_hash = ?');
    $st->execute([$propietario, $invitado, md5(relClean($rel))]);
}

function compartidosDe(int $propietario, string $rel): array
{
    $st = bd()->prepare(
        'SELECT c.id, c.invitado_id, c.permiso, c.creado_en, u.usuario, u.email
         FROM compartidos c JOIN usuarios u ON u.id = c.invitado_id
         WHERE c.propietario_id = ? AND c.ruta_hash = ?
         ORDER BY u.usuario'
    );
    $st->execute([$propietario, md5(relClean($rel))]);
    return $st->fetchAll();
}

function compartidosConmigo(int $acceso): array
{
    $st = bd()->prepare(
        'SELECT c.id, c.propietario_id, c.ruta, c.permiso, c.creado_en, u.usuario AS propietario
         FROM compartidos c JOIN usuarios u ON u.id = c.propietario_id
         WHERE c.invitado_id = ?
         ORDER BY c.creado_en DESC'
    );
    $st->execute([$acceso]);
    return $st->fetchAll();
}

/** Compartidos (propios) que cubren $rel o sus descendientes. */
function sharesAfectados(int $propietario, string $rel): array
{
    $rel = relClean($rel);
    $st = bd()->prepare('SELECT id, ruta FROM compartidos WHERE propietario_id = ?');
    $st->execute([$propietario]);
    $out = [];
    foreach ($st->fetchAll() as $s) {
        $r = relClean((string) $s['ruta']);
        if ($r === $rel || str_starts_with($r, $rel . '/')) {
            $out[] = $s;
        }
    }
    return $out;
}

function eliminarSharesDe(int $propietario, string $rel): void
{
    foreach (sharesAfectados($propietario, $rel) as $s) {
        bd()->prepare('DELETE FROM compartidos WHERE id = ?')->execute([$s['id']]);
    }
}

function renombrarSharesDe(int $propietario, string $viejo, string $nuevo): void
{
    $viejo = relClean($viejo);
    $nuevo = relClean($nuevo);
    foreach (sharesAfectados($propietario, $viejo) as $s) {
        $r = relClean((string) $s['ruta']);
        $sufijo = substr($r, strlen($viejo));
        $nueva = $nuevo . $sufijo;
        bd()->prepare('UPDATE compartidos SET ruta = ?, ruta_hash = ? WHERE id = ?')
            ->execute([$nueva, md5($nueva), $s['id']]);
    }
}
