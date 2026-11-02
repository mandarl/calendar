<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Event;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Cake\Log\Log;

/**
 * Events Model
 *
 * @property \Cake\ORM\Association\BelongsTo $Rooms
 * @property \Cake\ORM\Association\BelongsTo $Contacts
 * @property \Cake\ORM\Association\BelongsTo $FulfillsPrerequisites
 * @property \Cake\ORM\Association\BelongsTo $RequiresPrerequisites
 * @property \Cake\ORM\Association\BelongsTo $PartOfs
 * @property \Cake\ORM\Association\BelongsTo $CopyOfs
 * @property \Cake\ORM\Association\HasMany $Files
 * @property \Cake\ORM\Association\HasMany $Registrations
 * @property \Cake\ORM\Association\HasOne $Honoraria
 * @property \Cake\ORM\Association\BelongsToMany $Categories
 * @property \Cake\ORM\Association\BelongsToMany $Tools
 */
class EventsTable extends Table
{
    use LocatorAwareTrait;

    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    /**
     * Cake 3 returned DECIMAL columns as floats (a cost of 5.00 printed as `5`, which the templates rely on:
     * `"$" . $event->cost . ".00"`). Cake 5 returns the exact decimal string ("5.00"), so map `cost` back to float.
     *
     * @return \Cake\Database\Schema\TableSchemaInterface
     */
    public function getSchema(): \Cake\Database\Schema\TableSchemaInterface
    {
        $schema = parent::getSchema();
        $schema->setColumnType('cost', 'float');

        return $schema;
    }

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('events');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('FriendlyTime');
        $this->addBehavior('RelationalTime');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Rooms');
        $this->belongsTo('Contacts', ['joinType' => 'INNER']);
        $this->belongsTo('FulfillsPrerequisites', [
            'className' => 'Prerequisites',
            'foreignKey' => 'fulfills_prerequisite_id'
        ]);
        $this->belongsTo('RequiresPrerequisites', [
            'className' => 'Prerequisites',
            'foreignKey' => 'requires_prerequisite_id'
        ]);
        $this->belongsTo('PartOfs', [
            'className' => 'Events',
            'foreignKey' => 'part_of_id'
        ]);
        $this->belongsTo('CopyOfs', [
            'className' => 'Events',
            'foreignKey' => 'copy_of_id'
        ]);
        $this->hasOne('Honoraria');
        $this->belongsToMany('Categories');
        $this->belongsToMany('Tools');
        $this->hasMany('Files', [
            'dependent' => true
        ]);
        $this->hasMany('Registrations');
        $this->hasMany('OldRegistrations', [
            'className' => 'Registrations',
            'foreign_key' => 'event_id'
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->requirePresence('short_description', 'create')
            ->notEmptyString('short_description');

        $validator
            ->requirePresence('long_description', 'create')
            ->allowEmptyString('long_description');

        $validator
            ->allowEmptyString('advisories');

        $validator
            ->dateTime('event_start')
            ->requirePresence('event_start', 'create')
            ->notEmptyString('event_start')
            ->add('event_start', 'custom', [
                'rule' => function ($value, $context) {
                    $config = $this->fetchTable('Configurations')->find('list')->toArray();

                    $startTime = new DateTime($value);

                    // Cake 3's Time::isWithinNext() turned a bare number into "N days"; Chronos 3 would take "+N" as a
                    // timezone offset, so the unit is spelled out here.
                    if (!empty($context['data']['request_honorarium'])) {
                        if ($startTime->isWithinNext($config[3] . ' days')) {
                            return 'The start date must be at least ' . $config[3] . ' days out when requesting honorarium.';
                        }
                    } else {
                        if ($startTime->isWithinNext($config[4] . ' days')) {
                            return 'Events must be scheduled at least ' . $config[4] . ' days in advance.';
                        }
                    }

                    if (!$startTime->isWithinNext(($config[5] + 1) . ' days')) {
                        return 'Events can only be scheduled ' . $config[5] . ' days in advance.';
                    }

                    return true;
                }
            ]);

        $validator
            ->dateTime('event_end')
            ->requirePresence('event_end', 'create')
            ->notEmptyString('event_end')
            ->add('event_end', 'custom', [
                'rule' => function ($value, $context) {
                    if ($context['data']['event_start'] >= $value) {
                        return false;
                    }

                    return true;
                },
                'message' => 'The event can not end before it starts.'
            ]);

        $validator
            ->dateTime('booking_start')
            ->requirePresence('booking_start', 'create')
            ->notEmptyString('booking_start');

        $validator
            ->dateTime('booking_end')
            ->requirePresence('booking_end', 'create')
            ->notEmptyString('booking_end');

        $validator
            ->decimal('cost')
            ->requirePresence('cost', 'create')
            ->notEmptyString('cost');

        $validator
            ->allowEmptyString('eventbrite_link');

        $validator
            ->integer('free_spaces')
            ->requirePresence('free_spaces', 'create')
            ->notEmptyString('free_spaces');

        $validator
            ->integer('paid_spaces')
            ->requirePresence('paid_spaces', 'create')
            ->notEmptyString('paid_spaces');

        $validator
            ->boolean('members_only')
            ->requirePresence('members_only', 'create')
            ->notEmptyString('members_only');

        $validator
            ->integer('age_restriction')
            ->requirePresence('age_restriction', 'create')
            ->notEmptyString('age_restriction')
            ->add('age_restriction', 'inList', [
                'rule' => ['inList', [0, 13, 16, 18, 21]]
            ]);

        $validator
            ->boolean('attendees_require_approval')
            ->requirePresence('attendees_require_approval', 'create')
            ->notEmptyString('attendees_require_approval');

        $validator
            ->dateTime('attendee_cancellation')
            ->requirePresence('attendee_cancellation', 'create')
            ->notEmptyString('attendee_cancellation');

        $validator
            ->allowEmptyString('extend_registration')
            ->add('extend_registration', 'inList', [
                'rule' => ['inList', [0, 15, 20, 25, 30]]
            ]);

        $validator
            ->allowEmptyString('contact_id');

        $validator
            ->requirePresence('room_id', 'create')
            ->notEmptyString('room_id')
            ->add('room_id', 'custom', [
                'rule' => function ($value, $context) {
                    if (empty($value)) {
                        return true;
                    }

                    $room = $this->fetchTable('Rooms')->get($value);
                    if (!$room->exclusive) {
                        return true;
                    }

                    // if edit, get booking from database
                    if (isset($context['data']['id'])) {
                        $id = intval($context['data']['id']);
                        if ($id > 0) {
                            $events = $this->fetchTable('Events');
                            $event = $events->find()->where(['id =' => $id])->first();
                            if ($event) {
                                $context['data']['booking_start'] = $event['booking_start'];
                                $context['data']['booking_end'] = $event['booking_end'];
                            } else {
                                return 'The room selected for this event is not available at the requested time.';
                            }
                        } else {
                            return 'The room selected for this event is not available at the requested time.';
                        }
                    }

                    $query = [
                        $context['field'] => $value,
                        'booking_start <' => $context['data']['booking_end'],
                        'booking_end >' => $context['data']['booking_start'],
                        'status IN' => ['approved', 'pending']
                    ];

                    if (isset($context['data']['id'])) {
                        $query['id !='] = $context['data']['id'];
                    }

                    $conflictingEvents = $this->find('list', keyField: 'id', valueField: 'name')->where($query);

                    if ($conflictingEvents->count() == 0) {
                        return true;
                    }

                    $conflict = $conflictingEvents->toArray();
                    reset($conflict);
                    $event_id = key($conflict);
                    $conflict = sprintf('<a href="/events/view/%d">%s</a>', $event_id, $conflict[$event_id]);

                    if ($context['data']['class_number'] > 1) {
                        $words = ['', 'first', 'second', 'third', 'fourth', 'fifth'];

                        return "The room selected for this event's " . $words[$context['data']['class_number']] . ' date is not available at the requested time. Conflicts with ' . $conflict . '.';
                    }

                    return 'The room selected for this event is not available at the requested time. Conflicts with ' . $conflict . '.';
                }
            ]);

        $validator
            ->add('tools', 'custom', [
                'rule' => function ($values, $context) {
                    if (empty($values['_ids'])) {
                        return true;
                    }

                    // if edit, get booking from database
                    if (isset($context['data']['id'])) {
                        $id = intval($context['data']['id']);
                        if ($id > 0) {
                            $events = $this->fetchTable('Events');
                            $event = $events->find()->where(['id =' => $id])->first();
                            if ($event) {
                                $context['data']['booking_start'] = $event['booking_start'];
                                $context['data']['booking_end'] = $event['booking_end'];
                            } else {
                                return 'Some of the tools selected for this event are not available at the requested time.';
                            }
                        } else {
                            return 'Some of the tools selected for this event are not available at the requested time.';
                        }
                    }


                    $unavailableTools = [];
                    $toolNames = [];
                    foreach ($values['_ids'] as $tool) {
                        $query = [
                            'Events.booking_start <' => $context['data']['booking_end'],
                            'Events.booking_end >' => $context['data']['booking_start'],
                            'status IN' => ['approved', 'pending']
                        ];

                        if (isset($context['data']['id'])) {
                            $query['Events.id !='] = $context['data']['id'];
                        }

                        $conflictingEvents = $this->find('list')
                            ->where($query)
                            ->innerJoin(
                                ['EventsTools' => 'events_tools'],
                                [
                                    'EventsTools.tool_id' => $tool,
                                    'Events.id = EventsTools.event_id'
                                ],
                                ['EventsTools.tool_id' => 'integer']
                            );

                        if ($conflictingEvents->count() > 0) {
                            if (empty($toolNames)) {
                                $tools = $this->fetchTable('Tools');
                                $query = $tools
                                    ->find('list', keyField: 'id', valueField: 'name');
                                foreach ($query as $id => $name) {
                                    $toolNames[$id] = $name;
                                }
                            }
                            $unavailableTools[] = $toolNames[$tool];
                        }
                    }

                    if (count($unavailableTools) > 0) {
                        if ($context['data']['class_number'] > 1) {
                            $words = ['', 'first', 'second', 'third', 'fourth', 'fifth'];

                            return "Some of the tools selected for this event's " . $words[$context['data']['class_number']] . " date are not available at the requested time. Tools in use: " . implode(', ', $unavailableTools);
                        }

                        return 'Some of the tools selected for this event are not available at the requested time. Tools in use: ' . implode(', ', $unavailableTools);
                    }

                    return true;
                }
            ]);

        $validator
            ->integer('class_number')
            ->requirePresence('class_number', 'create')
            ->notEmptyString('class_number');

        $validator
            ->boolean('sponsored')
            ->requirePresence('sponsored', 'create')
            ->notEmptyString('sponsored');

        $validator
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->add('status', 'inList', [
                'rule' => ['inList', ['approved', 'completed', 'cancelled', 'pending', 'rejected']]
            ]);

        $validator
            ->allowEmptyString('rejected_by');

        $validator
            ->allowEmptyString('rejection_reason');

        $validator
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        $validator
            ->allowEmptyString('cancel_notification');

        $validator
            ->allowEmptyString('reminder_notification');

        $validator
            ->boolean('notify_instructor_of_registers');

        $validator
            ->boolean('notify_instructor_of_cancels');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['room_id'], 'Rooms'));
        $rules->add($rules->existsIn(['fulfills_prerequisite_id'], 'FulfillsPrerequisites'));
        $rules->add($rules->existsIn(['requires_prerequisite_id'], 'RequiresPrerequisites'));
        $rules->add($rules->existsIn(['part_of_id'], 'PartOfs'));
        $rules->add($rules->existsIn(['copy_of_id'], 'CopyOfs'));

        return $rules;
    }

    public function hasHonorarium($id)
    {
        return $this->find('all')
            ->where(['Honoraria.event_id' => $id])
            ->contain('Honoraria')
            ->count();
    }

    /**
     * Returns the total number of spaces avalible or true if unlimited
     *
     * @param int $id Event Id
     * @return int|true
     */
    public function getTotalSpaces($id)
    {
        $event = $this->get($id, fields: ['free_spaces', 'paid_spaces']);

        if ($event->free_spaces == 0 && $event->paid_spaces == 0) {
            return true;
        }

        return $event->free_spaces + $event->paid_spaces;
    }

    /**
     * Returns the number of filled spaces or true if unlimited
     *
     * @param int $id Event Id
     * @return int|true
     */
    public function getFilledSpaces($id)
    {
        $event = $this->get($id, fields: ['free_spaces', 'paid_spaces']);

        if ($event->free_spaces == 0 && $event->paid_spaces == 0) {
            return true;
        }

        $regs = $this->find('all')
            ->where(['Events.id' => $id])
            ->innerJoinWith(
                'Registrations',
                function ($q) {
                    return $q->where(['Registrations.status NOT IN' => ['cancelled','rejected']]);
                }
            )
            ->count();

        return $regs;
    }

    public function hasFreeSpaces($id)
    {
        $event = $this->get($id, fields: ['free_spaces', 'paid_spaces']);

        if ($event->free_spaces == 0 && $event->paid_spaces == 0) {
            return true;
        }

        if ($event->free_spaces > 0) {
            $freeRegs = $this->find('all')
                ->where(['Events.id' => $id])
                ->innerJoinWith(
                    'Registrations',
                    function ($q) {
                        return $q->where([
                            'Registrations.type' => 'free',
                            'AND' => [
                                'Registrations.status NOT IN' => ['cancelled', 'rejected'],
                            ],
                        ]);
                    }
                )
                ->count();
            if ($freeRegs < $event->free_spaces) {
                return true;
            }
        }

        return false;
    }

    public function hasPaidSpaces($id)
    {
        $event = $this->get($id, fields: ['cost', 'free_spaces', 'paid_spaces']);

        if ($event->cost) {
            if ($event->paid_spaces == 0) {
                return true;
            }

            if ($event->paid_spaces > 0) {
                $paidRegs = $this->find('all')
                    ->where(['Events.id' => $id])
                    ->innerJoinWith(
                        'Registrations',
                        function ($q) {
                            return $q->where([
                                'Registrations.type' => 'paid',
                                'AND' => [
                                    'Registrations.status NOT IN' => ['cancelled', 'rejected'],
                                ],
                            ]);
                        }
                    )
                    ->count();

                if ($paidRegs < $event->paid_spaces) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasOpenSpaces($id)
    {
        return $this->hasFreeSpaces($id) || $this->hasPaidSpaces($id);
    }

    /**
     * Returns a boolean indictating whether or not a given event is owned
     * by a user with a given AD Username.
     *
     * @param int $id The id of the event to check.
     * @param string $user The username to check against.
     * @return bool
     */
    public function isOwnedBy($id, $user)
    {
        return $this->exists(['id' => $id, 'created_by' => $user]);
    }
}

