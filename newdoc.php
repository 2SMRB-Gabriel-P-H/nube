<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

requiereLogin();

function fallo(string $back, string $msg): void
{
    header('Location: ' . enlace('index.php?p=' . rawurlencode($back) . '&msg=' . rawurlencode($msg) . '&tipo=err'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$p    = relClean((string) ($_POST['p'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
$tipo = (string) ($_POST['type'] ?? 'word');

$tipo = in_array($tipo, ['word', 'cell', 'slide'], true) ? $tipo : 'word';
$back = $p;

if (!is_dir(pathOf($p)) || !dentroDelRoot($p)) fallo('', 'Carpeta no válida');
if (!puedeEditar($p)) fallo($back, 'No tienes permiso para crear documentos aquí');
if ($name === '') fallo($back, 'Falta el nombre');

$ownerDoc = (int) ($GLOBALS['NUBE_UID'] ?? 0);
if (!cabeEnCuota($ownerDoc, 2 * 1024 * 1024)) {
    fallo($back, 'Sin espacio en la cuota de almacenamiento (' . sizeHumano(cuotaDe($ownerDoc)) . ')');
}

$ext = extDe($name);
$permitidos = [
    'word'  => ['odt', 'docx', 'rtf'],
    'cell'  => ['ods', 'xlsx', 'csv'],
    'slide' => ['odp', 'pptx'],
][$tipo];
if (!in_array($ext, $permitidos, true)) {
    $ext = $permitidos[0];
    $name = pathinfo($name, PATHINFO_FILENAME) . '.' . $ext;
}
$base = pathinfo($name, PATHINFO_FILENAME);
$base = limpiarNombre($base) ?: 'Documento';
$name = $base . '.' . $ext;

$soffice = '/opt/libreoffice26.8/program/soffice';
if (!is_file($soffice)) $soffice = '/usr/bin/soffice';

$tmp     = sys_get_temp_dir();
$flatExt = ['word' => 'fodt', 'cell' => 'fods', 'slide' => 'fodp'][$tipo];
$tmpBase = 'nuevo_' . bin2hex(random_bytes(6));
$flat    = $tmp . '/' . $tmpBase . '.' . $flatExt;

$plantillas = [
    'word' => '<?xml version="1.0" encoding="UTF-8"?>
<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.text"><office:automatic-styles><style:style style:name="Titulo" style:family="paragraph"><style:text-properties fo:font-size="20pt" fo:font-weight="bold"/></style:style></office:automatic-styles><office:body><office:text><text:p text:style-name="Titulo">Nuevo documento</text:p><text:p>Empieza a escribir aquí…</text:p></office:text></office:body></office:document>',

    'cell' => '<?xml version="1.0" encoding="UTF-8"?>
<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.spreadsheet"><office:body><office:spreadsheet><table:table table:name="Hoja1"><table:table-column table:number-columns-repeated="4"/><table:table-row><table:table-cell office:value-type="string"><text:p>A1</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>B1</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>C1</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>D1</text:p></table:table-cell></table:table-row></table:table></office:spreadsheet></office:body></office:document>',

    'slide' => '<?xml version="1.0" encoding="UTF-8"?>
<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.presentation"><office:body><office:presentation><draw:page draw:name="Página 1"><draw:frame presentation:style-name="pr1" draw:layer="layout" svg:width="25cm" svg:height="4cm" svg:x="2cm" svg:y="6cm"><draw:text-box><text:p>Nueva presentación</text:p></draw:text-box></draw:frame></draw:page></office:presentation></office:body></office:document>',
];

if (@file_put_contents($flat, $plantillas[$tipo]) === false) fallo($back, 'No se pudo crear la plantilla');

$outDir = $tmp . '/' . $tmpBase . '_out';
@mkdir($outDir, 0777, true);

$cmd = 'env -u LD_LIBRARY_PATH HOME=' . escapeshellarg($tmp)
     . ' timeout 90 ' . escapeshellarg($soffice)
     . ' --headless --norestore --nologo --convert-to ' . escapeshellarg($ext)
     . ' --outdir ' . escapeshellarg($outDir) . ' '
     . '-env:UserInstallation=' . escapeshellarg('file://' . $tmp . '/' . $tmpBase . '_prof') . ' '
     . escapeshellarg($flat) . ' 2>&1';
@shell_exec($cmd);

$creado = $outDir . '/' . $tmpBase . '.' . $ext;
@shell_exec('rm -f ' . escapeshellarg($flat));
@shell_exec('rm -rf ' . escapeshellarg($tmp . '/' . $tmpBase . '_prof'));

if (!is_file($creado)) {
    @shell_exec('rm -rf ' . escapeshellarg($outDir));
    fallo($back, 'No se pudo generar el documento de Office');
}

$destDir = pathOf($p);
$name    = nombreUnico($destDir, $name);
$destino = $destDir . '/' . $name;
if (!cifrarArchivoOrigen($creado, $destino, $ownerDoc)) {
    @unlink($creado);
    @shell_exec('rm -rf ' . escapeshellarg($outDir));
    fallo($back, 'No se pudo guardar el documento');
}
@unlink($creado);
@shell_exec('rm -rf ' . escapeshellarg($outDir));
@chmod($destino, 0666);

header('Location: ' . enlace('office.php?p=' . rawurlencode(relOf($destino))));
exit;
