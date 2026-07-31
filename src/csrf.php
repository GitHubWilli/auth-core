<?php
declare(strict_types=1);

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function isValidCsrfToken($token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }

    $current = $_SESSION['csrf_token'] ?? '';
    return is_string($current) && $current !== '' && hash_equals($current, $token);
}
