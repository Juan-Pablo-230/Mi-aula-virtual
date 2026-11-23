<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';

Auth::checkRole(['madre']);

$institucionId = (int)($_GET['id'] ?? 0);
if (!$institucionId) { header('Location: /plataforma/admin/instituciones.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM instituciones WHERE id = ?");
$stmt->execute([$institucionId]);
$institucion = $stmt->fetch();
if (!$institucion) { header('Location: /plataforma/admin/instituciones.php'); exit; }

$tab = $_GET['tab'] ?? 'usuarios';
if (!in_array($tab, ['usuarios', 'importacion', 'estadisticas'])) $tab = 'usuarios';

$usuarios = $pdo->prepare("
    SELECT u.*, d.id AS division_id, d.nombre AS division_nombre, a.nombre AS asignacion_nombre
    FROM usuarios u
    LEFT JOIN alumnos_division ad ON ad.alumno_id = u.id
    LEFT JOIN divisiones d ON d.id = ad.division_id
    LEFT JOIN asignaciones a ON a.id = d.asignacion_id
    WHERE u.institucion_id = ?
    ORDER BY u.rol, u.apellido, u.nombre
");
$usuarios->execute([$institucionId]);
$usuarios = $usuarios->fetchAll();

$divisiones = $pdo->prepare("
    SELECT d.id, d.nombre, a.nombre AS asignacion, d.orientacion_id, d.asignacion_id
    FROM divisiones d JOIN asignaciones a ON a.id = d.asignacion_id
    ORDER BY a.nombre, d.nombre
");
$divisiones->execute();
$divisiones = $divisiones->fetchAll();

$materias = $pdo->query("SELECT id, nombre, orientacion_id FROM materias ORDER BY nombre")->fetchAll();

// Cursos por materia (many-to-many) para el filtro JS
$stmtCursosMat = $pdo->query("SELECT materia_id, asignacion_id FROM materias_asignaciones");
$materiasCursos = [];
foreach ($stmtCursosMat as $row) {
    $materiasCursos[(int)$row['materia_id']][] = (int)$row['asignacion_id'];
}
foreach ($materias as &$m) {
    $m['cursos_ids'] = $materiasCursos[(int)$m['id']] ?? [];
}
unset($m);

$asignacionesDocentes = [];
$stmt = $pdo->prepare("
    SELECT ad.docente_id, ad.materia_id, ad.division_id
    FROM asignaciones_docente ad
    JOIN usuarios u ON u.id = ad.docente_id
    WHERE u.institucion_id = ?
");
$stmt->execute([$institucionId]);
foreach ($stmt as $row) $asignacionesDocentes[$row['docente_id']][] = $row;

$contarUsuarios = function (string $condRol) use ($pdo, $institucionId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE $condRol AND institucion_id = ?"); // $condRol es literal fijo, no entrada del usuario
    $st->execute([$institucionId]);
    return (int)$st->fetchColumn();
};
$statsUsuarios = [
    'alumnos'   => $contarUsuarios("rol='alumno'"),
    'docentes'  => $contarUsuarios("rol='docente'"),
    'directivos'=> $contarUsuarios("rol IN ('directivo','secretario')"),
    'total'     => count($usuarios),
];

$alumnosPorDivision = $pdo->prepare("
    SELECT CONCAT(a.nombre,' ',d.nombre) AS division, COUNT(ad.alumno_id) AS total
    FROM divisiones d
    JOIN asignaciones a ON a.id = d.asignacion_id
    LEFT JOIN alumnos_division ad ON ad.division_id = d.id
    LEFT JOIN usuarios u ON u.id = ad.alumno_id AND u.institucion_id = ?
    GROUP BY d.id ORDER BY a.nombre, d.nombre
");
$alumnosPorDivision->execute([$institucionId]);
$alumnosPorDivision = $alumnosPorDivision->fetchAll();

$distRoles = $pdo->prepare("
    SELECT rol, COUNT(*) AS total FROM usuarios WHERE institucion_id = ? GROUP BY rol
");
$distRoles->execute([$institucionId]);
$distRoles = $distRoles->fetchAll();

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$asistenciaPorEstado = [];
$alumnosInst = $pdo->prepare("SELECT id FROM usuarios WHERE rol = 'alumno' AND institucion_id = ?");
$alumnosInst->execute([$institucionId]);
$alumnosIdsInst = array_map('intval', $alumnosInst->fetchAll(PDO::FETCH_COLUMN));
if (!empty($alumnosIdsInst)) {
    try {
        $desdeMongo = new MongoDB\BSON\UTCDateTime(strtotime($desde . ' 00:00:00') * 1000);
        $hastaMongo = new MongoDB\BSON\UTCDateTime(strtotime($hasta . ' 23:59:59') * 1000);
        $agg = $mongoDB->selectCollection('asistencia')->aggregate([
            ['$match' => [
                'alumno_id' => ['$in' => $alumnosIdsInst],
                'fecha' => ['$gte' => $desdeMongo, '$lte' => $hastaMongo],
            ]],
            ['$group' => ['_id' => '$estado', 'total' => ['$sum' => 1]]],
        ])->toArray();
        foreach ($agg as $r) $asistenciaPorEstado[$r['_id']] = (int)$r['total'];
    } catch (Exception $e) {}
}

$pubsPorMateria = [];
try {
    $agg = $mongoPublicaciones->aggregate([
        ['$group' => ['_id' => '$materia_id', 'total' => ['$sum' => 1]]],
    ])->toArray();
    foreach ($agg as $r) $pubsPorMateria[(int)$r['_id']] = (int)$r['total'];
} catch (Exception $e) {}
$nombresMaterias = [];
foreach ($materias as $m) $nombresMaterias[(int)$m['id']] = $m['nombre'];
$labelsMaterias = []; $datosMaterias = [];
foreach ($pubsPorMateria as $mid => $total) { $labelsMaterias[] = $nombresMaterias[$mid] ?? "Materia $mid"; $datosMaterias[] = $total; }

$labelsRoles = []; $datosRoles = [];
$rolesES = ['alumno'=>'Alumnos','docente'=>'Docentes','directivo'=>'Directivos','secretario'=>'Secretarios','familiar'=>'Familiares','madre'=>'Madre'];
foreach ($distRoles as $r) {
    $labelsRoles[] = $rolesES[$r['rol']] ?? ucfirst($r['rol']);
    $datosRoles[] = (int)$r['total'];
}

$desdeEsc = h($desde);
$hastaEsc = h($hasta);
?>
<?php include __DIR__ . '/../views/header.php'; ?>
<?php include __DIR__ . '/../views/menu.php'; ?>
<main class="contenido-principal">
    <div class="institucion-header">
        <div>
            <a class="btn-secundario" href="/plataforma/admin/instituciones.php">← Instituciones</a>
            <h1 style="display:inline-block; margin-left:1rem;">🏛️ <?= h($institucion['nombre']) ?></h1>
        </div>
        <small style="color:#a5c3e8;">
            <?= h($institucion['direccion'] ?? '') ?>
            <?php if (!empty($institucion['telefono'])): ?> · 📞 <?= h($institucion['telefono']) ?><?php endif; ?>
            <?php if (!empty($institucion['email'])): ?> · ✉️ <?= h($institucion['email']) ?><?php endif; ?>
        </small>
    </div>

    <nav class="admin-nav">
        <a href="?id=<?= $institucionId ?>&tab=usuarios" class="admin-nav-item <?= $tab === 'usuarios' ? 'activo' : '' ?>">👥 Usuarios</a>
        <a href="?id=<?= $institucionId ?>&tab=importacion" class="admin-nav-item <?= $tab === 'importacion' ? 'activo' : '' ?>">📥 Importación / Exportación</a>
        <a href="?id=<?= $institucionId ?>&tab=estadisticas" class="admin-nav-item <?= $tab === 'estadisticas' ? 'activo' : '' ?>">📈 Estadísticas</a>
    </nav>

    <?php if ($tab === 'usuarios'): ?>
        <button class="btn-primario" onclick="abrirModalCrear()">➕ Crear usuario</button>

        <div class="panel-vidrio">
            <table class="tabla-alumnos">
                <thead>
                    <tr>
                        <th>Apellido, Nombre</th>
                        <th>DNI</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Asignación</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?= h($u['apellido'] . ', ' . $u['nombre']) ?></td>
                            <td><?= h($u['dni']) ?></td>
                            <td><?= h($u['email'] ?? '—') ?></td>
                            <td><?= h(ucfirst($u['rol'])) ?></td>
                            <td>
                                <?php if ($u['rol'] === 'alumno'): ?>
                                    <?= $u['asignacion_nombre'] ? h($u['asignacion_nombre'] . ' ' . $u['division_nombre']) : '<em style="color:#a5c3e8;">Sin división</em>' ?>
                                <?php elseif ($u['rol'] === 'docente'): ?>
                                    <?php $asigs = $asignacionesDocentes[$u['id']] ?? []; ?>
                                    <?= empty($asigs) ? '<em style="color:#a5c3e8;">Sin asignaciones</em>' : count($asigs) . ' asignación(es)' ?>
                                <?php else: ?>
                                    <em style="color:#a5c3e8;">—</em>
                                <?php endif; ?>
                            </td>
                            <td><?= $u['activo'] ? '✅' : '❌' ?></td>
                            <td>
                                <button type="button" class="btn-secundario" onclick='abrirEditar(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>, <?= jsonJs($asignacionesDocentes[$u['id']] ?? []) ?>, <?= (int)($u['division_id'] ?? 0) ?>)'>✏️ Editar</button>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <button type="button" class="btn-peligro-sm" onclick="eliminarUsuario(<?= $u['id'] ?>)">🗑️</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="modal-fondo oculto" id="modalUser">
            <div class="modal-caja" style="min-width: 500px; max-width: 90vw;">
                <h3 id="modalUserTitulo">Crear usuario</h3>
                <form id="formUser">
                    <input type="hidden" name="id" id="userId">
                    <input type="hidden" name="institucion_id" value="<?= $institucionId ?>">
                    <label>Nombre <input type="text" name="nombre" id="userNombre" required></label>
                    <label>Apellido <input type="text" name="apellido" id="userApellido" required></label>
                    <label>DNI <input type="text" name="dni" id="userDni" required inputmode="numeric" pattern="[0-9]{6,12}" maxlength="12"></label>
                    <div id="campoEmail" style="display:none;">
                        <label>Email (opcional) <input type="email" name="email" id="userEmail"></label>
                    </div>
                    <label>Rol
                        <select name="rol" id="userRol" required onchange="actualizarAsignacionForm()">
                            <option value="">— Seleccione un rol —</option>
                            <option value="alumno">Alumno</option>
                            <option value="docente">Docente</option>
                            <option value="secretario">Secretario</option>
                            <option value="directivo">Directivo</option>
                        </select>
                    </label>
                    <div id="seccionAsignacion" style="display:none;">
                        <hr style="border-color: var(--borde-vidrio); margin: 1rem 0;">
                        <h4 style="margin-bottom: 0.5rem; color: #7dd3fc;">Asignación</h4>
                        <div id="contenidoAsignacion"></div>
                    </div>
                    <div id="campoFamiliar" style="display:none;">
                        <hr style="border-color: var(--borde-vidrio); margin: 1rem 0;">
                        <h4 style="margin-bottom: 0.5rem; color: #7dd3fc;">Familiar / Tutor</h4>
                        <label>DNI del familiar <input type="text" name="dni_familiar" id="dniFamiliar" inputmode="numeric" pattern="[0-9]{6,12}" maxlength="12"></label>
                        <label>Nombre del familiar (opcional) <input type="text" name="nombre_familiar" id="nombreFamiliar"></label>
                    </div>
                    <div id="campoPasswordInfo">
                        <p class="alerta info" style="font-size:0.85rem; margin-top:1rem;">El usuario se creará con la contraseña temporal <strong>Temporal123</strong>.</p>
                    </div>
                    <div id="campoActivo" style="display:none;">
                        <label>Activo
                            <select name="activo" id="userActivo">
                                <option value="1">Sí</option>
                                <option value="0">No</option>
                            </select>
                        </label>
                    </div>
                    <div class="modal-acciones">
                        <button type="button" class="btn-secundario" onclick="cerrarModal('modalUser')">Cancelar</button>
                        <button class="btn-primario" id="btnGuardarUser" type="submit">Crear</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        window.INST_USER_API = '/plataforma/api/institucion_ajax.php?id=<?= $institucionId ?>';
        window.DIVISIONES = <?= jsonJs($divisiones) ?>;
        window.MATERIAS = <?= jsonJs($materias) ?>;
        </script>
        <script src="/assets/js/institucion_usuarios.js"></script>

    <?php elseif ($tab === 'importacion'): ?>
        <div class="grid-admin">
            <section class="panel-vidrio">
                <h3>📤 Exportar usuarios de esta institución</h3>
                <p style="color:#a5c3e8; font-size:0.85rem;">Descarga un CSV con todos los usuarios de <strong><?= h($institucion['nombre']) ?></strong>.</p>
                <a class="btn-primario" href="/plataforma/api/institucion_export.php?id=<?= $institucionId ?>">⬇️ Descargar CSV</a>
            </section>

            <section class="panel-vidrio">
                <h3>📥 Importar CSV</h3>
                <pre style="background:rgba(0,0,0,0.3); padding:1rem; border-radius:8px; overflow-x:auto; color:#cfe2ff; font-size:.8rem;">nombre,apellido,dni,email,rol
Juan,Pérez,12345678,juan@example.com,alumno</pre>
                <form id="formImport">
                    <label>Archivo CSV <input type="file" name="csv" accept=".csv" required></label>
                    <button class="btn-primario">⬆️ Importar a esta institución</button>
                </form>
                <div id="resultImport" style="display:none; margin-top:1rem;"></div>
            </section>
        </div>

        <script>
        window.INST_IMPORT_URL = '/plataforma/api/institucion_import.php?id=<?= $institucionId ?>';
        </script>
        <script src="/assets/js/institucion_importacion.js"></script>

    <?php elseif ($tab === 'estadisticas'): ?>
        <div class="panel-vidrio stat-filtros">
            <form method="get">
                <input type="hidden" name="id" value="<?= $institucionId ?>">
                <input type="hidden" name="tab" value="estadisticas">
                <label>Desde <input type="date" name="desde" value="<?= h($desde) ?>"></label>
                <label>Hasta <input type="date" name="hasta" value="<?= h($hasta) ?>"></label>
                <button class="btn-primario" type="submit">Aplicar</button>
            </form>
        </div>

        <div class="grid-estadisticas">
            <div class="stat-card panel-vidrio"><h3><?= $statsUsuarios['alumnos'] ?></h3><p>Alumnos</p></div>
            <div class="stat-card panel-vidrio"><h3><?= $statsUsuarios['docentes'] ?></h3><p>Docentes</p></div>
            <div class="stat-card panel-vidrio"><h3><?= $statsUsuarios['directivos'] ?></h3><p>Directivos</p></div>
            <div class="stat-card panel-vidrio"><h3><?= $statsUsuarios['total'] ?></h3><p>Total usuarios</p></div>
        </div>

        <div class="chart-grid">
            <div class="panel-vidrio chart-container"><h3>Alumnos por división</h3><canvas id="chartDivisionesInst"></canvas></div>
            <div class="panel-vidrio chart-container"><h3>Distribución por rol</h3><canvas id="chartRolesInst"></canvas></div>
            <div class="panel-vidrio chart-container"><h3>Asistencias por estado (<?= $desdeEsc ?> → <?= $hastaEsc ?>)</h3><canvas id="chartAsistenciaInst"></canvas></div>
            <div class="panel-vidrio chart-container"><h3>Publicaciones por materia</h3><canvas id="chartPubsInst"></canvas></div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
        window.INST_CHART_DATA = {
            divisiones: { labels: <?= jsonJs(array_column($alumnosPorDivision, 'division')) ?>, data: <?= jsonJs(array_map('intval', array_column($alumnosPorDivision, 'total'))) ?> },
            roles:      { labels: <?= jsonJs($labelsRoles) ?>, data: <?= jsonJs($datosRoles) ?> },
            asistencia: { labels: <?= jsonJs(array_keys($asistenciaPorEstado)) ?>, data: <?= jsonJs(array_values($asistenciaPorEstado)) ?> },
            pubs:       { labels: <?= jsonJs($labelsMaterias) ?>, data: <?= jsonJs($datosMaterias) ?> }
        };
        </script>
        <script src="/assets/js/institucion_estadisticas.js"></script>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../views/footer.php'; ?>