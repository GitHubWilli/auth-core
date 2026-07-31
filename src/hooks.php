<?php
declare(strict_types=1);

/**
 * Optionaler Erweiterungspunkt fuer Apps, die bei Nutzer-Lifecycle-Events
 * eigenen Code ausfuehren muessen (z.B. app-eigene Nutzerdaten anlegen/umbenennen/loeschen).
 * Eine App definiert dazu einfach eine Funktion mit dem entsprechenden Namen
 * (z.B. "authOnUserCreated") - existiert sie nicht, passiert nichts.
 */
function authFireHook(string $hookName, ...$args)
{
    if (function_exists($hookName)) {
        return call_user_func_array($hookName, $args);
    }

    return null;
}
