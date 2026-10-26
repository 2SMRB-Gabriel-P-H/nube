-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 26-10-2026 a las 15:21:47
-- Versión del servidor: 10.11.16-MariaDB
-- Versión de PHP: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `nube`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compartidos`
--

CREATE TABLE `compartidos` (
  `id` int(10) UNSIGNED NOT NULL,
  `propietario_id` int(10) UNSIGNED NOT NULL,
  `ruta` varchar(1024) NOT NULL,
  `ruta_hash` char(32) NOT NULL,
  `invitado_id` int(10) UNSIGNED NOT NULL,
  `permiso` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `enlaces_publicos`
--

CREATE TABLE `enlaces_publicos` (
  `id` int(10) UNSIGNED NOT NULL,
  `propietario_uid` int(10) UNSIGNED NOT NULL,
  `ruta_relativa` varchar(1024) NOT NULL,
  `es_carpeta` tinyint(1) DEFAULT 0,
  `token` char(64) NOT NULL,
  `pass_hash` varchar(255) DEFAULT NULL,
  `expira_en` int(10) UNSIGNED DEFAULT NULL,
  `max_descargas` int(10) UNSIGNED DEFAULT NULL,
  `descargas` int(10) UNSIGNED DEFAULT 0,
  `permitir_vista` tinyint(1) DEFAULT 1,
  `permitir_descarga` tinyint(1) DEFAULT 1,
  `revocado` tinyint(1) DEFAULT 0,
  `creado_en` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL,
  `usuario` varchar(60) NOT NULL,
  `email` varchar(190) NOT NULL,
  `hash` varchar(255) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `carpeta` varchar(255) NOT NULL DEFAULT '',
  `clave_cifrado` varchar(255) NOT NULL DEFAULT '',
  `es_admin` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `usuario`, `email`, `hash`, `creado_en`, `carpeta`, `clave_cifrado`, `es_admin`) VALUES
(2, 'admin', 'admin@nube.local', '$2y$12$c3lhsiqidDUesDw702Oar.1E7CIbBhcoz7zSG0nhULp/ntxUig8l2', '2026-10-20 15:44:53', 'admin-admin@nube.local', '', 1);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `compartidos`
--
ALTER TABLE `compartidos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_compartido` (`propietario_id`,`invitado_id`,`ruta_hash`),
  ADD KEY `idx_invitado` (`invitado_id`),
  ADD KEY `idx_propietario` (`propietario_id`);

--
-- Indices de la tabla `enlaces_publicos`
--
ALTER TABLE `enlaces_publicos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_prop` (`propietario_uid`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuario` (`usuario`),
  ADD UNIQUE KEY `uq_email` (`email`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `compartidos`
--
ALTER TABLE `compartidos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `enlaces_publicos`
--
ALTER TABLE `enlaces_publicos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
