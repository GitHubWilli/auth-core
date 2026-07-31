<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function authMailerAutoload(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    $loaded = true;
}

/**
 * Versendet eine E-Mail ueber den in auth-site-config.php konfigurierten SMTP-Account.
 * Gibt true bei Erfolg zurueck, wirft RuntimeException bei Fehlkonfiguration/Versandfehler.
 */
function sendAuthMail(string $toAddress, string $toName, string $subject, string $bodyText): bool
{
    authMailerAutoload();

    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('PHPMailer ist nicht installiert (auth-core/vendor fehlt).');
    }

    $config = authConfig();
    $smtp = $config['smtp'] ?? [];
    $emailConfig = $config['email'] ?? [];

    $host = trim((string) ($smtp['host'] ?? ''));
    if ($host === '') {
        throw new RuntimeException('SMTP ist nicht konfiguriert (smtp.host fehlt in auth-site-config.php).');
    }

    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = (int) ($smtp['port'] ?? 587);
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) ($smtp['username'] ?? '');
        $mailer->Password = (string) ($smtp['password'] ?? '');

        $encryption = (string) ($smtp['encryption'] ?? 'tls');
        if ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        }

        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom(
            (string) ($emailConfig['from_address'] ?? $mailer->Username),
            (string) ($emailConfig['from_name'] ?? (string) ($config['app_name'] ?? ''))
        );
        $mailer->addAddress($toAddress, $toName);
        $mailer->Subject = $subject;
        $mailer->isHTML(false);
        $mailer->Body = $bodyText;

        return $mailer->send();
    } catch (PHPMailerException $exception) {
        throw new RuntimeException('E-Mail konnte nicht versendet werden: ' . $mailer->ErrorInfo, 0, $exception);
    }
}
