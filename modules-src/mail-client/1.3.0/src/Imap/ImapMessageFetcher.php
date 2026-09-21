<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Imap;

use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;
use ModulNest\MailClient\Ics\IcsParserService;

/**
 * Lädt Mail-Bodies und Anhänge direkt vom IMAP-Server.
 * Bodies werden NICHT gecacht – jeder Aufruf geht live zum Server.
 */
final class ImapMessageFetcher
{
    private IcsParserService $icsParser;

    public function __construct(?IcsParserService $icsParser = null)
    {
        $this->icsParser = $icsParser ?? new IcsParserService();
    }
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
    public function fetchByUid(array $account, string $folder, int $uid, string $userTimezone = 'Europe/Berlin'): array
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

            return $this->extractMessageData($messages, $userTimezone);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Nachricht konnte nicht geladen werden: ' . $e->getMessage(), 0, $e);
        } finally {
            $client->disconnect();
            ImapConnectionFactory::flushImapErrors();
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
            ImapConnectionFactory::flushImapErrors();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function extractMessageData(Message $msg, string $userTimezone = 'Europe/Berlin'): array
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
        if ($fromObj !== null) {
            $fromArr = is_object($fromObj) && method_exists($fromObj, 'toArray')
                ? $fromObj->toArray()
                : (is_array($fromObj) ? $fromObj : null);
            if (is_array($fromArr) && isset($fromArr[0])) {
                $fa       = $fromArr[0];
                $from     = is_object($fa) ? (string) ($fa->mail ?? '') : '';
                $fromName = is_object($fa) ? (string) ($fa->personal ?? '') : '';
                if ($from === '' && is_object($fa)) {
                    $mb   = (string) ($fa->mailbox ?? '');
                    $host = (string) ($fa->host ?? '');
                    if ($mb !== '' && $host !== '') {
                        $from = $mb . '@' . $host;
                    }
                }
            } elseif (is_string($fromObj)) {
                $from = $fromObj;
            }
        }

        $from     = self::decodeHeader($from);
        $fromName = self::decodeHeader($fromName);

        // Falls from oder fromName kombiniert Format "Name <mail>" haben
        if (preg_match('/^(.*?)\s*<([^>]+)>?$/', $from, $mFrom)) {
            if ($fromName === '') {
                $fromName = trim($mFrom[1], "\"\x27\t\n\r ");
            }
            $from = trim($mFrom[2]);
        }
        if (preg_match('/^(.*?)\s*<([^>]+)>?$/', $fromName, $mFromName)) {
            $fromName = trim($mFromName[1], "\"\x27\t\n\r ");
            if ($from === '') {
                $from = trim($mFromName[2]);
            }
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

        // Anhänge & Kalender-Termine (.ics / VCALENDAR)
        $attachments    = [];
        $calendarEvents = [];
        $attList        = $msg->getAttachments();

        foreach ($attList as $att) {
            if (!is_object($att)) {
                continue;
            }
            $filename = $this->sanitizeFilename((string) ($att->getName() ?? "attachment"));
            $mimetype = (string) ($att->getMimeType() ?? "application/octet-stream");
            $partId   = (string) ($att->part_number ?? "");
            $size     = (int) ($att->getSize() ?? 0);

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $isIcs = in_array($ext, ["ics", "ical", "ifb"], true)
                     || in_array(strtolower($mimetype), ["text/calendar", "application/ics", "text/x-vcalendar"], true);

            if ($isIcs) {
                try {
                    $rawContent = (string) ($att->getContent() ?? "");
                    if ($rawContent !== "" && str_contains(strtoupper($rawContent), "BEGIN:VCALENDAR")) {
                        $parsedEvents = $this->icsParser->parse($rawContent, $userTimezone);
                        foreach ($parsedEvents as $ev) {
                            $ev["part_id"]  = $partId;
                            $ev["filename"] = $filename;
                            $calendarEvents[] = $ev;
                        }
                    }
                } catch (\Throwable) {}
            }

            $attachments[] = [
                "filename"    => $filename,
                "mimetype"    => $mimetype,
                "size"        => $size,
                "part_id"     => $partId,
                "is_calendar" => $isIcs,
            ];
        }

        // Auch Inline- oder Multipart-Kalenderdaten prüfen (z.B. aus Google Calendar oder Outlook)
        try {
            $bodies = $msg->getBodies();
            if (is_array($bodies)) {
                foreach (["calendar", "vcalendar", "ics"] as $bodyKey) {
                    if (!empty($bodies[$bodyKey]) && is_string($bodies[$bodyKey])) {
                        if (str_contains(strtoupper($bodies[$bodyKey]), "BEGIN:VCALENDAR")) {
                            $parsedEvents = $this->icsParser->parse($bodies[$bodyKey], $userTimezone);
                            foreach ($parsedEvents as $ev) {
                                if (!in_array($ev["uid"], array_column($calendarEvents, "uid"), true)) {
                                    $ev["part_id"]  = "";
                                    $ev["filename"] = "invite.ics";
                                    $calendarEvents[] = $ev;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {}

        // Fallback: Falls noch keine Events gefunden wurden, rohen Text/Body nach BEGIN:VCALENDAR durchsuchen
        if (empty($calendarEvents)) {
            $rawCandidates = [$textBody, $htmlBody];
            try {
                $rawCandidates[] = $msg->getRawBody();
            } catch (\Throwable) {}

            foreach ($rawCandidates as $candidate) {
                if (is_string($candidate) && str_contains(strtoupper($candidate), "BEGIN:VCALENDAR")) {
                    if (preg_match('/BEGIN:VCALENDAR[\s\S]*?END:VCALENDAR/i', $candidate, $m)) {
                        $parsedEvents = $this->icsParser->parse($m[0], $userTimezone);
                        if (!empty($parsedEvents)) {
                            foreach ($parsedEvents as $ev) {
                                $ev["part_id"]  = "";
                                $ev["filename"] = "invite.ics";
                                $calendarEvents[] = $ev;
                            }
                            break;
                        }
                    }
                }
            }
        }

        return [
            "uid"             => (int) $msg->getUid(),
            "subject"         => self::decodeHeader((string) ($msg->getSubject() ?? "")),
            "from"            => $from,
            "from_name"       => $fromName,
            "to"              => self::decodeHeader($to),
            "cc"              => self::decodeHeader($cc),
            "date"            => $dateStr,
            "date_ts"         => $dateTs,
            "html_body"       => $htmlBody,
            "text_body"       => $textBody,
            "has_html"        => $htmlBody !== "",
            "attachments"     => $attachments,
            "calendar_events" => $calendarEvents,
        ];
    }

        private function addressListToString(mixed $list): string
    {
        if ($list === null) {
            return '';
        }
        if (is_string($list)) {
            return trim($list);
        }
        if (is_object($list) && method_exists($list, 'toArray')) {
            $list = $list->toArray();
        }
        if (is_iterable($list)) {
            $parts = [];
            foreach ($list as $addr) {
                if (is_object($addr)) {
                    $full     = (string) ($addr->full ?? '');
                    $personal = (string) ($addr->personal ?? '');
                    $mail     = (string) ($addr->mail ?? '');
                    if ($full !== '') {
                        $parts[] = self::decodeHeader($full);
                    } elseif ($personal !== '' && $mail !== '') {
                        $personalDecoded = self::decodeHeader($personal);
                        $parts[] = "{$personalDecoded} <{$mail}>";
                    } elseif ($mail !== '') {
                        $parts[] = self::decodeHeader($mail);
                    } elseif (method_exists($addr, '__toString')) {
                        $s = trim((string) $addr);
                        if ($s !== '') {
                            $parts[] = $s;
                        }
                    }
                } elseif (is_string($addr) && trim($addr) !== '') {
                    $parts[] = trim($addr);
                }
            }
            if (!empty($parts)) {
                return implode(', ', $parts);
            }
        }
        if (is_object($list) && method_exists($list, 'toString')) {
            return trim((string) $list->toString());
        }
        if (is_object($list) && method_exists($list, '__toString')) {
            return trim((string) $list);
        }
        return '';
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

    /**
     * Dekodiert MIME-encoded Words (RFC 2047) wie =?UTF-8?Q?...?= oder =?UTF-8?B?...?=.
     */
    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, "=?")) {
            return $value;
        }
        if (function_exists("iconv_mime_decode")) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8");
            if ($decoded !== false && $decoded !== "") {
                return $decoded;
            }
        }
        if (function_exists("mb_decode_mimeheader")) {
            return mb_decode_mimeheader($value);
        }
        return $value;
    }

}
