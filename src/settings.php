<?php
declare(strict_types=1);

function authSettingsFilePath(): string
{
    return (string) authConfig()['auth_settings_file'];
}

function defaultAuthSettings(): array
{
    return [
        'allow_self_registration' => true,
        'require_admin_approval' => !empty(authConfig()['registration']['require_admin_approval']),
    ];
}

function normalizeAuthSettings(array $settings): array
{
    return [
        'allow_self_registration' => !array_key_exists('allow_self_registration', $settings) || (bool) $settings['allow_self_registration'],
        'require_admin_approval' => array_key_exists('require_admin_approval', $settings)
            ? (bool) $settings['require_admin_approval']
            : !empty(authConfig()['registration']['require_admin_approval']),
    ];
}

function readAuthSettings(): array
{
    $path = authSettingsFilePath();
    if (!file_exists($path)) {
        return defaultAuthSettings();
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return defaultAuthSettings();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return defaultAuthSettings();
    }

    return normalizeAuthSettings($decoded);
}

function writeAuthSettings(array $settings)
{
    $path = authSettingsFilePath();
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Einstellungsverzeichnis konnte nicht erstellt werden.');
    }

    $encoded = json_encode(normalizeAuthSettings($settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Einstellungen konnten nicht serialisiert werden.');
    }

    if (file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Einstellungen konnten nicht gespeichert werden.');
    }
}

function selfRegistrationAllowed(): bool
{
    return !empty(readAuthSettings()['allow_self_registration']);
}

function requireAdminApprovalForRegistration(): bool
{
    return !empty(readAuthSettings()['require_admin_approval']);
}
