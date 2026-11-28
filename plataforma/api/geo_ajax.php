<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Geo.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['madre']);

switch ($_GET['action'] ?? '') {
    case 'paises':
        $p = Geo::paises();
        if (!$p) jsonResponse(['ok' => false, 'mensaje' => 'No se pudo obtener la lista de países. Probá de nuevo en un momento.'], 502);
        jsonResponse(['ok' => true, 'paises' => $p]);
    case 'localidades':
        $pais = trim($_GET['pais'] ?? '');
        if ($pais === '' || !Geo::buscarPais($pais)) jsonResponse(['ok' => false, 'mensaje' => 'País inválido'], 400);
        $l = Geo::localidades($pais);
        // Sin lista disponible (API caída o sin localidades): la pantalla pasa a escritura manual
        if (!$l) jsonResponse(['ok' => true, 'localidades' => [], 'manual' => true]);
        jsonResponse(['ok' => true, 'localidades' => $l]);
    default:
        jsonResponse(['ok' => false, 'mensaje' => 'Acción inválida'], 400);
}
