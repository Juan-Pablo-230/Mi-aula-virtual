<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/models/AcademicModel.php';

Auth::checkRole(ROLES_ALL);
$user = Auth::currentUser($pdo);
$rol  = $user['rol'];
$puedeSubir = in_array($rol, ['docente', 'directivo', 'secretario', 'madre']);

if ($rol === 'alumno') $materias = AcademicModel::materiasAlumno($pdo, (int)$user['id']);
elseif ($rol === 'familiar') $materias = AcademicModel::materiasDeFamiliar($pdo, (int)$user['id']);
elseif ($rol === 'docente') {
    $stmt = $pdo->prepare("SELECT DISTINCT m.id, m.nombre FROM asignaciones_docente ad JOIN materias m ON m.id = ad.materia_id WHERE ad.docente_id = ? ORDER BY m.nombre");
    $stmt->execute([$user['id']]); $materias = $stmt->fetchAll();
} elseif ($rol === 'madre') {
    $materias = $pdo->query("SELECT id, nombre FROM materias ORDER BY nombre")->fetchAll();
} else {
    // Directivo/secretario: solo las materias de SU institución
    $stmt = $pdo->prepare("SELECT id, nombre FROM materias WHERE institucion_id = ? ORDER BY nombre");
    $stmt->execute([(int)($user['institucion_id'] ?? 0)]);
    $materias = $stmt->fetchAll();
}

$materiaIds = array_map('intval', array_column($materias, 'id'));
$materiaSel = (int)($_GET['materia_id'] ?? 0);
$filtro = ['materia_id' => ['$in' => $materiaIds]];
if ($materiaSel > 0 && in_array($materiaSel, $materiaIds)) $filtro['materia_id'] = $materiaSel;

$docs = !empty($materiaIds) ? $mongoBibliografia->find($filtro, ['sort' => ['fecha' => -1], 'limit' => 200])->toArray() : [];
$archivoIds = []; foreach ($docs as $d) if (!empty($d['archivo_id'])) $archivoIds[] = $d['archivo_id'];
$archivos = [];
if (!empty($archivoIds)) foreach ($mongoArchivos->find(['_id' => ['$in' => $archivoIds]]) as $a) $archivos[(string)$a['_id']] = $a;

$divisionesPorMateria = [];
if ($puedeSubir && !empty($materiaIds)) {
    $ph = implode(',', array_fill(0, count($materiaIds), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT ad.materia_id, d.id, d.nombre AS division, a.nombre AS curso
        FROM asignaciones_docente ad
        JOIN divisiones d ON d.id = ad.division_id
        JOIN asignaciones a ON a.id = d.asignacion_id
        WHERE ad.materia_id IN ($ph)
        ORDER BY a.nombre, d.nombre
    ");
    $stmt->execute($materiaIds);
    foreach ($stmt as $d) {
        $mid = (int)$d['materia_id'];
        if (!isset($divisionesPorMateria[$mid])) $divisionesPorMateria[$mid] = [];
        $divisionesPorMateria[$mid][] = [
            'id' => (int)$d['id'],
            'label' => trim($d['curso'] . ' ' . $d['division']),
        ];
    }
}
?>
<?php include __DIR__ . '/views/header.php'; ?>
<?php include __DIR__ . '/views/menu.php'; ?>
<main class="contenido-principal">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
        <h1 style="margin:0;">📚 Bibliografía</h1>
        <?php if ($puedeSubir && !empty($materias)): ?>
            <button class="btn-primario" onclick="abrirModalBib()">➕ Subir documento</button>
        <?php endif; ?>
    </div>
    <div style="margin:1rem 0;">
        <form method="get"><label>Filtrar por materia:
            <select name="materia_id" onchange="this.form.submit()">
                <option value="0">— Todas —</option>
                <?php foreach ($materias as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $m['id'] == $materiaSel ? 'selected' : '' ?>><?= h($m['nombre']) ?></option><?php endforeach; ?>
            </select>
        </label></form>
    </div>
    <?php if (empty($docs)): ?><div class="panel-vidrio"><p class="alerta info">Sin documentos.</p></div>
    <?php else: ?>
        <div class="grid-bibliografia">
            <?php foreach ($docs as $d): $arch = $archivos[(string)$d['archivo_id']] ?? null; if (!$arch) continue;
                $mime = $arch['mime'] ?? '';
                $esImg = strpos($mime, 'image/') === 0; $esVid = strpos($mime, 'video/') === 0;
                $esAud = strpos($mime, 'audio/') === 0; $esPdf = $mime === 'application/pdf';
            ?>
                <div class="panel-vidrio bib-card">
                    <div class="bib-icono"><?= $esImg ? '🖼️' : ($esVid ? '🎬' : ($esAud ? '🎵' : ($esPdf ? '📄' : '📎'))) ?></div>
                    <h4><?= h($arch['nombre_original']) ?></h4>
                    <a class="btn-secundario" href="/plataforma/descargar.php?id=<?= h((string)$arch['_id']) ?>" <?= ($esPdf||$esImg||$esVid||$esAud) ? 'target="_blank"' : '' ?>>Ver</a>
                    <?php if ($puedeSubir): ?>
                        <button class="btn-peligro-sm" style="margin-top:.5rem" onclick="eliminarBib('<?= h((string)$d['_id']) ?>','<?= h(addslashes($arch['nombre_original'])) ?>')">🗑️ Eliminar</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php if ($puedeSubir && !empty($materias)): ?>
<div class="modal-fondo oculto" id="modalBib">
    <div class="modal-caja">
        <h3>📚 Subir documento</h3>
        <form id="formBib" enctype="multipart/form-data">
            <label>Materia
                <select name="materia_id" id="bibMateria" required onchange="actualizarDivisionesBib()">
                    <option value="">— Elegí una materia —</option>
                    <?php foreach ($materias as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"
                                data-divs="<?= h(json_encode($divisionesPorMateria[(int)$m['id']] ?? [])) ?>">
                            <?= h($m['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>División (obligatoria)
                <select name="division_id" id="bibDivision" required>
                    <option value="">— Elegí una materia primero —</option>
                </select>
            </label>
            <label>Archivo <input type="file" name="archivo" required
                accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.7z"></label>
            <div id="bibError" class="alerta danger" style="display:none;"></div>
            <div class="modal-acciones">
                <button type="button" class="btn-secundario" onclick="cerrarModal('modalBib')">Cancelar</button>
                <button type="submit" class="btn-primario" id="btnSubirBib">Subir</button>
            </div>
        </form>
    </div>
</div>
<script src="/assets/js/bibliografia.js"></script>
<?php endif; ?>
<?php include __DIR__ . '/views/footer.php'; ?>