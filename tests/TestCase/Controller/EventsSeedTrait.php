<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;

/**
 * Seeding helpers for the AHL controller tests (events, honoraria, registrations, files).
 * Every row is synthetic (example.test addresses, "Test ..." names) and lives inside the
 * test transaction, so tearDown() rolls it back.
 *
 * Note on the array union below: `$over + $defaults` (overrides first) is deliberate.
 * PHP's `+` keeps the LEFT operand's keys, so `$defaults + $over` silently dropped every
 * override that collided with a default (name, status, user, dates) and the tests then
 * asserted on rows that were never seeded that way.
 */
trait EventsSeedTrait
{
    protected function seedContact(array $over = [])
    {
        return $this->make('Contacts', $over + [
            'name' => 'Test Contact',
            'ad_username' => 'test-contact',
            'email' => 'test-contact@example.test',
            'phone' => '555-0100',
            'w9_on_file' => 0,
        ]);
    }

    protected function seedRoom(array $over = [])
    {
        return $this->make('Rooms', $over + [
            'name' => 'Test Room',
            'exclusive' => 1,
        ]);
    }

    protected function seedCommittee(array $over = [])
    {
        return $this->make('Committees', $over + [
            'name' => 'Test Committee',
        ]);
    }

    /**
     * A minimal approved event. Pass event_start/event_end/status/part_of_id/room_id/contact_id
     * to override. Times are UTC strings as stored in the database; they are converted to the
     * `m/d/Y g:i A` America/Chicago strings the FriendlyTimeBehavior parses on marshal, so the
     * stored value is exactly the UTC time given (the behaviour converts it straight back).
     */
    protected function seedEvent(array $over = [])
    {
        $contact = $over['contact'] ?? $this->seedContact();
        unset($over['contact']);
        $room = $over['room'] ?? $this->seedRoom();
        unset($over['room']);

        $data = $over + [
            'name' => 'Seeded Event',
            'short_description' => 'Seeded short description',
            'long_description' => 'Seeded long description',
            'event_start' => '2031-01-15 16:00:00',
            'event_end' => '2031-01-15 18:00:00',
            // RelationalTimeBehavior's contract: these are integer offsets from the
            // event start/end (days for attendee_cancellation, minutes for the booking
            // window), not datetimes. 0 keeps them at the event start/end; no AHL test
            // asserts these fields, and datetime strings throw in Chronos (subDays(string)).
            'booking_start' => 0,
            'booking_end' => 0,
            'attendee_cancellation' => 0,
            'cost' => 0,
            'free_spaces' => 10,
            'paid_spaces' => 0,
            'status' => 'approved',
            'room_id' => $room->id,
            'contact_id' => $contact->id,
            'created_by' => 'test-user',
        ];
        foreach (['event_start', 'event_end'] as $field) {
            if (is_string($data[$field]) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data[$field])) {
                $utc = new \DateTimeImmutable($data[$field], new \DateTimeZone('UTC'));
                $data[$field] = $utc->setTimezone(new \DateTimeZone('America/Chicago'))->format('m/d/Y g:i A');
            }
        }

        return $this->make('Events', $data);
    }

    protected function seedHonorarium($event, $committee, array $over = [])
    {
        return $this->make('Honoraria', $over + [
            'event_id' => $event->id,
            'committee_id' => $committee->id,
            'pay_contact' => 1,
            'paid' => 0,
        ]);
    }

    protected function seedRegistration($event, array $over = [])
    {
        return $this->make('Registrations', $over + [
            'event_id' => $event->id,
            'type' => 'free',
            'name' => 'Test Attendee',
            'email' => 'test-attendee@example.test',
            'edit_key' => 'test-edit-key',
            'status' => 'confirmed',
            'attended' => 0,
        ]);
    }

    protected function linkCategory(int $eventId, int $categoryId): void
    {
        ConnectionManager::get('default')->execute(
            'INSERT INTO categories_events (event_id, category_id) VALUES (:event_id, :category_id)',
            ['event_id' => $eventId, 'category_id' => $categoryId]
        );
    }
}
