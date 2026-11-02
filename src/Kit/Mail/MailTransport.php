<?php

declare(strict_types=1);

namespace Dms\Kit\Mail;

interface MailTransport
{
    /**
     * @return array<string,mixed> provider metadata (SparkPost: id, accepted, rejected); empty for SMTP
     * @throws MailException when the provider refuses the message or cannot be reached
     */
    public function send(MailMessage $message): array;
}
