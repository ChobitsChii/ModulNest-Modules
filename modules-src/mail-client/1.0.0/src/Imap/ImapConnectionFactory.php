<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Imap;

use ModulNest\MailClient\Repository\FolderCacheRepository;
use ModulNest\MailClient\Repository\MessageIndexRepository;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

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
        $encMap = [
            'ssl'      => 'ssl',
            'tls'      => 'tls',
            'starttls' => 'starttls',
        ];

        $enc = strtolower((string) ($account['imap_encryption'] ?? 'ssl'));
        $encValue = $encMap[$enc] ?? 'ssl';

        $manager = new ClientManager();
        $client = $manager->make([
            'host'          => (string) $account['imap_host'],
            'port'          => (int) $account['imap_port'],
            'encryption'    => $encValue,
            'validate_cert' => true,
            'username'      => (string) $account['imap_username'],
            'password'      => (string) $account['password'],
            'protocol'      => 'imap',
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'IMAP-Verbindung fehlgeschlagen: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $client;
    }
}

