<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

Auth::checkRole(['madre']);

$institucionId = (int)($_GET['id'] ?? 0);
if (!$institucionId) { http_response_code(400); exit('Institución requerida'); }

$stmt = $pdo->prepare("SELECT nombre FROM instituciones WHERE id = ?");
$stmt->execute([$institucionId]);
$nombreInst = $stmt->fetchColumn();
if (!$nombreInst) { http_response_code(404); exit('Institución no encontrada'); }

$stmt = $pdo->prepare("
    SELECT apellido, nombre, dni, COALESCE(email,'') AS email, rol
    FROM usuarios WHERE institucion_id = ? ORDER BY apellido, nombre
");
$stmt->execute([$institucionId]);

$slug = preg_replace('/[^A-Za-z0-9]+/', '_', $nombreInst);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="usuarios_' . $slug . '_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['apellido','nombre','dni','email','rol']);
while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, array_map('csvSeguro', $u));
fclose($out);
exit;