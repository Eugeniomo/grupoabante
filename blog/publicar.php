<?php
// Publica el borrador del token. Toda la logica vive fuera de public_html.
require dirname(__DIR__, 2) . '/blog_privado/lib.php';
atender_accion('publicar');
