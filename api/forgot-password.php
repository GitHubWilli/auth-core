<?php
declare(strict_types=1);

requireGuest();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('forgot_password'));
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('forgot_password'));
}

$identifier = trim((string) ($_POST['identifier'] ?? ''));

// Immer dieselbe Erfolgsmeldung, unabhaengig davon ob ein Konto gefunden wurde
// (kein Enumeration-Leak ueber Existenz von Benutzernamen/E-Mail-Adressen).
$successMessage = 'Falls ein Konto zu dieser Angabe existiert, wurde eine E-Mail mit einem Link zum Zuruecksetzen des Passworts verschickt.';

if ($identifier === '') {
    setFlash('success', $successMessage);
    redirectTo(authRoute('login'));
}

$user = strpos($identifier, '@') !== false ? findUserByEmail($identifier) : findUser($identifier);

if ($user !== null && !empty($user['is_active']) && !empty($user['email'])) {
    $normalizedUsername = (string) $user['username_normalized'];
    $token = createPasswordResetToken($normalizedUsername);

    $resetLink = authAbsoluteUrl(authRoute('reset_password')) . '?u=' . rawurlencode($normalizedUsername) . '&token=' . rawurlencode($token);

    $appName = (string) (authConfig()['app_name'] ?? 'App');
    $body = "Hallo " . $user['username'] . ",\n\n"
        . "fuer dein Konto bei " . $appName . " wurde ein Zuruecksetzen des Passworts angefordert.\n"
        . "Falls du das warst, klicke auf folgenden Link (gueltig fuer kurze Zeit):\n\n"
        . $resetLink . "\n\n"
        . "Falls du das nicht warst, kannst du diese E-Mail ignorieren.\n";

    try {
        sendAuthMail((string) $user['email'], (string) $user['username'], $appName . ': Passwort zuruecksetzen', $body);
    } catch (RuntimeException $runtimeException) {
        // Versandfehler nicht an den Client durchreichen (kein Enumeration-Leak),
        // aber fuer Admin-Diagnose loggen.
        error_log('[auth-core] Passwort-Reset-Mail konnte nicht versendet werden: ' . $runtimeException->getMessage());
    }
}

setFlash('success', $successMessage);
redirectTo(authRoute('login'));
