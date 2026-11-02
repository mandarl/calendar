<?php

declare(strict_types=1);

namespace Dms\Kit\Mail;

/**
 * Framework-neutral outgoing message. The app today uses (src/Controller/Component/EmailComponent.php L60-L73): one From,
 * one To, a subject and an HTML body (setEmailFormat('html')). Cc, Bcc, Reply-To and custom headers are supported for the Cake 5
 * transport but nothing in the app sets them.
 */
final class MailMessage
{
    /**
     * @param array<string,string> $from    [address => display name]
     * @param array<string,string> $to      [address => display name]
     * @param string               $subject plain UTF-8 (NOT RFC 2047 encoded)
     * @param array<string,string> $replyTo [address => display name]
     * @param array<string,string> $cc      [address => display name]
     * @param array<string,string> $bcc     [address => display name]
     * @param array<string,string> $headers custom headers only (no From/To/Subject/Date/MIME headers)
     */
    public function __construct(
        public readonly array $from,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text = '',
        public readonly array $replyTo = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $headers = [],
    ) {
    }
}
