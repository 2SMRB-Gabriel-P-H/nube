<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

/**
 * Convierte un documento de Office a PDF (cacheado) y devuelve la ruta relativa
 * del PDF dentro de cache/, o null si falla.
 */
function convertirAPdf(string $abs, string $rel): ?string
{
    if (!is_file($abs)) return null;

    $clave   = md5(propietarioActual() . '|' . $rel . '|' . tamanoPlano($abs) . '|' . filemtime($abs));
    $pdfName = 'conv_' . $clave . '.pdf';
    $pdfPath = CACHE_ROOT . '/' . $pdfName;

    if (is_file($pdfPath)) {
        return 'cache/' . rawurlencode($pdfName);
    }

    $soffice = '/opt/libreoffice26.8/program/soffice';
    if (!is_file($soffice)) {
        $soffice = '/usr/bin/soffice';
    }

    $plano = materializarPlano($abs, null, extDe($abs));
    if ($plano === null) {
        return null;
    }
    $baseEntrada = pathinfo($plano, PATHINFO_FILENAME);

    $tmp     = sys_get_temp_dir();
    $perfil  = $tmp . '/lo_profile_' . $clave;
    $outDir  = $tmp . '/lo_out_' . $clave;
    @mkdir($outDir, 0777, true);

    $cmd = 'env -u LD_LIBRARY_PATH HOME=' . escapeshellarg($tmp)
         . ' timeout 120 ' . escapeshellarg($soffice)
         . ' --headless --norestore --nologo --convert-to pdf '
         . '--outdir ' . escapeshellarg($outDir) . ' '
         . '-env:UserInstallation=' . escapeshellarg('file://' . $perfil) . ' '
         . escapeshellarg($plano) . ' 2>&1';

    @shell_exec($cmd);

    $generado = $outDir . '/' . $baseEntrada . '.pdf';
    $ok = false;
    if (is_file($generado)) {
        $ok = @rename($generado, $pdfPath);
    }
    @shell_exec('rm -rf ' . escapeshellarg($perfil) . ' ' . escapeshellarg($outDir));
    if ($plano !== $abs) {
        @unlink($plano);
    }

    return $ok && is_file($pdfPath) ? 'cache/' . rawurlencode($pdfName) : null;
}
