<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../models/UserModel.php';

Auth::checkRole(ROLES_ALL);
$action = $_GET['action'] ?? '';
$fotoDir = __DIR__ . '/../../assets/img/';
$fotoUrlBase = '/assets/img/';

if (!is_dir($fotoDir)) @mkdir($fotoDir, 0775, true);

if ($action === 'guardar') {
    $id       = (int)$_SESSION['user_id'];
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $dni      = trim($_POST['dni'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    // ============================================================
    // Validaciones
    // ============================================================
    if ($nombre === '' || $apellido === '') {
        jsonResponse(['ok' => false, 'mensaje' => 'Nombre y apellido son obligatorios']);
    }
    if ($dni === '') {
        jsonResponse(['ok' => false, 'mensaje' => 'El DNI es obligatorio']);
    }
    if (!dniValido($dni)) {
        jsonResponse(['ok' => false, 'mensaje' => 'DNI inválido: solo números (6 a 12 dígitos)']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'mensaje' => 'Email inválido']);
    }

    // DNI único (si cambió)
    $actual = UserModel::findById($pdo, $id);
    if (!$actual) jsonResponse(['ok' => false, 'mensaje' => 'Usuario no encontrado'], 404);

    if ($dni !== $actual['dni']) {
        $existente = UserModel::findByDni($pdo, $dni);
        if ($existente && (int)$existente['id'] !== $id) {
            jsonResponse(['ok' => false, 'mensaje' => 'Ese DNI ya está en uso']);
        }
    }

    // Email único
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
    $stmt->execute([$email, $id]);
    if ($stmt->fetchColumn()) {
        jsonResponse(['ok' => false, 'mensaje' => 'Email ya en uso']);
    }

    // Cambiar DNI (es el usuario de login), email o contraseña exige la contraseña ACTUAL:
    // si alguien se apropia de una sesión abierta no puede quedarse con la cuenta.
    $pass = $_POST['password'] ?? '';
    $cambiaCredenciales = ($pass !== '')
        || ($dni !== (string)$actual['dni'])
        || (strcasecmp($email, (string)($actual['email'] ?? '')) !== 0);
    if ($cambiaCredenciales) {
        // Límite de intentos por sesión (evita adivinar la clave actual a fuerza bruta)
        $bloqueadoHasta = (int)($_SESSION['pw_bloqueo_hasta'] ?? 0);
        if ($bloqueadoHasta > time()) {
            jsonResponse(['ok' => false, 'mensaje' => 'Demasiados intentos. Esperá unos minutos e intentá de nuevo.'], 429);
        }
        $passActual = $_POST['password_actual'] ?? '';
        if (!is_string($passActual) || $passActual === '' || !Auth::verifyPassword($passActual, (string)$actual['password_hash'])) {
            $_SESSION['pw_fallos'] = (int)($_SESSION['pw_fallos'] ?? 0) + 1;
            if ($_SESSION['pw_fallos'] >= 5) {
                $_SESSION['pw_bloqueo_hasta'] = time() + 900;
                $_SESSION['pw_fallos'] = 0;
            }
            jsonResponse(['ok' => false, 'mensaje' => 'Para cambiar tu DNI, email o contraseña ingresá tu contraseña actual correctamente.']);
        }
        $_SESSION['pw_fallos'] = 0;
    }

    // Password opcional
    $pass = $_POST['password'] ?? '';
    if ($pass !== '') {
        $val = validarPassword($pass, $dni);
        if (!$val['ok']) jsonResponse(['ok' => false, 'mensaje' => $val['msg']]);
    } else {
        $pass = null;
    }

    // Foto opcional
    $fotoNombre = null;
    if (!empty($_FILES['foto']['tmp_name'])) {
        if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['ok' => false, 'mensaje' => 'Error al subir la imagen (código ' . $_FILES['foto']['error'] . ')']);
        }
        if ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
            jsonResponse(['ok' => false, 'mensaje' => 'La imagen supera los 5 MB']);
        }
        $mime = mime_content_type($_FILES['foto']['tmp_name']) ?: '';
        $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($permitidos[$mime])) {
            jsonResponse(['ok' => false, 'mensaje' => 'Formato no permitido (solo JPG, PNG, GIF, WEBP)']);
        }
        $ext = $permitidos[$mime];
        $nombreArchivo = 'perfil_' . $id . '_' . time() . '.' . $ext;
        $rutaDestino = $fotoDir . $nombreArchivo;
        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $rutaDestino)) {
            jsonResponse(['ok' => false, 'mensaje' => 'No se pudo guardar la imagen en el servidor']);
        }
        $fotoNombre = $fotoUrlBase . $nombreArchivo;

        // Borrar foto anterior si era local
        if (!empty($actual['foto_perfil']) && strpos($actual['foto_perfil'], $fotoUrlBase . 'perfil_') === 0) {
            $rutaVieja = __DIR__ . '/../../' . ltrim($actual['foto_perfil'], '/');
            if (file_exists($rutaVieja)) @unlink($rutaVieja);
        }
    }

    UserModel::updatePerfilCompleto($pdo, $id, $nombre, $apellido, $dni, $email, $pass, $fotoNombre);

    $_SESSION['nombre'] = $nombre . ' ' . $apellido;
    if ($fotoNombre) $_SESSION['foto_perfil'] = $fotoNombre;

    jsonResponse(['ok' => true]);
}

if ($action === 'eliminar_foto') {
    $id = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $fotoActual = $stmt->fetchColumn();
    if (!$fotoActual) jsonResponse(['ok' => false, 'mensaje' => 'Sin foto para eliminar']);
    if (strpos($fotoActual, $fotoUrlBase . 'perfil_') === 0) {
        $ruta = __DIR__ . '/../../' . ltrim($fotoActual, '/');
        if (file_exists($ruta)) @unlink($ruta);
    }
    $pdo->prepare("UPDATE usuarios SET foto_perfil = NULL WHERE id = ?")->execute([$id]);
    $_SESSION['foto_perfil'] = null;
    jsonResponse(['ok' => true]);
}

jsonResponse(['ok' => false, 'mensaje' => 'Acción desconocida'], 400);