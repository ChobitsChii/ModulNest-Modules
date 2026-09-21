<?php

declare(strict_types=1);

namespace ModulNest\MailClient\Smtp;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sendet Mails über SMTP via symfony/mailer.
 */
final class SmtpSender
{
    /**
     * Sendet eine Mail.
     *
     * @param array<string, mixed> $account     Konto-Daten (smtp_host, smtp_port etc. + password Klartext)
     * @param array<string, mixed> $envelope    {from: string, to: string[], cc?: string[], bcc?: string[], reply_to?: string}
     * @param string               $subject
     * @param string               $htmlBody    Bereits sanitierter HTML-Body
     * @param string               $textBody    Plaintext-Version (Fallback)
     */
    public function send(array $account, array $envelope, string $subject, string $htmlBody, string $textBody = ''): void
    {
        $dsn = $this->buildDsn($account);

        try {
            $transport = Transport::fromDsn($dsn);
            $mailer    = new Mailer($transport);

            $email = new Email();
            $email->subject($subject);

            // Absender (unterstützt Aliase / alternative Identitäten)
            $fromAddress = (string) ($envelope['from_email'] ?? $account['email_address'] ?? '');
            $fromName    = (string) ($envelope['from_name'] ?? $account['display_name'] ?? '');
            $email->from(new Address($fromAddress, $fromName));

            // Empfänger
            $to = $this->parseAddresses((array) $envelope['to']);
            if ($to === []) {
                throw new RuntimeException('Kein Empfänger angegeben.');
            }
            $email->to(...$to);

            // CC
            if (!empty($envelope['cc'])) {
                $cc = $this->parseAddresses((array) $envelope['cc']);
                if ($cc !== []) {
                    $email->cc(...$cc);
                }
            }

            // BCC
            if (!empty($envelope['bcc'])) {
                $bcc = $this->parseAddresses((array) $envelope['bcc']);
                if ($bcc !== []) {
                    $email->bcc(...$bcc);
                }
            }

            // Reply-To
            if (!empty($envelope['reply_to'])) {
                $rt = $this->parseAddresses([(string) $envelope['reply_to']]);
                if ($rt !== []) {
                    $email->replyTo(...$rt);
                }
            }

            // Body: HTML bevorzugt, Text als Fallback
            if ($htmlBody !== '') {
                $email->html($htmlBody);
                if ($textBody !== '') {
                    $email->text($textBody);
                }
            } elseif ($textBody !== '') {
                $email->text($textBody);
            } else {
                throw new RuntimeException('Nachrichtentext fehlt.');
            }

            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('SMTP-Versand fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $account */
    private function buildDsn(array $account): string
    {
        $user = rawurlencode((string) $account['smtp_username']);
        $pass = rawurlencode((string) $account['password']);
        $host = (string) $account['smtp_host'];
        $port = (int) $account['smtp_port'];

        $enc = strtolower((string) ($account['smtp_encryption'] ?? 'tls'));
        // symfony/mailer DSN Schema:
        // smtp(s)://user:pass@host:port?verify_peer=0
        // smtps = TLS/SSL (Port 465), smtp = STARTTLS/Plain (Port 587)
        $scheme = match ($enc) {
            'ssl'      => 'smtps',
            'starttls' => 'smtp',
            default    => 'smtp', // 'tls' → STARTTLS
        };

        return "{$scheme}://{$user}:{$pass}@{$host}:{$port}";
    }

    /**
     * Parst Adressen aus Strings wie "Name <email@example.com>" oder "email@example.com".
     *
     * @param array<int, string|mixed> $rawList
     * @return array<int, Address>
     */
    private function parseAddresses(array $rawList): array
    {
        $result = [];
        foreach ($rawList as $raw) {
            $str = trim((string) $raw);
            if ($str === '') {
                continue;
            }
            // Mehrere durch Komma getrennte Adressen aufsplitten
            $parts = array_map('trim', explode(',', $str));
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                // "Name <email>" Muster
                if (preg_match('/^(.+?)\s*<([^>]+)>\s*$/', $part, $m)) {
                    $name  = trim($m[1], '"\'');
                    $email = trim($m[2]);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $result[] = new Address($email, $name);
                    }
                } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                    $result[] = new Address($part);
                }
            }
        }
        return $result;
    }
}

