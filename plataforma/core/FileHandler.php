<?php
require_once __DIR__ . '/../config/constants.php';
use MongoDB\BSON\UTCDateTime;

class FileHandler {
    private $archivos;
    private $vtKey;

    public function __construct($mongoArchivos) {
        $this->archivos = $mongoArchivos;
        $this->vtKey    = $_ENV['VIRUSTOTAL_API_KEY'] ?? '';
    }

    public function procesar(array $file, string $tipoEsperado): array {
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return ['estado' => 'error', 'mensaje' => 'Error al subir el archivo (código ' . $file['error'] . ')'];
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['estado' => 'error', 'mensaje' => 'Archivo inválido'];
        }
        if ($file['size'] > UPLOAD_MAX_BYTES) {
            return ['estado' => 'error', 'mensaje' => 'El archivo excede el tamaño máximo permitido (' . UPLOAD_MAX_LABEL . ').'];
        }

        $mime = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        $esVideo  = strpos($mime, 'video/') === 0;
        $esImagen = strpos($mime, 'image/') === 0;
        $esAudio  = strpos($mime, 'audio/') === 0;
        $esDocumento = !$esVideo && !$esImagen && !$esAudio;

        switch ($tipoEsperado) {
            case 'video':      if (!$esVideo) return ['estado' => 'error', 'mensaje' => 'Se esperaba un video']; break;
            case 'imagen':     if (!$esImagen) return ['estado' => 'error', 'mensaje' => 'Se esperaba una imagen']; break;
            case 'audio':      if (!$esAudio) return ['estado' => 'error', 'mensaje' => 'Se esperaba un audio']; break;
            case 'multimedia': if (!$esVideo && !$esImagen && !$esAudio) return ['estado' => 'error', 'mensaje' => 'Se esperaba imagen, video o audio']; break;
            case 'documento':  if ($esVideo) return ['estado' => 'error', 'mensaje' => 'Se esperaba un documento (no video)']; break;
        }

        $sha = hash_file('sha256', $file['tmp_name']);
        $existente = $this->archivos->findOne(['sha256' => $sha]);
        if ($existente) {
            return [
                'estado'     => $existente['vt_estado'] ?? 'ok',
                'mensaje'    => 'El archivo ya existe.',
                'archivo_id' => (string)$existente['_id'],
                'nombre'     => $existente['nombre_original'],
            ];
        }

        $vtEstado = 'ok';
        $vtStats  = null;
        if ($esDocumento && $this->vtKey) {
            try {
                [$vtEstado, $vtStats] = $this->escanear($file['tmp_name']);
            } catch (Throwable $e) {
                $vtEstado = 'sospechoso';
            }
            if ($vtEstado === 'malicioso') {
                return ['estado' => 'malicioso', 'mensaje' => 'El archivo contiene malware y fue bloqueado.'];
            }
        }

        self::prepararDirectorio();
        // El archivo se guarda SOLO con su hash: sin extensión ni nada controlado por el usuario.
        // Así, aunque alguien suba "x.php", el servidor nunca lo interpreta como código.
        // El nombre original se conserva en Mongo y se usa únicamente al descargar.
        $nombreSeguro = $sha;
        $rutaFinal = UPLOAD_DIR . $nombreSeguro;
        if (!move_uploaded_file($file['tmp_name'], $rutaFinal)) {
            return ['estado' => 'error', 'mensaje' => 'No se pudo guardar el archivo'];
        }
        @chmod($rutaFinal, 0640);

        $ins = $this->archivos->insertOne([
            'sha256'          => $sha,
            'nombre_original' => $file['name'],
            'nombre_guardado' => $nombreSeguro,
            'mime'            => $mime,
            'size'            => (int)$file['size'],
            'tipo'            => $tipoEsperado,
            'vt_estado'       => $vtEstado,
            'vt_stats'        => $vtStats,
            'subido_por'      => $_SESSION['user_id'] ?? null,
            'fecha'           => new UTCDateTime(),
        ]);

        return [
            'estado'     => $vtEstado,
            'mensaje'    => $vtEstado === 'sospechoso' ? 'Archivo subido, marcado como sospechoso.' : 'Archivo subido correctamente.',
            'archivo_id' => (string)$ins->getInsertedId(),
            'nombre'     => $file['name'],
        ];
    }

    /**
     * Crea el directorio de subidas y lo blinda aunque esté dentro del web root:
     * .htaccess (Apache 2.4) que niega todo acceso directo e index.html vacío.
     * Con nginx hay que negar el acceso a esa ruta en la configuración del servidor
     * (o, mejor, definir UPLOAD_DIR fuera del directorio web).
     */
    public static function prepararDirectorio(): void {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0750, true);
        $ht = UPLOAD_DIR . '.htaccess';
        if (!file_exists($ht)) {
            file_put_contents($ht, "# Generado automáticamente: sin acceso directo a los archivos subidos\n"
                . "Require all denied\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
                . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
                . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar\n"
                . "SetHandler none\n");
        }
        $ix = UPLOAD_DIR . 'index.html';
        if (!file_exists($ix)) file_put_contents($ix, '');
    }

    private function escanear(string $ruta): array {
        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            return ['sospechoso', null];
        }
        $uploadUrl = 'https://www.virustotal.com/api/v3/files';
        $cfile = new CURLFile($ruta);
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => $cfile],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['x-apikey: ' . $this->vtKey],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        $analysisId = $data['data']['id'] ?? null;
        if (!$analysisId) return ['sospechoso', null];

        for ($i = 0; $i < 10; $i++) {
            sleep(3);
            $ch = curl_init("https://www.virustotal.com/api/v3/analyses/{$analysisId}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['x-apikey: ' . $this->vtKey],
                CURLOPT_TIMEOUT        => 20,
            ]);
            $rep = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $stats = $rep['data']['attributes']['stats'] ?? null;
            if ($stats) {
                if (($stats['malicious']  ?? 0) > 0) return ['malicioso',  $stats];
                if (($stats['suspicious'] ?? 0) > 0) return ['sospechoso', $stats];
                return ['ok', $stats];
            }
        }
        return ['sospechoso', null];
    }
}