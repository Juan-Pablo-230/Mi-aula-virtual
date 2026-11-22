<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';

Auth::checkRole(ROLES_ALL);
if (($_SESSION['rol'] ?? '') === 'madre') {
    header('Location: /plataforma/admin/instituciones.php');
    exit;
}
Auth::checkRole(['directivo', 'secretario']);

$userActual = Auth::currentUser($pdo);
$miInst = (int)($userActual['institucion_id'] ?? 0);
if (!$miInst) die('Tu usuario no tiene institución asignada. Contactá al administrador.');

$resultado = [];
if (!empty($_FILES['csv']['tmp_name'])) {
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
        if (UserModel::findByDni($pdo, $dni)) { $resultado[] = "Línea $linea: DNI $dni ya existe (omitido)"; continue; }
        try {
            $id = UserModel::create($pdo, [
                'nombre' => $nombre, 'apellido' => $apellido,
                'dni' => $dni, 'email' => $email !== '' ? $email : null,
                'password' => 'Temporal123', 'rol' => $rol,
            ]);
            $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE id = ?")->execute([$miInst, $id]);
            $resultado[] = "✔ Línea $linea: $apellido, $nombre (DNI $dni) creado como $rol";
        } catch (Exception $e) {
            error_log('importacion ERROR: ' . $e->getMessage());
            $resultado[] = "Línea $linea: ERROR al crear el usuario";
        }
    }
    fclose($f);
}

// === EXPORTAR ===
if (isset($_GET['export'])) {
    $soloRol = $_GET['export']; // 'todos' | rol
    $sql = "SELECT u.apellido, u.nombre, u.dni, COALESCE(u.email,'') AS email, u.rol,
                   COALESCE(i.nombre,'') AS institucion
            FROM usuarios u LEFT JOIN instituciones i ON i.id = u.institucion_id";
    $params = [];
    if ($soloRol !== 'todos' && in_array($soloRol, ROLES_ALL)) { $sql .= " WHERE u.rol = ?"; $params[] = $soloRol; }
    $sql .= " ORDER BY u.apellido, u.nombre";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usuarios_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
    fputcsv($out, ['apellido','nombre','dni','email','rol','institucion']);
    while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $u);
    fclose($out);
    exit;
}
?>
<?php include __DIR__ . '/../views/header.php'; ?>
<?php include __DIR__ . '/../views/menu.php'; ?>
<main class="contenido-principal">
    <h1>📥 Importación / Exportación</h1>
    <?php include __DIR__ . '/../views/admin_nav.php'; ?>

    <div class="grid-admin">
        <section class="panel-vidrio">
            <h3>📤 Exportar usuarios</h3>
            <form method="get">
                <label>Filtrar por rol
                    <select name="export">
                        <option value="todos">Todos</option>
                        <?php foreach (ROLES_ALL as $r): ?>
                            <option value="<?= h($r) ?>"><?= h(ucfirst($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn-primario" type="submit">⬇️ Descargar CSV</button>
            </form>
        </section>

        <section class="panel-vidrio">
            <h3>📥 Importar CSV</h3>
            <pre style="background:rgba(0,0,0,0.3); padding:1rem; border-radius:8px; overflow-x:auto; color:#cfe2ff; font-size:.8rem;">nombre,apellido,dni,email,rol
Juan,Pérez,12345678,juan@example.com,alumno
María,Gómez,87654321,,docente</pre>
            <form method="post" enctype="multipart/form-data">
                <label>Archivo CSV <input type="file" name="csv" accept=".csv" required></label>
                <button class="btn-primario">⬆️ Importar</button>
            </form>
        </section>
    </div>

    <?php if (!empty($resultado)): ?>
        <div class="panel-vidrio">
            <h3>Resultado</h3>
            <pre style="background:rgba(0,0,0,0.3); padding:1rem; border-radius:8px; max-height:400px; overflow:auto; color:#cfe2ff;"><?= h(implode("\n", $resultado)) ?></pre>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../views/footer.php'; ?>