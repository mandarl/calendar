<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\Chronos\ChronosDate;
use Cake\View\Helper\TimeHelper as CoreTimeHelper;
use DateTimeInterface;
use DateTimeZone;

/**
 * Keeps the Cake 3 call style the templates use: `format($date, $format, null, $timezone)`. Cake 5 wants `false`
 * (not null) for the "text to show when the date is invalid" argument.
 */
class TimeHelper extends CoreTimeHelper
{
    /**
     * @inheritDoc
     */
    public function format(
        ChronosDate|DateTimeInterface|string|int|null $date,
        array|string|int|null $format = null,
        string|false|null $invalid = false,
        DateTimeZone|string|null $timezone = null,
    ): string|int|false {
        return parent::format($date, $format, $invalid ?? false, $timezone);
    }
}
