<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Imap;

use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Lädt Mail-Bodies und Anhänge direkt vom IMAP-Server.
 * Bodies werden NICHT gecacht – jeder Aufruf geht live zum Server.
 */
final class ImapMessageFetcher
{
    /**
     * Lädt den Body einer Nachricht anhand der UID.
     *
     * @param array<string, mixed> $account
     * @return array{
     *   uid: int,
     *   subject: string,
     *   from: string,
     *   from_name: string,
     *   to: string,
     *   cc: string,
     *   date: string,
     *   date_ts: int,
     *   html_body: string,
     *   text_body: string,
     *   has_html: bool,
     *   attachments: array<int, array{filename: string, mimetype: string, size: int, part_id: string}>
     * }
     */
    public function fetchByUid(array $account, string $folder, int $uid): array
    {
        $client = ImapConnectionFactory::connect($account);
        try {
            // Ordner öffnen
            $folderObj = $this->findFolder($client, $folder);
            if ($folderObj === null) {
                throw new RuntimeException("Ordner '{$folder}' nicht gefunden.");
            }

            // Nachricht per UID laden
            $messages = $folderObj->query()->getMessageByUid($uid);
            if ($messages === null) {
                throw new RuntimeException("Nachricht UID {$uid} nicht gefunden.");
            }

            return $this->extractMessageData($messages);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Nachricht konnte nicht geladen werden: ' . $e->getMessage(), 0, $e);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Lädt einen Anhang für Download (gibt rohe Bytes zurück).
     *
     * @param array<string, mixed> $account
     * @return array{filename: string, mimetype: string, data: string}
     */
    public function fetchAttachment(array $account, string $folder, int $uid, string $partId): array
    {
        $client = ImapConnectionFactory::connect($account);
        try {
            $folderObj = $this->findFolder($client, $folder);
            if ($folderObj === null) {
                throw new RuntimeException("Ordner '{$folder}' nicht gefunden.");
            }

            $message = $folderObj->query()->getMessageByUid($uid);
            if ($message === null) {
                throw new RuntimeException("Nachricht UID {$uid} nicht gefunden.");
            }

            $attachments = $message->getAttachments();
            foreach ($attachments as $att) {
                if (!is_object($att)) {
                    continue;
                }
                $attId = (string) ($att->part_number ?? '');
                if ($attId !== $partId) {
                    continue;
                }
                return [
                    'filename' => $this->sanitizeFilename((string) ($att->getName() ?? 'attachment')),
                    'mimetype' => (string) ($att->getMimeType() ?? 'application/octet-stream'),
                    'data'     => (string) ($att->getContent() ?? ''),
                ];
            }

            throw new RuntimeException("Anhang '{$partId}' nicht gefunden.");
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Anhang konnte nicht geladen werden: ' . $e->getMessage(), 0, $e);
        } finally {
            $client->disconnect();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function extractMessageData(Message $msg): array
    {
        $htmlBody  = '';
        $textBody  = '';

        // HTML bevorzugen, Plaintext als Fallback
        $htmlParts = $msg->getHTMLBody();
        if ($htmlParts !== null && (string) $htmlParts !== '') {
            $htmlBody = (string) $htmlParts;
        }
        $textParts = $msg->getTextBody();
        if ($textParts !== null && (string) $textParts !== '') {
            $textBody = (string) $textParts;
        }

        // Absender
        $fromObj  = $msg->getFrom();
        $from     = '';
        $fromName = '';
        if ($fromObj !== null && method_exists($fromObj, 'toArray')) {
            $fromArr = $fromObj->toArray();
            if (is_array($fromArr) && isset($fromArr[0])) {
                $fa       = $fromArr[0];
                $from     = is_object($fa) ? (string) ($fa->mail ?? '') : '';
                $fromName = is_object($fa) ? (string) ($fa->personal ?? '') : '';
            }
        } elseif (is_string($fromObj)) {
            $from = $fromObj;
        }

        // An / CC
        $to = $this->addressListToString($msg->getTo());
        $cc = $this->addressListToString($msg->getCc());

        // Datum
        $dateObj = $msg->getDate();
        $dateStr = '';
        $dateTs  = 0;
        if ($dateObj !== null && method_exists($dateObj, 'first')) {
            $d = $dateObj->first();
            if ($d !== null) {
                $dateStr = (string) $d;
                $dateTs  = (int) @strtotime($dateStr);
            }
        }

        // Anhänge
        $attachments = [];
        $attList = $msg->getAttachments();
        foreach ($attList as $att) {
            if (!is_object($att)) {
                continue;
            }
            $attachments[] = [
                'filename' => $this->sanitizeFilename((string) ($att->getName() ?? 'attachment')),
                'mimetype' => (string) ($att->getMimeType() ?? 'application/octet-stream'),
                'size'     => (int) ($att->getSize() ?? 0),
                'part_id'  => (string) ($att->part_number ?? ''),
            ];
        }

        return [
            'uid'         => (int) $msg->getUid(),
            'subject'     => (string) ($msg->getSubject() ?? ''),
            'from'        => $from,
            'from_name'   => $fromName,
            'to'          => $to,
            'cc'          => $cc,
            'date'        => $dateStr,
            'date_ts'     => $dateTs,
            'html_body'   => $htmlBody,
            'text_body'   => $textBody,
            'has_html'    => $htmlBody !== '',
            'attachments' => $attachments,
        ];
    }

    private function addressListToString(mixed $list): string
    {
        if ($list === null) {
            return '';
        }
        if (is_string($list)) {
            return $list;
        }
        $parts = [];
        if (is_iterable($list)) {
            foreach ($list as $addr) {
                if (is_object($addr) && isset($addr->mail)) {
                    $name = (string) ($addr->personal ?? '');
                    $mail = (string) ($addr->mail ?? '');
                    $parts[] = $name !== '' ? "{$name} <{$mail}>" : $mail;
                } elseif (is_string($addr)) {
                    $parts[] = $addr;
                }
            }
        }
        return implode(', ', $parts);
    }

    private function findFolder(mixed $client, string $folder): mixed
    {
        if (!is_object($client) || !method_exists($client, 'getFolders')) {
            return null;
        }
        foreach ($client->getFolders(false) as $f) {
            if (!is_object($f)) {
                continue;
            }
            $name = strtolower(trim((string) ($f->path ?? $f->name ?? '')));
            if ($name === strtolower($folder)) {
                return $f;
            }
        }
        return null;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N} ._-]/u', '_', $name) ?? 'attachment';
        return trim($name, ' ._-') ?: 'attachment';
    }
}

