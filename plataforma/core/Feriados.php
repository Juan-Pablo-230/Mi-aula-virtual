<?php
function obtenerFeriados(int $año): array {
    $archivoCache = __DIR__ . '/../config/feriados_' . $año . '.json';
    if (file_exists($archivoCache)) {
        $datos = json_decode(@file_get_contents($archivoCache), true);
        if (is_array($datos) && !empty($datos)) return $datos;
    }
    $url = "https://api.argentinadatos.com/v1/feriados/{$año}";
    $fechas = consultarAPI($url);
    if (!empty($fechas)) {
        if (is_writable(dirname($archivoCache))) {
            @file_put_contents($archivoCache, json_encode($fechas));
        }
        return $fechas;
    }
    return [
        "{$año}-01-01", "{$año}-03-24", "{$año}-04-02",
        "{$año}-05-01", "{$año}-05-25", "{$año}-06-20",
        "{$año}-07-09", "{$año}-12-08", "{$año}-12-25",
    ];
}

function consultarAPI(string $url): array {
    if (!function_exists('curl_init')) return consultarConFileGetContents($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'MiAulaVirtual/1.0',
    ]);
    $respuesta = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($respuesta === false || $httpCode !== 200) return [];
    $json = json_decode($respuesta, true);
    if (!is_array($json) || empty($json)) return [];
    $fechas = [];
    foreach ($json as $item) if (isset($item['fecha'])) $fechas[] = $item['fecha'];
    return array_values(array_unique($fechas));
}

function consultarConFileGetContents(string $url): array {
    $contexto = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'MiAulaVirtual/1.0']]);
    $respuesta = @file_get_contents($url, false, $contexto);
    if ($respuesta === false) return [];
    $json = json_decode($respuesta, true);
    if (!is_array($json)) return [];
    $fechas = [];
    foreach ($json as $item) if (isset($item['fecha'])) $fechas[] = $item['fecha'];
    return array_values(array_unique($fechas));
}