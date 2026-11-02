<?php

declare(strict_types=1);

namespace Dms\Kit\Cake;

use Cake\Mailer\Message;
use Dms\Kit\Mail\MailMessage;

/**
 * Cake\Mailer\Message (CakePHP 5) -> framework-neutral MailMessage.
 *
 *  Cake 3 plugin call                      Cake 5 Message getter used here
 *  $email->from()                          getFrom()                [address => name]
 *  $email->to()                            getTo()                  (Cake uses the address as the name when none was given)
 *  (not used)                              getCc(), getBcc(), getReplyTo()
 *  mb_decode_mimeheader($email->subject()) getOriginalSubject()     (getSubject() returns the RFC 2047 encoded form)
 *  $email->message('html'|'text')          getBodyHtml(), getBodyText()   (valid once Mailer::deliver()/send() rendered the body)
 *
 * Custom headers are whatever remains in Message::getHeaders() after removing the headers Cake derives itself.
 */
final class MessageMapper
{
    /** Headers Cake generates or SparkPost owns; everything else counts as a custom header. Compared case-insensitively. */
    private const DERIVED_HEADERS = [
        'from', 'sender', 'reply-to', 'return-path', 'to', 'cc', 'bcc', 'subject', 'date', 'message-id', 'mime-version',
        'content-type', 'content-transfer-encoding', 'x-mailer', 'disposition-notification-to',
    ];

    public static function toMailMessage(Message $message): MailMessage
    {
        $custom = [];
        foreach ($message->getHeaders() as $name => $value) {
            if (!in_array(strtolower((string)$name), self::DERIVED_HEADERS, true) && $value !== '') {
                $custom[(string)$name] = (string)$value;
            }
        }

        return new MailMessage(
            $message->getFrom(),
            $message->getTo(),
            $message->getOriginalSubject(),
            $message->getBodyHtml(),
            $message->getBodyText(),
            $message->getReplyTo(),
            $message->getCc(),
            $message->getBcc(),
            $custom,
        );
    }
}
