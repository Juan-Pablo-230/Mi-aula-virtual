<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';

header('Content-Type: application/json; charset=utf-8');
Auth::checkRole(['madre']);

$institucionId = (int)($_GET['id'] ?? 0);
if (!$institucionId) jsonResponse(['ok' => false, 'mensaje' => 'Institución requerida'], 400);

$stmt = $pdo->prepare("SELECT id FROM instituciones WHERE id = ?");
$stmt->execute([$institucionId]);
if (!$stmt->fetch()) jsonResponse(['ok' => false, 'mensaje' => 'Institución no encontrada'], 404);

if (empty($_FILES['csv']['tmp_name'])) jsonResponse(['ok' => false, 'mensaje' => 'Archivo requerido'], 400);

$resultado = [];
$f = fopen($_FILES['csv']['tmp_name'], 'r');
$header = fgetcsv($f);
$linea = 1;
while (($row = fgetcsv($f)) !== false) {
    $linea++;
    if (count($row) < 5) { $resultado[] = "Línea $linea: columnas insuficientes"; continue; }
    [$nombre, $apellido, $dni, $email, $rol] = array_map('trim', $row);
    if (!in_array($rol, ['alumno','docente','secretario','directivo','familiar'])) {
        $resultado[] = "Línea $linea: rol inválido ($rol)"; continue;
    }
    if ($dni === '') { $resultado[] = "Línea $linea: DNI vacío"; continue; }
    if (!dniValido($dni)) { $resultado[] = "Línea $linea: DNI inválido (solo números, 6 a 12 dígitos)"; continue; }
    if (UserModel::findByDni($pdo, $dni)) { $resultado[] = "Línea $linea: DNI $dni ya existe (omitido)"; continue; }
    try {
        $id = UserModel::create($pdo, [
            'nombre' => $nombre, 'apellido' => $apellido,
            'dni' => $dni, 'email' => $email !== '' ? $email : null,
            'password' => 'Temporal123', 'rol' => $rol,
        ]);
        $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE id = ?")->execute([$institucionId, $id]);
        $resultado[] = "✔ Línea $linea: $apellido, $nombre (DNI $dni) creado como $rol";
    } catch (Exception $e) {
        error_log('institucion_import ERROR: ' . $e->getMessage());
        $resultado[] = "Línea $linea: ERROR al crear el usuario";
    }
}
fclose($f);

jsonResponse(['ok' => true, 'resultado' => $resultado]);