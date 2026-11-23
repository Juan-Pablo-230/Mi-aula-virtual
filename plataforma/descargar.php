<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/Acceso.php';

Auth::checkRole(ROLES_ALL);
$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) { http_response_code(400); die('ID inválido'); }

try {
    $doc = $mongoArchivos->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
    if (!$doc) { http_response_code(404); die('Archivo no encontrado'); }

    // Solo quien tiene acceso al mensaje/tarea/entrega/publicación que contiene el archivo.
    // Se responde 404 (no 403) para no revelar qué IDs existen.
    if (!Acceso::puedeDescargarArchivo($pdo, $mongoDB, Acceso::yo(), $doc)) {
        http_response_code(404);
        die('Archivo no encontrado');
    }
    $archivoId = $_SESSION['user_id'] ?? 0;
    if (($doc['vt_estado'] ?? '') === 'sospechoso' && ($doc['subido_por'] ?? 0) != $archivoId) {
        if (!isset($_GET['confirmado'])) {
            ?>
            <!DOCTYPE html><html><head><meta charset="utf-8"><title>Advertencia</title>
            <link rel="stylesheet" href="/assets/css/aulaviva.css"></head><body>
            <div class="panel-vidrio" style="max-width:600px;margin:3rem auto;">
                <h2>⚠️ Archivo sospechoso</h2>
                <p>VirusTotal marcó este archivo como <strong>sospechoso</strong>. Descargarlo corre bajo tu propio riesgo.</p>
                <a class="btn-peligro" href="?id=<?= h($id) ?>&confirmado=1">Descargar de todas formas</a>
                <a class="btn-secundario" href="javascript:history.back()">Volver</a>
            </div></body></html>
            <?php
            exit;
        }
    }

    // Defensa contra path traversal: solo el nombre base, y debe quedar dentro de UPLOAD_DIR
    $ruta = UPLOAD_DIR . basename((string)($doc['nombre_guardado'] ?? ''));
    if (!is_file($ruta)) { http_response_code(404); die('Archivo no disponible'); }

    // Solo el PDF se muestra en el navegador; todo lo demás se descarga como binario genérico.
    // Nunca se reenvía un Content-Type controlado por el usuario (evita servir HTML/SVG activos).
    $esPdf  = ($doc['mime'] ?? '') === 'application/pdf';
    $nombre = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string)($doc['nombre_original'] ?? 'archivo')));
    $nombre = $nombre !== '' ? $nombre : 'archivo';

    header('Content-Type: ' . ($esPdf ? 'application/pdf' : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    if (!$esPdf) header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: ' . ($esPdf ? 'inline' : 'attachment') . '; filename="' . $nombre . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
} catch (Throwable $e) {
    error_log('descargar ERROR: ' . $e->getMessage());
    http_response_code(500);
    die('No se pudo procesar la descarga.');
}
