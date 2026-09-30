<?php
declare(strict_types=1);

// Empfaenger fuer die weitergereichte Anmeldung (POST mit token + redirect).
// Jeder Fehlerfall fuehrt wie gehabt zur normalen Login-Maske.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('login'));
}

$redirect = safeRedirectPath($_POST['redirect'] ?? authRoute('after_login'), authRoute('after_login'));
$loginUrl = authRoute('login') . '?redirect=' . rawurlencode($redirect);

$username = ssoVerifyToken((string) ($_POST['token'] ?? ''), ssoAudience(), ssoReceiverSecret());
$user = $username !== null ? findUser($username) : null;

if ($user === null || empty($user['is_active'])) {
    // Kein gueltiges Token: bestehende Sitzung behalten, sonst Login-Maske
    redirectTo(currentUser() !== null ? $redirect : $loginUrl);
}

$current = currentUser();
if ($current === null || ($current['username_normalized'] ?? '') !== $user['username_normalized']) {
    establishAuthenticatedSession($user);
}

redirectTo($redirect);
