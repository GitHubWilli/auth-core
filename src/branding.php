<?php
declare(strict_types=1);

/**
 * Logo der oberen Statusleiste (eine Datei je App-Instanz).
 *
 * Ablage: storage/branding/logo.<svg|png|webp|jpg> und optional storage/branding/branding.json
 * ({"alt": "...", "href": "..."}). Ohne Datei zeigt die Leiste kein Logo (wie bisher).
 * Verwaltung: Benutzerverwaltung (Admin) ueber authRenderBrandingPanel() und
 * api/admin-update-logo.php; Auslieferung (ohne Anmeldung) ueber api/brand-logo.php.
 */

const AUTH_BRAND_LOGO_MAX_BYTES = 2097152;
const AUTH_BRAND_LOGO_MAX_HEIGHT = 160;

function authBrandLogoExtensions(): array
{
    return ['svg', 'png', 'webp', 'jpg'];
}

function authBrandLogoMimeTypes(): array
{
    return [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
    ];
}

function authBrandingDir(): string
{
    return authStorageRoot() . DIRECTORY_SEPARATOR . 'branding';
}

function authBrandLogoPath(): ?string
{
    foreach (authBrandLogoExtensions() as $extension) {
        $path = authBrandingDir() . DIRECTORY_SEPARATOR . 'logo.' . $extension;
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function authBrandMeta(): array
{
    $file = authBrandingDir() . DIRECTORY_SEPARATOR . 'branding.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $data = is_array($data) ? $data : [];

    return [
        'alt' => trim((string) ($data['alt'] ?? '')),
        'href' => trim((string) ($data['href'] ?? '')),
    ];
}

/**
 * Logo fuer auth_statusbar_top() oder null, wenn keins hinterlegt ist.
 *
 * @return array{src: string, alt: string, href: string}|null
 */
function authBrandLogo(): ?array
{
    $path = authBrandLogoPath();
    if ($path === null) {
        return null;
    }

    $meta = authBrandMeta();

    return [
        'src' => authRoute('brand_logo') . '?v=' . (string) filemtime($path),
        'alt' => $meta['alt'],
        'href' => $meta['href'],
    ];
}

/** Interner Link-Pfad (kein externes Ziel, kein javascript:) oder ''. */
function authBrandNormalizeHref(string $href): string
{
    $href = trim($href);
    // nur interne Pfade (/...): kein Schema (javascript:, https:) und kein protokollrelatives //
    if ($href === '' || $href[0] !== '/' || str_starts_with($href, '//')) {
        return '';
    }

    return safeRedirectPath($href, '');
}

function authBrandSaveMeta(string $alt, string $href): void
{
    $dir = authBrandingDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Branding-Verzeichnis konnte nicht erstellt werden.');
    }

    $alt = mb_substr(trim($alt), 0, 120);
    $encoded = json_encode(['alt' => $alt, 'href' => authBrandNormalizeHref($href)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($dir . DIRECTORY_SEPARATOR . 'branding.json', $encoded . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Branding-Einstellungen konnten nicht gespeichert werden.');
    }
}

function authBrandLogoRemove(): void
{
    foreach (authBrandLogoExtensions() as $extension) {
        $path = authBrandingDir() . DIRECTORY_SEPARATOR . 'logo.' . $extension;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Speichert ein hochgeladenes Logo ($_FILES-Eintrag). Wirft RuntimeException mit
 * verstaendlicher Meldung bei Fehlern; ersetzt ein vorhandenes Logo.
 */
function authBrandLogoSave(array $file): void
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Die Datei ist zu groß (Server-Limit).',
            UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
            UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Bitte eine Datei auswählen.',
        ];
        throw new RuntimeException($messages[$error] ?? 'Upload-Fehler.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Ungültiger Upload.');
    }

    if ((int) ($file['size'] ?? 0) > AUTH_BRAND_LOGO_MAX_BYTES) {
        throw new RuntimeException('Die Datei ist zu groß (höchstens 2 MB).');
    }

    $content = (string) file_get_contents($tmp);
    $head = ltrim(substr($content, 0, 512));

    if (stripos($head, '<svg') !== false || (stripos($head, '<?xml') === 0 && stripos($content, '<svg') !== false)) {
        // SVG: Skripte und Ereignis-Attribute nicht zulassen (zusaetzlich wird es nur als <img> bzw. mit CSP sandbox ausgeliefert)
        if (preg_match('/<script|<foreignObject|\son[a-z]+\s*=|javascript:/i', $content) === 1) {
            throw new RuntimeException('Die SVG-Datei enthält nicht erlaubte Inhalte (Skripte).');
        }
        $extension = 'svg';
    } else {
        $info = @getimagesizefromstring($content);
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
        $extension = is_array($info) ? ($types[$info[2]] ?? '') : '';
        if ($extension === '') {
            throw new RuntimeException('Ungültiger Dateityp. Erlaubt: SVG, PNG, JPG, WebP.');
        }
    }

    $dir = authBrandingDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Branding-Verzeichnis konnte nicht erstellt werden.');
    }

    $data = $content;

    // Raster-Logos auf die Leistenhoehe (mit Reserve fuer Hochaufloesung) verkleinern
    if ($extension !== 'svg' && function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring($content);
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            if ($h > AUTH_BRAND_LOGO_MAX_HEIGHT) {
                $nh = AUTH_BRAND_LOGO_MAX_HEIGHT;
                $nw = max(1, (int) round($w * $nh / $h));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

                ob_start();
                if ($extension === 'png') {
                    imagepng($dst);
                } elseif ($extension === 'webp' && function_exists('imagewebp')) {
                    imagewebp($dst, null, 90);
                } else {
                    // JPEG kennt keine Transparenz: auf Weiss setzen
                    $flat = imagecreatetruecolor($nw, $nh);
                    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                    imagecopy($flat, $dst, 0, 0, 0, 0, $nw, $nh);
                    imagejpeg($flat, null, 90);
                    imagedestroy($flat);
                    $extension = $extension === 'webp' ? 'jpg' : $extension;
                }
                $resized = (string) ob_get_clean();
                if ($resized !== '') {
                    $data = $resized;
                }
                imagedestroy($dst);
            }
            imagedestroy($src);
        }
    }

    authBrandLogoRemove();
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . 'logo.' . $extension, $data, LOCK_EX) === false) {
        throw new RuntimeException('Das Logo konnte nicht gespeichert werden.');
    }
}
