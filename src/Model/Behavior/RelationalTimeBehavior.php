<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Behavior;
use DateTimeInterface;

/**
 * Computes `booking_start`, `booking_end` and `attendee_cancellation` from the offsets (minutes/days) the form posts,
 * relative to `event_start` / `event_end`, and turns stored dates back into offsets for the edit form.
 *
 * Cake 5 notes: DateTime is immutable, so the result of every add/sub is assigned back (Cake 3 mutated `$source`).
 * Chronos 1 cast the offset with `(int)` and built the modifier string itself (`"$n day"` / `"-$n day"`); the
 * modifier strings are rebuilt here the same way, because Chronos 3 types its arguments (`int`) and a posted
 * string such as "30" would be a TypeError under strict_types.
 */
class RelationalTimeBehavior extends Behavior
{
    protected array $_defaultConfig = [
        'fields' => [
            'attendee_cancellation' => [
                'operation' => 'subtract',
                'measure' => 'days',
                'source' => 'event_start',
            ],
            'booking_start' => [
                'operation' => 'subtract',
                'measure' => 'minutes',
                'source' => 'event_start',
            ],
            'booking_end' => [
                'operation' => 'add',
                'measure' => 'minutes',
                'source' => 'event_end',
            ],
        ],
        'format' => 'Y-m-d H:i:s',
        'timezone' => 'UTC',
    ];

    /**
     * Rewrite the posted offsets as absolute (UTC, `Y-m-d H:i:s`) timestamps.
     *
     * @param \ArrayObject $data Posted data, modified in place.
     * @return void
     */
    public function convertFromOffset(ArrayObject $data): void
    {
        $config = $this->getConfig();
        $operations = ['add' => 'add', 'subtract' => 'sub'];
        $measures = ['Days' => 'day', 'Minutes' => 'minute'];
        foreach ($config['fields'] as $field => $options) {
            if (isset($data[$options['source']])) {
                $source = DateTime::createFromFormat(
                    $config['format'],
                    $data[$options['source']],
                    $config['timezone']
                );

                $measure = ucfirst($options['measure']);
                if (array_key_exists($options['operation'], $operations) && isset($measures[$measure])) {
                    // Chronos 1: addDays($v) => modify("$v day"), subDays($v) => modify("-$v day"), $v = (int)$v.
                    $value = (int)($data[$field] ?? 0);
                    $sign = $operations[$options['operation']] === 'sub' ? '-' : '';
                    $source = $source->modify($sign . $value . ' ' . $measures[$measure]);
                }

                $data[$field] = $source->format('Y-m-d H:i:s');
            }
        }
    }

    /**
     * Turn a stored date back into the offset (minutes, or days for attendee_cancellation) from its relation.
     *
     * Quirks kept from Cake 3: minutes are `h * 60 + i` of the interval, so an offset of a day or more wraps;
     * the sign is ignored.
     *
     * @param \DateTimeInterface|string|null $date The stored date.
     * @param \DateTimeInterface|string|null $relation The date it is relative to.
     * @param string $field Name of the field being converted.
     * @return int|false
     */
    public function convertToOffset(DateTimeInterface|string|null $date, DateTimeInterface|string|null $relation, string $field): int|false
    {
        $config = $this->getConfig();
        $measure = ($field == 'attendee_cancellation' ? 'Days' : 'Minutes');

        if (isset($date) && isset($relation)) {
            $date = FriendlyTimeBehavior::toMinutePrecision($date, $config['timezone']);
            $relation = FriendlyTimeBehavior::toMinutePrecision($relation, $config['timezone']);

            $interval = $relation->diff($date);

            if ($measure == 'Minutes') {
                return $interval->h * 60 + $interval->i;
            }

            return (int)$interval->days;
        }

        return false;
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
        $this->convertFromOffset($data);
    }
}
