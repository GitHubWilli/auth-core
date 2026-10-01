<?php
declare(strict_types=1);

// Logo der oberen Statusleiste hochladen/aendern/entfernen (nur Administratoren).
// POST: csrf_token, action = save | remove; save nimmt logo (Datei, optional), alt, href.

requireAdmin();

$target = authRoute('users') . '?tab=logo';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo($target);
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo($target);
}

try {
    if (($_POST['action'] ?? 'save') === 'remove') {
        authBrandLogoRemove();
        setFlash('success', 'Das Logo wurde entfernt.');
        redirectTo($target);
    }

    $hasFile = isset($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        authBrandLogoSave($_FILES['logo']);
    } elseif (authBrandLogoPath() === null) {
        throw new RuntimeException('Bitte eine Logo-Datei auswählen.');
    }

    authBrandSaveMeta((string) ($_POST['alt'] ?? ''), (string) ($_POST['href'] ?? ''));
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo($target);
}

setFlash('success', 'Das Logo wurde gespeichert.');
redirectTo($target);
