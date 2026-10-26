<?php
declare(strict_types=1);

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

function bd(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = defined('NUBE_DB_HOST') ? NUBE_DB_HOST : (getenv('NUBE_DB_HOST') ?: '127.0.0.1');
    $port = defined('NUBE_DB_PORT') ? NUBE_DB_PORT : (getenv('NUBE_DB_PORT') ?: '3306');
    $user = defined('NUBE_DB_USER') ? NUBE_DB_USER : (getenv('NUBE_DB_USER') ?: 'root');
    $pass = defined('NUBE_DB_PASS') ? NUBE_DB_PASS : (getenv('NUBE_DB_PASS') ?: '');
    $name = defined('NUBE_DB_NAME') ? NUBE_DB_NAME : (getenv('NUBE_DB_NAME') ?: 'nube_privada');

    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $opciones);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$name}`");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuarios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario VARCHAR(60) NOT NULL,
            email VARCHAR(190) NOT NULL,
            hash VARCHAR(255) NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            carpeta VARCHAR(255) NOT NULL DEFAULT '',
            clave_cifrado VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY uq_usuario (usuario),
            UNIQUE KEY uq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $cols = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'carpeta'")->fetchAll();
    if (!$cols) {
        $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `carpeta` VARCHAR(255) NOT NULL DEFAULT ''");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'clave_cifrado'")->fetchAll();
    if (!$cols) {
        $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `clave_cifrado` VARCHAR(255) NOT NULL DEFAULT ''");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS compartidos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            propietario_id INT UNSIGNED NOT NULL,
            ruta VARCHAR(1024) NOT NULL,
            ruta_hash CHAR(32) NOT NULL,
            invitado_id INT UNSIGNED NOT NULL,
            permiso TINYINT UNSIGNED NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_compartido (propietario_id, invitado_id, ruta_hash),
            KEY idx_invitado (invitado_id),
            KEY idx_propietario (propietario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $cols = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'es_admin'")->fetchAll();
    if (!$cols) {
        $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `es_admin` TINYINT(1) NOT NULL DEFAULT 0");
    }

    return $pdo;
}
