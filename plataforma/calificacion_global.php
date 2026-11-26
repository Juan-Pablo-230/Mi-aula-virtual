<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/GradeHelpers.php';
require_once __DIR__ . '/core/MongoIndices.php';

// Calificación global (p. ej. "Conducta"): dirección/secretaría cargan a todos los alumnos de la institución; el docente, a los de sus divisiones
Auth::checkRole(['directivo', 'secretario', 'docente']);
$user = Auth::currentUser($pdo);
$miInst = (int)($user['institucion_id'] ?? 0);
if ($miInst <= 0) redirect('/plataforma/calificaciones.php');
MongoIndices::asegurar($mongoDB);

$gid = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/i', $gid)) redirect('/plataforma/calificaciones.php');
$global = $mongoDB->selectCollection('calificaciones_globales')->findOne(['_id' => new MongoDB\BSON\ObjectId($gid), 'institucion_id' => $miInst]);
if (!$global) redirect('/plataforma/calificaciones.php');
$añoSel = (int)$global['año_lectivo'];

// Alumnos de la institución con cursada activa en el año (con su curso/división)
$esDocente = ($user['rol'] === 'docente');
$stmt = $pdo->prepare("
    SELECT u.id, u.nombre, u.apellido,
           GROUP_CONCAT(DISTINCT CONCAT(a.nombre, ' ', d.nombre) ORDER BY a.nombre, d.nombre SEPARATOR ' · ') AS cursos
    FROM usuarios u
    JOIN alumnos_division_historial h ON h.alumno_id = u.id AND h.activo = 1 AND h.año_lectivo = ?
    JOIN divisiones d ON d.id = h.division_id
    JOIN asignaciones a ON a.id = d.asignacion_id AND a.institucion_id = ?
    " . ($esDocente ? "JOIN asignaciones_docente adoc ON adoc.division_id = d.id AND adoc.docente_id = ?" : "") . "
    WHERE u.rol = 'alumno' AND u.activo = 1 AND u.institucion_id = ?
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY cursos, u.apellido, u.nombre
");
$stmt->execute($esDocente ? [$añoSel, $miInst, (int)$user['id'], $miInst] : [$añoSel, $miInst, $miInst]);
$alumnos = $stmt->fetchAll();

$notas = [];
if ($alumnos) {
    $cur = $mongoDB->selectCollection('calificaciones_globales_notas')->find([
        'global_id' => $global['_id'], 'alumno_id' => ['$in' => array_map(fn($a) => (int)$a['id'], $alumnos)],
    ]);
    foreach ($cur as $n) $notas[(int)$n['alumno_id']] = (float)$n['nota'];
}
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem; flex-wrap:wrap;">
        <a href="/plataforma/calificaciones.php?año=<?= $añoSel ?>" class="btn-secundario">← Volver</a>
        <h1 style="margin:0;">🌐 <?= h($global['nombre']) ?> — <?= $añoSel ?></h1>
    </div>

    <div class="panel-vidrio">
        <?php if (empty($alumnos)): ?>
            <p class="alerta info">No hay alumnos con cursada activa en <?= $añoSel ?>.</p>
        <?php else: ?>
            <p style="color:#a5c3e8; font-size:0.85rem;">Cargá la nota de 0 a 10 de cada alumno (se puede usar coma o punto). Dejá el casillero vacío si todavía no tiene nota.</p>
            <table class="tabla-alumnos">
                <thead><tr><th>Curso</th><th>Alumno</th><th style="width:9rem;">Nota (0-10)</th><th>Se verá como</th></tr></thead>
                <tbody>
                <?php foreach ($alumnos as $a): $aid = (int)$a['id']; ?>
                    <tr>
                        <td><?= h($a['cursos']) ?></td>
                        <td><?= h(nombreCompleto($a)) ?></td>
                        <td><input type="text" class="input-nota-global" data-alumno="<?= $aid ?>" inputmode="decimal" maxlength="5"
                                   value="<?= isset($notas[$aid]) ? h(rtrim(rtrim(number_format($notas[$aid], 2, ',', ''), '0'), ',')) : '' ?>" style="width:6rem; margin:0;"></td>
                        <td class="vista-nota"><?= isset($notas[$aid]) ? h(formatoNota($notas[$aid])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:1rem; text-align:center;">
                <button class="btn-primario" id="btnGuardarGlobal">💾 Guardar notas</button>
                <span id="avisoGlobal" style="display:none; color:#fbbf24; margin-left:1rem;">Cambios sin guardar</span>
            </div>
        <?php endif; ?>
    </div>
</main>
<script>
const GLOBAL_ID = <?= jsonJs($gid) ?>;
const API_CAL_G = '/plataforma/api/calificaciones_ajax.php';
const ESCALA = { M: 2, R: 4, 'B-': 6, B: 7, MB: 8, 'MB+': 9, EX: 10 };
function letraDe(n) { let mejor = null, dif = 99; Object.entries(ESCALA).sort((a, b) => b[1] - a[1]).forEach(([l, v]) => { if (Math.abs(n - v) < dif) { dif = Math.abs(n - v); mejor = l; } }); return mejor; }
function fmt(n) {
    const num = Math.abs(n - Math.round(n)) < 0.005 ? String(Math.round(n)).padStart(2, '0') : n.toFixed(2).replace('.', ',').padStart(5, '0');
    return num + ' - ' + letraDe(n);
}
const inputs = document.querySelectorAll('.input-nota-global');
const originales = {};
inputs.forEach(i => { originales[i.dataset.alumno] = i.value; });
function parsear(v) { v = v.trim().replace(',', '.'); if (v === '') return ''; const n = Number(v); return (isNaN(n) || n < 0 || n > 10) ? null : n; }
function hayCambios() { return [...inputs].some(i => i.value !== originales[i.dataset.alumno]); }
inputs.forEach(i => i.addEventListener('input', () => {
    const n = parsear(i.value);
    i.style.outline = n === null ? '0.125rem solid #f87171' : '';
    i.closest('tr').querySelector('.vista-nota').textContent = (n === null || n === '') ? '—' : fmt(n);
    document.getElementById('avisoGlobal').style.display = hayCambios() ? 'inline' : 'none';
}));
document.getElementById('btnGuardarGlobal')?.addEventListener('click', async () => {
    const notas = {};
    for (const i of inputs) {
        if (i.value === originales[i.dataset.alumno]) continue;
        const n = parsear(i.value);
        if (n === null) { mostrarToast('Hay notas fuera de rango (0 a 10)', 'error'); i.focus(); return; }
        notas[i.dataset.alumno] = n;
    }
    if (!Object.keys(notas).length) { mostrarToast('No hay cambios para guardar', 'error'); return; }
    const fd = new FormData(); fd.append('global_id', GLOBAL_ID); fd.append('notas', JSON.stringify(notas));
    const btn = document.getElementById('btnGuardarGlobal'); btn.disabled = true;
    try {
        const r = await fetchConSesion(API_CAL_G + '?action=guardar_notas_global', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { mostrarToast('Notas guardadas', 'ok'); inputs.forEach(i => { originales[i.dataset.alumno] = i.value; }); document.getElementById('avisoGlobal').style.display = 'none'; }
        else mostrarToast(d.mensaje || 'Error', 'error');
    } catch (err) { if (err.message !== 'Sesión expirada') mostrarToast('Error de red: ' + err.message, 'error'); }
    btn.disabled = false;
});
window.addEventListener('beforeunload', e => { if (hayCambios()) { e.preventDefault(); e.returnValue = ''; } });
</script>
<?php include __DIR__ . '/views/footer.php'; ?>
