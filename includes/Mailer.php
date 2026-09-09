<?php

declare(strict_types=1);

/**
 * Schlanker SMTP-Client (AUTH LOGIN, STARTTLS/SSL) ohne externe Abhängigkeiten.
 * Konfiguration kommt vollständig aus der .env (SMTP_HOST, SMTP_PORT,
 * SMTP_ENCRYPTION, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM).
 */
final class Mailer
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct(string $fromName = 'AFBÖ U19 Mitgliederverwaltung')
    {
        $this->host = (string) getenv('SMTP_HOST');
        $this->port = (int) getenv('SMTP_PORT');
        $this->encryption = strtoupper((string) (getenv('SMTP_ENCRYPTION') ?: 'STARTTLS'));
        $this->username = (string) getenv('SMTP_USERNAME');
        $this->password = (string) getenv('SMTP_PASSWORD');
        $this->fromEmail = (string) (getenv('SMTP_FROM') ?: $this->username);
        $this->fromName = $fromName;
    }

    /**
     * @throws RuntimeException bei jedem SMTP- oder Verbindungsfehler
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): void
    {
        $useImplicitTls = $this->encryption === 'SSL' || $this->encryption === 'TLS';
        $transport = $useImplicitTls ? 'ssl://' : 'tcp://';

        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);

        try {
            $localName = $_SERVER['SERVER_NAME'] ?? 'localhost';

            $this->expect($socket, 220);
            $this->command($socket, "EHLO {$localName}", 250);

            if ($this->encryption === 'STARTTLS') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS-Verschlüsselung fehlgeschlagen.');
                }
                $this->command($socket, "EHLO {$localName}", 250);
            }

            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($this->username), 334);
            $this->command($socket, base64_encode($this->password), 235);

            $this->command($socket, "MAIL FROM:<{$this->fromEmail}>", 250);
            $this->command($socket, "RCPT TO:<{$toEmail}>", 250);
            $this->command($socket, 'DATA', 354);

            $message = $this->buildMessage($toEmail, $toName, $subject, $htmlBody, $textBody);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, 250);

            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function buildMessage(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $localName = $_SERVER['SERVER_NAME'] ?? 'localhost';

        $headers = [
            'From: ' . $this->encodeHeader($this->fromName) . " <{$this->fromEmail}>",
            'To: ' . $this->encodeHeader($toName) . " <{$toEmail}>",
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@{$localName}>",
        ];

        $plain = $textBody !== '' ? $textBody : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $plain . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . "--{$boundary}--";

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        // Dot-Stuffing: Zeilen, die mit einem Punkt beginnen, müssen verdoppelt werden.
        $lines = explode("\r\n", $message);
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
        }

        return implode("\r\n", $lines);
    }

    /**
     * @return resource
     */
    private function command($socket, string $cmd, int $expectedCode): string
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, int $expectedCode): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('Keine Antwort vom SMTP-Server erhalten.');
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("SMTP-Fehler: erwartet {$expectedCode}, erhalten: " . trim($response));
        }

        return $response;
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
