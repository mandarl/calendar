<?php
declare(strict_types=1);

namespace App\Controller\Concern;

use App\Model\Entity\Contact;
use Psr\Http\Message\UploadedFileInterface;

/**
 * EventsController actions: SubmitActions (add, edit, copy and their marshalling helpers).
 *
 * Ported from the Cake 3 EventsController (add/edit/_formContent/_afterCreate/_afterUpdate/_beforeCreate/
 * _beforeUpdate/__constructPostForMarshal/__constructContinuedEventsForCreate|Update/__resetCategorySplits).
 *
 * Cake 5 request objects are immutable: the Cake 3 code that wrote `$this->request->data[...]` (44 places) now
 * works on a local copy of the posted data, which is written back with `setRequest($request->withParsedBody())`
 * before Crud reads it. Uploaded files arrive as UploadedFileInterface objects (not `['name' => ...]` arrays), see
 * `__uploadName()`.
 */
trait EventsSubmitActionsTrait
{
    public function add()
    {
        $calendarConfigurations = $this->fetchTable('CalendarSuperConfigurations');
        $this->set('honorariaMessage', $calendarConfigurations->get(1)->value);

        $configurations = $this->fetchTable('Configurations');
        $config = $configurations->find('list')->toArray();
        $this->set('config', $config);
        // Cake 3 read these from AuthComponent::user(); Cake 5 keeps the identity as an array.
        $this->set('contactError', $this->__authUser('contact_error'));
        $this->set('blacklisted', $this->__authUser('blacklisted'));

        $this->Crud->action()
            ->saveOptions(
                [
                'associated' => ['Categories', 'Contacts', 'Contacts.W9s', 'Files', 'Honoraria', 'Tools']
                ]
            );

        $this->__constructPostForMarshal('add');

        $this->Crud->on('beforeSave', [$this, '_beforeCreate']);
        $this->Crud->on('afterSave', [$this, '_afterCreate']);
        $this->Crud->on('beforeRender', [$this, '_formContent']);
        $this->Crud->on(
            'beforeRender',
            function (\Cake\Event\EventInterface $event) {
                $entity = $event->getSubject()->entity;
                if ($this->request->getQuery('copy') !== null) {
                    if ($this->Events->isOwnedBy($this->request->getQuery('copy'), $this->currentUsername()) || parent::isAuthorized($this->currentUser())) {
                        if (!$this->request->is(['post', 'put'])) {
                            $entity = $this->Events->get(
                                $this->request->getQuery('copy'),
                                [
                                    'contain' => ['Categories', 'Contacts', 'Files', 'FulfillsPrerequisites', 'Honoraria', 'Honoraria.Committees', 'RequiresPrerequisites', 'Tools']
                                ]
                            );
                        } else {
                            $copy = $this->Events->get(
                                $this->request->getQuery('copy'),
                                [
                                    'contain' => ['Files']
                                ]
                            );
                            $entity->files = $copy->files;
                        }

                        $entity->attendee_cancellation = $this->Events->convertToOffset($entity->attendee_cancellation, $entity->event_start, 'attendee_cancellation');
                        $entity->booking_start = $this->Events->convertToOffset($entity->booking_start, $entity->event_start, 'booking_start');
                        $entity->booking_end = $this->Events->convertToOffset($entity->booking_end, $entity->event_end, 'booking_end');

                        $categories = $entity->categories;
                        $entity->categories = [];
                        $entity->optional_categories = [];

                        foreach ($categories as $category) {
                            if ($category->id <= 2) {
                                $entity->categories[] = $category;
                            } else {
                                $entity->optional_categories[] = $category;
                            }
                        }

                        if (!empty($entity->honorarium)) {
                            $entity->request_honorarium = 1;
                        }

                        unset(
                            $entity->eventbrite_link,
                            $entity->event_start,
                            $entity->event_end,
                            $entity->status,
                            $entity->type
                        );
                        // Cake 3 unset these on a possibly-missing contact; guard so a copy without a contact works.
                        if ($entity->contact !== null) {
                            unset(
                                $entity->contact->name,
                                $entity->contact->email,
                                $entity->contact->phone
                            );
                        }
                        $event->getSubject()->entity = $entity;
                    }
                }
            }
        );

        return $this->Crud->execute();
    }

    public function edit($id = null)
    {
        // Redirect to primary event if event is a continuation of a multi-part event
        if ($baseId = $this->Events->get($id)->part_of_id) {
            // Cake 3 did not return here either; the action keeps running (see CHANGES, deviations).
            $this->redirect(['action' => 'edit', $baseId]);
        }

        if (!parent::isAuthorized($this->currentUser())) {
            $this->Events->removeBehavior('FriendlyTime');
            $this->Events->removeBehavior('RelationalTime');
        }

        $configurations = $this->fetchTable('Configurations');
        $config = $configurations->find('list')->toArray();
        $this->set('config', $config);

        $this->Crud->action()
            ->saveOptions(
                [
                'associated' => ['Categories', 'Files', 'Tools']
                ]
            );

        $this->__constructPostForMarshal('edit');

        $this->Crud->on(
            'beforeFind',
            function (\Cake\Event\EventInterface $event) {
                $event->getSubject()->query->contain(['Categories', 'Contacts', 'Files', 'FulfillsPrerequisites', 'Honoraria', 'Honoraria.Committees', 'RequiresPrerequisites', 'Tools']);
            }
        );
        $this->Crud->on('beforeSave', [$this, '_beforeUpdate']);
        $this->Crud->on('afterSave', [$this, '_afterUpdate']);
        $this->Crud->on('beforeRender', [$this, '_formContent']);
        $this->Crud->on(
            'beforeRender',
            function (\Cake\Event\EventInterface $event) {
                $entity = $event->getSubject()->entity;
                $categories = $entity->categories;

                if (parent::isAuthorized($this->currentUser())) {
                    $entity->attendee_cancellation = $this->Events->convertToOffset($entity->attendee_cancellation, $entity->event_start, 'attendee_cancellation');
                    $entity->booking_start = $this->Events->convertToOffset($entity->booking_start, $entity->event_start, 'booking_start');
                    $entity->booking_end = $this->Events->convertToOffset($entity->booking_end, $entity->event_end, 'booking_end');

                    $entity->event_start = $this->Events->convertToFormat($entity->event_start);
                    $entity->event_end = $this->Events->convertToFormat($entity->event_end);
                }

                $entity->categories = [];
                $entity->optional_categories = [];

                foreach ($categories as $category) {
                    if ($category->id <= 2) {
                        $entity->categories[] = $category;
                    } else {
                        $entity->optional_categories[] = $category;
                    }
                }

                $continuedDates = $this->Events->find('all')
                    ->select(['class_number', 'event_start', 'event_end'])
                    ->where(['part_of_id' => $entity->id])
                    ->orderBy('class_number ASC')
                    ->toArray();
                $this->set('continuedDates', $continuedDates);

                $nextDate = 2;
                foreach ($continuedDates as $continuedDate) {
                    $entity['event_start_' . $nextDate] = $this->Events->convertToFormat($continuedDate['event_start']);
                    $entity['event_end_' . $nextDate] = $this->Events->convertToFormat($continuedDate['event_end']);
                    $nextDate++;
                }

                $this->set('unlockedEdits', (parent::isAuthorized($this->currentUser()) ? true : false));
                $event->getSubject()->entity = $entity;
            }
        );

        return $this->Crud->execute();
    }

    public function _formContent(\Cake\Event\EventInterface $event)
    {
        $rooms = $this->Events->Rooms->find('list')->orderBy('name ASC');
        $contacts = $this->Events->Contacts->find('list', keyField: 'id', valueField: 'contact_list_label')->where(
            [
                'ad_username IS NULL',
                'blacklisted' => false
                ]
        )->orderBy('name ASC');

        $fulfillsPrerequisites = $this->Events->FulfillsPrerequisites->find('list')->orderBy('name ASC');
        $requiresPrerequisites = $this->Events->RequiresPrerequisites->find('list')->orderBy('name ASC');
        $partOfs = $this->Events->PartOfs->find('list')->orderBy('name ASC');
        $copyOfs = $this->Events->CopyOfs->find('list')->orderBy('name ASC');
        $categories = $this->Events->Categories->find('list')->where(['id <=' => 2])->orderBy('name ASC');
        $optionalCategories = $this->Events->Categories->find('list')->where(['id >' => 2])->orderBy('name ASC');
        $tools = $this->Events->Tools->find('list')->orderBy('name ASC');
        $committees = $this->Events->Honoraria->Committees->find('list')->orderBy('name ASC');
        $this->set(compact('event', 'rooms', 'contacts', 'fulfillsPrerequisites', 'requiresPrerequisites', 'partOfs', 'copyOfs', 'categories', 'optionalCategories', 'tools', 'committees'));
    }

    public function _afterCreate(\Cake\Event\EventInterface $event)
    {
        if ($event->getSubject()->success) {
            $continuedEvents = $this->__constructContinuedEventsForCreate();
            // Use the base event's id to properly link continued event dates and save
            foreach ($continuedEvents as $continuedEvent) {
                $continuedEvent->set('part_of_id', $event->getSubject()->entity->id);
                $this->Events->save($continuedEvent);
            }

            if (isset($event->getSubject()->entity->files_to_copy)) {
                foreach ($event->getSubject()->entity->files_to_copy as $copyFile) {
                    $copying = $this->Events->Files->get($copyFile['id']);

                    $copied = $this->Events->Files->newEmptyEntity();
                    $copied->file = $copying->file;
                    $copied->dir = $copying->dir;
                    $copied->type = $copying->type;
                    $copied->event_id = $event->getSubject()->entity->id;
                    $copied->private = $copying->private;
                    $this->Events->Files->save($copied);
                }
            }

            /** @var Contact $contact */
            $contact = $this->Events->Contacts
                ->find()
                ->where(['ad_username' => $event->getSubject()->entity->created_by])
                ->first();

            // Send an email to the submitter with some information
            $this->Email->sendEventSubmitted(
                $contact,
                $event->getSubject()->entity, //Event
            );

            $this->Flash->success(__('The event has been created. Your event will appear in 48 hours (non honorarium) or 72 hours (honorarium) unless there is an objection.'));
        } else {
            $this->__resetCategorySplits();

            $this->Flash->error(__('The event could not be created. Errors are highlighted in red below. Make any necessary adjustments and try submitting again.'));

            $x = $event->getSubject()->entity->getErrors();
            if ($x) {
                return false;
            }
        }
    }

    public function _afterUpdate(\Cake\Event\EventInterface $event)
    {
        if ($event->getSubject()->success) {
            $continuedEvents = $this->__constructContinuedEventsForUpdate($event->getSubject()->entity->id);
            foreach ($continuedEvents as $continuedEvent) {
                $this->Events->save($continuedEvent);
            }

            // Cake 5: Entity::visibleProperties() was removed; getVisible() is its replacement.
            $this->customLog("User changed :" . serialize($event->getSubject()->entity->extractOriginalChanged($event->getSubject()->entity->getVisible())));
            $this->Flash->success(__('The event has been updated.'));
        } else {
            $this->customLog("User failed to change :" . serialize($event->getSubject()->entity->extractOriginalChanged(['name', 'short_description'])));
            $this->__resetCategorySplits();

            $this->Flash->error(__('The event could not be updated. Errors are highlighted in red below. Make any necessary adjustments and try submitting again.'));
        }
    }

    public function _beforeCreate(\Cake\Event\EventInterface $event)
    {
        // We are now assuming they have files a W9 with legal, not here - so this bypasses.
        // Cake 3 wrote this onto a possibly-null contact (a no-op there); Cake 5 would throw, so guard it.
        $contact = $event->getSubject()->entity->contact;
        if ($contact !== null) {
            $contact->w9_on_file = true;
        }

        $continuedEvents = $this->__constructContinuedEventsForCreate();
        $completeSave = true;
        foreach ($continuedEvents as $continuedEvent) {
            if ($continuedEvent->getErrors()) {
                $completeSave = false;
                $event->getSubject()->entity->setErrors($continuedEvent->getErrors());
            }
        }

        if (!$completeSave) {
            $event->stopPropagation();
        }
    }

    public function _beforeUpdate(\Cake\Event\EventInterface $event)
    {
        $continuedEvents = $this->__constructContinuedEventsForUpdate($event->getSubject()->entity->id);
        $completeSave = true;
        foreach ($continuedEvents as $continuedEvent) {
            if ($continuedEvent->getErrors()) {
                $completeSave = false;
                $event->getSubject()->entity->setErrors($continuedEvent->getErrors());
            }
        }

        if (!$completeSave) {
            $event->stopPropagation();
        }
    }

    /**
     * What AuthComponent::user($key) returned: one key of the identity array, or null.
     */
    private function __authUser(string $key): mixed
    {
        return $this->currentUser()[$key] ?? null;
    }

    /**
     * Posted data as an array (what Cake 3 kept in $this->request->data).
     */
    private function __postedData(): array
    {
        return $this->request->getData() ?? [];
    }

    /**
     * Writes a modified data array back onto the (immutable) request so that Crud and the callbacks see it.
     */
    private function __setPostedData(array $data): void
    {
        $this->setRequest($this->request->withParsedBody($data));
    }

    /**
     * The client file name of an upload field, or null when the field is absent.
     *
     * Cake 5 puts uploads into the parsed body as UploadedFileInterface objects; test or legacy callers may still
     * pass ['name' => ...] arrays, so both shapes are accepted.
     */
    private function __uploadName(mixed $field): ?string
    {
        if ($field instanceof UploadedFileInterface) {
            return $field->getClientFilename();
        }
        if (is_array($field)) {
            return $field['name'] ?? null;
        }

        return null;
    }

    private function __constructPostForMarshal($mode = 'add')
    {
        if (!$this->request->is(['patch', 'post', 'put'])) {
            return;
        }

        $data = $this->__postedData();

        if ($mode == 'add') {
            $data['created_by'] = $this->currentUsername();

            /**
             * Unset contact information if event is not sponsored and update contact reference
             * to match the current auth user. If contact information is present and empty the
             * submission will fail validation.
             */
            if (isset($data['sponsored']) && !$data['sponsored']) {
                unset($data['contact']['name'], $data['contact']['email'], $data['contact']['phone'], $data['contact']['w9_on_file']);
                $data['contact_id'] = $this->__authUser('contact_id');
            }

            /**
             * Unset contact_id if a contact is not selected. If the id field is present and
             * empty the submission will fail validation.
             */
            if (empty($data['contact_id'])) {
                unset($data['contact_id']);
            } else {
                unset($data['contact']['name'], $data['contact']['email'], $data['contact']['phone'], $data['contact']['w9_on_file']);
            }

            /**
             * Set base contact information based on presence of an existing contact in the
             * submitted data.
             */
            if (isset($data['contact_id'])) {
                $contact = $this->fetchTable('Contacts')->get($data['contact_id']);
                $data['contact']['id'] = $contact->id;
                $data['contact']['name'] = $contact->name;
                $data['contact']['email'] = $contact->email;
                $data['contact']['phone'] = $contact->phone;
                $data['contact']['w9_on_file'] = $contact->w9_on_file;
            }

            /**
             * Unset w9 file field when not used. If file fields are present and empty
             * the submission will fail validation.
             */
            $w9Name = $this->__uploadName($data['contact']['w9']['file'] ?? null);
            if ($w9Name !== null && empty($w9Name)) {
                unset($data['contact']['w9']);
            }

            /**
             * Unset contact data if no relevant data is present in the submission.
             * If an empty contact is present the submission will fail validation.
             */
            if (isset($data['contact']) && empty($data['contact'])) {
                unset($data['contact']);
            }

            /**
             * Unset honoratium information is honorarium is not requested.
             */
            if (isset($data['sponsored']) && !($data['request_honorarium'] ?? null)) {
                unset($data['honorarium']);
            }

            /**
             * Set event as members only if a prerequisite is required. This dependency is also
             * handled from the front end via jQuery, but this serves as the hard check.
             */
            if (!empty($data['requires_prerequisite_id'])) {
                $data['members_only'] = 1;
            }
        }

        /**
         * Unset file fields which are not used. If file fields are present and empty
         * the submission will fail validation.
         */
        if (isset($data['files'])) {
            foreach ($data['files'] as $index => $file) {
                $name = $this->__uploadName(is_array($file) ? ($file['file'] ?? null) : null);
                if (empty($name)) {
                    unset($data['files'][$index]);
                }
            }
        }

        /**
         * Combine category data, separated on the front end for ease of use.
         */
        if (!empty($data['optional_categories']['_ids'])) {
            $data['categories']['_ids'] = array_merge($data['categories']['_ids'] ?? [], $data['optional_categories']['_ids']);
        }

        $this->__setPostedData($data);
    }

    private function __constructContinuedEventsForCreate()
    {
        /**
         * Validate continued dates for multipart events. These are validated first so that
         * errors can be returned before saving the primary event and so the continued events
         * can be properly linked to the first event in the series.
         */
        $data = $this->__postedData();
        $continuedEvents = [];
        if (!empty($data['multipart_event'])) {
            for ($i = 2; $i < 6; $i++) {
                if (!empty($data['event_start_' . $i])) {
                    // Copy source data to build a representative entity
                    $copyData = $data;

                    // Update copy's data
                    $copyData['event_start'] = $data['event_start_' . $i];
                    $copyData['event_end'] = $data['event_end_' . $i] ?? null;
                    $copyData['request_honorarium'] = 0;
                    unset($copyData['honorarium']);
                    $copyData['class_number'] = $i;

                    // Validate copy's data
                    $continuedEvents[] = $this->Events->patchEntity($this->Events->newEmptyEntity(), $copyData);
                } else {
                    break;
                }
            }
        }

        return $continuedEvents;
    }

    private function __constructContinuedEventsForUpdate($id = null)
    {
        /**
         * Validate continued dates for multipart events. These are validated first so that
         * errors can be returned before saving the primary event and so the continued events
         * can be properly linked to the first event in the series.
         */
        $continuedEvents = [];

        $events = $this->Events->find('all')
            ->where(['part_of_id' => $id]);

        $data = $this->__postedData();
        $copyData = $data;

        unset(
            $copyData['event_start'],
            $copyData['event_end'],
            $copyData['class_number']
        );

        $i = 2;
        foreach ($events as $event) {
            if (parent::isAuthorized($this->currentUser())) {
                $copyData['event_start'] = $data['event_start_' . $i] ?? null;
                $copyData['event_end'] = $data['event_end_' . $i] ?? null;
            } else {
                $copyData['booking_start'] = $event->booking_start->i18nFormat('yyyy-MM-dd HH:mm:ss');
                $copyData['booking_end'] = $event->booking_end->i18nFormat('yyyy-MM-dd HH:mm:ss');
            }
            $continuedEvents[] = $this->Events->patchEntity($event, $copyData);
            $i++;
        }

        return $continuedEvents;
    }

    private function __resetCategorySplits()
    {
        /**
         * Separate radio category data for redisplay.
         */
        $data = $this->__postedData();
        // PHP 8: current() on a missing/non-array value fatals (Cake 3 / PHP 7 only warned).
        // Guard so a validation-failure redisplay without categories._ids cannot crash.
        if (!empty($data['optional_categories']['_ids']) && isset($data['categories']['_ids']) && is_array($data['categories']['_ids'])) {
            $data['categories']['_ids'] = current($data['categories']['_ids']);
            $this->__setPostedData($data);
        }
    }
}
