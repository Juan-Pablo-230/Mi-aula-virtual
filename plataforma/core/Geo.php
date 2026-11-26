<?php
/**
 * Países y localidades desde APIs públicas (con caché en disco para no depender de ellas en cada pedido).
 *  - Países:      https://restcountries.com  (nombre en español + nombre común en inglés)
 *  - Localidades: https://countriesnow.space (ciudades por país)
 */
class Geo {
    private const TTL = 2592000; // 30 días

    private static function http(string $url, ?array $postJson = null): ?array {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                 CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                 CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if ($postJson !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($postJson);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;
        $d = json_decode($body, true);
        return is_array($d) ? $d : null;
    }

    private static function cache(string $clave, callable $generar): ?array {
        $f = sys_get_temp_dir() . '/aula_geo_' . md5($clave) . '.json';
        if (is_file($f) && filemtime($f) > time() - self::TTL) {
            $d = json_decode((string)@file_get_contents($f), true);
            if (is_array($d) && $d) return $d;
        }
        $d = $generar();
        if (is_array($d) && $d) { @file_put_contents($f, json_encode($d), LOCK_EX); return $d; }
        // API caída: usar caché vencida si existe
        if (is_file($f)) { $v = json_decode((string)@file_get_contents($f), true); if (is_array($v) && $v) return $v; }
        return null;
    }

    private static function ordenar(array &$lista, string $campo): void {
        $col = class_exists('Collator') ? new Collator('es') : null;
        usort($lista, fn($a, $b) => $col ? $col->compare($a[$campo], $b[$campo]) : strcasecmp($a[$campo], $b[$campo]));
    }

    /** @return array<int,array{valor:string,nombre:string}> valor = nombre común (inglés), nombre = español */
    public static function paises(): ?array {
        return self::cache('paises', function () {
            $d = self::http('https://restcountries.com/v3.1/all?fields=name,translations');
            if (!$d) return null;
            $out = [];
            foreach ($d as $p) {
                $en = trim((string)($p['name']['common'] ?? ''));
                if ($en === '') continue;
                $es = trim((string)($p['translations']['spa']['common'] ?? '')) ?: $en;
                $out[] = ['valor' => $en, 'nombre' => $es];
            }
            self::ordenar($out, 'nombre');
            return $out;
        });
    }

    /** @return string[]|null  localidades del país (valor = nombre común en inglés) */
    public static function localidades(string $paisValor): ?array {
        return self::cache('loc:' . $paisValor, function () use ($paisValor) {
            $d = self::http('https://countriesnow.space/api/v0.1/countries/cities', ['country' => $paisValor]);
            if (!$d || !empty($d['error']) || !is_array($d['data'] ?? null)) return null;
            $l = array_values(array_unique(array_filter(array_map(fn($c) => trim((string)$c), $d['data']))));
            $col = class_exists('Collator') ? new Collator('es') : null;
            usort($l, fn($a, $b) => $col ? $col->compare($a, $b) : strcasecmp($a, $b));
            return $l;
        });
    }

    /** Devuelve el país de la lista (o null si no existe). */
    public static function buscarPais(string $valor): ?array {
        foreach (self::paises() ?? [] as $p) if ($p['valor'] === $valor) return $p;
        return null;
    }
}
