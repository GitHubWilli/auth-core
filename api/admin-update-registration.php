<?php
declare(strict_types=1);


requireAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('users'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('users'));
}

try {
    writeAuthSettings([
        'allow_self_registration' => ($_POST['allow_self_registration'] ?? null) === '1',
        'require_admin_approval' => ($_POST['require_admin_approval'] ?? null) === '1',
    ]);
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('users'));
}

setFlash('success', 'Die Registrierungseinstellung wurde gespeichert.');
redirectTo(authRoute('users'));
