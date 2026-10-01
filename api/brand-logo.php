<?php
declare(strict_types=1);

// Liefert das Logo der oberen Statusleiste. Bewusst ohne Anmeldung: auch die oeffentlichen
// Freigabe-Seiten haben eine obere Leiste. Das Logo ist nicht vertraulich.

$path = authBrandLogoPath();
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kein Logo vorhanden.';
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = authBrandLogoMimeTypes()[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
// SVG nie als Dokument mit Skriptrechten ausliefern (als <img> ohnehin ungefaehrlich)
header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
// Versionierte URL (?v=Aenderungszeit) aendert sich mit dem Logo -> dauerhaft cachebar
header(isset($_GET['v']) ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: public, max-age=300');
readfile($path);
exit;
