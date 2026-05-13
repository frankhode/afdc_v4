-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 14-05-2026 a las 00:31:21
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `archivocronica`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `areas`
--

CREATE TABLE `areas` (
  `sys` varchar(9) DEFAULT NULL,
  `area` varchar(7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `campeonatos_import`
--

CREATE TABLE `campeonatos_import` (
  `id` int(10) UNSIGNED NOT NULL,
  `titulo_fuente` varchar(255) NOT NULL DEFAULT '',
  `temporada_detectada` varchar(20) DEFAULT NULL,
  `estado` varchar(50) NOT NULL DEFAULT 'parseado',
  `fuente_tipo` varchar(30) NOT NULL DEFAULT 'text',
  `fuente_url` varchar(500) DEFAULT NULL,
  `texto_crudo` longtext DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `campeonatos_import_matches`
--

CREATE TABLE `campeonatos_import_matches` (
  `id` int(10) UNSIGNED NOT NULL,
  `import_id` int(10) UNSIGNED NOT NULL,
  `nodo_id` int(10) UNSIGNED DEFAULT NULL,
  `nodo_label` varchar(255) DEFAULT NULL,
  `nodo_tipo` varchar(50) DEFAULT NULL,
  `nodo_subtipo` varchar(50) DEFAULT NULL,
  `local_texto` varchar(190) NOT NULL,
  `goles_local` int(11) DEFAULT NULL,
  `goles_visitante` int(11) DEFAULT NULL,
  `visitante_texto` varchar(190) NOT NULL,
  `fuente_linea` text DEFAULT NULL,
  `home_team_raw` varchar(190) DEFAULT NULL,
  `home_team_normalized` varchar(190) DEFAULT NULL,
  `home_team_canonical` varchar(190) DEFAULT NULL,
  `home_team_match_status` varchar(40) DEFAULT NULL,
  `away_team_raw` varchar(190) DEFAULT NULL,
  `away_team_normalized` varchar(190) DEFAULT NULL,
  `away_team_canonical` varchar(190) DEFAULT NULL,
  `away_team_match_status` varchar(40) DEFAULT NULL,
  `goal_text_raw` text DEFAULT NULL,
  `goal_events_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `campeonatos_import_nodos`
--

CREATE TABLE `campeonatos_import_nodos` (
  `id` int(10) UNSIGNED NOT NULL,
  `import_id` int(10) UNSIGNED NOT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `tipo` varchar(50) NOT NULL,
  `subtipo` varchar(50) DEFAULT NULL,
  `label` varchar(255) NOT NULL DEFAULT '',
  `texto_original` text DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `line_start` int(11) DEFAULT NULL,
  `line_end` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `carritousuario`
--

CREATE TABLE `carritousuario` (
  `usuario` varchar(250) NOT NULL,
  `imagen` varchar(500) NOT NULL,
  `fecha` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cmp_entidades`
--

CREATE TABLE `cmp_entidades` (
  `id` int(10) UNSIGNED NOT NULL,
  `nombre_oficial` varchar(180) NOT NULL,
  `nombre_mostrable` varchar(180) NOT NULL,
  `nombre_normalizado` varchar(180) NOT NULL,
  `tipo` enum('club','seleccion','combinado') NOT NULL DEFAULT 'club',
  `pais` varchar(120) DEFAULT NULL,
  `ciudad` varchar(120) DEFAULT NULL,
  `provincia_estado` varchar(120) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cmp_entidades_alias`
--

CREATE TABLE `cmp_entidades_alias` (
  `id` int(10) UNSIGNED NOT NULL,
  `entidad_id` int(10) UNSIGNED NOT NULL,
  `alias` varchar(180) NOT NULL,
  `alias_normalizado` varchar(180) NOT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `origen` enum('manual','rsssf','migracion','detected') NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cmp_importaciones`
--

CREATE TABLE `cmp_importaciones` (
  `id` int(10) UNSIGNED NOT NULL,
  `fuente_tipo` varchar(20) NOT NULL,
  `fuente_url` varchar(500) DEFAULT NULL,
  `titulo_fuente` varchar(255) NOT NULL,
  `temporada_detectada` int(11) DEFAULT NULL,
  `estado` varchar(30) NOT NULL DEFAULT 'capturado',
  `texto_crudo` mediumtext DEFAULT NULL,
  `tree_json` mediumtext DEFAULT NULL,
  `creado_en` datetime NOT NULL,
  `actualizado_en` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cmp_importacion_goles`
--

CREATE TABLE `cmp_importacion_goles` (
  `id` int(10) UNSIGNED NOT NULL,
  `importacion_id` int(10) UNSIGNED NOT NULL,
  `partido_id` int(10) UNSIGNED NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `team_side` varchar(20) NOT NULL DEFAULT 'desconocido',
  `team_name` varchar(190) DEFAULT NULL,
  `jugador_raw` varchar(190) NOT NULL,
  `jugador_normalizado` varchar(190) DEFAULT NULL,
  `minuto` int(11) DEFAULT NULL,
  `goal_type` varchar(30) NOT NULL DEFAULT 'normal',
  `raw_fragment` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cmp_importacion_nodos`
--

CREATE TABLE `cmp_importacion_nodos` (
  `id` int(10) UNSIGNED NOT NULL,
  `importacion_id` int(10) UNSIGNED NOT NULL,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `tipo` varchar(30) NOT NULL,
  `subtipo` varchar(30) DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `nivel` int(11) NOT NULL DEFAULT 0,
  `texto_original` mediumtext DEFAULT NULL,
  `meta_json` mediumtext DEFAULT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL,
  `actualizado_en` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cmp_importacion_partidos`
--

CREATE TABLE `cmp_importacion_partidos` (
  `id` int(10) UNSIGNED NOT NULL,
  `importacion_id` int(10) UNSIGNED NOT NULL,
  `nodo_id` int(10) UNSIGNED NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `local_texto` varchar(255) NOT NULL,
  `local_entidad_id` int(10) UNSIGNED DEFAULT NULL,
  `local_normalizado` varchar(180) DEFAULT NULL,
  `visitante_texto` varchar(255) NOT NULL,
  `visitante_entidad_id` int(10) UNSIGNED DEFAULT NULL,
  `visitante_normalizado` varchar(180) DEFAULT NULL,
  `goles_local` int(11) DEFAULT NULL,
  `goles_visitante` int(11) DEFAULT NULL,
  `fuente_linea` varchar(1000) DEFAULT NULL,
  `meta_json` mediumtext DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'activo',
  `observacion_manual` varchar(1000) DEFAULT NULL,
  `is_manual_edit` tinyint(1) NOT NULL DEFAULT 0,
  `nodo_id_origen` int(10) UNSIGNED DEFAULT NULL,
  `creado_en` datetime NOT NULL,
  `actualizado_en` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cmp_importacion_partido_vinculos`
--

CREATE TABLE `cmp_importacion_partido_vinculos` (
  `id` int(10) UNSIGNED NOT NULL,
  `importacion_id` int(10) UNSIGNED NOT NULL,
  `importacion_partido_id` int(10) UNSIGNED NOT NULL,
  `partido_barcode` varchar(8) NOT NULL,
  `tituloReg` varchar(250) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'propuesto',
  `score` int(11) NOT NULL DEFAULT 0,
  `es_localia_invertida` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_importada` varchar(8) DEFAULT NULL,
  `fecha_validada` varchar(8) DEFAULT NULL,
  `fecha_coincide` tinyint(1) NOT NULL DEFAULT 0,
  `equipo1_validado` varchar(250) DEFAULT NULL,
  `equipo2_validado` varchar(250) DEFAULT NULL,
  `cancha_validada` varchar(250) DEFAULT NULL,
  `origen` enum('automatico','manual_drag','manual_boton') NOT NULL DEFAULT 'manual_boton',
  `observacion` varchar(500) DEFAULT NULL,
  `meta_json` mediumtext DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `colecciones`
--

CREATE TABLE `colecciones` (
  `nombramiento` varchar(250) DEFAULT NULL,
  `coleccion` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `collections_v2`
--

CREATE TABLE `collections_v2` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `owner_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `is_curated` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `collection_items_v2`
--

CREATE TABLE `collection_items_v2` (
  `collection_id` bigint(20) UNSIGNED NOT NULL,
  `item_type` enum('foto','recorte') NOT NULL DEFAULT 'foto',
  `item_key` varchar(190) DEFAULT NULL,
  `image_key` varchar(64) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `added_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `conjuntos`
--

CREATE TABLE `conjuntos` (
  `idConj` int(11) NOT NULL,
  `titulo` varchar(100) DEFAULT NULL,
  `barcode` varchar(8) DEFAULT NULL,
  `status` varchar(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `conservaciondigi`
--

CREATE TABLE `conservaciondigi` (
  `fecha` varchar(100) DEFAULT NULL,
  `agente` varchar(100) DEFAULT NULL,
  `barcode` varchar(8) DEFAULT NULL,
  `estadodelsobre` varchar(1) DEFAULT NULL,
  `deterioros` varchar(250) DEFAULT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `formato` varchar(10) DEFAULT NULL,
  `polaridad` varchar(100) DEFAULT NULL,
  `proceso` varchar(100) DEFAULT NULL,
  `cantidadtiras` int(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `cantidadfotogramas` int(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `descriptoresimagenes`
--

CREATE TABLE `descriptoresimagenes` (
  `nombramiento` varchar(250) DEFAULT NULL,
  `descriptor` varchar(250) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `digitales`
--

CREATE TABLE `digitales` (
  `nombramiento` varchar(50) DEFAULT NULL,
  `inv` varchar(8) DEFAULT NULL,
  `cajon` varchar(100) DEFAULT NULL,
  `carpeta` varchar(250) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `edicionimpresa`
--

CREATE TABLE `edicionimpresa` (
  `barcode` varchar(50) NOT NULL,
  `fechaIso` varchar(8) DEFAULT NULL,
  `dia` varchar(2) DEFAULT NULL,
  `mes` varchar(2) DEFAULT NULL,
  `anio` varchar(4) DEFAULT NULL,
  `ed` varchar(1) DEFAULT NULL,
  `pag` varchar(10) DEFAULT NULL,
  `folder` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `equipos_alias`
--

CREATE TABLE `equipos_alias` (
  `id` int(10) UNSIGNED NOT NULL,
  `equipo_nombre` varchar(190) NOT NULL,
  `alias` varchar(190) NOT NULL,
  `alias_normalizado` varchar(190) NOT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `expo_piece_v1`
--

CREATE TABLE `expo_piece_v1` (
  `id` int(10) UNSIGNED NOT NULL,
  `expo_id` int(10) UNSIGNED NOT NULL,
  `piece_type` enum('imagen','recorte_impreso') NOT NULL,
  `ref_id` varchar(64) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `caption_html` mediumtext DEFAULT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `is_discarded` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `expo_v1`
--

CREATE TABLE `expo_v1` (
  `id` int(10) UNSIGNED NOT NULL,
  `slug` varchar(120) NOT NULL,
  `title` varchar(255) NOT NULL,
  `kicker` varchar(120) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `intro_html` mediumtext DEFAULT NULL,
  `template_name` varchar(80) NOT NULL DEFAULT 'futbolistas_equipos',
  `source_collection_id` int(10) UNSIGNED DEFAULT NULL,
  `hero_type` enum('imagen','recorte_impreso') NOT NULL DEFAULT 'imagen',
  `hero_ref_id` varchar(64) DEFAULT NULL,
  `hero_src` varchar(500) DEFAULT NULL,
  `hero_pos_x` varchar(20) NOT NULL DEFAULT '50%',
  `hero_pos_y` varchar(20) NOT NULL DEFAULT '50%',
  `hero_height_px` int(10) UNSIGNED NOT NULL DEFAULT 520,
  `hero_width_pct` int(10) UNSIGNED DEFAULT 38,
  `hero_overlay_opacity` decimal(4,2) NOT NULL DEFAULT 0.35,
  `cta_label` varchar(120) DEFAULT 'Explorar colección',
  `cta_target` varchar(255) DEFAULT 'viewer.html',
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fotografos`
--

CREATE TABLE `fotografos` (
  `id` int(10) UNSIGNED NOT NULL,
  `apellido` varchar(150) NOT NULL DEFAULT '',
  `nombre` varchar(150) NOT NULL DEFAULT '',
  `nombre_mostrar` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `fecha_nacimiento` varchar(50) NOT NULL DEFAULT '',
  `fecha_fallecimiento` varchar(50) NOT NULL DEFAULT '',
  `bio` mediumtext DEFAULT NULL,
  `imagen_tipo` enum('ninguna','url','barcode','recorte') NOT NULL DEFAULT 'ninguna',
  `imagen_valor` varchar(255) NOT NULL DEFAULT '',
  `visible` tinyint(1) NOT NULL DEFAULT 1,
  `observaciones` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fotografos_raw`
--

CREATE TABLE `fotografos_raw` (
  `id` int(10) UNSIGNED NOT NULL,
  `valor_raw` varchar(255) NOT NULL,
  `valor_raw_norm` varchar(255) NOT NULL DEFAULT '',
  `fuente` varchar(50) NOT NULL DEFAULT 'inventario_autor',
  `fuente_ref` varchar(100) NOT NULL DEFAULT '',
  `cantidad_usos` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `estado` enum('pendiente','resuelto','parcial','colectivo','ignorar') NOT NULL DEFAULT 'pendiente',
  `observaciones` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fotografos_sobres`
--

CREATE TABLE `fotografos_sobres` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fotografo_id` int(10) UNSIGNED NOT NULL,
  `barcode` varchar(50) NOT NULL,
  `raw_id` int(10) UNSIGNED DEFAULT NULL,
  `autor_raw` varchar(255) NOT NULL DEFAULT '',
  `origen` varchar(50) NOT NULL DEFAULT 'inventario_autor',
  `origen_ref` varchar(100) NOT NULL DEFAULT '',
  `confianza` enum('alta','media','baja') NOT NULL DEFAULT 'alta',
  `observaciones` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fototeca_revision_bolsa`
--

CREATE TABLE `fototeca_revision_bolsa` (
  `id` int(10) UNSIGNED NOT NULL,
  `sys` char(9) NOT NULL,
  `tipo_bolsa` varchar(20) NOT NULL,
  `valor_bolsa` varchar(255) NOT NULL,
  `accion` enum('pendiente','asignar','descartar') NOT NULL DEFAULT 'pendiente',
  `coleccion_asignada` varchar(255) DEFAULT NULL,
  `nota` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `futbol_sobres_clasificacion`
--

CREATE TABLE `futbol_sobres_clasificacion` (
  `id` int(10) UNSIGNED NOT NULL,
  `sys` varchar(32) NOT NULL,
  `barcode` varchar(64) NOT NULL,
  `estado` enum('pendiente','partido_posible','futbol_general','listo_para_relacionar','vinculado','dudoso','descartado') NOT NULL DEFAULT 'pendiente',
  `equipo1_texto` varchar(255) DEFAULT NULL,
  `equipo2_texto` varchar(255) DEFAULT NULL,
  `equipo_principal_texto` varchar(255) DEFAULT NULL,
  `fecha_sugerida` varchar(32) DEFAULT NULL,
  `fecha_precision` varchar(32) DEFAULT NULL,
  `campeonato_sugerido_texto` varchar(255) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `indizimagenes`
--

CREATE TABLE `indizimagenes` (
  `barcode` varchar(8) NOT NULL,
  `nombramiento` varchar(250) DEFAULT NULL,
  `materia` varchar(250) DEFAULT NULL,
  `personaEnImagen` varchar(500) NOT NULL,
  `lugarEnImagen` varchar(500) NOT NULL,
  `objetoEnImagen` varchar(500) NOT NULL,
  `eventoEnImagen` varchar(500) NOT NULL,
  `institucionEnImagen` varchar(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `intervenciones`
--

CREATE TABLE `intervenciones` (
  `idUsuario` varchar(50) DEFAULT NULL,
  `idProceso` varchar(100) DEFAULT NULL,
  `barcode` varchar(8) DEFAULT NULL,
  `fecha` varchar(14) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `inventario`
--

CREATE TABLE `inventario` (
  `barcode` varchar(8) NOT NULL,
  `nroA` varchar(100) DEFAULT NULL,
  `nroNid` varchar(100) NOT NULL DEFAULT '',
  `nroAnm` varchar(100) NOT NULL DEFAULT '',
  `autor` varchar(100) DEFAULT NULL,
  `titulo` varchar(250) DEFAULT NULL,
  `fechaISO` varchar(8) DEFAULT NULL,
  `observaciones` varchar(100) NOT NULL DEFAULT '',
  `ufi` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `items`
--

CREATE TABLE `items` (
  `sys` varchar(9) DEFAULT NULL,
  `dato` varchar(500) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `ufi` varchar(500) DEFAULT NULL,
  `nroA` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mapaareas`
--

CREATE TABLE `mapaareas` (
  `cod` varchar(7) DEFAULT NULL,
  `ingles` varchar(100) DEFAULT NULL,
  `espaniol` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `materias`
--

CREATE TABLE `materias` (
  `sys` varchar(9) DEFAULT NULL,
  `campo` varchar(3) DEFAULT NULL,
  `materia` varchar(1000) DEFAULT NULL,
  `linea` varchar(1000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `partidos`
--

CREATE TABLE `partidos` (
  `barcode` varchar(8) NOT NULL,
  `tituloSobre` varchar(250) NOT NULL,
  `tituloReg` varchar(250) NOT NULL,
  `fecha` varchar(8) NOT NULL,
  `equipo1` varchar(250) NOT NULL,
  `equipo2` varchar(250) NOT NULL,
  `cancha` varchar(250) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recortes`
--

CREATE TABLE `recortes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `barcode` varchar(8) NOT NULL,
  `barcode_izq` varchar(255) DEFAULT NULL,
  `barcode_der` varchar(255) DEFAULT NULL,
  `pag_izq` int(11) DEFAULT NULL,
  `pag_der` int(11) DEFAULT NULL,
  `fechaIso` varchar(8) DEFAULT NULL,
  `ed` varchar(20) DEFAULT NULL,
  `tipo` varchar(20) DEFAULT NULL,
  `recortadoDe` varchar(250) NOT NULL,
  `xval` double NOT NULL,
  `yval` double NOT NULL,
  `alto` double NOT NULL,
  `ancho` double NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `publico` tinyint(4) DEFAULT 0,
  `recorte_origen_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recorte_vinculos`
--

CREATE TABLE `recorte_vinculos` (
  `id` int(11) NOT NULL,
  `recorte_id` int(11) NOT NULL,
  `tipo_objeto` varchar(30) NOT NULL,
  `objeto_id` varchar(190) NOT NULL,
  `creado` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `registros`
--

CREATE TABLE `registros` (
  `sys` varchar(9) DEFAULT NULL,
  `registro` mediumtext DEFAULT NULL,
  `titulo245` varchar(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `relaciones`
--

CREATE TABLE `relaciones` (
  `id1` varchar(9) DEFAULT NULL,
  `relacion` varchar(100) DEFAULT NULL,
  `id2` varchar(9) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sets_v2`
--

CREATE TABLE `sets_v2` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `owner_user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `kind` enum('temp','def') NOT NULL DEFAULT 'temp',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `set_items_v2`
--

CREATE TABLE `set_items_v2` (
  `set_id` bigint(20) UNSIGNED NOT NULL,
  `item_type` enum('sobre','recorte') NOT NULL,
  `item_key` varchar(128) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `added_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `set_sobre_photos_v2`
--

CREATE TABLE `set_sobre_photos_v2` (
  `set_id` bigint(20) UNSIGNED NOT NULL,
  `barcode` varchar(32) NOT NULL,
  `photo_idx` smallint(5) UNSIGNED NOT NULL,
  `image_key` varchar(64) NOT NULL,
  `cajon` varchar(100) DEFAULT NULL,
  `nombramiento` varchar(80) DEFAULT NULL,
  `seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `set_sobre_progress_v2`
--

CREATE TABLE `set_sobre_progress_v2` (
  `set_id` bigint(20) UNSIGNED NOT NULL,
  `barcode` varchar(32) NOT NULL,
  `total_photos` int(11) NOT NULL DEFAULT 0,
  `seen_photos` int(11) NOT NULL DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `terminos`
--

CREATE TABLE `terminos` (
  `id` int(11) NOT NULL,
  `termino` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `titulos`
--

CREATE TABLE `titulos` (
  `sys` varchar(9) DEFAULT NULL,
  `titulo` varchar(1000) DEFAULT NULL,
  `nroA` varchar(100) DEFAULT NULL,
  `barcode` varchar(8) DEFAULT NULL,
  `ufi` varchar(100) DEFAULT NULL,
  `fecha` varchar(8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `display_name` varchar(128) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_favorites`
--

CREATE TABLE `user_favorites` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `image_key` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) DEFAULT NULL,
  `nivel` varchar(1) DEFAULT NULL,
  `rol` varchar(1) DEFAULT NULL,
  `pass` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `vistoimagenes`
--

CREATE TABLE `vistoimagenes` (
  `nombramiento` varchar(250) DEFAULT NULL,
  `vistoPor` varchar(250) DEFAULT NULL,
  `vistoFecha` varchar(14) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `areas`
--
ALTER TABLE `areas`
  ADD KEY `a` (`area`),
  ADD KEY `sys` (`sys`),
  ADD KEY `area` (`area`);

--
-- Indices de la tabla `campeonatos_import`
--
ALTER TABLE `campeonatos_import`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ci_estado` (`estado`),
  ADD KEY `idx_ci_temporada` (`temporada_detectada`),
  ADD KEY `idx_ci_fuente_tipo` (`fuente_tipo`);

--
-- Indices de la tabla `campeonatos_import_matches`
--
ALTER TABLE `campeonatos_import_matches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cim_import` (`import_id`),
  ADD KEY `idx_cim_nodo` (`nodo_id`),
  ADD KEY `idx_cim_local` (`local_texto`),
  ADD KEY `idx_cim_visitante` (`visitante_texto`),
  ADD KEY `idx_cim_home_canonical` (`home_team_canonical`),
  ADD KEY `idx_cim_away_canonical` (`away_team_canonical`);

--
-- Indices de la tabla `campeonatos_import_nodos`
--
ALTER TABLE `campeonatos_import_nodos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cin_import` (`import_id`),
  ADD KEY `idx_cin_parent` (`parent_id`),
  ADD KEY `idx_cin_tipo` (`tipo`),
  ADD KEY `idx_cin_subtipo` (`subtipo`),
  ADD KEY `idx_cin_orden` (`orden`);

--
-- Indices de la tabla `cmp_entidades`
--
ALTER TABLE `cmp_entidades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cmp_entidades_nombre_normalizado` (`nombre_normalizado`),
  ADD KEY `idx_cmp_entidades_tipo` (`tipo`),
  ADD KEY `idx_cmp_entidades_nombre_mostrable` (`nombre_mostrable`);

--
-- Indices de la tabla `cmp_entidades_alias`
--
ALTER TABLE `cmp_entidades_alias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cmp_entidades_alias_normalizado` (`alias_normalizado`),
  ADD KEY `idx_cmp_entidades_alias_alias` (`alias`),
  ADD KEY `idx_cmp_entidades_alias_entidad_id` (`entidad_id`);

--
-- Indices de la tabla `cmp_importaciones`
--
ALTER TABLE `cmp_importaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cmp_importaciones_estado` (`estado`),
  ADD KEY `idx_cmp_importaciones_temporada` (`temporada_detectada`);

--
-- Indices de la tabla `cmp_importacion_goles`
--
ALTER TABLE `cmp_importacion_goles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cig_importacion` (`importacion_id`),
  ADD KEY `idx_cig_partido` (`partido_id`),
  ADD KEY `idx_cig_jugador_raw` (`jugador_raw`),
  ADD KEY `idx_cig_jugador_norm` (`jugador_normalizado`),
  ADD KEY `idx_cig_team_side` (`team_side`);

--
-- Indices de la tabla `cmp_importacion_nodos`
--
ALTER TABLE `cmp_importacion_nodos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cmp_nodos_importacion` (`importacion_id`),
  ADD KEY `idx_cmp_nodos_parent` (`parent_id`);

--
-- Indices de la tabla `cmp_importacion_partidos`
--
ALTER TABLE `cmp_importacion_partidos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cmp_partidos_importacion` (`importacion_id`),
  ADD KEY `idx_cmp_partidos_nodo` (`nodo_id`),
  ADD KEY `idx_cmp_imp_partidos_local_entidad_id` (`local_entidad_id`),
  ADD KEY `idx_cmp_imp_partidos_visitante_entidad_id` (`visitante_entidad_id`),
  ADD KEY `idx_cmp_imp_partidos_local_normalizado` (`local_normalizado`),
  ADD KEY `idx_cmp_imp_partidos_visitante_normalizado` (`visitante_normalizado`);

--
-- Indices de la tabla `cmp_importacion_partido_vinculos`
--
ALTER TABLE `cmp_importacion_partido_vinculos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cmp_vinculo_import_partido_barcode` (`importacion_partido_id`,`partido_barcode`),
  ADD KEY `idx_cmp_vinculo_importacion` (`importacion_id`),
  ADD KEY `idx_cmp_vinculo_partido_importado` (`importacion_partido_id`),
  ADD KEY `idx_cmp_vinculo_tituloreg` (`tituloReg`),
  ADD KEY `idx_cmp_vinculo_barcode` (`partido_barcode`),
  ADD KEY `idx_cmp_vinculo_score` (`importacion_partido_id`,`score`),
  ADD KEY `idx_cmp_vinculo_estado` (`estado`);

--
-- Indices de la tabla `colecciones`
--
ALTER TABLE `colecciones`
  ADD UNIQUE KEY `uq_coleccion_nombramiento` (`coleccion`,`nombramiento`),
  ADD KEY `coleccion` (`coleccion`);

--
-- Indices de la tabla `collections_v2`
--
ALTER TABLE `collections_v2`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_col_owner_title` (`owner_user_id`,`title`),
  ADD KEY `idx_col_owner_title` (`owner_user_id`,`title`),
  ADD KEY `idx_col_curated` (`is_curated`),
  ADD KEY `idx_col_created_by` (`created_by_user_id`),
  ADD KEY `idx_collections_v2_is_public` (`is_public`),
  ADD KEY `idx_owner_public` (`created_by_user_id`,`is_public`);

--
-- Indices de la tabla `collection_items_v2`
--
ALTER TABLE `collection_items_v2`
  ADD PRIMARY KEY (`collection_id`,`image_key`),
  ADD UNIQUE KEY `ux_collection_item` (`collection_id`,`image_key`),
  ADD UNIQUE KEY `uq_collection_image` (`collection_id`,`image_key`),
  ADD KEY `idx_colitems_collection_pos` (`collection_id`,`position`),
  ADD KEY `idx_colitems_image` (`image_key`),
  ADD KEY `idx_collection` (`collection_id`),
  ADD KEY `idx_image` (`image_key`),
  ADD KEY `idx_collection_items_key` (`item_type`,`item_key`);

--
-- Indices de la tabla `conjuntos`
--
ALTER TABLE `conjuntos`
  ADD PRIMARY KEY (`idConj`);

--
-- Indices de la tabla `descriptoresimagenes`
--
ALTER TABLE `descriptoresimagenes`
  ADD KEY `descriptor` (`descriptor`);

--
-- Indices de la tabla `digitales`
--
ALTER TABLE `digitales`
  ADD KEY `cod` (`inv`),
  ADD KEY `inv` (`inv`),
  ADD KEY `carpeta` (`cajon`),
  ADD KEY `idx_digitales_nombramiento` (`nombramiento`),
  ADD KEY `idx_digitales_carpeta_inv` (`carpeta`,`inv`),
  ADD KEY `idx_digitales_carpeta_cajon` (`carpeta`,`cajon`);

--
-- Indices de la tabla `edicionimpresa`
--
ALTER TABLE `edicionimpresa`
  ADD PRIMARY KEY (`barcode`),
  ADD KEY `nomb` (`barcode`),
  ADD KEY `fiso` (`fechaIso`),
  ADD KEY `ed` (`ed`),
  ADD KEY `idx_edimp_fechaiso` (`fechaIso`),
  ADD KEY `idx_edimp_mes_dia_anio` (`mes`,`dia`,`anio`);

--
-- Indices de la tabla `equipos_alias`
--
ALTER TABLE `equipos_alias`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ea_equipo` (`equipo_nombre`),
  ADD KEY `idx_ea_alias_norm` (`alias_normalizado`);

--
-- Indices de la tabla `expo_piece_v1`
--
ALTER TABLE `expo_piece_v1`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expo_piece_v1_expo` (`expo_id`),
  ADD KEY `idx_expo_piece_v1_type_ref` (`piece_type`,`ref_id`),
  ADD KEY `idx_expo_piece_v1_order` (`expo_id`,`sort_order`);

--
-- Indices de la tabla `expo_v1`
--
ALTER TABLE `expo_v1`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_expo_v1_slug` (`slug`),
  ADD KEY `idx_expo_v1_collection` (`source_collection_id`),
  ADD KEY `idx_expo_v1_status` (`status`);

--
-- Indices de la tabla `fotografos`
--
ALTER TABLE `fotografos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fotografos_slug` (`slug`),
  ADD KEY `idx_fotografos_visible` (`visible`),
  ADD KEY `idx_fotografos_apellido` (`apellido`),
  ADD KEY `idx_fotografos_nombre_mostrar` (`nombre_mostrar`);

--
-- Indices de la tabla `fotografos_raw`
--
ALTER TABLE `fotografos_raw`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fotografos_raw_valor_raw` (`valor_raw`),
  ADD KEY `idx_fotografos_raw_norm` (`valor_raw_norm`),
  ADD KEY `idx_fotografos_raw_estado` (`estado`),
  ADD KEY `idx_fotografos_raw_cantidad` (`cantidad_usos`);

--
-- Indices de la tabla `fotografos_sobres`
--
ALTER TABLE `fotografos_sobres`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fotografos_sobres_fotografo_barcode_raw` (`fotografo_id`,`barcode`,`raw_id`),
  ADD KEY `idx_fotografos_sobres_barcode` (`barcode`),
  ADD KEY `idx_fotografos_sobres_fotografo` (`fotografo_id`),
  ADD KEY `idx_fotografos_sobres_raw` (`raw_id`);

--
-- Indices de la tabla `fototeca_revision_bolsa`
--
ALTER TABLE `fototeca_revision_bolsa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sys_bolsa` (`sys`,`tipo_bolsa`,`valor_bolsa`),
  ADD KEY `idx_tipo_valor` (`tipo_bolsa`,`valor_bolsa`),
  ADD KEY `idx_sys` (`sys`),
  ADD KEY `idx_accion` (`accion`),
  ADD KEY `idx_coleccion_asignada` (`coleccion_asignada`);

--
-- Indices de la tabla `futbol_sobres_clasificacion`
--
ALTER TABLE `futbol_sobres_clasificacion`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_futbol_sobres_barcode` (`barcode`),
  ADD KEY `idx_futbol_sobres_sys` (`sys`),
  ADD KEY `idx_futbol_sobres_estado` (`estado`),
  ADD KEY `idx_futbol_sobres_fecha_sugerida` (`fecha_sugerida`);

--
-- Indices de la tabla `indizimagenes`
--
ALTER TABLE `indizimagenes`
  ADD KEY `materia` (`materia`),
  ADD KEY `personaEnImagen` (`personaEnImagen`(255)),
  ADD KEY `lugarEnImagen` (`lugarEnImagen`(255)),
  ADD KEY `objetoEnImagen` (`objetoEnImagen`(255)),
  ADD KEY `institucionEnImagen` (`institucionEnImagen`(255)),
  ADD KEY `barcode` (`barcode`);

--
-- Indices de la tabla `inventario`
--
ALTER TABLE `inventario`
  ADD PRIMARY KEY (`barcode`),
  ADD KEY `inv` (`barcode`);

--
-- Indices de la tabla `items`
--
ALTER TABLE `items`
  ADD KEY `bar` (`barcode`),
  ADD KEY `a` (`nroA`(255)),
  ADD KEY `ufi` (`ufi`(255)),
  ADD KEY `sys` (`sys`);

--
-- Indices de la tabla `mapaareas`
--
ALTER TABLE `mapaareas`
  ADD KEY `c` (`cod`),
  ADD KEY `i` (`ingles`),
  ADD KEY `e` (`espaniol`);

--
-- Indices de la tabla `materias`
--
ALTER TABLE `materias`
  ADD KEY `mat` (`materia`(255)),
  ADD KEY `mat_sys` (`sys`),
  ADD KEY `campo` (`campo`);

--
-- Indices de la tabla `partidos`
--
ALTER TABLE `partidos`
  ADD KEY `TituloSobre` (`tituloSobre`(191)),
  ADD KEY `TituloSobre_2` (`tituloSobre`(191));

--
-- Indices de la tabla `recortes`
--
ALTER TABLE `recortes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recortes_barcode` (`barcode`),
  ADD KEY `idx_recortes_recortadoDe` (`recortadoDe`),
  ADD KEY `idx_recortes_barcode_recortadoDe` (`barcode`,`recortadoDe`);

--
-- Indices de la tabla `recorte_vinculos`
--
ALTER TABLE `recorte_vinculos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rv_unico` (`recorte_id`,`tipo_objeto`,`objeto_id`),
  ADD KEY `recorte_id` (`recorte_id`),
  ADD KEY `tipo_objeto` (`tipo_objeto`,`objeto_id`),
  ADD KEY `idx_rv_recorte` (`recorte_id`),
  ADD KEY `idx_rv_tipo_objeto` (`tipo_objeto`,`objeto_id`);

--
-- Indices de la tabla `registros`
--
ALTER TABLE `registros`
  ADD KEY `titulo245` (`titulo245`(255)),
  ADD KEY `sys` (`sys`);

--
-- Indices de la tabla `relaciones`
--
ALTER TABLE `relaciones`
  ADD KEY `rel1` (`id1`),
  ADD KEY `rel2` (`id2`),
  ADD KEY `tit` (`relacion`);

--
-- Indices de la tabla `sets_v2`
--
ALTER TABLE `sets_v2`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sets_owner_kind_name` (`owner_user_id`,`kind`,`name`),
  ADD KEY `idx_sets_owner_kind` (`owner_user_id`,`kind`),
  ADD KEY `idx_sets_owner_name` (`owner_user_id`,`name`);

--
-- Indices de la tabla `set_items_v2`
--
ALTER TABLE `set_items_v2`
  ADD PRIMARY KEY (`set_id`,`item_type`,`item_key`),
  ADD KEY `idx_setitems_set_pos` (`set_id`,`position`),
  ADD KEY `idx_setitems_type_key` (`item_type`,`item_key`);

--
-- Indices de la tabla `set_sobre_photos_v2`
--
ALTER TABLE `set_sobre_photos_v2`
  ADD PRIMARY KEY (`set_id`,`barcode`,`photo_idx`),
  ADD KEY `idx_ssp_set_bar_seen` (`set_id`,`barcode`,`seen_at`),
  ADD KEY `idx_ssp_set_bar_img` (`set_id`,`barcode`,`image_key`);

--
-- Indices de la tabla `set_sobre_progress_v2`
--
ALTER TABLE `set_sobre_progress_v2`
  ADD PRIMARY KEY (`set_id`,`barcode`),
  ADD KEY `idx_sspr_set_completed` (`set_id`,`completed_at`),
  ADD KEY `idx_sspr_set_seen` (`set_id`,`seen_photos`);

--
-- Indices de la tabla `terminos`
--
ALTER TABLE `terminos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `termino` (`termino`),
  ADD KEY `ter` (`termino`),
  ADD KEY `id` (`id`);

--
-- Indices de la tabla `titulos`
--
ALTER TABLE `titulos`
  ADD KEY `tit` (`titulo`(255)),
  ADD KEY `na` (`nroA`),
  ADD KEY `tit_bar` (`barcode`),
  ADD KEY `tit_ufi` (`ufi`),
  ADD KEY `f` (`fecha`),
  ADD KEY `sys` (`sys`),
  ADD KEY `idx_titulos_fecha` (`fecha`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_active` (`is_active`);

--
-- Indices de la tabla `user_favorites`
--
ALTER TABLE `user_favorites`
  ADD PRIMARY KEY (`user_id`,`image_key`),
  ADD KEY `idx_fav_user` (`user_id`),
  ADD KEY `idx_fav_image` (`image_key`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `campeonatos_import`
--
ALTER TABLE `campeonatos_import`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `campeonatos_import_matches`
--
ALTER TABLE `campeonatos_import_matches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `campeonatos_import_nodos`
--
ALTER TABLE `campeonatos_import_nodos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cmp_entidades`
--
ALTER TABLE `cmp_entidades`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cmp_entidades_alias`
--
ALTER TABLE `cmp_entidades_alias`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cmp_importaciones`
--
ALTER TABLE `cmp_importaciones`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cmp_importacion_goles`
--
ALTER TABLE `cmp_importacion_goles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cmp_importacion_nodos`
--
ALTER TABLE `cmp_importacion_nodos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cmp_importacion_partidos`
--
ALTER TABLE `cmp_importacion_partidos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cmp_importacion_partido_vinculos`
--
ALTER TABLE `cmp_importacion_partido_vinculos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `collections_v2`
--
ALTER TABLE `collections_v2`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `conjuntos`
--
ALTER TABLE `conjuntos`
  MODIFY `idConj` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `equipos_alias`
--
ALTER TABLE `equipos_alias`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `expo_piece_v1`
--
ALTER TABLE `expo_piece_v1`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `expo_v1`
--
ALTER TABLE `expo_v1`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fotografos`
--
ALTER TABLE `fotografos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fotografos_raw`
--
ALTER TABLE `fotografos_raw`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fotografos_sobres`
--
ALTER TABLE `fotografos_sobres`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fototeca_revision_bolsa`
--
ALTER TABLE `fototeca_revision_bolsa`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `futbol_sobres_clasificacion`
--
ALTER TABLE `futbol_sobres_clasificacion`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `recortes`
--
ALTER TABLE `recortes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `recorte_vinculos`
--
ALTER TABLE `recorte_vinculos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `sets_v2`
--
ALTER TABLE `sets_v2`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `terminos`
--
ALTER TABLE `terminos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `campeonatos_import_matches`
--
ALTER TABLE `campeonatos_import_matches`
  ADD CONSTRAINT `fk_cim_import` FOREIGN KEY (`import_id`) REFERENCES `campeonatos_import` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cim_nodo` FOREIGN KEY (`nodo_id`) REFERENCES `campeonatos_import_nodos` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `campeonatos_import_nodos`
--
ALTER TABLE `campeonatos_import_nodos`
  ADD CONSTRAINT `fk_cin_import` FOREIGN KEY (`import_id`) REFERENCES `campeonatos_import` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cin_parent` FOREIGN KEY (`parent_id`) REFERENCES `campeonatos_import_nodos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `cmp_entidades_alias`
--
ALTER TABLE `cmp_entidades_alias`
  ADD CONSTRAINT `fk_cmp_entidades_alias_entidad` FOREIGN KEY (`entidad_id`) REFERENCES `cmp_entidades` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `cmp_importacion_goles`
--
ALTER TABLE `cmp_importacion_goles`
  ADD CONSTRAINT `fk_cig_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `cmp_importaciones` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cig_partido` FOREIGN KEY (`partido_id`) REFERENCES `cmp_importacion_partidos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `cmp_importacion_nodos`
--
ALTER TABLE `cmp_importacion_nodos`
  ADD CONSTRAINT `fk_cmp_nodos_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `cmp_importaciones` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cmp_nodos_parent` FOREIGN KEY (`parent_id`) REFERENCES `cmp_importacion_nodos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `cmp_importacion_partidos`
--
ALTER TABLE `cmp_importacion_partidos`
  ADD CONSTRAINT `fk_cmp_imp_partidos_local_entidad` FOREIGN KEY (`local_entidad_id`) REFERENCES `cmp_entidades` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cmp_imp_partidos_visitante_entidad` FOREIGN KEY (`visitante_entidad_id`) REFERENCES `cmp_entidades` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cmp_partidos_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `cmp_importaciones` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cmp_partidos_nodo` FOREIGN KEY (`nodo_id`) REFERENCES `cmp_importacion_nodos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `collections_v2`
--
ALTER TABLE `collections_v2`
  ADD CONSTRAINT `fk_col_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_col_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `collection_items_v2`
--
ALTER TABLE `collection_items_v2`
  ADD CONSTRAINT `fk_colitems_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections_v2` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `expo_piece_v1`
--
ALTER TABLE `expo_piece_v1`
  ADD CONSTRAINT `fk_expo_piece_v1_expo` FOREIGN KEY (`expo_id`) REFERENCES `expo_v1` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `fotografos_sobres`
--
ALTER TABLE `fotografos_sobres`
  ADD CONSTRAINT `fk_fotografos_sobres_fotografo` FOREIGN KEY (`fotografo_id`) REFERENCES `fotografos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fotografos_sobres_raw` FOREIGN KEY (`raw_id`) REFERENCES `fotografos_raw` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `sets_v2`
--
ALTER TABLE `sets_v2`
  ADD CONSTRAINT `fk_sets_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `set_items_v2`
--
ALTER TABLE `set_items_v2`
  ADD CONSTRAINT `fk_setitems_set` FOREIGN KEY (`set_id`) REFERENCES `sets_v2` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `user_favorites`
--
ALTER TABLE `user_favorites`
  ADD CONSTRAINT `fk_fav_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
