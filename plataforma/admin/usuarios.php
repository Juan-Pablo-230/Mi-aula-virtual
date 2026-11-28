<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/AcademicModel.php';

Auth::checkRole(ROLES_ALL);
if (($_SESSION['rol'] ?? '') === 'madre') {
    $instId = (int)($_GET['institucion_id'] ?? 0);
    if ($instId > 0) {
        header('Location: /plataforma/admin/institucion.php?id=' . $instId . '&tab=usuarios');
    } else {
        header('Location: /plataforma/admin/instituciones.php');
    }
    exit;
}
Auth::checkRole(['directivo', 'secretario']);

$currentUser = Auth::currentUser($pdo);
$miInstitucionId = (int)($currentUser['institucion_id'] ?? 0);
if (!$miInstitucionId) die('Tu usuario no tiene institución asignada. Contactá al administrador.');

$stmt = $pdo->prepare("SELECT nombre FROM instituciones WHERE id = ?");
$stmt->execute([$miInstitucionId]);
$miInstitucionNombre = $stmt->fetchColumn() ?: 'Mi institución';

$ciclos = AcademicModel::ciclosLectivos($pdo, $miInstitucionId);
$cicloActivo = AcademicModel::cicloActivo($pdo, $miInstitucionId);

$divisiones = $pdo->prepare("
    SELECT d.id, d.nombre, a.nombre AS asignacion, d.orientacion_id, d.asignacion_id
    FROM divisiones d
    JOIN asignaciones a ON a.id = d.asignacion_id
    WHERE a.institucion_id = ?
    ORDER BY a.nombre, d.nombre
");
$divisiones->execute([$miInstitucionId]);
$divisiones = $divisiones->fetchAll();

$stmt = $pdo->prepare("
    SELECT u.*, (ui.usuario_id IS NOT NULL) AS es_secundario, ui.rol AS rol_aca,
           (SELECT i2.nombre FROM instituciones i2 WHERE i2.id = u.institucion_id) AS inst_principal
    FROM usuarios u
    LEFT JOIN usuarios_instituciones ui ON ui.usuario_id = u.id AND ui.institucion_id = ?
    WHERE u.institucion_id = ? OR ui.usuario_id IS NOT NULL
    ORDER BY u.rol, u.apellido, u.nombre
");
$stmt->execute([$miInstitucionId, $miInstitucionId]);
$usuarios = $stmt->fetchAll();
// Personal de otra institución que también trabaja acá: se muestra con el rol que tiene EN esta institución
foreach ($usuarios as &$uRol) {
    $uRol['es_secundario'] = (int)$uRol['es_secundario'];
    if ($uRol['es_secundario']) $uRol['rol'] = $uRol['rol_aca'];
}
unset($uRol);
// Un alumno puede tener varias cursadas a la vez en la institución
$cursadasPorAlumno = AcademicModel::cursadasPorAlumno($pdo, $miInstitucionId);
foreach ($usuarios as &$uCur) $uCur['cursadas'] = $cursadasPorAlumno[(int)$uCur['id']] ?? [];
unset($uCur);

$tab = $_GET['tab'] ?? 'personal';
if (!in_array($tab, ['personal', 'alumnos', 'familiares', 'solicitudes'], true)) $tab = 'personal';
$rolesPersonal = ['docente', 'secretario', 'directivo'];
$cantTab = ['personal' => 0, 'alumnos' => 0, 'familiares' => 0];
$lista = [];
foreach ($usuarios as $u) {
    $t = $u['rol'] === 'alumno' ? 'alumnos' : ($u['rol'] === 'familiar' ? 'familiares' : 'personal');
    $cantTab[$t]++;
    if ($t === $tab) $lista[] = $u;
}

// Alumnos a cargo de cada familiar
$alumnosDeFamiliar = [];
if ($tab === 'familiares') {
    $stF = $pdo->prepare("
        SELECT fa.familiar_id, al.apellido, al.nombre
        FROM familiares_alumnos fa
        JOIN usuarios f ON f.id = fa.familiar_id
        JOIN usuarios al ON al.id = fa.alumno_id
        WHERE f.institucion_id = ?
        ORDER BY al.apellido, al.nombre
    ");
    $stF->execute([$miInstitucionId]);
    foreach ($stF as $r) $alumnosDeFamiliar[(int)$r['familiar_id']][] = nombreCompleto($r);
}

// Solicitudes de transferencia
$solRecibidas = $solEnviadas = [];
$pendientesRecibidas = 0;
$solicitudesError = false;
try {
    $stS = $pdo->prepare("
        SELECT s.*, al.apellido, al.nombre, al.dni, io.nombre AS inst_origen, idd.nombre AS inst_destino,
               CONCAT_WS(', ', sp.apellido, sp.nombre) AS solicitante
        FROM solicitudes_transferencia s
        JOIN usuarios al ON al.id = s.alumno_id
        JOIN instituciones io ON io.id = s.institucion_origen_id
        JOIN instituciones idd ON idd.id = s.institucion_destino_id
        LEFT JOIN usuarios sp ON sp.id = s.solicitada_por
        WHERE s.institucion_origen_id = ? OR s.institucion_destino_id = ?
        ORDER BY (s.estado = 'pendiente') DESC, s.creada_en DESC
        LIMIT 200
    ");
    $stS->execute([$miInstitucionId, $miInstitucionId]);
    foreach ($stS as $r) {
        if ((int)$r['institucion_origen_id'] === $miInstitucionId) {
            $solRecibidas[] = $r;
            if ($r['estado'] === 'pendiente') $pendientesRecibidas++;
        } else {
            $solEnviadas[] = $r;
        }
    }
} catch (Exception $e) {
    $solicitudesError = true; // falta ejecutar sql/migracion_solicitudes_transferencia.sql
}

$stmtMaterias = $pdo->prepare("
    SELECT m.id, m.nombre, m.orientacion_id
    FROM materias m
    WHERE m.institucion_id = ?
    ORDER BY m.nombre
");
$stmtMaterias->execute([$miInstitucionId]);
$materias = $stmtMaterias->fetchAll();

$stmtCursosMat = $pdo->prepare("
    SELECT ma.materia_id, ma.asignacion_id
    FROM materias_asignaciones ma
    JOIN materias m ON m.id = ma.materia_id
    WHERE m.institucion_id = ?
");
$stmtCursosMat->execute([$miInstitucionId]);
$materiasCursos = [];
foreach ($stmtCursosMat as $row) $materiasCursos[(int)$row['materia_id']][] = (int)$row['asignacion_id'];
foreach ($materias as &$m) $m['cursos_ids'] = $materiasCursos[(int)$m['id']] ?? [];
unset($m);

$asignacionesDocentes = [];
$stmt = $pdo->prepare("
    SELECT ad.docente_id, ad.materia_id, ad.division_id
    FROM asignaciones_docente ad
    JOIN divisiones d ON d.id = ad.division_id
    JOIN asignaciones a ON a.id = d.asignacion_id
    WHERE a.institucion_id = ?
");
$stmt->execute([$miInstitucionId]);
foreach ($stmt as $row) $asignacionesDocentes[$row['docente_id']][] = $row;
?>
<?php include __DIR__ . '/../views/header.php'; ?>
<?php include __DIR__ . '/../views/menu.php'; ?>

<main class="contenido-principal">
    <h1>👥 Administrar Usuarios — <?= h($miInstitucionNombre) ?></h1>
    <?php include __DIR__ . '/../views/admin_nav.php'; ?>

    <nav class="admin-nav sub-tabs" style="margin-bottom:1rem;">
        <a href="?tab=personal" class="admin-nav-item <?= $tab === 'personal' ? 'activo' : '' ?>">🏫 Personal de la institución (<?= $cantTab['personal'] ?>)</a>
        <a href="?tab=alumnos" class="admin-nav-item <?= $tab === 'alumnos' ? 'activo' : '' ?>">🎓 Alumnos (<?= $cantTab['alumnos'] ?>)</a>
        <a href="?tab=familiares" class="admin-nav-item <?= $tab === 'familiares' ? 'activo' : '' ?>">👪 Padres / Tutores (<?= $cantTab['familiares'] ?>)</a>
        <a href="?tab=solicitudes" class="admin-nav-item <?= $tab === 'solicitudes' ? 'activo' : '' ?>">🔄 Solicitudes de transferencia<?= $pendientesRecibidas ? ' <span class="badge-pendiente">' . $pendientesRecibidas . '</span>' : '' ?></a>
    </nav>

<?php if ($tab !== 'solicitudes'): ?>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1rem;">
        <button class="btn-primario" onclick="abrirModalCrear('<?= $tab === 'alumnos' ? 'alumno' : ($tab === 'familiares' ? 'familiar' : '') ?>')">➕ Crear <?= $tab === 'alumnos' ? 'alumno' : ($tab === 'familiares' ? 'padre / tutor' : 'personal') ?></button>
        <?php if ($tab === 'alumnos'): ?>
        <button class="btn-secundario" id="btnTransferir" onclick="abrirModalTransferir()" disabled>
            🔄 Transferir seleccionados (<span id="contadorSeleccionados">0</span>)
        </button>
        <?php endif; ?>
    </div>

    <div class="panel-vidrio">
        <div style="margin-bottom:0.75rem; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
            <?php if ($tab === 'alumnos'): ?>
            <label class="label-checkbox" style="margin:0;">
                <input type="checkbox" id="chkTodosAlumnos" onchange="marcarTodosAlumnos(this.checked)">
                <span>Seleccionar todos los alumnos</span>
            </label>
            <?php endif; ?>
            <input type="text" id="buscarAlumno" placeholder="🔍 Buscar..." oninput="filtrarAlumnos()" style="flex:1; min-width:10rem; padding:0.4rem 0.6rem; border-radius:0.5rem; border:0.0625rem solid var(--borde-vidrio); background:rgba(255,255,255,0.06); color:#fff; font-size:0.85rem;">
        </div>

        <table class="tabla-alumnos" id="tablaUsuarios">
            <thead>
                <tr>
                    <?php if ($tab === 'alumnos'): ?><th style="width:2.5rem;"></th><?php endif; ?>
                    <th>Apellido, Nombre</th>
                    <th>DNI</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th><?= $tab === 'familiares' ? 'Alumnos a cargo' : 'Asignación' ?></th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lista as $u): ?>
                    <tr data-rol="<?= h($u['rol']) ?>" data-nombre="<?= h(mb_strtolower($u['apellido'] . ' ' . $u['nombre'])) ?>">
                        <?php if ($tab === 'alumnos'): ?><td>
                            <?php if ($u['rol'] === 'alumno'): ?>
                                <input type="checkbox" class="chk-alumno" value="<?= (int)$u['id'] ?>" onchange="actualizarContador()">
                            <?php endif; ?>
                        </td><?php endif; ?>
                        <td><?= h(nombreCompleto($u)) ?><?php if (!empty($u['es_secundario'])): ?> <span class="badge-otra-inst" title="Su institución principal es <?= h($u['inst_principal'] ?? '—') ?>">también en <?= h($u['inst_principal'] ?? '—') ?></span><?php endif; ?></td>
                        <td><?= h($u['dni']) ?></td>
                        <td><?= h($u['email'] ?? '—') ?></td>
                        <td><?= h(ucfirst($u['rol'])) ?></td>
                        <td>
                            <?php if ($u['rol'] === 'alumno'): ?>
                                <?php if (empty($u['cursadas'])): ?>
                                    <em style="color:#a5c3e8;">Sin división</em>
                                <?php else: foreach ($u['cursadas'] as $cu): ?>
                                    <div><?= h($cu['nombre']) ?>
                                        <?php if ($cu['solo']): ?><small style="color:#7dd3fc;">· parcial (<?= count($cu['materias']) ?> materia<?= count($cu['materias']) === 1 ? '' : 's' ?>)</small><?php endif; ?>
                                        <?php if ($cu['anio']): ?><small style="color:#a5c3e8;">· <?= (int)$cu['anio'] ?></small><?php endif; ?>
                                    </div>
                                <?php endforeach; endif; ?>
                            <?php elseif ($u['rol'] === 'docente'): ?>
                                <?php $asigs = $asignacionesDocentes[$u['id']] ?? []; ?>
                                <?= empty($asigs) ? '<em style="color:#a5c3e8;">Sin asignaciones</em>' : count($asigs) . ' asignación(es)' ?>
                            <?php elseif ($u['rol'] === 'familiar'): ?>
                                <?php $acargo = $alumnosDeFamiliar[(int)$u['id']] ?? []; ?>
                                <?= $acargo ? h(implode(' · ', $acargo)) : '<em style="color:#a5c3e8;">Sin alumnos vinculados</em>' ?>
                            <?php else: ?>
                                <em style="color:#a5c3e8;">—</em>
                            <?php endif; ?>
                        </td>
                        <td><?= $u['activo'] ? '✅' : '❌' ?></td>
                        <td>
                            <button type="button" class="btn-secundario" onclick='abrirEditar(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>, <?= jsonJs($asignacionesDocentes[$u['id']] ?? []) ?>, 0)'>✏️ Editar</button>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <button type="button" class="btn-peligro-sm" onclick="eliminarUsuario(<?= $u['id'] ?>, <?= !empty($u['es_secundario']) ? 'true' : 'false' ?>)" title="<?= !empty($u['es_secundario']) ? 'Quitar de esta institución' : 'Eliminar' ?>">🗑️</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$lista): ?><p style="color:#a5c3e8; margin-top:0.75rem;">No hay usuarios en esta sección.</p><?php endif; ?>
    </div>

<?php else: ?>
    <?php if ($solicitudesError): ?>
        <div class="alerta danger">Falta crear la tabla de solicitudes: ejecutá <code>sql/migracion_solicitudes_transferencia.sql</code>.</div>
    <?php endif; ?>
    <?php
    $etiquetaEstado = ['pendiente' => '⏳ Pendiente', 'aprobada' => '✅ Aprobada', 'denegada' => '❌ Denegada', 'cancelada' => '🚫 Cancelada'];
    ?>
    <div class="panel-vidrio" style="margin-bottom:1rem;">
        <h3>📥 Recibidas — alumnos de tu institución que otra institución quiere incorporar</h3>
        <p style="color:#a5c3e8; font-size:0.85rem;">Si aprobás, el alumno pasa a la otra institución y deja de verse en la tuya.</p>
        <?php if (!$solRecibidas): ?>
            <p style="color:#a5c3e8;">No recibiste solicitudes.</p>
        <?php else: ?>
        <table class="tabla-alumnos">
            <thead><tr><th>Alumno</th><th>DNI</th><th>Institución que lo solicita</th><th>Solicitó</th><th>Fecha</th><th>Nota</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($solRecibidas as $r): ?>
                <tr>
                    <td><?= h(nombreCompleto($r)) ?></td>
                    <td><?= h($r['dni']) ?></td>
                    <td><?= h($r['inst_destino']) ?></td>
                    <td><?= h($r['solicitante'] ?? '—') ?></td>
                    <td><?= h(date('d/m/Y', strtotime($r['creada_en']))) ?></td>
                    <td><?= h($r['nota'] ?? '—') ?></td>
                    <td><?= h($etiquetaEstado[$r['estado']] ?? $r['estado']) ?></td>
                    <td>
                        <?php if ($r['estado'] === 'pendiente'): ?>
                            <button type="button" class="btn-primario" onclick="resolverSolicitud(<?= (int)$r['id'] ?>, 'aprobar')">Aprobar</button>
                            <button type="button" class="btn-peligro-sm" onclick="resolverSolicitud(<?= (int)$r['id'] ?>, 'denegar')">Denegar</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="panel-vidrio">
        <h3>📤 Enviadas — alumnos de otras instituciones que querés incorporar</h3>
        <p style="color:#a5c3e8; font-size:0.85rem;">Para pedir una transferencia, intentá crear al alumno con su DNI: si ya está en otra institución, el sistema te ofrece enviar la solicitud.</p>
        <?php if (!$solEnviadas): ?>
            <p style="color:#a5c3e8;">No enviaste solicitudes.</p>
        <?php else: ?>
        <table class="tabla-alumnos">
            <thead><tr><th>Alumno</th><th>DNI</th><th>Institución actual</th><th>Fecha</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($solEnviadas as $r): ?>
                <tr>
                    <td><?= h(nombreCompleto($r)) ?></td>
                    <td><?= h($r['dni']) ?></td>
                    <td><?= h($r['inst_origen']) ?></td>
                    <td><?= h(date('d/m/Y', strtotime($r['creada_en']))) ?></td>
                    <td><?= h($etiquetaEstado[$r['estado']] ?? $r['estado']) ?></td>
                    <td><?php if ($r['estado'] === 'pendiente'): ?>
                        <button type="button" class="btn-secundario" onclick="cancelarSolicitud(<?= (int)$r['id'] ?>)">Cancelar solicitud</button>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

<?php endif; ?>
</main>

<div class="modal-fondo oculto" id="modalSolicitud">
    <div class="modal-caja" style="min-width: 400px; max-width: 90vw;">
        <h3>🔄 Alumno registrado en otra institución</h3>
        <p id="solTexto" style="color:#cfe2ff; margin-bottom:0.75rem;"></p>
        <p style="color:#cfe2ff; font-size:0.9rem; margin-bottom:0.5rem;">Podés enviar una solicitud de transferencia: la otra institución la verá en su sección de Usuarios y decidirá si la aprueba o la deniega.</p>
        <label id="solNotaLabel">Nota para la otra institución (opcional)
            <textarea id="solNota" maxlength="300" rows="3" style="width:100%;"></textarea>
        </label>
        <div class="modal-acciones">
            <button type="button" class="btn-secundario" onclick="cerrarModal('modalSolicitud')">Cancelar</button>
            <button type="button" class="btn-primario" id="btnEnviarSolicitud" onclick="enviarSolicitud()">Continuar</button>
        </div>
    </div>
</div>

<div class="modal-fondo oculto" id="modalUser">
    <div class="modal-caja" style="min-width: 500px; max-width: 90vw;">
        <h3 id="modalUserTitulo">Crear usuario</h3>
        <form id="formUser">
            <input type="hidden" name="id" id="userId">
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
                    <option value="familiar">Padre / Tutor</option>
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
                <p style="font-size:0.8rem; color:#a5c3e8; margin:0.25rem 0 0;">Solo se pide el DNI: el familiar completará sus datos en su primer ingreso.</p>
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

<div class="modal-fondo oculto" id="modalTransferir">
    <div class="modal-caja" style="min-width: 500px; max-width: 90vw;">
        <h3>🔄 Transferir alumnos</h3>
        <p style="color:#cfe2ff; font-size:0.85rem; margin-bottom:1rem;">
            Vas a transferir <strong><span id="cantTransferir">0</span></strong> alumno(s) seleccionado(s).
        </p>
        <form id="formTransferir">
            <label>Motivo
                <select name="motivo" id="transMotivo" required onchange="actualizarMotivoTransferir()">
                    <option value="promocion">Promoción (pasa al curso destino)</option>
                    <option value="repite">Repetición (se queda en el mismo curso)</option>
                    <option value="transferencia">Transferencia (cambio de curso)</option>
                </select>
            </label>

            <label id="labelDivisionDestino">División destino
                <select name="division_destino_id" id="transDivisionDestino" required>
                    <option value="">— Elegí la división destino —</option>
                    <?php foreach ($divisiones as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"><?= h($d['asignacion'] . ' ' . $d['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Ciclo lectivo destino
                <select name="ciclo_destino_id" id="transCicloDestino" required>
                    <option value="">— Elegí el ciclo —</option>
                    <?php foreach ($ciclos as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($cicloActivo && $cicloActivo['id'] == $c['id']) ? 'selected' : '' ?>>
                            <?= (int)$c['año'] ?> (<?= h(date('d/m/Y', strtotime($c['fecha_inicio']))) ?> → <?= h(date('d/m/Y', strtotime($c['fecha_fin']))) ?>)<?= $c['activo'] ? ' · ACTIVO' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Fecha efectiva
                <input type="date" name="fecha" id="transFecha" required value="<?= date('Y-m-d') ?>">
            </label>

            <div id="transError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalTransferir')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnConfirmarTransferir">Confirmar transferencia</button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/js/cursadas-alumno.js"></script>
<script>
const API = '/plataforma/api/usuarios_ajax.php';
const DIVISIONES = <?= jsonJs($divisiones) ?>;
const MATERIAS = <?= jsonJs($materias) ?>;

let asignacionesActuales = [];
let divisionActual = 0;
let cursadasActuales = [];

function materiasCompatiblesConDivision(divisionId) {
    const div = DIVISIONES.find(d => d.id == divisionId);
    if (!div) return [];
    const divOrient = parseInt(div.orientacion_id || 0, 10);
    const divAsig = parseInt(div.asignacion_id || 0, 10);
    return MATERIAS.filter(m => {
        const matOrient = parseInt(m.orientacion_id || 0, 10);
        const cursosIds = (m.cursos_ids || []).map(Number);
        if (matOrient > 0 && matOrient !== divOrient) return false;
        if (cursosIds.length > 0 && !cursosIds.includes(divAsig)) return false;
        return true;
    });
}

function marcarTodosAlumnos(marcar) {
    document.querySelectorAll('.chk-alumno').forEach(chk => {
        const fila = chk.closest('tr');
        if (fila && fila.style.display !== 'none') chk.checked = marcar;
    });
    actualizarContador();
}

function actualizarContador() {
    const seleccionados = document.querySelectorAll('.chk-alumno:checked').length;
    const cont = document.getElementById('contadorSeleccionados');
    const btnT = document.getElementById('btnTransferir');
    if (cont) cont.textContent = seleccionados;
    if (btnT) btnT.disabled = seleccionados === 0;
}

function filtrarAlumnos() {
    const q = document.getElementById('buscarAlumno').value.toLowerCase().trim();
    document.querySelectorAll('#tablaUsuarios tbody tr').forEach(tr => {
        const nombre = tr.dataset.nombre || '';
        tr.style.display = (!q || nombre.includes(q)) ? '' : 'none';
    });
    actualizarContador();
}

function abrirModalTransferir() {
    const seleccionados = Array.from(document.querySelectorAll('.chk-alumno:checked')).map(c => c.value);
    if (seleccionados.length === 0) {
        mostrarToast('Seleccioná al menos un alumno', 'error');
        return;
    }
    document.getElementById('cantTransferir').textContent = seleccionados.length;
    document.getElementById('transError').style.display = 'none';
    document.getElementById('btnConfirmarTransferir').disabled = false;
    document.getElementById('btnConfirmarTransferir').textContent = 'Confirmar transferencia';
    actualizarMotivoTransferir();
    document.getElementById('modalTransferir').classList.remove('oculto');
}

function actualizarMotivoTransferir() {
    const motivo = document.getElementById('transMotivo').value;
    const lblDiv = document.getElementById('labelDivisionDestino');
    const selDiv = document.getElementById('transDivisionDestino');
    if (motivo === 'repite') {
        lblDiv.style.display = 'none';
        selDiv.removeAttribute('required');
    } else {
        lblDiv.style.display = 'block';
        selDiv.setAttribute('required', 'required');
    }
}

document.getElementById('formTransferir')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = document.getElementById('transError');
    const btn = document.getElementById('btnConfirmarTransferir');
    if (err) err.style.display = 'none';
    if (btn) { btn.disabled = true; btn.textContent = 'Transfiriendo...'; }

    const seleccionados = Array.from(document.querySelectorAll('.chk-alumno:checked')).map(c => c.value);
    const formData = new FormData(e.target);
    formData.delete('alumnos_ids[]');
    seleccionados.forEach(id => formData.append('alumnos_ids[]', id));

    try {
        const r = await fetchConSesion(API + '?action=transferir_alumnos', { method: 'POST', body: formData });
        const d = await r.json();
        if (d.ok) {
            mostrarToast(d.procesados + ' alumno(s) transferido(s) correctamente', 'ok');
            setTimeout(() => location.reload(), 900);
        } else {
            if (err) { err.textContent = d.mensaje || 'Error al transferir'; err.style.display = 'block'; }
            if (btn) { btn.disabled = false; btn.textContent = 'Confirmar transferencia'; }
        }
    } catch (ex) {
        if (ex.message === 'Sesión expirada') return;
        if (err) { err.textContent = 'Error de red: ' + ex.message; err.style.display = 'block'; }
        if (btn) { btn.disabled = false; btn.textContent = 'Confirmar transferencia'; }
    }
});

function actualizarAsignacionForm() {
    const rol = document.getElementById('userRol').value;
    const seccion = document.getElementById('seccionAsignacion');
    const cont = document.getElementById('contenidoAsignacion');
    const campoFamiliar = document.getElementById('campoFamiliar');
    if (campoFamiliar) campoFamiliar.style.display = (rol === 'alumno') ? 'block' : 'none';

    if (rol === 'alumno') {
        seccion.style.display = 'block';
        cont.innerHTML = '<div id="cursadasAlumno"></div>';
        CursadasEditor.montar(document.getElementById('cursadasAlumno'), { divisiones: DIVISIONES, materiasDe: materiasCompatiblesConDivision, cursadas: cursadasActuales });
    } else if (rol === 'docente') {
        seccion.style.display = 'block';
        const asignadas = new Set((asignacionesActuales || []).map(a => `${a.materia_id}-${a.division_id}`));
        const materiasPorDivision = {};
        DIVISIONES.forEach(d => materiasPorDivision[d.id] = materiasCompatiblesConDivision(d.id));

        let html = `<p style="color:#cfe2ff; font-size:0.8rem; margin-bottom:0.5rem;">Las celdas atenuadas corresponden a materias incompatibles con el curso o la orientación de la división.</p>`;
        html += `<div class="tabla-asignaciones"><table style="width:100%; border-collapse:collapse; font-size:0.82rem;"><thead><tr><th style="text-align:left; padding:0.4rem; border-bottom:1px solid var(--borde-vidrio);">División \\ Materia</th>`;
        MATERIAS.forEach(m => { html += `<th style="padding:0.4rem; border-bottom:1px solid var(--borde-vidrio); font-size:0.72rem;">${m.nombre}</th>`; });
        html += `</tr></thead><tbody>`;
        DIVISIONES.forEach(d => {
            const compatibles = materiasPorDivision[d.id] || [];
            html += `<tr><td style="padding:0.4rem; border-bottom:1px solid rgba(255,255,255,0.05); white-space:nowrap;">${d.asignacion} ${d.nombre}</td>`;
            MATERIAS.forEach(m => {
                const key = `${m.id}-${d.id}`;
                const checked = asignadas.has(key) ? 'checked' : '';
                const esCompatible = compatibles.some(x => x.id == m.id);
                if (!esCompatible) {
                    html += `<td style="text-align:center; padding:0.4rem; border-bottom:1px solid rgba(255,255,255,0.05); opacity:0.25;"><input type="checkbox" disabled></td>`;
                } else {
                    html += `<td style="text-align:center; padding:0.4rem; border-bottom:1px solid rgba(255,255,255,0.05);"><input type="checkbox" name="asignaciones[]" value="${key}" ${checked}></td>`;
                }
            });
            html += `</tr>`;
        });
        html += `</tbody></table></div>`;
        cont.innerHTML = html;
    } else {
        seccion.style.display = 'none';
        cont.innerHTML = '';
    }
}


// Personal que trabaja en varias instituciones: sus datos personales los gestiona su institución principal.
// Solo el directivo puede cambiar nombre, apellido y DNI de un usuario existente
window.PUEDE_EDITAR_IDENTIDAD = <?= (($currentUser['rol'] ?? '') === 'directivo') ? 'true' : 'false' ?>;
function bloquearIdentidad(esEdicion) {
    const bloquear = esEdicion && !window.PUEDE_EDITAR_IDENTIDAD;
    ['userNombre', 'userApellido', 'userDni'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (bloquear) { el.readOnly = true; el.title = 'Solo dirección puede cambiar este dato'; }
        else if (!el.dataset.bloqSec) { el.title = ''; }
    });
}

function bloquearDatosPersonales(bloquear) {
    ['userNombre', 'userApellido', 'userDni', 'userEmail'].forEach(id => { const el = document.getElementById(id); if (el) el.readOnly = bloquear; });
    const act = document.getElementById('userActivo'); if (act) act.disabled = bloquear;
    let nota = document.getElementById('notaSecundario');
    if (bloquear && !nota) {
        nota = document.createElement('p');
        nota.id = 'notaSecundario'; nota.className = 'alerta info'; nota.style.fontSize = '0.85rem';
        nota.textContent = 'Esta persona trabaja también en otra institución: sus datos personales los edita su institución principal. Acá podés cambiar sus asignaciones.';
        document.getElementById('userNombre').closest('label').before(nota);
    }
    if (nota) nota.style.display = bloquear ? 'block' : 'none';
}

// El DNI ya es de personal de otra institución: preguntar si se lo suma a esta (con el rol elegido)
async function confirmarAgregarPersonal(d, rol, url, fd) {
    const donde = (d.instituciones || []).map(i => i.nombre + ' (' + i.rol + ')').join(', ');
    if (!confirm(d.persona + ' ya trabaja en: ' + donde + '.\n\n¿Querés sumarla/o también a esta institución como ' + rol + '?')) return null;
    fd.set('agregar_personal', '1');
    const r = await fetchConSesion(url, { method: 'POST', body: fd });
    return r.json();
}

// El rol es de solo lectura: fijo según la pestaña al crear, y no se puede cambiar al editar.
// Solo en "Personal" se elige entre los roles del personal.
function configurarRolSelect(rol, esEdicion) {
    const sel = document.getElementById('userRol');
    const libre = !esEdicion && rol === '';
    Array.from(sel.options).forEach(o => {
        const esPersonal = ['docente', 'secretario', 'directivo'].includes(o.value);
        o.hidden = o.disabled = libre && o.value !== '' && !esPersonal;
    });
    sel.value = rol;
    sel.disabled = !libre;
    sel.title = libre ? '' : 'El rol no se puede cambiar';
}

function abrirModalCrear(rolInicial) {
    document.getElementById('modalUserTitulo').textContent = 'Crear usuario';
    document.getElementById('formUser').reset();
    document.getElementById('userId').value = '';
    document.getElementById('userNombre').required = true;
    document.getElementById('campoPasswordInfo').style.display = 'block';
    document.getElementById('campoActivo').style.display = 'none';
    document.getElementById('campoEmail').style.display = 'none';
    document.getElementById('btnGuardarUser').textContent = 'Crear';
    document.getElementById('seccionAsignacion').style.display = 'none';
    document.getElementById('campoFamiliar').style.display = 'none';
    document.getElementById('contenidoAsignacion').innerHTML = '';
    asignacionesActuales = [];
    divisionActual = 0;
    cursadasActuales = [];
    configurarRolSelect(rolInicial || '', false);
    bloquearDatosPersonales(false);
    bloquearIdentidad(false);
    if (rolInicial) actualizarAsignacionForm();
    document.getElementById('modalUser').classList.remove('oculto');
}

function abrirEditar(u, asigs, divId) {
    document.getElementById('modalUserTitulo').textContent = 'Editar usuario';
    document.getElementById('userId').value = u.id;
    document.getElementById('userNombre').value = u.nombre;
    document.getElementById('userApellido').value = u.apellido;
    document.getElementById('userDni').value = u.dni;
    document.getElementById('userEmail').value = u.email || '';
    configurarRolSelect(u.rol, true);
    bloquearDatosPersonales(Number(u.es_secundario) === 1);
    bloquearIdentidad(true);
    document.getElementById('userNombre').required = u.rol !== 'familiar';
    document.getElementById('campoPasswordInfo').style.display = 'none';
    document.getElementById('campoActivo').style.display = 'block';
    document.getElementById('userActivo').value = u.activo;
    document.getElementById('campoEmail').style.display = 'block';
    document.getElementById('btnGuardarUser').textContent = 'Guardar cambios';
    asignacionesActuales = asigs || [];
    divisionActual = divId || 0;
    cursadasActuales = u.cursadas || [];
    actualizarAsignacionForm();
    document.getElementById('modalUser').classList.remove('oculto');
}

document.getElementById('formUser').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const isEdit = document.getElementById('userId').value !== '';
    const url = API + (isEdit ? '?action=editar' : '?action=crear');
    const fd = new FormData(form);
    if (!fd.has('rol')) fd.set('rol', form.rol.value); // el select puede estar bloqueado (no se envía solo)
    const rol = form.rol.value;
    if (rol === 'alumno') {
        const selectDiv = form.querySelector('select[name="division_id"]');
        if (selectDiv) fd.set('division_id', selectDiv.value || '0');
        const dniFam = document.getElementById('dniFamiliar');
        if (dniFam) fd.set('dni_familiar', dniFam.value.trim());
    } else if (rol === 'docente') {
        fd.delete('asignaciones[]');
        form.querySelectorAll('input[name="asignaciones[]"]:checked').forEach(chk => fd.append('asignaciones[]', chk.value));
    }
    const btn = document.getElementById('btnGuardarUser');
    btn.disabled = true;
    btn.textContent = 'Guardando...';
    try {
        const r = await fetchConSesion(url, { method: 'POST', body: fd });
        let d = await r.json();
        if (d.codigo === 'personal_otra_institucion') {
            const d2 = await confirmarAgregarPersonal(d, rol, url, fd);
            if (d2 === null) { btn.disabled = false; btn.textContent = 'Crear'; return; }
            d = d2;
        }
        if (d.ok) { mostrarToast(isEdit ? 'Usuario actualizado' : (d.agregado ? 'Agregado a tu institución' : 'Usuario creado'), 'ok'); setTimeout(() => location.reload(), 700); }
        else if (d.codigo === 'otra_institucion') {
            btn.disabled = false; btn.textContent = 'Crear';
            solicitudActual = d;
            document.getElementById('solTexto').innerHTML = '';
            document.getElementById('solTexto').appendChild(document.createTextNode('El DNI ingresado corresponde a ' + d.alumno + ' (' + (d.rol_existente || 'alumno') + '), que ya está registrado en «' + d.institucion + '».'));
            document.getElementById('solNota').value = '';
            const bs = document.getElementById('btnEnviarSolicitud');
            bs.disabled = !!d.solicitud_pendiente;
            bs.textContent = d.solicitud_pendiente ? 'Ya enviaste una solicitud pendiente' : 'Enviar solicitud de transferencia';
            document.getElementById('modalSolicitud').classList.remove('oculto');
        }
        else { mostrarToast(d.mensaje || 'Error', 'error'); btn.disabled = false; btn.textContent = isEdit ? 'Guardar cambios' : 'Crear'; }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
        btn.disabled = false;
        btn.textContent = isEdit ? 'Guardar cambios' : 'Crear';
    }
});

async function eliminarUsuario(id, secundario) {
    if (!confirm(secundario ? '¿Quitar a esta persona de tu institución? Su cuenta sigue existiendo en la otra institución.' : '¿Eliminar este usuario?')) return;
    const fd = new FormData(); fd.append('id', id);
    try {
        const r = await fetchConSesion(API + '?action=eliminar', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { mostrarToast('Usuario eliminado', 'ok'); setTimeout(() => location.reload(), 700); }
        else mostrarToast(d.mensaje || 'Error', 'error');
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
    }
}

let solicitudActual = null;

async function postAccion(accion, datos) {
    const fd = new FormData();
    Object.keys(datos).forEach(k => fd.append(k, datos[k]));
    const r = await fetchConSesion(API + '?action=' + accion, { method: 'POST', body: fd });
    return r.json();
}

async function enviarSolicitud() {
    if (!solicitudActual) return;
    const btn = document.getElementById('btnEnviarSolicitud');
    btn.disabled = true;
    try {
        const d = await postAccion('solicitar_transferencia', { alumno_id: solicitudActual.alumno_id, nota: document.getElementById('solNota').value });
        if (d.ok) { mostrarToast('Solicitud enviada', 'ok'); setTimeout(() => { location.href = '?tab=solicitudes'; }, 700); }
        else { mostrarToast(d.mensaje || 'Error', 'error'); btn.disabled = false; }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error'); btn.disabled = false;
    }
}

async function resolverSolicitud(id, decision) {
    const texto = decision === 'aprobar'
        ? '¿Aprobar la transferencia? El alumno saldrá de tu institución y perderá su división actual.'
        : '¿Denegar la solicitud de transferencia?';
    if (!confirm(texto)) return;
    try {
        const d = await postAccion('resolver_solicitud', { id: id, decision: decision });
        if (d.ok) { mostrarToast(decision === 'aprobar' ? 'Transferencia aprobada' : 'Solicitud denegada', 'ok'); setTimeout(() => location.reload(), 700); }
        else mostrarToast(d.mensaje || 'Error', 'error');
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
    }
}

async function cancelarSolicitud(id) {
    if (!confirm('¿Cancelar esta solicitud?')) return;
    try {
        const d = await postAccion('cancelar_solicitud', { id: id });
        if (d.ok) { mostrarToast('Solicitud cancelada', 'ok'); setTimeout(() => location.reload(), 700); }
        else mostrarToast(d.mensaje || 'Error', 'error');
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
    }
}

document.addEventListener('DOMContentLoaded', actualizarContador);
</script>

<?php include __DIR__ . '/../views/footer.php'; ?>