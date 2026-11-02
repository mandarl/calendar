<?php

declare(strict_types=1);

namespace Dms\Kit\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * NOT THE OWNER'S CHOICE for production mail: the owner decided on the SparkPost HTTP API (Cake\SparkPostTransport /
 * SparkPostApiTransport). Kept only as an option (CLI scripts, the kit's MailHog test).
 *
 * Minimal SMTP sender for NON-Cake contexts (CLI scripts, the kit's own MailHog test). A Cake 5 application should NOT use
 * this class: Cake\Mailer\Transport\SmtpTransport already speaks SMTP/STARTTLS/AUTH and is what the harness adapter already
 * points at MailHog (e2e/harness/adapters/cake3/app.php). Requires symfony/mailer (a dev dependency of the kit).
 *
 * DSN examples:  smtp://mail:1025                (MailHog, no TLS/auth)
 *                smtp://USER:PASS@smtp.relay.example.test:587   (STARTTLS is negotiated when the server offers it)
 */
final class SmtpSender implements MailTransport
{
    private readonly Mailer $mailer;

    public function __construct(string $dsn)
    {
        $this->mailer = new Mailer(Transport::fromDsn($dsn));
    }

    public static function fromEnv(array $env): self
    {
        $host = (string)($env['EMAIL_HOST'] ?? 'localhost');
        $port = (int)($env['EMAIL_PORT'] ?? 25);
        $user = (string)($env['EMAIL_USERNAME'] ?? '');
        $pass = (string)($env['EMAIL_PASSWORD'] ?? '');
        $auth = $user !== '' ? rawurlencode($user) . ':' . rawurlencode($pass) . '@' : '';
        // EMAIL_TLS=false in the harness: do not try STARTTLS against MailHog
        $tls = filter_var($env['EMAIL_TLS'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '' : '?auto_tls=false';

        return new self("smtp://{$auth}{$host}:{$port}{$tls}");
    }

    public function send(MailMessage $message): array
    {
        $email = (new Email())
            ->subject($message->subject)
            ->html($message->html);
        if ($message->text !== '') {
            $email->text($message->text);
        }
        foreach ($message->from as $address => $name) {
            $email->addFrom(new Address((string)$address, $name));
        }
        foreach ($message->to as $address => $name) {
            $email->addTo(new Address((string)$address, $name));
        }
        foreach ($message->cc as $address => $name) {
            $email->addCc(new Address((string)$address, $name));
        }
        foreach ($message->bcc as $address => $name) {
            $email->addBcc(new Address((string)$address, $name));
        }
        foreach ($message->replyTo as $address => $name) {
            $email->addReplyTo(new Address((string)$address, $name));
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new MailException('SMTP error: ' . $e->getMessage(), 0, $e);
        }

        return [];
    }
}
