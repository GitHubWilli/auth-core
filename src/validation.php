<?php
declare(strict_types=1);

function normalizeUsername(string $username): string
{
    return strtolower(trim($username));
}

function validateUsername(string $username)
{
    $username = trim($username);
    $config = authConfig();

    if ($username === '') {
        return 'Bitte einen Benutzernamen eingeben.';
    }

    if (strlen($username) < 3) {
        return 'Der Benutzername muss mindestens 3 Zeichen lang sein.';
    }

    if (strlen($username) > (int) $config['max_username_length']) {
        return 'Der Benutzername ist zu lang.';
    }

    if (!preg_match('/^[A-Za-z0-9_-]+$/', $username)) {
        return 'Erlaubt sind nur Buchstaben, Zahlen, Bindestriche und Unterstriche.';
    }

    return null;
}

function validatePassword(string $password)
{
    $config = authConfig();
    $minLength = (int) $config['min_password_length'];

    if ($password === '') {
        return 'Bitte ein Passwort eingeben.';
    }

    if (strlen($password) < $minLength) {
        return 'Das Passwort muss mindestens ' . $minLength . ' Zeichen lang sein.';
    }

    return null;
}

function validatePasswordConfirmation(string $password, string $confirmation)
{
    if ($password !== $confirmation) {
        return 'Die Passwörter stimmen nicht überein.';
    }

    return null;
}

function safeRedirectPath($path, string $default = 'index.php'): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return $default;
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path)) {
        return $default;
    }

    if (strpos($path, "\r") !== false || strpos($path, "\n") !== false) {
        return $default;
    }

    if (strpos($path, '..') !== false) {
        return $default;
    }

    if (!preg_match('#^/?[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]+$#', $path)) {
        return $default;
    }

    return $path;
}
