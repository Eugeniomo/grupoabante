<?php
// Blog automatico Grupo Abante - biblioteca comun. Vive FUERA de public_html.
declare(strict_types=1);
date_default_timezone_set('America/Santiago');
mb_internal_encoding('UTF-8');

const PRIV = __DIR__;
require_once PRIV . '/config.php';
require_once PRIV . '/vendor/phpmailer/Exception.php';
require_once PRIV . '/vendor/phpmailer/PHPMailer.php';
require_once PRIV . '/vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

const MAX_PALABRAS = 600;
const MIN_PALABRAS = 550;
const TOKEN_DIAS = 14;
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

// ---------- utilidades ----------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fecha_larga(?string $ymd = null): string {
    $t = $ymd ? strtotime($ymd) : time();
    return (int)date('j', $t) . ' de ' . MESES[(int)date('n', $t) - 1] . ' de ' . date('Y', $t);
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_d(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }
function slugify(string $t): string {
    $t = mb_strtolower($t);
    $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $t = trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    return mb_substr($t, 0, 70);
}
function dir_datos(string $sub): string { $d = PRIV . '/' . $sub; if (!is_dir($d)) mkdir($d, 0750, true); return $d; }
function leer_json(string $f, $def = []) { return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?? $def) : $def; }
function guardar_json(string $f, $d): void {
    file_put_contents($f . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    rename($f . '.tmp', $f);
}
function log_blog(string $m): void { file_put_contents(dir_datos('datos') . '/blog.log', date('Y-m-d H:i:s') . ' ' . $m . "\n", FILE_APPEND | LOCK_EX); }

// ---------- articulo: conteo y texto editable ----------
function palabras_articulo(array $a): int {
    $txt = [$a['titulo']];
    foreach ($a['intro'] as $p) $txt[] = $p;
    foreach ($a['secciones'] as $s) {
        $txt[] = $s['h'];
        foreach ($s['bloques'] as $b) {
            if ($b['t'] === 'p') $txt[] = $b['x'];
            elseif ($b['t'] === 'ul') foreach ($b['items'] as $i) $txt[] = $i;
        }
    }
    return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’%.,\/-]*/u', strip_tags(implode(' ', $txt)));
}
function md_inline(string $s): string { // **negrita** -> <strong>
    return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', h($s));
}
function tiempo_lectura(array $a): int { return max(1, (int)ceil(palabras_articulo($a) / 200)); }

function articulo_a_texto(array $a): string {
    $o = "TÍTULO: " . $a['titulo'] . "\n\n";
    $o .= implode("\n\n", $a['intro']) . "\n";
    foreach ($a['secciones'] as $s) {
        $o .= "\n## " . $s['h'] . "\n\n";
        foreach ($s['bloques'] as $b) {
            if ($b['t'] === 'p') $o .= $b['x'] . "\n\n";
            elseif ($b['t'] === 'ul') { foreach ($b['items'] as $i) $o .= "- " . $i . "\n"; $o .= "\n"; }
            elseif ($b['t'] === 'img') $o .= "[IMAGEN " . $b['n'] . "]\n\n";
        }
    }
    return rtrim($o) . "\n";
}
// Devuelve [articulo|null, error]. Conserva metadatos e imagenes del original.
function texto_a_articulo(string $txt, array $orig): array {
    $lineas = preg_split('/\R/', $txt);
    $titulo = $orig['titulo']; $intro = []; $secs = []; $cur = null; $buf = []; $lista = [];
    $flushP = function () use (&$buf, &$cur, &$intro) {
        if ($buf) { $p = trim(implode(' ', $buf)); $buf = [];
            if ($p !== '') { if ($cur === null) $intro[] = $p; else $cur['bloques'][] = ['t' => 'p', 'x' => $p]; } }
    };
    $flushL = function () use (&$lista, &$cur) { if ($lista && $cur !== null) $cur['bloques'][] = ['t' => 'ul', 'items' => $lista]; $lista = []; };
    foreach ($lineas as $l) {
        $l = trim($l);
        if (preg_match('/^T[ÍI]TULO:\s*(.+)$/iu', $l, $m)) { $titulo = trim($m[1]); continue; }
        if (preg_match('/^##\s+(.+)$/u', $l, $m)) { $flushP(); $flushL(); if ($cur !== null) $secs[] = $cur; $cur = ['h' => trim($m[1]), 'bloques' => []]; continue; }
        if (preg_match('/^\[IMAGEN\s+(\d+)\]$/iu', $l, $m)) { $flushP(); $flushL(); if ($cur !== null) $cur['bloques'][] = ['t' => 'img', 'n' => (int)$m[1]]; continue; }
        if (preg_match('/^[-•*]\s+(.+)$/u', $l, $m)) { $flushP(); $lista[] = trim($m[1]); continue; }
        if ($l === '') { $flushP(); $flushL(); continue; }
        $flushL(); $buf[] = $l;
    }
    $flushP(); $flushL(); if ($cur !== null) $secs[] = $cur;
    if (count($intro) < 1) return [null, 'No se encontró la introducción.'];
    if (count($secs) < 3) return [null, 'No se encontraron al menos 3 secciones con "## Subtítulo".'];
    $a = $orig; $a['titulo'] = $titulo; $a['intro'] = $intro; $a['secciones'] = $secs;
    return [$a, ''];
}

// ---------- tokens ----------
function token_crear(string $id, string $accion, ?int $exp = null): string {
    $exp = $exp ?? time() + TOKEN_DIAS * 86400;
    $p = $id . '|' . $accion . '|' . $exp;
    return b64u($p) . '.' . hash_hmac('sha256', $p, BLOG_SECRET);
}
// -> [id, accion, '']  o  [null, null, motivo]
function token_verificar(string $tok, string $accionEsperada): array {
    $partes = explode('.', $tok);
    if (count($partes) !== 2) return [null, null, 'formato'];
    $p = b64u_d($partes[0]);
    if (!hash_equals(hash_hmac('sha256', $p, BLOG_SECRET), $partes[1])) return [null, null, 'firma'];
    $x = explode('|', $p);
    if (count($x) !== 3) return [null, null, 'formato'];
    [$id, $acc, $exp] = $x;
    if ($acc !== $accionEsperada) return [null, null, 'accion'];
    if ((int)$exp < time()) return [null, null, 'vencido'];
    return [$id, $acc, ''];
}
function codigo_respuesta(string $id): string { return strtoupper(substr(hash_hmac('sha256', 'resp|' . $id, BLOG_SECRET), 0, 10)); }

// ---------- almacenamiento de borradores ----------
function ruta_borrador(string $id): string { return dir_datos('borradores') . '/' . preg_replace('/[^a-z0-9-]/', '', $id) . '.json'; }
function cargar_borrador(string $id): ?array { $f = ruta_borrador($id); return is_file($f) ? leer_json($f) : null; }
function guardar_borrador(array $a): void { guardar_json(ruta_borrador($a['id']), $a); }
function lista_cola(): array { $f = glob(dir_datos('cola') . '/*.json') ?: []; sort($f); return $f; }
function registrar_tema(string $t): void { $f = dir_datos('datos') . '/temas_usados.json'; $l = leer_json($f); if (!in_array($t, $l, true)) { $l[] = $t; guardar_json($f, $l); } }
function desregistrar_tema(string $t): void { $f = dir_datos('datos') . '/temas_usados.json'; guardar_json($f, array_values(array_diff(leer_json($f), [$t]))); }

// ---------- plantillas HTML ----------
function img_url(array $img, int $w): string { return $img['url_base'] . '?auto=compress&cs=tinysrgb&w=' . $w; }

function cabecera_sitio(string $base, string $activo = ''): string {
    return '<header><nav><a href="' . $base . 'index.html"><img src="' . $base . 'images/logoabante.png" alt="Grupo Abante" class="logo"></a>'
        . '<ul class="nav-links"><li><a href="' . $base . 'index.html">Inicio</a></li><li><a href="' . $base . 'landing.html">Control Documental</a></li><li><a href="' . $base . 'index.html#servicios">Servicios</a></li><li><a href="' . $base . 'blog/" class="activo">Blog</a></li><li><a href="' . $base . 'index.html#contacto">Contacto</a></li></ul></nav></header>';
}
function pie_sitio(): string {
    return '<footer><div class="footer-content"><div class="footer-section"><h3>Abante Consultores</h3><p>Expertos en implementación ISO y optimización de procesos empresariales</p></div>'
        . '<div class="footer-section"><h3>Contacto</h3><ul><li>info@grupoabante.com</li><li>+56 9 6271 1053</li><li>La Capitanía 80, Of 108, Las Condes, Santiago, Chile</li></ul></div>'
        . '<div class="footer-section"><h3>Blog</h3><ul><li><a href="index.html">Todos los artículos</a></li></ul></div></div>'
        . '<div class="footer-bottom"><p>&copy; ' . date('Y') . ' Abante Consultores. Todos los derechos reservados.</p></div></footer>';
}

function html_articulo(array $a): string {
    $url = BLOG_URL . '/' . $a['slug'] . '.html';
    $portada = $a['imagenes'][0] ?? null;
    $ogImg = $portada ? img_url($portada, 1200) : SITIO_URL . '/images/logoabante.png';
    $fecha = $a['fecha_pub'] ?? date('Y-m-d');
    $desc = $a['descripcion'];
    $t = h($a['titulo']);
    $ld = json_encode([
        '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $a['titulo'], 'description' => $desc,
        'image' => [$ogImg], 'datePublished' => $fecha, 'dateModified' => $fecha, 'inLanguage' => 'es-CL', 'mainEntityOfPage' => $url,
        'author' => ['@type' => 'Organization', 'name' => 'Grupo Abante', 'url' => SITIO_URL],
        'publisher' => ['@type' => 'Organization', 'name' => 'Grupo Abante', 'logo' => ['@type' => 'ImageObject', 'url' => SITIO_URL . '/images/logoabante.png']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $o = '<!DOCTYPE html>' . "\n" . '<html lang="es"><head>' . "\n" . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
    $o .= '<title>' . $t . ' | Grupo Abante</title>' . "\n" . '<meta name="description" content="' . h($desc) . '">' . "\n";
    $o .= '<link rel="canonical" href="' . h($url) . '">' . "\n" . '<meta property="og:type" content="article"><meta property="og:locale" content="es_CL">' . "\n";
    $o .= '<meta property="og:url" content="' . h($url) . '">' . "\n" . '<meta property="og:title" content="' . $t . '">' . "\n" . '<meta property="og:description" content="' . h($desc) . '">' . "\n";
    $o .= '<meta property="og:image" content="' . h($ogImg) . '">' . "\n" . '<meta property="article:published_time" content="' . $fecha . 'T09:00:00-03:00">' . "\n";
    $o .= '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . $t . '"><meta name="twitter:image" content="' . h($ogImg) . '">' . "\n";
    $o .= '<link rel="stylesheet" href="blog.css">' . "\n" . '<script type="application/ld+json">' . "\n" . $ld . "\n</script>\n</head><body>\n";
    $o .= cabecera_sitio('../');
    $o .= '<main class="wrap"><article class="articulo">' . "\n";
    $o .= '<p class="migas"><a href="../index.html">Inicio</a> / <a href="./">Blog</a> / ' . h($a['etiqueta']) . '</p>' . "\n";
    $o .= '<span class="etiqueta">' . h($a['etiqueta']) . '</span>' . "\n" . '<h1>' . $t . '</h1>' . "\n";
    $o .= '<p class="meta">' . fecha_larga($fecha) . ' · Grupo Abante · ' . tiempo_lectura($a) . ' min de lectura</p>' . "\n";
    if ($portada) {
        $o .= '<figure style="margin:0"><img class="portada" src="' . h(img_url($portada, 1200)) . '" alt="' . h($portada['alt']) . '" width="1200" height="675"><figcaption>Foto: <a href="' . h($portada['perfil']) . '" rel="noopener">' . h($portada['fotografo']) . '</a> en <a href="https://www.pexels.com" rel="noopener">Pexels</a></figcaption></figure>' . "\n";
    }
    foreach ($a['intro'] as $p) $o .= '<p>' . md_inline($p) . '</p>' . "\n";
    foreach ($a['secciones'] as $i => $s) {
        $o .= '<details class="seccion"' . ($i === 0 ? ' open' : '') . '><summary><h2>' . h($s['h']) . '</h2></summary><div class="cont">';
        foreach ($s['bloques'] as $b) {
            if ($b['t'] === 'p') $o .= '<p>' . md_inline($b['x']) . '</p>';
            elseif ($b['t'] === 'ul') { $o .= '<ul>'; foreach ($b['items'] as $it) $o .= '<li>' . md_inline($it) . '</li>'; $o .= '</ul>'; }
            elseif ($b['t'] === 'img' && isset($a['imagenes'][$b['n']])) {
                $im = $a['imagenes'][$b['n']];
                $o .= '<figure><img src="' . h(img_url($im, 900)) . '" alt="' . h($im['alt']) . '" width="900" height="600" loading="lazy"><figcaption>Foto: <a href="' . h($im['perfil']) . '" rel="noopener">' . h($im['fotografo']) . '</a> en <a href="https://www.pexels.com" rel="noopener">Pexels</a></figcaption></figure>';
            }
        }
        $o .= '</div></details>' . "\n";
    }
    $o .= '<div class="cta"><h3>' . h($a['cta_titulo'] ?? '¿Cómo le iría a su empresa?') . '</h3><p>' . h($a['cta_texto'] ?? 'Revisamos su situación actual y le entregamos una orientación, sin costo.') . '</p><a class="btn" href="../index.html#contacto">Solicitar asesoría gratuita</a></div>' . "\n";
    $o .= '</article></main>' . "\n" . pie_sitio() . "\n</body></html>\n";
    return $o;
}

function html_indice(array $pub): string {
    $o = '<!DOCTYPE html>' . "\n" . '<html lang="es"><head>' . "\n" . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
    $o .= '<title>Blog ISO en Chile | Grupo Abante</title>' . "\n" . '<meta name="description" content="Artículos semanales sobre ISO 9001, 14001 y 45001, seguridad y salud en el trabajo y certificación en Chile, escritos por Grupo Abante.">' . "\n";
    $o .= '<link rel="canonical" href="' . BLOG_URL . '/">' . "\n" . '<meta property="og:type" content="website"><meta property="og:title" content="Blog ISO en Chile | Grupo Abante">' . "\n";
    $o .= '<meta property="og:description" content="Artículos semanales sobre ISO 9001, 14001 y 45001 y seguridad y salud en el trabajo en Chile.">' . "\n";
    if ($pub && !empty($pub[0]['portada_url'])) $o .= '<meta property="og:image" content="' . h($pub[0]['portada_url']) . '">' . "\n";
    $o .= '<link rel="stylesheet" href="blog.css">' . "\n</head><body>\n" . cabecera_sitio('../');
    $o .= '<main class="wrap-ancho">' . "\n" . '<p class="migas"><a href="../index.html">Inicio</a> / Blog</p>' . "\n" . '<h1>Blog de Grupo Abante</h1>' . "\n";
    $o .= '<p class="intro">Un artículo cada semana sobre ISO 9001, 14001 y 45001 y sobre seguridad y salud en el trabajo en Chile: plazos, requisitos y errores frecuentes.</p>' . "\n<div class=\"lista\">\n";
    foreach ($pub as $p) {
        $o .= '<article class="tarjeta"><a href="' . h($p['slug']) . '.html">' . (!empty($p['portada_url']) ? '<img src="' . h($p['portada_url']) . '" alt="' . h($p['portada_alt']) . '" loading="lazy">' : '') . '</a><div class="cuerpo"><span class="etiqueta" style="align-self:flex-start">' . h($p['etiqueta']) . '</span><h2><a href="' . h($p['slug']) . '.html">' . h($p['titulo']) . '</a></h2><p>' . h($p['descripcion']) . '</p><span class="fecha">' . fecha_larga($p['fecha']) . '</span></div></article>' . "\n";
    }
    $o .= "</div></main>\n" . pie_sitio() . "\n</body></html>\n";
    return $o;
}

function html_sitemap(array $pub): string {
    $u = [[SITIO_URL . '/', date('Y-m-d')], [SITIO_URL . '/landing.html', date('Y-m-d')], [BLOG_URL . '/', date('Y-m-d')]];
    foreach ($pub as $p) $u[] = [BLOG_URL . '/' . $p['slug'] . '.html', $p['fecha']];
    $o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($u as [$l, $f]) $o .= '  <url><loc>' . h($l) . '</loc><lastmod>' . $f . '</lastmod></url>' . "\n";
    return $o . "</urlset>\n";
}

// Inserta "Blog" en el menu de index.html y landing.html si aun no esta.
function asegurar_menu(string $dirPublic): array {
    $res = [];
    $defs = [
        'index.html' => ['<li><a href="#contacto">Contacto</a></li>', '<li><a href="blog/">Blog</a></li>'],
        'landing.html' => ['<li><a href="index.html#contacto" style="text-decoration: none; color: #333; font-weight: 500;">Contacto</a></li>',
                           '<li><a href="blog/" style="text-decoration: none; color: #333; font-weight: 500;">Blog</a></li>'],
    ];
    foreach ($defs as $f => [$ancla, $nuevo]) {
        $p = $dirPublic . '/' . $f;
        $c = (string)@file_get_contents($p);
        if ($c === '') { $res[$f] = 'no-leido'; continue; }
        if (strpos($c, 'href="blog/"') !== false) { $res[$f] = 'ya-estaba'; continue; }
        if (strpos($c, $ancla) === false) { $res[$f] = 'sin-ancla'; continue; }
        $ind = str_repeat(' ', 16);
        $c = preg_replace('/^(\s*)' . preg_quote($ancla, '/') . '/m', '$1' . str_replace('$', '\$', $nuevo) . "\n" . '$1' . str_replace('$', '\$', $ancla), $c, 1);
        file_put_contents($p, $c);
        $res[$f] = 'agregado';
    }
    return $res;
}

// ---------- publicacion ----------
function lista_publicados(): array { return leer_json(dir_datos('datos') . '/publicados.json'); }
function regenerar_indice_y_sitemap(): void {
    $pub = lista_publicados();
    usort($pub, fn($a, $b) => strcmp($b['fecha'] . $b['slug'], $a['fecha'] . $a['slug']));
    file_put_contents(PUBLIC_DIR . '/blog/index.html', html_indice($pub));
    file_put_contents(PUBLIC_DIR . '/sitemap.xml', html_sitemap($pub));
}
function publicar_articulo(array $a): array {
    $a['fecha_pub'] = date('Y-m-d');
    if (!is_dir(PUBLIC_DIR . '/blog')) mkdir(PUBLIC_DIR . '/blog', 0755, true);
    if (!is_file(PUBLIC_DIR . '/blog/blog.css')) copy(PRIV . '/blog.css', PUBLIC_DIR . '/blog/blog.css');
    file_put_contents(PUBLIC_DIR . '/blog/' . $a['slug'] . '.html', html_articulo($a));
    $pub = array_values(array_filter(lista_publicados(), fn($p) => $p['slug'] !== $a['slug']));
    $pub[] = ['slug' => $a['slug'], 'id' => $a['id'], 'titulo' => $a['titulo'], 'descripcion' => $a['descripcion'], 'etiqueta' => $a['etiqueta'], 'fecha' => $a['fecha_pub'],
        'portada_url' => isset($a['imagenes'][0]) ? img_url($a['imagenes'][0], 800) : '', 'portada_alt' => $a['imagenes'][0]['alt'] ?? ''];
    guardar_json(dir_datos('datos') . '/publicados.json', $pub);
    regenerar_indice_y_sitemap();
    $menu = asegurar_menu(PUBLIC_DIR);
    $a['estado'] = 'publicado';
    guardar_borrador($a);
    log_blog('publicado ' . $a['id'] . ' menu=' . json_encode($menu));
    return $a;
}
// Despublica; si $volverACola, el borrador regresa a la cola.
function despublicar_articulo(string $id, bool $volverACola): bool {
    $a = cargar_borrador($id);
    if (!$a) return false;
    @unlink(PUBLIC_DIR . '/blog/' . $a['slug'] . '.html');
    guardar_json(dir_datos('datos') . '/publicados.json', array_values(array_filter(lista_publicados(), fn($p) => $p['slug'] !== $a['slug'])));
    regenerar_indice_y_sitemap();
    if ($volverACola && !empty($a['archivo_cola'])) {
        $a['estado'] = 'cola'; unset($a['fecha_pub'], $a['enviado']);
        guardar_json(dir_datos('cola') . '/' . $a['archivo_cola'], $a);
        @unlink(ruta_borrador($id));
        desregistrar_tema($a['tema'] ?? $a['titulo']);
    } else { $a['estado'] = 'despublicado'; guardar_borrador($a); }
    log_blog('despublicado ' . $id);
    return true;
}

// ---------- correo ----------
function enviar_correo(string $para, string $asunto, string $html, string $texto = '', array $extra = []): bool {
    $m = new PHPMailer(true);
    $m->isSMTP(); $m->Host = MAIL_HOST; $m->SMTPAuth = true; $m->Username = MAIL_USER; $m->Password = MAIL_PASS;
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $m->Port = (int)MAIL_PORT; $m->CharSet = 'UTF-8';
    $m->setFrom(MAIL_FROM, 'Blog Grupo Abante');
    $m->addAddress($para);
    if (!empty($extra['reply_to'])) $m->addReplyTo($extra['reply_to'], 'Blog Grupo Abante');
    $m->isHTML(true); $m->Subject = $asunto; $m->Body = $html; $m->AltBody = $texto;
    $m->send();
    return true;
}
function destinatario_revision(): string { return MODO_PRUEBA ? DEST_PRUEBA : DEST_REVISORA; }

const F = "font-family:'Segoe UI',Tahoma,Arial,sans-serif;";
function correo_marco(string $titulo, string $cuerpo, string $pie): string {
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . h($titulo) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#f1f3f6;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f1f3f6"><tr><td align="center" style="padding:20px 10px;">'
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;">'
    . '<tr><td style="padding:20px 28px;border-bottom:3px solid #003087;"><img src="' . SITIO_URL . '/images/logoabante.png" alt="Grupo Abante" height="40" style="display:block;height:40px;border:0;"></td></tr>'
    . $cuerpo . '<tr><td style="padding:20px 28px;background:#1a1a1a;' . F . 'font-size:12px;line-height:18px;color:#cccccc;">' . $pie . '</td></tr></table></td></tr></table></body></html>';
}
function boton(string $href, string $txt, bool $primario): string {
    return $primario
        ? '<td align="center" bgcolor="#FF6B00" style="border-radius:5px;border:1px solid #FF6B00;"><a href="' . h($href) . '" style="display:inline-block;padding:13px 28px;' . F . 'font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;">' . h($txt) . '</a></td>'
        : '<td align="center" bgcolor="#ffffff" style="border-radius:5px;border:1px solid #999999;"><a href="' . h($href) . '" style="display:inline-block;padding:13px 28px;' . F . 'font-size:16px;font-weight:bold;color:#333333;text-decoration:none;">' . h($txt) . '</a></td>';
}
function fila_botones(string $urlPub, string $urlDesc): string {
    return '<tr><td align="center" style="padding:8px 28px 24px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . boton($urlPub, 'Publicar', true) . '<td width="16" style="font-size:0;">&nbsp;</td>' . boton($urlDesc, 'Descartar', false) . '</tr></table></td></tr>';
}
function html_correo_revision(array $a, string $tokPub, string $tokDesc): string {
    $urlPub = BLOG_URL . '/publicar.php?t=' . $tokPub; $urlDesc = BLOG_URL . '/descartar.php?t=' . $tokDesc;
    $cod = $a['id'] . '-' . codigo_respuesta($a['id']);
    $b = '<tr><td style="padding:28px 28px 8px;' . F . 'font-size:16px;line-height:24px;color:#333333;"><p style="margin:0 0 12px;">Estimada Marlene:</p>'
       . '<p style="margin:0 0 12px;">Este es el borrador del artículo de esta semana. Está completo más abajo, con sus imágenes. Si le parece bien, apriete <strong>Publicar</strong>. Si no corresponde, apriete <strong>Descartar</strong>.</p>'
       . '<p style="margin:0 0 12px;">Si desea corregir algo, apriete <strong>Responder</strong> en su programa de correo, edite el texto que está entre las líneas INICIO ARTÍCULO y FIN ARTÍCULO (al final de este correo) y envíe. Se publicará su versión.</p></td></tr>';
    $b .= fila_botones($urlPub, $urlDesc);
    $b .= '<tr><td style="padding:0 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-top:1px solid #dddddd;font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>';
    $b .= '<tr><td style="padding:20px 28px 0;' . F . 'font-size:13px;color:#666666;">Borrador · ' . h(fecha_larga()) . ' · dirección prevista: grupoabante.com/blog/' . h($a['slug']) . '.html · ' . palabras_articulo($a) . ' palabras</td></tr>';
    $b .= '<tr><td style="padding:8px 28px 0;' . F . 'font-size:26px;line-height:32px;font-weight:bold;color:#003087;">' . h($a['titulo']) . '</td></tr>';
    $imgTr = function (array $im, int $w) {
        return '<tr><td style="padding:16px 28px 0;"><img src="' . h(img_url($im, $w)) . '" alt="' . h($im['alt']) . '" width="544" style="display:block;width:100%;max-width:544px;height:auto;border:0;"><div style="' . F . 'font-size:12px;color:#777777;padding-top:4px;">Foto: ' . h($im['fotografo']) . ' en Pexels</div></td></tr>';
    };
    if (isset($a['imagenes'][0])) $b .= $imgTr($a['imagenes'][0], 1200);
    $b .= '<tr><td style="padding:16px 28px 0;' . F . 'font-size:16px;line-height:25px;color:#333333;">';
    foreach ($a['intro'] as $p) $b .= '<p style="margin:0 0 14px;">' . md_inline($p) . '</p>';
    $b .= '</td></tr>';
    foreach ($a['secciones'] as $s) {
        $b .= '<tr><td style="padding:16px 28px 0;' . F . 'font-size:16px;line-height:25px;color:#333333;"><h2 style="margin:6px 0 10px;font-size:20px;line-height:26px;color:#003087;">' . h($s['h']) . '</h2>';
        $post = '';
        foreach ($s['bloques'] as $bl) {
            if ($bl['t'] === 'p') $b .= '<p style="margin:0 0 14px;">' . md_inline($bl['x']) . '</p>';
            elseif ($bl['t'] === 'ul') foreach ($bl['items'] as $it) $b .= '<p style="margin:0 0 8px;">&bull; ' . md_inline($it) . '</p>';
            elseif ($bl['t'] === 'img' && isset($a['imagenes'][$bl['n']])) $post .= $imgTr($a['imagenes'][$bl['n']], 900);
        }
        $b .= '</td></tr>' . $post;
    }
    $b .= '<tr><td style="font-size:0;line-height:0;height:12px;">&nbsp;</td></tr>' . fila_botones($urlPub, $urlDesc);
    // texto editable
    $b .= '<tr><td style="padding:8px 28px 20px;' . F . 'font-size:13px;line-height:20px;color:#666666;">Código del borrador: <strong>' . h($cod) . '</strong> (no lo borre si responde). Para corregir, edite solo el texto entre las dos líneas; no cambie las líneas que dicen [IMAGEN n].<br><br>'
       . '<span style="white-space:pre-wrap;color:#333333;">--- INICIO ARTÍCULO ---' . "\n" . h(articulo_a_texto($a)) . '--- FIN ARTÍCULO ---</span></td></tr>';
    return correo_marco('Borrador del blog para revisión', $b, 'Correo automático del blog de Grupo Abante. Los botones vencen en ' . TOKEN_DIAS . ' días.');
}
function texto_correo_revision(array $a, string $tokPub, string $tokDesc): string {
    $cod = $a['id'] . '-' . codigo_respuesta($a['id']);
    return "Estimada Marlene:\n\nBorrador del artículo de esta semana.\n\nPublicar: " . BLOG_URL . '/publicar.php?t=' . $tokPub . "\nDescartar: " . BLOG_URL . '/descartar.php?t=' . $tokDesc
        . "\n\nSi desea corregir, responda este correo editando el texto entre las dos líneas.\nCódigo del borrador: $cod\n\n--- INICIO ARTÍCULO ---\n" . articulo_a_texto($a) . "--- FIN ARTÍCULO ---\n";
}

function html_correo_publicado(array $a, string $fb = 'desactivado'): string {
    $url = BLOG_URL . '/' . $a['slug'] . '.html';
    $b = '<tr><td style="padding:28px 28px 8px;' . F . 'font-size:16px;line-height:24px;color:#333333;"><p style="margin:0 0 12px;">Estimada Marlene:</p><p style="margin:0 0 12px;">El artículo ya está publicado y visible en el sitio. Puede revisar cómo quedó con el botón de abajo.</p></td></tr>';
    if ($fb === '') $b .= '<tr><td style="padding:0 28px 8px;' . F . 'font-size:14px;line-height:22px;color:#1877F2;font-weight:bold;">Enviado a Facebook y LinkedIn (vía Make).</td></tr>';
    elseif ($fb !== 'desactivado') $b .= '<tr><td style="padding:0 28px 8px;' . F . 'font-size:14px;line-height:22px;color:#b00020;font-weight:bold;">No se pudo enviar a las redes (' . h($fb) . '). Publíquelo a mano con los textos de más abajo.</td></tr>';
    $b .= '<tr><td style="padding:8px 28px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dddddd;"><tr>';
    if (isset($a['imagenes'][0])) $b .= '<td width="150" valign="top" style="padding:0;"><img src="' . h(img_url($a['imagenes'][0], 300)) . '" alt="Portada del artículo" width="150" style="display:block;width:150px;height:auto;border:0;"></td>';
    $b .= '<td valign="top" style="padding:14px 16px;' . F . '"><div style="font-size:12px;color:#666666;">Publicado el ' . h(fecha_larga($a['fecha_pub'])) . '</div><div style="font-size:18px;line-height:24px;font-weight:bold;color:#003087;padding-top:4px;">' . h($a['titulo']) . '</div><div style="font-size:12px;color:#666666;padding-top:6px;">grupoabante.com/blog/' . h($a['slug']) . '.html</div></td></tr></table></td></tr>';
    $b .= '<tr><td align="center" style="padding:20px 28px 24px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . boton($url, 'Ver artículo publicado', true) . '</tr></table></td></tr>';
    $b .= '<tr><td style="padding:0 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-top:1px solid #dddddd;font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>';
    foreach ([['Texto para LinkedIn', 'Copie el recuadro y péguelo en una publicación nueva.', texto_red($a, 'linkedin'), '#003087'], ['Texto para Facebook', 'Para la página de Grupo Abante. Copie el recuadro y péguelo en una publicación nueva.', texto_red($a, 'facebook'), '#1877F2']] as [$t, $ayuda, $txt, $col]) {
        $b .= '<tr><td style="padding:20px 28px 6px;' . F . 'font-size:18px;font-weight:bold;color:#003087;">' . $t . '</td></tr><tr><td style="padding:0 28px 12px;' . F . 'font-size:14px;line-height:22px;color:#333333;">' . $ayuda . '</td></tr>'
           . '<tr><td style="padding:0 28px 20px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#f3f6fb" style="border:1px solid #d5dce8;border-left:4px solid ' . $col . ';padding:16px 18px;' . F . 'font-size:15px;line-height:23px;color:#333333;white-space:pre-wrap;word-break:break-word;">' . h($txt) . '</td></tr></table></td></tr>';
    }
    return correo_marco('Artículo publicado', $b, 'Correo automático del blog de Grupo Abante.');
}
function texto_red(array $a, string $red): string {
    $url = BLOG_URL . '/' . $a['slug'] . '.html';
    return str_replace('{URL}', $url, $a[$red]);
}

// ---------- paginas web simples (publicar/descartar) ----------
function pagina_simple(string $titulo, string $mensaje, ?string $formAccion = null, string $boton = ''): void {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex,nofollow"><title>' . h($titulo) . ' | Grupo Abante</title>'
       . '<style>body{margin:0;font-family:"Segoe UI",Tahoma,Arial,sans-serif;background:#f1f3f6;color:#333}.c{max-width:520px;margin:12vh auto;background:#fff;padding:2rem 1.5rem;border-top:4px solid #003087;border-radius:4px}h1{color:#003087;font-size:1.4rem;margin:0 0 1rem}a,button{display:inline-block;background:#FF6B00;color:#fff;padding:.8rem 1.6rem;border:0;border-radius:5px;font-weight:bold;text-decoration:none;font-size:1rem;cursor:pointer;margin-top:.5rem}</style></head><body><div class="c"><h1>' . h($titulo) . '</h1><p>' . $mensaje . '</p>';
    if ($formAccion) echo '<form method="post" action="' . h($formAccion) . '"><button type="submit">' . h($boton) . '</button></form>';
    echo '</div></body></html>';
}
// Flujo comun de publicar.php / descartar.php
function atender_accion(string $accion): void {
    $tok = (string)($_GET['t'] ?? '');
    [$id, , $err] = token_verificar($tok, $accion);
    if ($err !== '') {
        http_response_code(400);
        $m = ['firma' => 'El enlace no es válido.', 'vencido' => 'El enlace venció (duran ' . TOKEN_DIAS . ' días).', 'formato' => 'El enlace no es válido.', 'accion' => 'El enlace no corresponde a esta acción.'][$err] ?? 'El enlace no es válido.';
        pagina_simple('No se pudo completar', h($m)); return;
    }
    $a = cargar_borrador($id);
    if (!$a) { http_response_code(404); pagina_simple('No se pudo completar', 'No se encontró el borrador.'); return; }
    if ($a['estado'] !== 'pendiente') {
        http_response_code(409);
        pagina_simple('Enlace ya utilizado', 'Este borrador ya fue ' . ($a['estado'] === 'publicado' ? 'publicado' : ($a['estado'] === 'descartado' ? 'descartado' : 'procesado')) . '. El enlace sirve una sola vez.'); return;
    }
    $verbo = $accion === 'publicar' ? 'publicar' : 'descartar';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // GET: solo confirma (evita que un escáner de correo dispare la acción)
        pagina_simple(ucfirst($verbo) . ' artículo', '¿Confirma que desea <strong>' . $verbo . '</strong> el artículo «' . h($a['titulo']) . '»?', $_SERVER['REQUEST_URI'], 'Sí, ' . $verbo); return;
    }
    $fh = fopen(ruta_borrador($id), 'r'); flock($fh, LOCK_EX);
    $a = cargar_borrador($id);
    if ($a['estado'] !== 'pendiente') { flock($fh, LOCK_UN); http_response_code(409); pagina_simple('Enlace ya utilizado', 'Este borrador ya fue procesado.'); return; }
    if ($accion === 'descartar') {
        $a['estado'] = 'descartado'; $a['fecha_estado'] = date('c'); guardar_borrador($a); flock($fh, LOCK_UN);
        log_blog('descartado ' . $id);
        pagina_simple('Artículo descartado', 'El artículo «' . h($a['titulo']) . '» no se publicará.'); return;
    }
    $a['estado'] = 'publicando'; guardar_borrador($a);
    try {
        $a = publicar_articulo($a);
        flock($fh, LOCK_UN);
        notificar_publicado($a);
        pagina_simple('Artículo publicado', 'El artículo «' . h($a['titulo']) . '» ya está en el sitio: <br><a href="' . h(BLOG_URL . '/' . $a['slug'] . '.html') . '">Ver artículo</a>');
    } catch (Throwable $e) {
        $a['estado'] = 'pendiente'; guardar_borrador($a); flock($fh, LOCK_UN);
        log_blog('ERROR publicando ' . $id . ': ' . $e->getMessage());
        http_response_code(500); pagina_simple('No se pudo publicar', 'Ocurrió un error. Avise a soporte.');
    }
}
// Envia el articulo al webhook de Make (publica en Facebook y LinkedIn). Nunca lanza: devuelve '' si ok, o el motivo del fallo.
function publicar_redes(array $a): string {
    if (!defined('MAKE_AUTOPUBLICAR') || !MAKE_AUTOPUBLICAR) return 'desactivado';
    if (!defined('MAKE_WEBHOOK_URL') || MAKE_WEBHOOK_URL === '') return 'sin configurar';
    try {
        $url = BLOG_URL . '/' . $a['slug'] . '.html';
        $fb = trim(preg_replace("/\n{3,}/", "\n\n", str_replace('{URL}', '', (string)($a['facebook'] ?? ''))));
        $payload = json_encode(['titulo' => $a['titulo'], 'url' => $url, 'texto_facebook' => $fb,
            'texto_linkedin' => texto_red($a, 'linkedin'), 'imagen' => isset($a['imagenes'][0]) ? img_url($a['imagenes'][0], 1200) : ''], JSON_UNESCAPED_UNICODE);
        $ch = curl_init(MAKE_WEBHOOK_URL);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload]);
        $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($code === 200) { log_blog('make ok ' . $a['id']); return ''; }
        $m = 'HTTP ' . $code . ' ' . ($err ?: substr(trim((string)$r), 0, 80));
        log_blog('ERROR make ' . $a['id'] . ': ' . $m); return $m;
    } catch (Throwable $e) { log_blog('ERROR make ' . $a['id'] . ': ' . $e->getMessage()); return $e->getMessage(); }
}
function notificar_publicado(array $a): void {
    $fb = publicar_redes($a);
    try {
        $aviso = ($fb === '' ? "Enviado a Facebook y LinkedIn (via Make).\n\n" : ($fb === 'desactivado' ? '' : "AVISO: no se pudo enviar a las redes (" . $fb . "). Publiquelo a mano con los textos de abajo.\n\n"));
        enviar_correo(destinatario_revision(), 'Publicado: ' . $a['titulo'], html_correo_publicado($a, $fb),
            $aviso . "El artículo ya está publicado: " . BLOG_URL . '/' . $a['slug'] . ".html\n\nLINKEDIN:\n" . texto_red($a, 'linkedin') . "\n\nFACEBOOK:\n" . texto_red($a, 'facebook'));
    } catch (Throwable $e) { log_blog('ERROR correo publicado: ' . $e->getMessage()); }
}
