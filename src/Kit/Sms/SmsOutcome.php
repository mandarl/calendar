<?php

declare(strict_types=1);

namespace Dms\Kit\Sms;

/** The old __sendText() returned nothing; the outcome is exposed for tests and logging only. */
enum SmsOutcome
{
    case Sent;
    case SkippedInvalidNumber;
    case Failed;
    case NotConfigured;
}
