<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Behavior;
use DateTimeInterface;
use DateTimeZone;

/**
 * FriendlyTimeBehavior Utility class
 *
 * Turns the `m/d/Y g:i A` America/Chicago values the add/edit forms post into the UTC `Y-m-d H:i:s` strings the
 * rest of the app stores, and back again for the edit form.
 *
 * Cake 5 notes: DateTime is immutable (every setTimezone() result is assigned back), and `convertToFormat()` is
 * handed `DateTime` objects by the controllers where Cake 3 handed it `FrozenTime` objects that it parsed back out of
 * their locale string form (`m/d/y, g:i A`, minute precision). Objects are put through the same round-trip here:
 * the wall clock in the object's own timezone is formatted as the locale string and re-parsed as UTC, so a
 * non-UTC object converts exactly as it did in Cake 3 (DB values are UTC, so the live path is unchanged);
 * strings in the old locale form are still accepted.
 */
class FriendlyTimeBehavior extends Behavior
{
    protected array $_defaultConfig = [
        'fields' => ['event_start', 'event_end'],
        'format' => 'm/d/Y g:i A',
        'from_timezone' => 'America/Chicago',
        'to_timezone' => 'UTC',
    ];

    /**
     * Format the Cake 3 locale string form (`DateTime::__toString()`) had: `m/d/y, g:i A`.
     */
    public const LOCALE_SHORT_FORMAT = 'm/d/y, g:i A';

    /**
     * convertFrom Format.
     *
     * @param \ArrayObject $data Data to convert (posted values, modified in place).
     * @return void
     */
    public function convertFromFormat(ArrayObject $data): void
    {
        $config = $this->getConfig();
        foreach ($config['fields'] as $field) {
            if (isset($data[$field])) {
                $dateTime = DateTime::createFromFormat(
                    $config['format'],
                    $data[$field],
                    $config['from_timezone']
                );
                // Immutable: the result must be assigned back (Cake 3 mutated in place).
                $dateTime = $dateTime->setTimezone(new DateTimeZone($config['to_timezone']));
                $data[$field] = $dateTime->format('Y-m-d H:i:s');
            }
        }
    }

    /**
     * convertToFormat: stored (UTC) value -> the friendly America/Chicago `m/d/Y g:i A` string.
     *
     * @param \DateTimeInterface|string $date Date object, or its Cake 3 locale string form (`m/d/y, g:i A`, UTC).
     * @return string
     */
    public function convertToFormat(DateTimeInterface|string $date): string
    {
        $config = $this->getConfig();
        $dateTime = static::toMinutePrecision($date, $config['to_timezone']);
        $dateTime = $dateTime->setTimezone(new DateTimeZone($config['from_timezone']));

        return $dateTime->format($config['format']);
    }

    /**
     * Normalise the value the controllers pass in to a minute-precision DateTime in `$timezone`.
     *
     * Cake 3 went through the locale string (`m/d/y, g:i A`), which has no seconds, so seconds were always dropped;
     * objects go through that same string round-trip (see above). Newer ICU releases (Debian 12 and later) print a
     * narrow no-break space before AM/PM in that string, hence the replacement when a string is parsed.
     *
     * @param \DateTimeInterface|string $date Value to normalise.
     * @param string $timezone Timezone the value is expressed in (strings) / is converted to.
     * @return \Cake\I18n\DateTime
     */
    public static function toMinutePrecision(DateTimeInterface|string $date, string $timezone): DateTime
    {
        if ($date instanceof DateTimeInterface) {
            // Cake 3 round-trip: the object's locale string (wall clock in its own timezone) parsed as $timezone.
            $str = $date->format(static::LOCALE_SHORT_FORMAT);

            return DateTime::createFromFormat(static::LOCALE_SHORT_FORMAT, $str, $timezone);
        }

        $date = str_replace(["\u{202F}", "\u{00A0}"], ' ', $date);

        return DateTime::createFromFormat(static::LOCALE_SHORT_FORMAT, $date, $timezone);
    }

    /**
     * beforeMarshal
     *
     * @param \Cake\Event\EventInterface $event event
     * @param \ArrayObject $data data
     * @param \ArrayObject $options options
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        $this->convertFromFormat($data);
    }
}
