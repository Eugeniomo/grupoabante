<?php
// Crons (cPanel > Cron Jobs):
//   0 * * * *    /usr/local/bin/ea-php83 /home/gzqmzbbg/blog_privado/generar.php --cron >/dev/null 2>&1
//   */15 * * * * /usr/local/bin/ea-php83 /home/gzqmzbbg/blog_privado/correcciones.php >/dev/null 2>&1
// Copiar a config.php (NO se versiona) en /home/gzqmzbbg/blog_privado/ y completar. Permisos 600.
date_default_timezone_set('America/Santiago');
const MODO_PRUEBA   = true;                       // true: todos los correos van a DEST_PRUEBA. Producción: false
const DEST_PRUEBA   = 'emoyae@gmail.com';
const DEST_REVISORA = 'mrosas@grupoabante.com';   // Marlene Rosas: solo se usa con MODO_PRUEBA=false
const AUTORIZADOS   = [DEST_PRUEBA];              // producción: [DEST_REVISORA]
const SITIO_URL     = 'https://www.grupoabante.com';
const BLOG_URL      = 'https://www.grupoabante.com/blog';
const PUBLIC_DIR    = '/home/gzqmzbbg/public_html';
const BLOG_SECRET   = '';                         // 64 hex aleatorios (firma HMAC de los enlaces)
const MAIL_HOST = 'mail.grupoabante.com'; const MAIL_PORT = 587;
const MAIL_USER = ''; const MAIL_PASS = ''; const MAIL_FROM = '';
const REPLY_BUZON = '';                           // buzón que lee correcciones.php (Reply-To)
const IMAP_HOST = 'mail.grupoabante.com'; const IMAP_USER = ''; const IMAP_PASS = '';
const GEMINI_KEY = '';
const PEXELS_KEY = '';                            // FALTA: crear key gratuita en pexels.com/api
// Redes: el blog envia cada articulo publicado a un webhook de Make, que publica en Facebook y LinkedIn.
const MAKE_AUTOPUBLICAR = false;
const MAKE_WEBHOOK_URL  = '';                    // secreto: solo en el archivo real del servidor
