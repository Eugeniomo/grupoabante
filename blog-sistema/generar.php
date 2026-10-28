<?php
// Cron: lunes 08:00 (hora de Chile). Envia a la revisora el siguiente borrador de la cola;
// si la cola baja de 3, genera uno nuevo con Gemini (y fotos Pexels si hay key).
// Uso CLI: php generar.php [--sin-enviar] [--solo-generar]
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const TEMAS_POOL = [
    'ISO 9001: cómo definir el contexto de la organización y las partes interesadas en una pyme',
    'ISO 45001: cómo gestionar los EPP dentro de la jerarquía de controles',
    'ISO 14001: gestión de residuos industriales en una pyme chilena',
    'ISO 45001: preparación y respuesta ante emergencias, simulacros que sirven',
    'ISO 9001: cómo medir la satisfacción del cliente sin encuestas interminables',
    'ISO 45001: la gestión del cambio y su efecto en los riesgos',
    'ISO 14001: requisitos legales ambientales, cómo mantenerlos al día',
    'ISO 9001: gestión de proveedores y evaluación de proveedores críticos',
    'SGI integrado: revisión por la dirección de tres normas en una sola reunión',
    'ISO 45001: capacitación y competencia del personal, cómo evidenciarlas',
    'ISO 9001: control de la información documentada sin papeleo excesivo',
    'ISO 45001: indicadores proactivos y reactivos de seguridad y salud en el trabajo',
    'Certificación: cómo prepararse para la auditoría de seguimiento',
    'ISO 14001: cómo identificar aspectos e impactos ambientales en procesos de servicio',
    'ISO 45001: liderazgo de la gerencia, qué debe hacer y qué puede delegar',
];

function gemini_texto(string $prompt): ?string {
    if (!GEMINI_KEY) return null;
    $c = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => 1, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_KEY],
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.6]])]);
    $o = curl_exec($c);
    $j = json_decode((string)$o, true);
    return $j['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

function pexels_fotos(string $consulta, int $n): array {
    if (!PEXELS_KEY) return [];
    $c = curl_init('https://api.pexels.com/v1/search?' . http_build_query(['query' => $consulta, 'per_page' => 15, 'orientation' => 'landscape', 'locale' => 'es-ES']));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: ' . PEXELS_KEY]]);
    $j = json_decode((string)curl_exec($c), true);
    $fotos = $j['photos'] ?? [];
    shuffle($fotos);
    $out = [];
    foreach (array_slice($fotos, 0, $n) as $f) {
        $out[] = ['id' => $f['id'], 'url_base' => 'https://images.pexels.com/photos/' . $f['id'] . '/pexels-photo-' . $f['id'] . '.jpeg',
            'alt' => $f['alt'] ?: 'Fotografía ilustrativa', 'fotografo' => $f['photographer'], 'perfil' => $f['photographer_url']];
    }
    return $out;
}

function generar_nuevo(): ?array {
    $usados = leer_json(dir_datos('datos') . '/temas_usados.json');
    $tema = null;
    foreach (TEMAS_POOL as $t) if (!in_array($t, $usados, true)) { $tema = $t; break; }
    if (!$tema) { log_blog('generar: se agotó el pool de temas'); return null; }
    $err = '';
    for ($intento = 1; $intento <= 4; $intento++) {
        $prompt = "Escribe un artículo de blog en español de Chile para pymes sobre: $tema.\n"
            . "Reglas firmes: entre 550 y 600 palabras en total (introducción + subtítulos + párrafos + viñetas); 4 a 6 secciones con subtítulo; párrafos cortos (2-4 líneas); trato de USTED (nunca tú ni vos); "
            . "NO inventes cifras, plazos, multas, porcentajes ni números de norma o decreto: si no estás seguro de un dato, no lo escribas. Cita solo requisitos de ISO 9001:2015, 14001:2015 o 45001:2018 que existan con certeza. "
            . "Tono profesional y cercano, sin lenguaje de marketing. Sin emojis.\n" . ($err ? "Intento anterior rechazado: $err\n" : '')
            . "Responde SOLO JSON con esta forma: {\"titulo\":\"\",\"descripcion\":\"(meta description, máx 155 caracteres)\",\"etiqueta\":\"ISO 9001|ISO 14001|ISO 45001|Certificación|SGI\","
            . "\"intro\":[\"párrafo\",\"párrafo\"],\"secciones\":[{\"h\":\"subtítulo\",\"bloques\":[{\"t\":\"p\",\"x\":\"texto\"},{\"t\":\"ul\",\"items\":[\"..\"]}]}],"
            . "\"cta_titulo\":\"\",\"cta_texto\":\"\",\"linkedin\":\"texto breve de LinkedIn, termina con la línea {URL} y 3-4 hashtags\",\"facebook\":\"texto corto y cercano para Facebook, incluye {URL}\",\"consulta_imagen\":\"2-4 palabras en inglés para buscar fotos\"}";
        $raw = gemini_texto($prompt);
        $a = json_decode((string)$raw, true);
        if (!is_array($a) || empty($a['secciones']) || empty($a['intro'])) { $err = 'JSON inválido'; continue; }
        $n = palabras_articulo($a);
        // recorte: quita el ultimo parrafo de la ultima seccion si se pasa
        while ($n > MAX_PALABRAS && count($a['secciones']) > 4) { array_pop($a['secciones']); $n = palabras_articulo($a); }
        $s = count($a['secciones']);
        if ($n >= MIN_PALABRAS - 10 && $n <= MAX_PALABRAS && $s >= 4 && $s <= 6) {
            $a['tema'] = $tema; $a['id'] = date('Ymd') . '-' . slugify($a['titulo']); $a['slug'] = slugify($a['titulo']);
            $a['estado'] = 'cola'; $a['creado'] = date('c');
            $fotos = pexels_fotos($a['consulta_imagen'] ?? 'office team', 2);
            $a['imagenes'] = $fotos;
            if (count($fotos) > 1) array_splice($a['secciones'][0]['bloques'], count($a['secciones'][0]['bloques']), 0, [['t' => 'img', 'n' => 1]]);
            if (!$fotos) $a['aviso'] = 'Sin imágenes: falta PEXELS_KEY en config.php';
            registrar_tema($tema);
            return $a;
        }
        $err = "tenía $n palabras y $s secciones; debe tener 550-600 palabras y 4-6 secciones";
    }
    log_blog('generar: Gemini no entregó un artículo válido: ' . $err);
    return null;
}

function agregar_a_cola(array $a): string {
    $arch = date('YmdHis') . '-' . $a['slug'] . '.json';
    $a['archivo_cola'] = $arch;
    guardar_json(dir_datos('cola') . '/' . $arch, $a);
    return $arch;
}

// ---- ejecucion ----
// Modo --cron: el cron corre cada hora; solo actua los lunes a las 08:xx hora de Chile y una vez al dia
// (asi no depende de la zona horaria del servidor ni del cambio de hora).
$marca = dir_datos('datos') . '/ultimo_envio.txt';
if (in_array('--cron', $argv ?? [], true)) {
    if (date('N') !== '1' || date('G') !== '8') return;
    if (is_file($marca) && trim((string)file_get_contents($marca)) === date('Y-m-d')) return;
}
$sinEnviar = in_array('--sin-enviar', $argv ?? [], true);
$cola = lista_cola();
if (count($cola) < 3 || in_array('--forzar-generar', $argv ?? [], true)) {
    $nuevo = generar_nuevo();
    if ($nuevo) { agregar_a_cola($nuevo); log_blog('generado nuevo borrador en cola: ' . $nuevo['slug']); }
    $cola = lista_cola();
}
if (in_array('--solo-generar', $argv ?? [], true) || $sinEnviar) { echo 'cola=' . count($cola) . "\n"; return; }
if (!$cola) { log_blog('generar: cola vacía y no se pudo generar'); echo "FALLA: cola vacia\n"; exit(1); }

$arch = $cola[0];
$a = leer_json($arch);
$a['archivo_cola'] = basename($arch);
$n = palabras_articulo($a);
if ($n > MAX_PALABRAS) { log_blog("borrador {$a['id']} con $n palabras: no se envía"); echo "FALLA: $n palabras\n"; exit(1); }
$a['estado'] = 'pendiente'; $a['enviado'] = date('c');
registrar_tema($a['tema'] ?? $a['titulo']);
guardar_borrador($a);
$tp = token_crear($a['id'], 'publicar'); $td = token_crear($a['id'], 'descartar');
try {
    enviar_correo(destinatario_revision(), 'Borrador del blog: ' . $a['titulo'] . ' [' . $a['id'] . ']',
        html_correo_revision($a, $tp, $td), texto_correo_revision($a, $tp, $td), ['reply_to' => REPLY_BUZON]);
    @unlink($arch);
    file_put_contents($marca, date('Y-m-d'));
    log_blog('revisión enviada a ' . destinatario_revision() . ': ' . $a['id'] . " ($n palabras)");
    echo 'OK enviado ' . $a['id'] . " ($n palabras) a " . destinatario_revision() . "\n";
} catch (Throwable $e) {
    @unlink(ruta_borrador($a['id']));
    log_blog('ERROR enviando revisión: ' . $e->getMessage()); echo "FALLA envio\n"; exit(1);
}
