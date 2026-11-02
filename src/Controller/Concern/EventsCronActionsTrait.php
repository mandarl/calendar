<?php

declare(strict_types=1);

namespace App\Controller\Concern;

use App\Auth\KitFactory;
use Cake\I18n\DateTime;

/**
 * EventsController actions: cron() + __sendText.
 *
 * Cake 5 port of Cake 3 EventsController::cron() and __sendText(). Behaviour
 * is preserved branch for branch, in the old order:
 *  (1) complete ended approved events (pending registrations cancelled with
 *      refund + mail); (2) auto-approve pending events (no honorarium:
 *      Configurations id 1 days; with honorarium: id 2) + event.published
 *      webhook; (3) cancellation reminders (cancel_notification);
 *      (4) start reminders (reminder_notification).
 *
 * Cake 5 notes:
 *  - All time arithmetic is immutable: DateTime::now()->modify(...) returns
 *    the shifted instance and is assigned back (Cake 3 Time mutated in
 *    place). Comparisons against the DB columns are in UTC, as before
 *    (the datasource writes DateTime values as UTC).
 *  - The two SMS call sites in the reminder loops stay commented out,
 *    exactly as in Cake 3: cron() never texts. __sendText() itself is
 *    ported (kit TwilioSmsSender) for the cancel path's use and for tests.
 *  - $this->autoRender = false becomes disableAutoRender() + returning the
 *    (empty) response, as in the ported feed actions.
 */
trait EventsCronActionsTrait
{
    public function cron()
    {
        $registrations = $this->fetchTable('Registrations');
        $configurations = $this->fetchTable('Configurations');

        // Change event status to completed
        $now = DateTime::now();
        $completedEvents = $this->Events->find('all')
            ->where(['status' => 'approved', 'event_end <' => $now]);

        foreach ($completedEvents as $event) {
            if (!$event->part_of_id) {
                $pendingRegistrations = $registrations
                    ->find('all')
                    ->where([
                        'event_id' => $event->id,
                        'status' => 'pending',
                    ]);

                foreach ($pendingRegistrations as $registration) {
                    $this->Email->sendUnapprovedRegistrationCancelled($registration, $event);

                    $registrations->refund($registration->id);
                    $registration->status = 'cancelled';
                    $registrations->save($registration);
                }

                $event->status = 'completed';
                $this->Events->save($event);
            }
        }

        // Auto-approve Events
        $approvalTime = $configurations->get(1);
        $approvedTime = DateTime::now()->modify('-' . $approvalTime->value . ' days');
        $autoApprovedEvents = $this->Events->find('all')
            ->where(
                [
                'Events.status' => 'pending',
                'Events.created <' => $approvedTime,
                'Honoraria.id IS NULL',
                ]
            )
            ->contain(['Honoraria']);

        foreach ($autoApprovedEvents as $event) {
            $event->status = 'approved';
            $this->Events->save($event);
            $this->Webhook->dispatchEventPublished($event);
        }

        // Auto-approve Honoraria
        $honorariaTime = $configurations->get(2);
        $honorariadTime = DateTime::now()->modify('-' . $honorariaTime->value . ' days');
        $autoApprovedHonoraria = $this->Events->find('all')
            ->where(
                [
                'Events.status' => 'pending',
                'Events.created <' => $honorariadTime,
                'Honoraria.id IS NOT NULL',
                ]
            )
            ->contain(['Honoraria']);

        foreach ($autoApprovedHonoraria as $event) {
            $event->status = 'approved';
            $this->Events->save($event);
            $this->Webhook->dispatchEventPublished($event);
        }

        // Notify attendees that they can cancel for another 24 hours
        $tomorrow = DateTime::now()->modify('+1 days');
        $cancelNotices = $this->Events->find('all')
            ->where(
                [
                'Events.status' => 'approved',
                'Events.attendee_cancellation <' => $tomorrow,
                'Events.part_of_id IS NULL',
                'Events.cancel_notification' => 0,
                ]
            );

        foreach ($cancelNotices as $event) {
            $registrationsForEvent = $registrations
                ->find('all')
                ->where([
                        'event_id' => $event->id,
                        'status IN' => ['confirmed', 'pending'],
                ]);

            foreach ($registrationsForEvent as $registration) {
                $this->Email->sendCancellationReminder($registration, $event);

                if ($registration->phone && $registration->send_text) {
                    // $this->__sendText($registration->phone, 'DMS Event Reminder: ' . $event->name . ' cancellation deadline is soon.');
                }
            }

            $event->cancel_notification = 1;
            $this->Events->save($event);
        }

        // Notify attendees that they have an event in 24 hours
        $startNotices = $this->Events->find('all')
            ->where(
                [
                'Events.status' => 'approved',
                'Events.event_start <' => $tomorrow,
                'Events.reminder_notification' => 0,
                ]
            );

        foreach ($startNotices as $event) {
            $time = new DateTime($event->event_start);
            $formattedTime = $time->i18nFormat('EEEE MMMM d, h:mma', 'America/Chicago');

            $registrationsForEvent = $registrations
                ->find('all')
                ->where(['event_id' => $event->id, 'status IN' => ['confirmed']]);

            foreach ($registrationsForEvent as $registration) {
                $this->Email->sendEventStarting($registration, $event);

                if ($registration->phone && $registration->send_text) {
                    //$this->__sendText($registration->phone, 'DMS Event Reminder: ' . $event->name . ' starts ' . $formattedTime . '.');
                }
            }

            $event->reminder_notification = 1;
            $this->Events->save($event);
        }

        $this->disableAutoRender();

        return $this->response;
    }

    /**
     * Cake 3 __sendText(): the number is stripped to digits, a leading "1"
     * is prepended when missing, and only an 11-digit result is sent;
     * anything else is silently skipped. API errors were logged, never
     * raised. The kit sender does the same, and additionally returns
     * NotConfigured (logged, skipped) when TWILIO_* are empty instead of
     * reaching the SDK; it also catches the SDK base TwilioException, so
     * a transport failure is logged instead of aborting the caller's loop
     * (documented kit difference, same as slice D's sendCancellationText).
     */
    private function __sendText(?string $to, string $message): void
    {
        $sender = KitFactory::smsSender();
        $sender->sendText($to, $message);
    }
}
