<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Imap;

use RuntimeException;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;

/**
 * Baut IMAP-Verbindungen via webklex/php-imap auf.
 * Jede Connection wird nach dem Abrufen der benötigten Daten getrennt.
 */
final class ImapConnectionFactory
{
    /**
     * Erstellt einen IMAP-Client und verbindet sofort.
     *
     * @param array<string, mixed> $account Konto-Daten (imap_host, imap_port, imap_encryption, imap_username, password [Klartext])
     */
    public static function connect(array $account): Client
    {
        // Shutdown handler registrieren: IMAP error stack immer flushen,
        // damit libc-client keine PHP-Notices (errflg=3) beim Request-Shutdown erzeugt.
        static $shutdownRegistered = false;
        if (!$shutdownRegistered) {
            $shutdownRegistered = true;
            register_shutdown_function([self::class, 'flushImapErrors']);
        }

        $encMap = [
            'ssl'      => 'ssl',
            'tls'      => 'tls',
            'starttls' => 'starttls',
        ];

        $enc = strtolower((string) ($account['imap_encryption'] ?? 'ssl'));
        $encValue = $encMap[$enc] ?? 'ssl';

        // Optionen: rfc822=false verhindert \imap_rfc822_parse_headers(), welches bei
        // fehlerhaften Header-Adressen (z. B. Newslettern, Marketing-Mails) den c-client Error-Stack
        // mit Notices füllt und PHP Request Shutdown Fehler auslöst.
        $options = [
            'rfc822'    => false,
            'soft_fail' => true,
        ];

        $manager = new ClientManager(['options' => $options]);
        $client = $manager->make([
            'host'          => (string) $account['imap_host'],
            'port'          => (int) $account['imap_port'],
            'encryption'    => $encValue,
            'validate_cert' => true,
            'username'      => (string) $account['imap_username'],
            'password'      => (string) $account['password'],
            'protocol'      => 'imap',
            'options'       => $options,
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            self::flushImapErrors();
            throw new RuntimeException(
                'IMAP-Verbindung fehlgeschlagen: ' . $e->getMessage(),
                0,
                $e
            );
        }

        self::flushImapErrors();
        return $client;
    }

    /**
     * Leert den internen IMAP-Error- und Alert-Stack von c-client/ext-imap.
     */
    public static function flushImapErrors(): void
    {
        if (function_exists('imap_errors')) {
            @imap_errors();
        }
        if (function_exists('imap_alerts')) {
            @imap_alerts();
        }
    }
}
