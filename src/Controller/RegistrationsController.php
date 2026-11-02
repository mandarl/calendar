<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Table\EventsTable;
use App\Model\Table\RegistrationsTable;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\Utility\Security;
use Dms\Kit\Payments\BraintreeConfig;
use Dms\Kit\Payments\BraintreePayments;
use Dms\Kit\Payments\SaleRequest;

/**
 * Registrations Controller
 *
 * Cake 3 -> Cake 5 port (slice R). Behaviour is the Cake 3 behaviour, including its bugs (see the notes marked
 * CAKE5-PORT and calendar-cake5-r-CHANGES.md). Braintree goes through Dms\Kit\Payments\BraintreePayments.
 *
 * @property RegistrationsTable $Registrations
 * @property EventsTable $Events
 */
class RegistrationsController extends AppController
{
    /** @var RegistrationsTable */
    protected $Registrations;

    /** @var EventsTable */
    protected $Events;

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        // Cake 3: $this->Auth->allow(['cancel', 'event', 'view'])
        $this->Authentication->allowUnauthenticated(['cancel', 'event', 'view']);

        $this->Crud->mapAction('event', 'Crud.Add');
        $this->Crud->mapAction('accept', 'Crud.Edit');
        $this->Crud->mapAction('cancel', 'Crud.Edit');
        $this->Crud->mapAction('reject', 'Crud.Edit');

        $this->Crud->disable(['Add', 'Edit', 'Index', 'Delete']);
    }

    public function initialize(): void
    {
        parent::initialize();

        // Cake 3 auto-loaded $this->Registrations; Cake 5 needs an explicit table.
        $this->Registrations = $this->fetchTable('Registrations');
        // Email is loaded in AppController::initialize() (slice M hunk, merged);
        // no controller-local load here, so Email loads exactly once per path.
    }

    public function event($eventId = null)
    {
        if ($this->currentUser() && $this->Registrations->exists([
                'event_id' => $eventId,
                'ad_username' => $this->currentUsername()
            ])) {
            $registration = $this->Registrations->find('all')
                ->select(['id'])
                ->where([
                    'event_id' => $eventId,
                    'ad_username' => $this->currentUsername()
                ])
                ->first();

            return $this->redirect(['action' => 'view', $registration->id]);
        }

        $this->Events = $this->fetchTable('Events');
        if (!$this->Events->exists([
            'id' => $eventId,
            'status' => 'approved',
            'part_of_id IS NULL',
        ])) {
            return $this->redirect($this->referer());
        }

        $event = $this->Events->get($eventId);
        $now = new DateTime();
        // Cake 5 DateTime is immutable: assign the result back (gotcha 4)
        $cutoff = (new DateTime($event->attendee_cancellation))->addMinutes((int)$event->extend_registration);

        if ($now > $cutoff) {
            return $this->redirect($this->referer());
        }

        $this->Crud->on('beforeRender', function (EventInterface $event) use ($eventId) {
            $eventInfo = $this->Events->get($eventId, contain: ['RequiresPrerequisites']);
            $this->set('event', $eventInfo);
            $this->set(
                'continuedDates',
                $this->Events->find('all')
                    ->select(['class_number', 'event_start', 'event_end'])
                    ->where(['part_of_id' => $eventId])
                    ->orderBy('class_number ASC')
                    ->toArray()
            );
            $this->set('authUser', $this->currentUser());

            if (!empty($eventInfo->requires_prerequisite)) {
                $this->set('meetsPreq', $this->currentUserInGroup($eventInfo->requires_prerequisite->ad_group, /* $forceRefreshGroups= */ true));
            }

            $paidSpacesAvailable = $this->Events->hasPaidSpaces($eventId);

            if ($paidSpacesAvailable) {
                $this->set('clientToken', $this->braintree()->generateClientToken());
            }

            $this->set('hasFreeSpaces', $this->Events->hasFreeSpaces($eventId));
            $this->set('hasPaidSpaces', $paidSpacesAvailable);
            $this->set('editKey', bin2hex(Security::randomBytes(16)));
        });

        $this->Crud->on('beforeSave', function (EventInterface $event) {
            $this->Events = $this->fetchTable('Events');
            $registration = $event->getSubject()->entity;
            if ($registration->getErrors()) {
                $errors = $registration->getErrors();
                // Concatenate all error messages into a single string
                $errMessage = '';
                foreach ($errors as $error) {
                    $errMessage .= implode(' \n', $error);
                }
                $this->Flash->error($errMessage);
                $event->stopPropagation();
            } elseif ($registration->type == 'paid') {
                if (!$this->Events->hasPaidSpaces($registration->event_id)) {
                    $this->Flash->error('This event no longer has any paid spaces available. You have not been charged.');
                    $registration->setError('type', ['This event no longer has any paid spaces available.']);
                    $event->stopPropagation();
                } else {
                    $eventData = $this->Events->get($registration->event_id);

                    // CAKE5-PORT: cost is a float in both builds (EventsTable maps the
                    // DECIMAL column to float); the W4 Cake 3 gateway golden sends "5",
                    // so pass the float through unchanged (a "5.00" string changed the
                    // gateway log and failed the golden).
                    $result = $this->braintree()->sale(SaleRequest::forRegistration(
                        (float)$eventData->cost,
                        (string)$registration->payment_method_nonce,
                        $registration->email,
                        $registration->phone,
                        (string)$registration->name,
                        $registration->event_id,
                        (string)$eventData->name
                    ));

                    if ($result->success) {
                        $registration->transaction_id = $result->transactionId;
                    } else {
                        $this->Flash->error($result->message);
                        $registration->setError('transaction_id', [$result->message]);
                        //$event->stopPropagation();
                    }
                }
            } else {
                if (!$this->Events->hasFreeSpaces($registration->event_id)) {
                    $this->Flash->error('This event no longer has any free spaces available.');
                    // KNOWN BUG, PRESERVED (Cake 3 RegistrationsController.php:160): `$$registration` turns the entity into a
                    // variable-variable name. PHP 8 throws "Object of class ... could not be converted to string", so the
                    // request ends in the 500 error page after the flash above was queued. Do not "fix" without owner sign-off.
                    $$registration->setError('type', ['This event no longer has any free spaces available.']);
                    $event->stopPropagation();
                }
            }
        });

        $this->Crud->on('afterSave', function (EventInterface $event) {
            $this->Events = $this->fetchTable('Events');
            /** @var \App\Model\Entity\Event $eventReference */
            $eventReference = $this->Events->get(
                $event->getSubject()->entity->event_id,
                contain: ['Contacts']
            );
            $registration = $event->getSubject()->entity;

            if ($eventReference->attendees_require_approval) {
                $this->Email->sendRegistrationPending(
                    $registration, //Registration
                    $eventReference //Event
                );

                $this->Email->sendRegistrationRequested(
                    $registration, //Registration
                    $eventReference //Event
                );

            } else {
                $this->Email->sendRegistrationConfirmation(
                    $registration, //Registration
                    $eventReference //Event
                );

                if ($eventReference->notifyInstructorRegistrations) {
                    $this->Email->sendRegistrationToInstructor(
                        $registration, //Registration
                        $eventReference //Event
                    );
                }
            }
        });

        $this->Crud->on('beforeRedirect', function (EventInterface $event) {
            $subject = $event->getSubject();
            $subject->url = [
                'action' => 'view',
                $subject->entity->id
            ];

            if (!$subject->entity->ad_username) {
                $subject->url['?'] = ['edit_key' => $subject->entity->edit_key];
            }
        });

        return $this->Crud->execute();
    }

    /**
     * Braintree gateway from the same Configure keys the Cake 3 __configureBraintree() read.
     */
    private function braintree(): BraintreePayments
    {
        return BraintreePayments::fromConfig(new BraintreeConfig(
            (string)Configure::read('Braintree.environment'),
            (string)Configure::read('Braintree.merchantId'),
            (string)Configure::read('Braintree.publicKey'),
            (string)Configure::read('Braintree.privateKey')
        ));
    }

    public function view($id = null)
    {
        // Manual check in method to allow non-members to edit their data via an edit key
        if (!$this->isAuthorized($this->currentUser())) {
            return $this->redirect($this->referer());
        }

        $this->Crud->on('beforeFind', function (EventInterface $event) {
            $event->getSubject()->query->contain(['Events']);
        });

        return $this->Crud->execute();
    }

    /**
     * Cake 3 isAuthorized(): the registration owner (edit key or AD username), the event creator for
     * accept/cancel/reject/view, or a Calendar Admin. Sets the `isAdmin` view variable as a side effect.
     *
     * Logged-in users are also checked by App\Policy\RequestPolicy (checkRegistrations); this override is
     * the explicit check used by view() and cancel(), which allow anonymous access with an edit key.
     *
     * @param array<string, mixed>|null $user Identity data (null = anonymous)
     * @return bool
     */
    public function isAuthorized($user = null)
    {
        $regId = (int)$this->request->getParam('pass')[0];

        if (in_array($this->request->getParam('action'), ['accept', 'cancel', 'reject', 'view'])) {
            if (isset($user['samaccountname'])) {
                $registration = $this->Registrations->get($regId, contain: ['Events']);

                if ($user['samaccountname'] == $registration->event->created_by) {
                    return true;
                }
            }
        }

        $this->set('isAdmin', $this->inAdminstrativeGroup($user, 'Calendar Admins'));

        return $this->Registrations->isOwnedBy($regId, [
                'ad_username' => (isset($user['samaccountname']) ? $user['samaccountname'] : null),
                'edit_key' => $this->request->getQuery('edit_key'),
            ]) || $this->inAdminstrativeGroup($user, 'Calendar Admins');
    }

    public function cancel($id = null)
    {
        // Manual check in method to allow non-members to edit their data via an edit key
        if (!$this->isAuthorized($this->currentUser())) {
            return $this->redirect($this->referer());
        }

        $this->Crud->on('beforeFind', function (EventInterface $event) {
            $event->getSubject()->query->contain(['Events']);
        });

        $this->Crud->on('beforeSave', function (EventInterface $event) use ($id) {
            $now = new DateTime();
            if ($now > $event->getSubject()->entity->event->attendee_cancellation && !$this->inAdminstrativeGroup($this->currentUser(), 'Calendar Admins')) {
                $this->Flash->error('Your RSVP to this event could not be cancelled. The cutoff time for cancellations has already passed.');
                $event->stopPropagation();
            } else {
                $event->getSubject()->entity->status = 'cancelled';
                $this->Flash->success('Your RSVP to this event has been cancelled.');
                $this->Registrations->refund($id);
            }
        });

        $this->Crud->on('afterSave', function (EventInterface $event) {
            $this->Events = $this->fetchTable('Events');
            /** @var \App\Model\Entity\Event $eventReference */
            $eventReference = $this->Events->get(
                $event->getSubject()->entity->event_id,
                contain: ['Contacts']
            );
            $registration = $event->getSubject()->entity;

            $this->Email->sendRegistrationCancelled($registration, $eventReference);

            if ($eventReference->notifyInstructorCancellations) {
                $this->Email->sendCancellationToInstructor(
                    $registration, //Registration
                    $eventReference //Event
                );
            }
        });

        $this->Crud->on('beforeRedirect', function (EventInterface $event) {
            $subject = $event->getSubject();
            $subject->url = [
                'action' => 'view',
                $subject->entity->id
            ];

            if (!$subject->entity->ad_username) {
                $subject->url['?'] = ['edit_key' => $subject->entity->edit_key];
            }
        });

        return $this->Crud->execute();
    }

    public function accept($id = null)
    {
        $this->request->allowMethod(['POST']);

        $this->Crud->on('beforeFind', function (EventInterface $event) {
            $event->getSubject()->query->contain(['Events']);
        });

        $this->Crud->on('beforeSave', function (EventInterface $event) {
            $now = new DateTime();
            $cutoff = (new DateTime($event->getSubject()->entity->event->attendee_cancellation))
                ->addMinutes((int)$event->getSubject()->entity->event->extend_registration);
            if ($now > $cutoff) {
                $this->Flash->error('RSVPs for this event can no longer be approved. The cutoff time for cancellations has already passed.');
                $event->stopPropagation();
            } else {
                $event->getSubject()->entity->status = 'confirmed';
            }
        });

        $this->Crud->on('afterSave', function (EventInterface $event) {
            $this->Events = $this->fetchTable('Events');
            $eventReference = $this->Events->get($event->getSubject()->entity->event_id);
            $registration = $event->getSubject()->entity;
            $this->Email->sendRegistrationApproved($registration, $eventReference);
        });

        $this->Crud->on('beforeRedirect', function (EventInterface $event) {
            $event->getSubject()->url = $this->referer();
        });

        return $this->Crud->execute();
    }

    public function reject($id = null)
    {
        $this->request->allowMethod(['POST']);

        $this->Crud->on('beforeFind', function (EventInterface $event) {
            $event->getSubject()->query->contain(['Events']);
        });

        $this->Crud->on('beforeSave', function (EventInterface $event) use ($id) {
            $now = new DateTime();
            if ($now > $event->getSubject()->entity->event->attendee_cancellation) {
                $this->Flash->error('RSVPs for this event can no longer be rejected. The cutoff time for cancellations has already passed.');
                $event->stopPropagation();
            } else {
                $event->getSubject()->entity->status = 'rejected';
                $this->Registrations->refund($id);
            }
        });

        $this->Crud->on('afterSave', function (EventInterface $event) {
            $this->Events = $this->fetchTable('Events');
            $eventReference = $this->Events->get($event->getSubject()->entity->event_id);
            $registration = $event->getSubject()->entity;
            $this->Email->sendRegistrationRejected($registration, $eventReference);
        });

        $this->Crud->on('beforeRedirect', function (EventInterface $event) {
            $event->getSubject()->url = $this->referer();
        });

        return $this->Crud->execute();
    }
}
