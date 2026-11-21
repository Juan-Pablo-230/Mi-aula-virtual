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
    SELECT u.*, d.id AS division_id, d.nombre AS division_nombre, a.nombre AS asignacion_nombre,
           h.año_lectivo AS año_actual, h.estado AS estado_historial
    FROM usuarios u
    LEFT JOIN alumnos_division ad ON ad.alumno_id = u.id
    LEFT JOIN divisiones d ON d.id = ad.division_id
    LEFT JOIN asignaciones a ON a.id = d.asignacion_id
    LEFT JOIN alumnos_division_historial h ON h.alumno_id = u.id AND h.activo = 1
    WHERE u.institucion_id = ?
    ORDER BY u.rol, u.apellido, u.nombre
");
$stmt->execute([$miInstitucionId]);
$usuarios = $stmt->fetchAll();

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
    JOIN usuarios u ON u.id = ad.docente_id
    WHERE u.institucion_id = ?
");
$stmt->execute([$miInstitucionId]);
foreach ($stmt as $row) $asignacionesDocentes[$row['docente_id']][] = $row;
?>
<?php include __DIR__ . '/../views/header.php'; ?>
<?php include __DIR__ . '/../views/menu.php'; ?>

<main class="contenido-principal">
    <h1>👥 Administrar Usuarios — <?= h($miInstitucionNombre) ?></h1>
    <?php include __DIR__ . '/../views/admin_nav.php'; ?>

    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1rem;">
        <button class="btn-primario" onclick="abrirModalCrear()">➕ Crear usuario</button>
        <button class="btn-secundario" id="btnTransferir" onclick="abrirModalTransferir()" disabled>
            🔄 Transferir seleccionados (<span id="contadorSeleccionados">0</span>)
        </button>
    </div>

    <div class="panel-vidrio">
        <div style="margin-bottom:0.75rem; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
            <label class="label-checkbox" style="margin:0;">
                <input type="checkbox" id="chkTodosAlumnos" onchange="marcarTodosAlumnos(this.checked)">
                <span>Seleccionar todos los alumnos</span>
            </label>
            <input type="text" id="buscarAlumno" placeholder="🔍 Buscar alumno..." oninput="filtrarAlumnos()" style="flex:1; min-width:10rem; padding:0.4rem 0.6rem; border-radius:0.5rem; border:0.0625rem solid var(--borde-vidrio); background:rgba(255,255,255,0.06); color:#fff; font-size:0.85rem;">
        </div>

        <table class="tabla-alumnos" id="tablaUsuarios">
            <thead>
                <tr>
                    <th style="width:2.5rem;"></th>
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
                    <tr data-rol="<?= h($u['rol']) ?>" data-nombre="<?= h(mb_strtolower($u['apellido'] . ' ' . $u['nombre'])) ?>">
                        <td>
                            <?php if ($u['rol'] === 'alumno'): ?>
                                <input type="checkbox" class="chk-alumno" value="<?= (int)$u['id'] ?>" onchange="actualizarContador()">
                            <?php endif; ?>
                        </td>
                        <td><?= h($u['apellido'] . ', ' . $u['nombre']) ?></td>
                        <td><?= h($u['dni']) ?></td>
                        <td><?= h($u['email'] ?? '—') ?></td>
                        <td><?= h(ucfirst($u['rol'])) ?></td>
                        <td>
                            <?php if ($u['rol'] === 'alumno'): ?>
                                <?= $u['asignacion_nombre'] ? h($u['asignacion_nombre'] . ' ' . $u['division_nombre']) : '<em style="color:#a5c3e8;">Sin división</em>' ?>
                                <?php if ($u['año_actual']): ?>
                                    <br><small style="color:#a5c3e8;">Año lectivo: <?= (int)$u['año_actual'] ?></small>
                                <?php endif; ?>
                            <?php elseif ($u['rol'] === 'docente'): ?>
                                <?php $asigs = $asignacionesDocentes[$u['id']] ?? []; ?>
                                <?= empty($asigs) ? '<em style="color:#a5c3e8;">Sin asignaciones</em>' : count($asigs) . ' asignación(es)' ?>
                            <?php else: ?>
                                <em style="color:#a5c3e8;">—</em>
                            <?php endif; ?>
                        </td>
                        <td><?= $u['activo'] ? '✅' : '❌' ?></td>
                        <td>
                            <button type="button" class="btn-secundario" onclick='abrirEditar(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>, <?= json_encode($asignacionesDocentes[$u['id']] ?? []) ?>, <?= (int)($u['division_id'] ?? 0) ?>)'>✏️ Editar</button>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <button type="button" class="btn-peligro-sm" onclick="eliminarUsuario(<?= $u['id'] ?>)">🗑️</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<div class="modal-fondo oculto" id="modalUser">
    <div class="modal-caja" style="min-width: 500px; max-width: 90vw;">
        <h3 id="modalUserTitulo">Crear usuario</h3>
        <form id="formUser">
            <input type="hidden" name="id" id="userId">
            <label>Nombre <input type="text" name="nombre" id="userNombre" required></label>
            <label>Apellido <input type="text" name="apellido" id="userApellido" required></label>
            <label>DNI <input type="text" name="dni" id="userDni" required pattern="[0-9A-Za-z]+"></label>
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
                <label>DNI del familiar <input type="text" name="dni_familiar" id="dniFamiliar" pattern="[0-9A-Za-z]+"></label>
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

<script>
const API = '/plataforma/api/usuarios_ajax.php';
const DIVISIONES = <?= json_encode($divisiones) ?>;
const MATERIAS = <?= json_encode($materias) ?>;

let asignacionesActuales = [];
let divisionActual = 0;

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
    document.getElementById('contadorSeleccionados').textContent = seleccionados;
    document.getElementById('btnTransferir').disabled = seleccionados === 0;
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
        let html = `<label>División <select name="division_id" id="selectDivisionAlumno"><option value="0">— Sin división —</option>`;
        DIVISIONES.forEach(d => {
            const sel = (d.id == divisionActual) ? 'selected' : '';
            html += `<option value="${d.id}" ${sel}>${d.asignacion} ${d.nombre}</option>`;
        });
        html += `</select></label>`;
        cont.innerHTML = html;
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

function abrirModalCrear() {
    document.getElementById('modalUserTitulo').textContent = 'Crear usuario';
    document.getElementById('formUser').reset();
    document.getElementById('userId').value = '';
    document.getElementById('campoPasswordInfo').style.display = 'block';
    document.getElementById('campoActivo').style.display = 'none';
    document.getElementById('campoEmail').style.display = 'none';
    document.getElementById('btnGuardarUser').textContent = 'Crear';
    document.getElementById('seccionAsignacion').style.display = 'none';
    document.getElementById('campoFamiliar').style.display = 'none';
    document.getElementById('contenidoAsignacion').innerHTML = '';
    asignacionesActuales = [];
    divisionActual = 0;
    document.getElementById('modalUser').classList.remove('oculto');
}

function abrirEditar(u, asigs, divId) {
    document.getElementById('modalUserTitulo').textContent = 'Editar usuario';
    document.getElementById('userId').value = u.id;
    document.getElementById('userNombre').value = u.nombre;
    document.getElementById('userApellido').value = u.apellido;
    document.getElementById('userDni').value = u.dni;
    document.getElementById('userEmail').value = u.email || '';
    document.getElementById('userRol').value = u.rol;
    document.getElementById('campoPasswordInfo').style.display = 'none';
    document.getElementById('campoActivo').style.display = 'block';
    document.getElementById('userActivo').value = u.activo;
    document.getElementById('campoEmail').style.display = 'block';
    document.getElementById('btnGuardarUser').textContent = 'Guardar cambios';
    asignacionesActuales = asigs || [];
    divisionActual = divId || 0;
    actualizarAsignacionForm();
    document.getElementById('modalUser').classList.remove('oculto');
}

document.getElementById('formUser').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const isEdit = document.getElementById('userId').value !== '';
    const url = API + (isEdit ? '?action=editar' : '?action=crear');
    const fd = new FormData(form);
    const rol = form.rol.value;
    if (rol === 'alumno') {
        const selectDiv = form.querySelector('select[name="division_id"]');
        if (selectDiv) fd.set('division_id', selectDiv.value || '0');
        const dniFam = document.getElementById('dniFamiliar');
        const nomFam = document.getElementById('nombreFamiliar');
        if (dniFam) fd.set('dni_familiar', dniFam.value.trim());
        if (nomFam) fd.set('nombre_familiar', nomFam.value.trim());
    } else if (rol === 'docente') {
        fd.delete('asignaciones[]');
        form.querySelectorAll('input[name="asignaciones[]"]:checked').forEach(chk => fd.append('asignaciones[]', chk.value));
    }
    const btn = document.getElementById('btnGuardarUser');
    btn.disabled = true;
    btn.textContent = 'Guardando...';
    try {
        const r = await fetchConSesion(url, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { mostrarToast(isEdit ? 'Usuario actualizado' : 'Usuario creado', 'ok'); setTimeout(() => location.reload(), 700); }
        else { mostrarToast(d.mensaje || 'Error', 'error'); btn.disabled = false; btn.textContent = isEdit ? 'Guardar cambios' : 'Crear'; }
    } catch (err) {
        if (err.message === 'Sesión expirada') return;
        mostrarToast('Error de red: ' + err.message, 'error');
        btn.disabled = false;
        btn.textContent = isEdit ? 'Guardar cambios' : 'Crear';
    }
});

async function eliminarUsuario(id) {
    if (!confirm('¿Eliminar este usuario?')) return;
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

document.addEventListener('DOMContentLoaded', actualizarContador);
</script>

<?php include __DIR__ . '/../views/footer.php'; ?>