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
        'allow_non_admin_password_change' => ($_POST['allow_non_admin_password_change'] ?? null) === '1',
        'allow_non_admin_account_deletion' => ($_POST['allow_non_admin_account_deletion'] ?? null) === '1',
    ]);
} catch (RuntimeException $runtimeException) {
    setFlash('error', $runtimeException->getMessage());
    redirectTo(authRoute('users') . '?tab=options');
}

setFlash('success', 'Die Optionen wurden gespeichert.');
redirectTo(authRoute('users') . '?tab=options');
