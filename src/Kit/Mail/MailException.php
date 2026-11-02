<?php

declare(strict_types=1);

namespace Dms\Kit\Mail;

use RuntimeException;

/**
 * The old plugin threw Cake's BadRequestException('SparkPost error (%d): %s'). EmailComponent::sendEmail() catches Throwable
 * and only logs it, so a mail failure never breaks the request; keep that contract when wiring this in.
 */
final class MailException extends RuntimeException
{
}
