<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';

Auth::checkRole(ROLES_ALL);
$id = $_GET['id'] ?? '';
if (!$id) die('ID requerido');

try {
    $doc = $mongoArchivos->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);
    if (!$doc) die('Archivo no encontrado');
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
    $ruta = UPLOAD_DIR . $doc['nombre_guardado'];
    if (!file_exists($ruta)) die('Archivo no disponible');
    $esPdf = ($doc['mime'] ?? '') === 'application/pdf';
    header('Content-Type: ' . ($doc['mime'] ?? 'application/octet-stream'));
    if (!$esPdf) header('Content-Disposition: attachment; filename="' . basename($doc['nombre_original']) . '"');
    else header('Content-Disposition: inline; filename="' . basename($doc['nombre_original']) . '"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
} catch (Exception $e) { die('Error: ' . h($e->getMessage())); }