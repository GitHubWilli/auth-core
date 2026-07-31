<?php
declare(strict_types=1);


requireGuest();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirectTo(authRoute('login'));
}

$redirect = safeRedirectPath($_POST['redirect'] ?? authRoute('after_login'), authRoute('after_login'));

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('error', 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.');
    redirectTo(authRoute('login') . '?redirect=' . rawurlencode($redirect));
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    setFlash('error', 'Bitte Benutzername und Passwort eingeben.');
    redirectTo(authRoute('login') . '?redirect=' . rawurlencode($redirect));
}

if (!loginUser($username, $password)) {
    setFlash('error', 'Benutzername oder Passwort sind ungültig.');
    redirectTo(authRoute('login') . '?redirect=' . rawurlencode($redirect));
}

setFlash('success', 'Erfolgreich angemeldet.');
redirectTo($redirect);
