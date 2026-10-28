<?php
// Cron cada 15 min: lee por IMAP las respuestas de la revisora a los correos de borrador y publica su version.
// Sin extension imap: cliente IMAP minimo sobre sockets.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

function imap_leer($fp, string $tag): string {
    $buf = '';
    while (($l = fgets($fp, 65536)) !== false) {
        $buf .= $l;
        if (preg_match('/\{(\d+)\}\r?\n$/', $l, $m)) { $n = (int)$m[1]; $d = ''; while (strlen($d) < $n) { $x = fread($fp, $n - strlen($d)); if ($x === false || $x === '') break; $d .= $x; } $buf .= $d; continue; }
        if (strpos($l, $tag . ' ') === 0) break;
    }
    return $buf;
}
function imap_cmd($fp, string $tag, string $cmd): string { fwrite($fp, "$tag $cmd\r\n"); return imap_leer($fp, $tag); }

function mime_decode_header(string $v): string { return function_exists('iconv_mime_decode') ? (string)iconv_mime_decode($v, 0, 'UTF-8') : $v; }
function mime_partes(string $raw): array { // devuelve [['tipo'=>, 'texto'=>]]
    [$cab, $cuerpo] = array_pad(preg_split('/\R\R/', $raw, 2), 2, '');
    $cab = preg_replace('/\R[ \t]+/', ' ', $cab);
    $tipo = preg_match('/^Content-Type:\s*([^;\s]+)/mi', $cab, $m) ? strtolower($m[1]) : 'text/plain';
    if (strpos($tipo, 'multipart/') === 0 && preg_match('/boundary="?([^";\s]+)"?/i', $cab, $b)) {
        $out = [];
        foreach (preg_split('/--' . preg_quote($b[1], '/') . '(?:--)?\R?/', $cuerpo) as $p) { if (trim($p) !== '') $out = array_merge($out, mime_partes(ltrim($p, "\r\n"))); }
        return $out;
    }
    $cte = preg_match('/^Content-Transfer-Encoding:\s*(\S+)/mi', $cab, $m) ? strtolower($m[1]) : '7bit';
    if ($cte === 'base64') $cuerpo = (string)base64_decode($cuerpo);
    elseif ($cte === 'quoted-printable') $cuerpo = quoted_printable_decode($cuerpo);
    $cs = preg_match('/charset="?([\w-]+)"?/i', $cab, $m) ? $m[1] : 'utf-8';
    if (strtolower($cs) !== 'utf-8') $cuerpo = (string)@mb_convert_encoding($cuerpo, 'UTF-8', $cs);
    return [['tipo' => $tipo, 'texto' => $cuerpo]];
}
function cuerpo_texto(string $raw): string {
    $p = mime_partes($raw);
    foreach ($p as $x) if ($x['tipo'] === 'text/plain') return $x['texto'];
    foreach ($p as $x) if ($x['tipo'] === 'text/html') return html_a_texto($x['texto']);
    return '';
}
function html_a_texto(string $h): string {
    $h = preg_replace('/<(br|\/p|\/div|\/tr|\/h\d|\/li)[^>]*>/i', "\n", $h);
    return html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8');
}
// Quita prefijos de cita ("> ") y devuelve el bloque entre marcadores, o null.
function extraer_articulo(string $texto): ?string {
    $l = array_map(fn($x) => preg_replace('/^(\s*>\s?)+/', '', rtrim($x)), preg_split('/\R/', $texto));
    $ini = null; $fin = null;
    foreach ($l as $i => $x) {
        if (preg_match('/^\s*-{2,}\s*INICIO ART[ÍI]CULO\s*-{2,}\s*$/iu', $x)) $ini = $i;       // se queda con la ultima aparicion pareada
        if ($ini !== null && $i > $ini && preg_match('/^\s*-{2,}\s*FIN ART[ÍI]CULO\s*-{2,}\s*$/iu', $x)) { $fin = $i; break; }
    }
    if ($ini === null || $fin === null) return null;
    return implode("\n", array_slice($l, $ini + 1, $fin - $ini - 1));
}
function responder_fallo(string $para, string $asunto, string $msg): void {
    try { enviar_correo($para, 'Re: ' . $asunto, '<p>' . nl2br(h($msg)) . '</p>', $msg); } catch (Throwable $e) { log_blog('ERROR respuesta: ' . $e->getMessage()); }
}

function procesar_mensaje(string $raw): string {
    preg_match('/^From:.*?<?([\w.+-]+@[\w.-]+)>?\s*$/mi', preg_replace('/\R[ \t]+/', ' ', $raw), $mf);
    $de = strtolower($mf[1] ?? '');
    $asunto = preg_match('/^Subject:\s*(.+)$/mi', preg_replace('/\R[ \t]+/', ' ', $raw), $ms) ? mime_decode_header(trim($ms[1])) : '';
    if (!in_array($de, array_map('strtolower', AUTORIZADOS), true)) return 'ignorado: remitente no autorizado (' . $de . ')';
    $txt = cuerpo_texto($raw);
    if (!preg_match('/C[óo]digo del borrador:\s*([a-z0-9-]+)-([A-F0-9]{10})/iu', $txt, $mc)) return 'ignorado: sin código de borrador';
    $id = strtolower($mc[1]);
    if (!hash_equals(codigo_respuesta($id), strtoupper($mc[2]))) return 'ignorado: código inválido';
    $a = cargar_borrador($id);
    if (!$a || $a['estado'] !== 'pendiente') { responder_fallo($de, $asunto, 'Ese borrador ya fue publicado, descartado o no existe, así que no se aplicó su corrección.'); return "rechazado: borrador $id no pendiente"; }
    $bloque = extraer_articulo($txt);
    if ($bloque === null) { responder_fallo($de, $asunto, "No pude leer su corrección: no encontré las líneas \"--- INICIO ARTÍCULO ---\" y \"--- FIN ARTÍCULO ---\" en su respuesta.\nNo se publicó nada. Por favor use el botón Publicar del correo original, o responda de nuevo conservando esas dos líneas."); return "rechazado: sin marcadores ($id)"; }
    [$nuevo, $err] = texto_a_articulo($bloque, $a);
    if (!$nuevo) { responder_fallo($de, $asunto, "No pude interpretar su corrección: $err\nNo se publicó nada. Por favor use el botón Publicar del correo original."); return "rechazado: $err ($id)"; }
    $n = palabras_articulo($nuevo);
    if ($n > MAX_PALABRAS + 50) { responder_fallo($de, $asunto, "La versión corregida tiene $n palabras y el máximo es " . MAX_PALABRAS . ". No se publicó; por favor recórtela y vuelva a responder."); return "rechazado: $n palabras ($id)"; }
    $nuevo['slug'] = $a['slug']; $nuevo['id'] = $a['id']; // direccion no cambia
    $nuevo['estado'] = 'publicando'; guardar_borrador($nuevo);
    $nuevo = publicar_articulo($nuevo);
    notificar_publicado($nuevo);
    log_blog("publicado por corrección de $de: $id ($n palabras)");
    return "publicado versión corregida de $id ($n palabras)";
}

// ---- ejecucion ----
$fp = @stream_socket_client('ssl://' . IMAP_HOST . ':993', $en, $es, 20);
if (!$fp) { log_blog("IMAP: no conecta ($es)"); echo "FALLA IMAP conexion\n"; exit(1); }
stream_set_timeout($fp, 30);
fgets($fp);
$r = imap_cmd($fp, 'a1', 'LOGIN "' . addcslashes(IMAP_USER, '"\\') . '" "' . addcslashes(IMAP_PASS, '"\\') . '"');
if (!preg_match('/^a1 OK/m', $r)) { log_blog('IMAP: login falla'); echo "FALLA IMAP login\n"; exit(1); }
imap_cmd($fp, 'a2', 'SELECT INBOX');
$r = imap_cmd($fp, 'a3', 'SEARCH UNSEEN SINCE ' . date('j-M-Y', strtotime('-20 days')));
preg_match('/\* SEARCH([\d ]*)/', $r, $m);
$ids = array_slice(array_filter(array_map('intval', preg_split('/\s+/', trim($m[1] ?? '')))), -50);
$vistosF = dir_datos('datos') . '/imap_vistos.json'; $vistos = leer_json($vistosF);
$res = [];
foreach ($ids as $i => $n) {
    $r = imap_cmd($fp, 'f' . $i, "FETCH $n BODY.PEEK[]");
    if (!preg_match('/\{(\d+)\}\r?\n/', $r, $mm, PREG_OFFSET_CAPTURE)) continue;
    $raw = substr($r, $mm[0][1] + strlen($mm[0][0]), (int)$mm[1][0]);
    $mid = preg_match('/^Message-ID:\s*(\S+)/mi', $raw, $x) ? $x[1] : sha1($raw);
    if (in_array($mid, $vistos, true)) continue;
    try { $res[] = procesar_mensaje($raw); } catch (Throwable $e) { $res[] = 'ERROR: ' . $e->getMessage(); log_blog('ERROR correcciones: ' . $e->getMessage()); }
    $vistos[] = $mid;
}
guardar_json($vistosF, array_slice($vistos, -500));
imap_cmd($fp, 'z', 'LOGOUT');
echo 'OK ' . count($ids) . " sin leer; " . implode(' | ', $res) . "\n";
