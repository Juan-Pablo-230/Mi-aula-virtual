<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/AcademicModel.php';
require_once __DIR__ . '/../core/Feriados.php';
require_once __DIR__ . '/../core/Acceso.php';
require_once __DIR__ . '/../core/AsistenciaReglas.php';

Auth::checkRole(ROLES_ALL);
if (($_SESSION['rol'] ?? '') === 'madre') {
    header('Location: /plataforma/admin/instituciones.php');
    exit;
}
Auth::checkRole(['directivo', 'secretario']);

$userActual = Auth::currentUser($pdo);
$miInst = (int)($userActual['institucion_id'] ?? 0);
if (!$miInst) die('Tu usuario no tiene institución asignada. Contactá al administrador.');

$stmt = $pdo->prepare("SELECT nombre FROM instituciones WHERE id = ?");
$stmt->execute([$miInst]);
$miInstNombre = $stmt->fetchColumn() ?: 'Mi institución';

$niveles = AcademicModel::niveles($pdo);
$modoDiaDisponible = AsistenciaReglas::modoPorDiaDisponible($pdo);
$orientaciones = AcademicModel::orientaciones($pdo);
$ciclos = AcademicModel::ciclosLectivos($pdo, $miInst);

$stmt = $pdo->prepare("
    SELECT a.*, n.nombre AS nivel
    FROM asignaciones a
    JOIN niveles n ON n.id = a.nivel_id
    WHERE a.institucion_id = ?
    ORDER BY n.id, a.nombre
");
$stmt->execute([$miInst]);
$asignaciones = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT m.*, o.nombre AS orientacion
    FROM materias m
    LEFT JOIN orientaciones o ON o.id = m.orientacion_id
    WHERE m.institucion_id = ?
    ORDER BY m.nombre
");
$stmt->execute([$miInst]);
$materias = $stmt->fetchAll();

$materiasCursos = [];
$stmt = $pdo->prepare("
    SELECT ma.materia_id, a.id, a.nombre AS asignacion_nombre, n.nombre AS nivel_nombre
    FROM materias_asignaciones ma
    JOIN asignaciones a ON a.id = ma.asignacion_id
    JOIN niveles n ON n.id = a.nivel_id
    WHERE a.institucion_id = ?
    ORDER BY n.id, a.nombre
");
$stmt->execute([$miInst]);
foreach ($stmt as $row) $materiasCursos[(int)$row['materia_id']][] = $row;

$divisionesPorAsignacion = [];
$stmt = $pdo->prepare("
    SELECT id, nombre, asignacion_id, orientacion_id FROM divisiones
    WHERE asignacion_id IN (SELECT id FROM asignaciones WHERE institucion_id = ?)
    ORDER BY nombre
");
$stmt->execute([$miInst]);
foreach ($stmt as $d) $divisionesPorAsignacion[(int)$d['asignacion_id']][] = $d;

$materiasPorAsignacion = [];
$stmt = $pdo->prepare("
    SELECT m.id, m.nombre, ma.asignacion_id
    FROM materias m
    JOIN materias_asignaciones ma ON ma.materia_id = m.id
    WHERE m.institucion_id = ?
    ORDER BY m.nombre
");
$stmt->execute([$miInst]);
foreach ($stmt as $m) $materiasPorAsignacion[(int)$m['asignacion_id']][] = $m;
?>
<?php include __DIR__ . '/../views/header.php'; ?>
<?php include __DIR__ . '/../views/menu.php'; ?>

<main class="contenido-principal">
    <h1>🏫 Administrar institución — <?= h($miInstNombre) ?></h1>

    <?php include __DIR__ . '/../views/admin_nav.php'; ?>

    <div class="grid-admin">
        <section class="panel-vidrio">
            <h3>Crear Curso</h3>
            <form id="formAsignacion">
                <label>Nombre <input type="text" name="nombre" required placeholder="Ej: 1° Grado, 4° Año"></label>
                <label>Nivel
                    <select name="nivel_id" required>
                        <?php foreach ($niveles as $n): ?>
                            <option value="<?= $n['id'] ?>"><?= h($n['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($modoDiaDisponible): ?>
                <label class="label-checkbox">
                    <input type="checkbox" name="asistencia_por_dia" value="1">
                    <span>🗓️ <strong>Asistencia única</strong> (por día completo: una sola asistencia para todas las materias)</span>
                </label>
                <?php endif; ?>
                <button class="btn-primario">Crear Curso</button>
            </form>
        </section>

        <section class="panel-vidrio">
            <h3>Crear Orientación</h3>
            <form id="formOrientacion">
                <label>Nombre <input type="text" name="nombre" required placeholder="Ej: Electrónica, Informática"></label>
                <button class="btn-primario">Crear Orientación</button>
            </form>
        </section>

        <section class="panel-vidrio" id="seccionCrearMateria">
            <h3>Crear Materia</h3>
            <form id="formMateria">
                <label>Nombre
                    <input type="text" name="nombre" required placeholder="Ej: Matemática, Historia" list="listaNombresMaterias" autocomplete="off">
                    <datalist id="listaNombresMaterias">
                        <?php foreach ($materias as $m): ?>
                            <option value="<?= h($m['nombre']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label>Orientación (opcional)
                    <select name="orientacion_id">
                        <option value="">Sin orientación (común a todas)</option>
                        <?php foreach ($orientaciones as $o): ?>
                            <option value="<?= $o['id'] ?>"><?= h($o['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Cursos específicos <span style="color:#fca5a5;">*</span>
                    <small style="color:#a5c3e8; font-size:0.75rem; display:block; margin-bottom:0.35rem;">
                        Obligatorio: marcá al menos un curso donde aplica esta materia.
                    </small>
                    <div class="checkbox-cursos" data-target="crear">
                        <div class="checkbox-cursos-controles">
                            <input type="text" class="checkbox-cursos-buscar" placeholder="🔍 Buscar curso..." oninput="filtrarCursosCheckbox(this, 'crear')">
                            <button type="button" class="btn-mini" onclick="marcarTodosCursos('crear', true)">Todos</button>
                            <button type="button" class="btn-mini" onclick="marcarTodosCursos('crear', false)">Ninguno</button>
                        </div>
                        <div class="checkbox-cursos-lista" id="crearCursosLista">
                            <?php foreach ($asignaciones as $a): ?>
                                <label class="checkbox-cursos-item" data-nombre="<?= h(mb_strtolower($a['nombre'] . ' ' . $a['nivel'])) ?>">
                                    <input type="checkbox" name="asignaciones_ids[]" value="<?= (int)$a['id'] ?>">
                                    <span><?= h($a['nombre']) ?> <small style="color:#a5c3e8;">(<?= h($a['nivel']) ?>)</small></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($asignaciones)): ?>
                                <small style="color:#a5c3e8; padding:0.5rem;">No hay cursos creados todavía.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </label>
                <button class="btn-primario">Crear Materia</button>
            </form>
        </section>

        <section class="panel-vidrio">
            <h3>📅 Ciclos lectivos</h3>
            <form id="formCiclo">
                <label>Año
                    <input type="number" name="año" required min="2020" max="2100" value="<?= date('Y') ?>">
                </label>
                <label>Fecha inicio
                    <input type="date" name="fecha_inicio" required value="<?= date('Y') ?>-03-01">
                </label>
                <label>Fecha fin
                    <input type="date" name="fecha_fin" required value="<?= date('Y') ?>-12-15">
                </label>
                <label class="label-checkbox">
                    <input type="checkbox" name="activar" value="1" checked>
                    <span>Activar este ciclo</span>
                </label>
                <button class="btn-primario">Crear ciclo</button>
            </form>

            <h4 style="margin-top:1.5rem;">Ciclos existentes</h4>
            <?php if (empty($ciclos)): ?>
                <p class="alerta info">No hay ciclos creados.</p>
            <?php else: ?>
                <table class="tabla-alumnos" style="font-size:0.85rem;">
                    <thead><tr><th>Año</th><th>Inicio</th><th>Fin</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($ciclos as $c): ?>
                            <tr>
                                <td><?= (int)$c['año'] ?></td>
                                <td><?= h(date('d/m/Y', strtotime($c['fecha_inicio']))) ?></td>
                                <td><?= h(date('d/m/Y', strtotime($c['fecha_fin']))) ?></td>
                                <td><?= $c['activo'] ? '✅ Activo' : '⏸️' ?></td>
                                <td>
                                    <?php if (!$c['activo']): ?>
                                        <button type="button" class="btn-mini" onclick="activarCiclo(<?= (int)$c['id'] ?>)">Activar</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

    <div class="header-seccion">
        <h2>Cursos existentes</h2>
        <div class="toggle-vista">
            <button type="button" class="btn-toggle" data-target="cursos" data-vista="lista" onclick="cambiarVista('cursos','lista',this)">📋 Lista</button>
            <button type="button" class="btn-toggle" data-target="cursos" data-vista="grid" onclick="cambiarVista('cursos','grid',this)">🔲 Cuadrícula</button>
        </div>
    </div>

    <div id="cursos-vista" class="vista-lista">
        <?php if (empty($asignaciones)): ?>
            <p class="alerta info">No hay cursos creados todavía.</p>
        <?php endif; ?>

        <?php foreach ($asignaciones as $a): ?>
            <div class="panel-vidrio curso-card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                    <h3><?= h($a['nombre']) ?> <small style="color:#cfe2ff;">(<?= h($a['nivel']) ?>)</small></h3>
                    <div class="curso-acciones">
                        <button type="button" class="btn-secundario" onclick="abrirEditarCurso(<?= (int)$a['id'] ?>)">✏️ Editar curso</button>
                        <button type="button" class="btn-secundario" onclick="duplicarAsignacion(<?= $a['id'] ?>)">📋 Duplicar</button>
                        <button type="button" class="btn-peligro" onclick="eliminarAsignacion(<?= $a['id'] ?>)">🗑️ Eliminar</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="header-seccion">
        <h2>Materias existentes</h2>
        <div class="toggle-vista">
            <button type="button" class="btn-toggle" data-target="materias" data-vista="lista" onclick="cambiarVista('materias','lista',this)">📋 Lista</button>
            <button type="button" class="btn-toggle" data-target="materias" data-vista="grid" onclick="cambiarVista('materias','grid',this)">🔲 Cuadrícula</button>
        </div>
    </div>

    <div id="materias-vista" class="vista-lista">
        <?php if (empty($materias)): ?>
            <p class="alerta info">No hay materias creadas todavía.</p>
        <?php else: ?>
            <?php foreach ($materias as $m): ?>
                <div class="panel-vidrio materia-card">
                    <div style="display:flex; flex-direction:column; gap:0.25rem;">
                        <strong><?= h($m['nombre']) ?></strong>
                        <small style="color:#cfe2ff;">
                            <?php
                                $partes = [];
                                if (!empty($m['orientacion'])) $partes[] = $m['orientacion'];
                                $cursos = $materiasCursos[(int)$m['id']] ?? [];
                                if (!empty($cursos)) {
                                    $nombresCursos = array_map(fn($c) => $c['asignacion_nombre'] . ' (' . $c['nivel_nombre'] . ')', $cursos);
                                    $partes[] = 'Cursos: ' . implode(', ', $nombresCursos);
                                }
                                echo $partes ? h(implode(' · ', $partes)) : 'Común a todas las orientaciones y cursos';
                            ?>
                        </small>
                    </div>
                    <div style="display:flex; gap:0.35rem;">
                        <button type="button" class="btn-secundario"
                            onclick='abrirEditarMateria(<?= jsonJs(['materia' => $m, 'cursos_ids' => array_map(fn($c) => (int)$c['id'], $cursos)]) ?>)'>✏️ Editar</button>
                        <button type="button" class="btn-peligro-sm" onclick="eliminarMateria(<?= $m['id'] ?>)">🗑️</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal-fondo oculto" id="modalEditarMateria">
    <div class="modal-caja" style="min-width: 480px; max-width: 90vw;">
        <h3>✏️ Editar materia</h3>
        <form id="formEditarMateria">
            <input type="hidden" name="id" id="editMatId">
            <label>Nombre
                <input type="text" name="nombre" id="editMatNombre" required maxlength="150" list="listaNombresMateriasEdit" autocomplete="off">
                <datalist id="listaNombresMateriasEdit">
                    <?php foreach ($materias as $m): ?>
                        <option value="<?= h($m['nombre']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </label>
            <label>Orientación (opcional)
                <select name="orientacion_id" id="editMatOrientacion">
                    <option value="">Sin orientación (común a todas)</option>
                    <?php foreach ($orientaciones as $o): ?>
                        <option value="<?= (int)$o['id'] ?>"><?= h($o['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Cursos específicos <span style="color:#fca5a5;">*</span>
                <small style="color:#a5c3e8; font-size:0.75rem; display:block; margin-bottom:0.35rem;">
                    Obligatorio: marcá al menos un curso donde aplica esta materia.
                </small>
                <div class="checkbox-cursos" data-target="editar">
                    <div class="checkbox-cursos-controles">
                        <input type="text" class="checkbox-cursos-buscar" placeholder="🔍 Buscar curso..." oninput="filtrarCursosCheckbox(this, 'editar')">
                        <button type="button" class="btn-mini" onclick="marcarTodosCursos('editar', true)">Todos</button>
                        <button type="button" class="btn-mini" onclick="marcarTodosCursos('editar', false)">Ninguno</button>
                    </div>
                    <div class="checkbox-cursos-lista" id="editarCursosLista">
                        <?php foreach ($asignaciones as $a): ?>
                            <label class="checkbox-cursos-item" data-nombre="<?= h(mb_strtolower($a['nombre'] . ' ' . $a['nivel'])) ?>">
                                <input type="checkbox" name="asignaciones_ids[]" value="<?= (int)$a['id'] ?>">
                                <span><?= h($a['nombre']) ?> <small style="color:#a5c3e8;">(<?= h($a['nivel']) ?>)</small></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($asignaciones)): ?>
                            <small style="color:#a5c3e8; padding:0.5rem;">No hay cursos creados todavía.</small>
                        <?php endif; ?>
                    </div>
                </div>
            </label>
            <div id="editMatError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalEditarMateria')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnGuardarMateria">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-fondo oculto" id="modalEditarCurso">
    <div class="modal-caja" style="min-width: 520px; max-width: 92vw; max-height: 90vh; overflow-y:auto;">
        <h3>✏️ Editar curso</h3>
        <form id="formEditarCurso">
            <input type="hidden" name="id" id="ecId">
            <label>Nombre del curso
                <input type="text" name="nombre" id="ecNombre" required maxlength="100" autocomplete="off">
            </label>
            <?php if ($modoDiaDisponible): ?>
            <input type="hidden" name="modo_enviado" value="1">
            <label class="label-checkbox" title="Si está activado, en Asistencias se pasa lista una sola vez por día para todo el curso, en lugar de por materia.">
                <input type="checkbox" name="asistencia_por_dia" id="ecPorDia" value="1">
                <span>🗓️ <strong>Asistencia única</strong> (por día completo, en vez de por materia)</span>
            </label>
            <?php else: ?>
            <small style="color:#fbbf24; display:block; margin:0.25rem 0;">Para elegir cómo se toma asistencia hay que ejecutar <code>sql/migracion_asistencia_por_dia.sql</code>.</small>
            <?php endif; ?>
        </form>

        <hr style="border-color: var(--borde-vidrio); margin: 1rem 0;">
        <h4 style="margin-bottom:0.5rem; color:#7dd3fc;">Divisiones</h4>
        <div class="divisiones-lista" id="ecDivisiones"></div>
        <form id="formEcDivision" style="margin-top:0.5rem;">
            <input type="text" name="nombre" placeholder="División (A, B...)" required maxlength="10" style="width:120px;">
            <select name="orientacion_id" id="ecOrientacion">
                <option value="">Sin orientación</option>
                <?php foreach ($orientaciones as $o): ?>
                    <option value="<?= $o['id'] ?>"><?= h($o['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-secundario">➕ Agregar a la lista</button>
        </form>

        <small style="color:#a5c3e8; display:block; margin-top:0.75rem;">Los cambios (nombre, asistencia y divisiones) se aplican todos juntos al tocar <strong>Guardar cambios</strong>.</small>
        <div id="ecError" class="alerta danger" style="display:none;"></div>
        <div class="modal-acciones">
            <button type="button" class="btn-secundario" onclick="cerrarEditarCurso()">Cerrar</button>
            <button type="submit" form="formEditarCurso" class="btn-primario">Guardar cambios</button>
        </div>
    </div>
</div>

<div class="modal-fondo oculto" id="modalDuplicar">
    <div class="modal-caja" style="min-width: 500px; max-width: 90vw;">
        <h3>📋 Duplicar curso</h3>
        <form id="formDuplicar">
            <input type="hidden" name="id" id="dupId">
            <label>Nombre del nuevo curso
                <input type="text" name="nombre" id="dupNombre" required maxlength="100">
            </label>

            <?php if ($modoDiaDisponible): ?>
            <input type="hidden" name="modo_enviado" value="1">
            <label class="label-checkbox" title="Si está activado, en Asistencias se pasa lista una sola vez por día para todo el curso, en lugar de por materia.">
                <input type="checkbox" name="asistencia_por_dia" id="dupPorDia" value="1">
                <span>🗓️ <strong>Asistencia única</strong> (por día completo, en vez de por materia)</span>
            </label>
            <?php endif; ?>

            <label class="label-checkbox">
                <input type="checkbox" name="copiar_divisiones" value="1" id="dupCopiarDivisiones" checked onchange="toggleSeccionDuplicar('divisiones')">
                <span>Duplicar <strong>divisiones</strong></span>
            </label>
            <div id="dupSeccionDivisiones" style="padding:0.5rem 1rem; background:rgba(0,0,0,0.15); border-radius:0.5rem; margin-bottom:0.5rem;">
                <div id="dupListaDivisiones"></div>
            </div>

            <label class="label-checkbox">
                <input type="checkbox" name="copiar_materias" value="1" id="dupCopiarMaterias" checked onchange="toggleSeccionDuplicar('materias')">
                <span>Duplicar <strong>materias específicas del curso</strong></span>
            </label>
            <div id="dupSeccionMaterias" style="padding:0.5rem 1rem; background:rgba(0,0,0,0.15); border-radius:0.5rem;">
                <div id="dupListaMaterias"></div>
                <small style="color:#a5c3e8; font-size:0.75rem; display:block; margin-top:0.35rem;">
                    Las materias seleccionadas se <strong>reutilizan</strong> (se asocian al curso nuevo sin duplicarlas).
                </small>
            </div>

            <div id="dupError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalDuplicar')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnDuplicar">Duplicar</button>
            </div>
        </form>
    </div>
</div>

<script>
window.CURSOS_DATA = {
    asignaciones: <?= jsonJs($asignaciones) ?>,
    divisionesPorAsignacion: <?= jsonJs($divisionesPorAsignacion) ?>,
    materiasPorAsignacion: <?= jsonJs($materiasPorAsignacion) ?>,
    orientaciones: <?= jsonJs($orientaciones) ?>
};
</script>
<script src="/assets/js/admin_cursos.js?v=<?= time() ?>"></script>

<?php include __DIR__ . '/../views/footer.php'; ?>