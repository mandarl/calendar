<?php
declare(strict_types=1);

/** In-memory Cake 5 log engine for the differential tests. */
namespace DiffTest;

use Cake\Log\Engine\BaseLog;

final class CaptureLog extends BaseLog
{
    public static array $lines = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        self::$lines[] = $level . ': ' . $message;
    }
}
