<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

requiereLogin();

function volver(string $rel, string $msg, string $tipo = 'ok'): void
{
    header('Location: ' . enlace('index.php?p=' . rawurlencode($rel) . '&msg=' . rawurlencode($msg) . '&tipo=' . $tipo));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

if (!csrfOk($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
}

$accion = (string) ($_POST['action'] ?? '');
$rel    = relClean((string) ($_POST['p'] ?? ''));
$back   = relClean((string) ($_POST['back'] ?? ''));
$dest   = relClean((string) ($_POST['dest'] ?? ''));
$name   = trim((string) ($_POST['name'] ?? ''));

$lista = [];
if (isset($_POST['items']) && is_array($_POST['items'])) {
    foreach ($_POST['items'] as $it) {
        $lista[] = relClean((string) $it);
    }
} elseif ($rel !== '') {
    $lista[] = $rel;
}

if ($back === '' && $rel !== '') {
    $back = dirname($rel);
    if ($back === '.') $back = '';
}
$back = relClean($back);

switch ($accion) {

    case 'mkdir':
        if (!validName($name)) volver($back, 'Nombre de carpeta no válido', 'err');
        if (!is_dir(pathOf($rel)) || !dentroDelRoot($rel)) volver($back, 'Carpeta de destino no válida', 'err');
        if (!puedeEscribir($rel)) volver($back, 'No tienes permiso para crear carpetas aquí', 'err');
        $destino = pathOf(($rel === '' ? '' : $rel . '/') . $name);
        if (file_exists($destino)) volver($back, 'Ya existe "' . $name . '"', 'err');
        if (@mkdir($destino, 0777, true)) {
            @chmod($destino, 0777);
            volver($rel, 'Carpeta "' . $name . '" creada');
        }
        volver($back, 'No se pudo crear la carpeta', 'err');

    case 'newfile':
        if (!validName($name)) volver($back, 'Nombre no válido', 'err');
        if (!is_dir(pathOf($rel)) || !dentroDelRoot($rel)) volver($back, 'Carpeta de destino no válida', 'err');
        if (!puedeEditar($rel)) volver($back, 'No tienes permiso para crear archivos aquí', 'err');
        $nombreFinal = str_contains($name, '.') ? $name : $name . '.txt';
        $destino = pathOf(($rel === '' ? '' : $rel . '/') . $nombreFinal);
        if (file_exists($destino)) volver($back, 'Ya existe "' . $nombreFinal . '"', 'err');
        if (escribirArchivo($destino, '', (int) ($GLOBALS['NUBE_UID'] ?? 0))) {
            @chmod($destino, 0666);
            header('Location: ' . enlace('textedit.php?p=' . rawurlencode(relOf($destino))));
            exit;
        }
        volver($back, 'No se pudo crear el archivo', 'err');

    case 'rename':
        if ($rel === '') volver('', 'No se puede renombrar la raíz', 'err');
        if (!validName($name)) volver($back, 'Nombre no válido', 'err');
        if (!puedeEscribir($rel)) volver($back, 'No tienes permiso para renombrar este elemento', 'err');
        $abs = pathOf($rel);
        if (!file_exists($abs) || !dentroDelRoot($rel)) volver($back, 'No existe el elemento o la ruta no es válida', 'err');
        $destino = dirname($abs) . '/' . $name;
        if (file_exists($destino) && realpath($destino) !== realpath($abs)) {
            volver($back, 'Ya existe "' . $name . '"', 'err');
        }
        if (@rename($abs, $destino)) {
            renombrarSharesDe((int) $GLOBALS['NUBE_UID'], $rel, relOf($destino));
            volver($back, 'Renombrado a "' . $name . '"');
        }
        volver($back, 'No se pudo renombrar', 'err');

    case 'delete':
        if (!$lista) volver($back, 'Nada que eliminar', 'err');
        $hechos = 0;
        foreach ($lista as $r) {
            if ($r === '') continue;
            if (!puedeEscribir($r)) continue;
            $a = pathOf($r);
            if (file_exists($a) && dentroDelRoot($r) && rmrf($a)) { eliminarSharesDe((int) $GLOBALS['NUBE_UID'], $r); $hechos++; }
        }
        volver($back, $hechos . ' elemento(s) eliminado(s)', $hechos ? 'ok' : 'err');

    case 'move':
    case 'copy':
        if (!$lista) volver($back, 'Nada que procesar', 'err');
        $destDir = pathOf($dest);
        if (!is_dir($destDir) || !dentroDelRoot($dest)) volver($back, 'Carpeta destino no válida', 'err');
        if (!puedeEscribir($dest)) volver($back, 'No tienes permiso en la carpeta destino', 'err');
        $ownerA = (int) ($GLOBALS['NUBE_UID'] ?? 0);
        $cuotaA = ($accion === 'copy') ? cuotaDe($ownerA) : 0;
        $usadoA = $cuotaA > 0 ? usoEspacioDe($ownerA) : 0;
        $hechos = 0;
        $omitidos = 0;
        foreach ($lista as $r) {
            if ($r === '') continue;
            if (!puedeEscribir($r)) { $omitidos++; continue; }
            $a = pathOf($r);
            if (!file_exists($a)) continue;
            if (!dentroDelRoot($r)) { $omitidos++; continue; }
            if (is_dir($a)) {
                $realAbs = realpath($a);
                $realDest = realpath($destDir);
                if ($realAbs !== false && $realDest !== false && str_starts_with($realDest . '/', $realAbs . '/')) { $omitidos++; continue; }
            }
            $tamItem = 0;
            if ($cuotaA > 0) {
                $tamItem = tamanoDe($a);
                if ($usadoA + $tamItem > $cuotaA) { $omitidos++; continue; }
            }
            $nombre    = basename($a);
            $nombreFin = nombreUnico($destDir, $nombre);
            $destino   = $destDir . '/' . $nombreFin;
            $ok = $accion === 'move' ? @rename($a, $destino) : copiarRec($a, $destino);
            if ($ok) {
                if ($accion === 'move') renombrarSharesDe((int) $GLOBALS['NUBE_UID'], $r, relOf($destino));
                else $usadoA += $tamItem;
                $hechos++;
            } else $omitidos++;
        }
        if ($accion === 'move') {
            if ($hechos && !$omitidos) volver($back, 'Movido(s) ' . $hechos . ' elemento(s)');
            elseif ($hechos) volver($back, $hechos . ' movido(s), ' . $omitidos . ' no se pudieron mover', 'err');
            else volver($back, 'No se pudo mover', 'err');
        } else {
            if ($hechos && !$omitidos) volver($back, 'Copiado(s) ' . $hechos . ' elemento(s)');
            elseif ($hechos) volver($back, $hechos . ' copiado(s), ' . $omitidos . ' no se pudieron copiar', 'err');
            else volver($back, 'No se pudo copiar', 'err');
        }

    default:
        volver($back, 'Acción desconocida', 'err');
}

function copiarRec(string $origen, string $destino): bool
{
    if (is_link($origen)) return false;
    if (is_dir($origen)) {
        if (!@mkdir($destino, 0777, true)) return false;
        @chmod($destino, 0777);
        $entradas = @scandir($origen);
        if ($entradas === false) { rmrf($destino); return false; }
        foreach ($entradas as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!copiarRec($origen . '/' . $f, $destino . '/' . $f)) {
                rmrf($destino);
                return false;
            }
        }
        return true;
    }
    if (@copy($origen, $destino)) { @chmod($destino, 0666); return true; }
    @unlink($destino);
    return false;
}
