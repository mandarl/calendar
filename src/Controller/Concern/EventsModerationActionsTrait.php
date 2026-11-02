<?php
declare(strict_types=1);

namespace App\Controller\Concern;

use App\Auth\KitFactory;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Dms\Kit\Directory\DirectoryException;
use Dms\Kit\Directory\GroupNotFound;

/**
 * EventsController actions: ModerationActions.
 *
 * approve, reject, processRejection, cancel, attendance, assignments. Ported from the Cake 3 EventsController
 * (approve/reject/processRejection/cancel/attendance/assignments, plus the private sendCancellationText helper) and checked
 * against it by the differential tests in tests/differential/events-moderation. Behaviour list: see
 * calendar-cake5-d-CHANGES.md (section "Behaviours"). Authorization is App\Policy\RequestPolicy (checkEvents).
 */
trait EventsModerationActionsTrait
{
    public function attendance($id = null)
    {
        $this->Crud->on(
            'beforeFind',
            function (EventInterface $event) {
                $event->getSubject()->query->contain(['Registrations']);
            }
        );

        $this->Crud->on(
            'beforeRedirect',
            function (EventInterface $event) {
                $event->getSubject()->url = $this->referer();
            }
        );

        return $this->Crud->execute();
    }

    public function assignments($id = null)
    {
        $this->Crud->on(
            'beforeFind',
            function (EventInterface $event) {
                $event->getSubject()->query->contain(['FulfillsPrerequisites', 'Registrations']);
            }
        );

        $this->Crud->on(
            'beforeRedirect',
            function (EventInterface $event) {
                $event->getSubject()->url = $this->referer();
            }
        );

        // Cake 3 did the LDAP work in beforeSave and let any exception escape (HTTP 500). Crud 7 cannot abort a save
        // from a listener, so the directory failures are caught around Crud->execute(): the user
        // gets an error flash and is sent back to the referer (deliberate deviation, see CHANGES; note: registrations attached before a failure stay attached, so partial application is possible).
        try {
            $this->Crud->on(
                'beforeSave',
                function (EventInterface $event) {
                    $entity = $event->getSubject()->entity;
                    $directory = KitFactory::directory();
                    $directory->connect();

                    foreach ($entity->registrations as $registration) {
                        if ($registration->ad_assigned && $registration->ad_username) {
                            $prerequisite = $entity->fulfills_prerequisite ?? null;
                            if ($prerequisite === null || empty($prerequisite->ad_group)) {
                                throw new GroupNotFound('Fulfilled prerequisite AD group missing for assignment');
                            }
                            $directory->addUserToGroup(
                                $prerequisite->ad_group,
                                $registration->ad_username
                            );
                        }
                    }
                }
            );

            return $this->Crud->execute();
        } catch (GroupNotFound | DirectoryException $e) {
            Log::error('AD group assignment failed for event ' . $id . ': ' . $e->getMessage());
            $this->Flash->error(__('Could not update {0}', 'Event'));

            return $this->redirect($this->referer());
        }
    }

    public function approve($id = null)
    {
        $this->Crud->on(
            'beforeSave',
            function (EventInterface $event) {
                $event->getSubject()->entity->status = 'approved';
            }
        );

        // Approve multi-part dates
        $this->Crud->on(
            'afterSave',
            function (EventInterface $event) {
                $events = $this->fetchTable('Events');
                $events->updateQuery()
                    ->set(['status' => 'approved'])
                    ->where(['part_of_id' => $event->getSubject()->entity->id])
                    ->execute();

                $contact = $events->Contacts
                    ->find()
                    ->where(['ad_username' => $event->getSubject()->entity->created_by])
                    ->first();

                // Send an email to the submitter with some information
                $this->Email->sendEventApproved(
                    $contact,
                    $event->getSubject()->entity, //Event
                );

                // Notify subscribed external systems (e.g. member-portal) that
                // this event is now publicly visible.
                $this->Webhook->dispatchEventPublished($event->getSubject()->entity);
            }
        );

        return $this->Crud->execute();
    }

    public function processRejection($id = null)
    {
        return $this->Crud->execute();
    }

    public function reject($id = null)
    {
        $this->Crud->on(
            'beforeSave',
            function (EventInterface $event) {
                $event->getSubject()->entity->status = 'rejected';
                $event->getSubject()->entity->rejection_reason = $this->request->getData('event.rejection_reason');
                $event->getSubject()->entity->rejected_by = $this->currentUsername();
            }
        );

        // Reject multi-part dates
        $this->Crud->on(
            'afterSave',
            function (EventInterface $event) {
                $events = $this->fetchTable('Events');
                $events->updateQuery()
                    ->set(['status' => 'rejected'])
                    ->where(['part_of_id' => $event->getSubject()->entity->id])
                    ->execute();

                // Get contact info by created_by id
                $contact = $events->Contacts
                    ->find()
                    ->where(['ad_username' => $event->getSubject()->entity->created_by])
                    ->first();

                $this->Email->sendEventRejected(
                    $contact,
                    $event->getSubject()->entity, //Event
                );
            }
        );

        // TODO: Dispatch rejection message to person who submitted event

        return $this->Crud->execute();
    }

    public function cancel($id = null)
    {
        $this->Crud->on(
            'beforeSave',
            function (EventInterface $event) {
                $event->getSubject()->entity->status = 'cancelled';
            }
        );

        // Cancel multi-part dates
        $this->Crud->on(
            'afterSave',
            function (EventInterface $event) {
                $events = $this->fetchTable('Events');
                $registrationsTable = $this->fetchTable('Registrations');
                $registrations = $registrationsTable->find('all')
                    ->where(['event_id' => $this->request->getParam('pass')[0], 'status IN' => ['confirmed', 'pending']])
                    ->all();

                $eventRef = $event->getSubject()->entity;

                foreach ($registrations as $registration) {
                    $this->Email->sendEventCancelled($registration, $eventRef);

                    if ($registration->phone && $registration->send_text) {
                        $this->sendCancellationText($registration->phone, 'DMS Event Update: ' . $eventRef->name . ' has been cancelled.');
                    }

                    // Unified on RegistrationsTable::refund() (slice R kit port)
                    // at merge; result ignored, as Cake 3 cancel() ignored it.
                    $registrationsTable->refund($registration->id);
                    $registration->status = 'cancelled';
                    $registrationsTable->save($registration);
                }

                $events->updateQuery()
                    ->set(['status' => 'cancelled'])
                    ->where(['part_of_id' => $eventRef->id])
                    ->execute();
            }
        );

        return $this->Crud->execute();
    }

    /**
     * Cake 3 __sendText(): the number is normalised to E.164 (invalid numbers are skipped silently); Twilio errors are
     * logged, never raised. TwilioSmsSender does the same (NotConfigured when TWILIO_* are empty).
     */
    private function sendCancellationText(?string $to, string $message): void
    {
        $sender = KitFactory::smsSender();
        $sender->sendText($to, $message);
    }
}
