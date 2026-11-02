<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Component\EmailComponent;
use App\Controller\Component\WebhookComponent;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\Routing\Router;

/**
 * Events Controller
 *
 * Core actions (public listing, calendar, view, the member/admin lists). The other actions live in the traits in
 * App\Controller\Concern so that the port can be split between people without editing the same file:
 *
 *  - EventsFeedActionsTrait        feed, ics, eventsJson, add-to-calendar links
 *  - EventsHonorariaActionsTrait   honoraria lists and the CSV export
 *  - EventsModerationActionsTrait  approve, reject, cancel, attendance, assignments
 *  - EventsSubmitActionsTrait      add, edit and the marshalling helpers
 *  - EventsCronActionsTrait        cron() and the SMS helper
 *
 * STATUS (foundation pass): this file is ported to Cake 5 for index, calendar, view and the lists; the traits are
 * still Cake 3 code with mechanical renames only.
 *
 * @property \App\Model\Table\EventsTable $Events
 * @property EmailComponent $Email
 * @property WebhookComponent $Webhook
 */
class EventsController extends AppController
{
    use \App\Controller\Concern\EventsFeedActionsTrait;
    use \App\Controller\Concern\EventsHonorariaActionsTrait;
    use \App\Controller\Concern\EventsModerationActionsTrait;
    use \App\Controller\Concern\EventsSubmitActionsTrait;
    use \App\Controller\Concern\EventsCronActionsTrait;

    public array $paginate = [
        'sortableFields' => ['Events.event_start', 'Events.created'],
        'order' => [
            'Events.event_start' => 'asc',
        ],
        'maxLimit' => PHP_INT_MAX,
    ];

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Webhook');
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $this->Authentication->allowUnauthenticated(['calendar', 'cron', 'embed', 'feed', 'index', 'view', 'ics']);

        $this->Crud->mapAction('all', 'Crud.Index');
        $this->Crud->mapAction('attendance', 'Crud.Edit');
        $this->Crud->mapAction('assignments', 'Crud.Edit');
        $this->Crud->mapAction('attending', 'Crud.Index');
        $this->Crud->mapAction('calendar', 'Crud.Index');
        $this->Crud->mapAction('embed', 'Crud.Index');
        $this->Crud->mapAction('pending', 'Crud.Index');
        $this->Crud->mapAction('submitted', 'Crud.Index');
        $this->Crud->mapAction('approve', 'Crud.Edit');
        $this->Crud->mapAction('cancel', 'Crud.Edit');
        $this->Crud->mapAction('processRejection', 'Crud.View');
        $this->Crud->mapAction('reject', 'Crud.Edit');
        $this->Crud->mapAction('pendingHonoraria', 'Crud.Index');
        $this->Crud->mapAction('acceptedHonoraria', 'Crud.Index');
        $this->Crud->mapAction('rejectedHonoraria', 'Crud.Index');
        $this->Crud->mapAction('upcomingHonoraria', 'Crud.Index');

        $this->Crud->disable(['Delete']);

        // Cake 3 called setConfig('unlockedActions', ['edit']) and then, a few lines later, overwrote it with
        // ['exportHonoraria']; only the last call took effect, which is kept.
        $this->FormProtection->setConfig('unlockedActions', ['exportHonoraria']);
    }

    public function all()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                if ($this->request->getQuery('start_date') !== null && $this->request->getQuery('end_date') !== null) {
                    $start_date = new \DateTime($this->request->getQuery('start_date') . ' 00:00:00', new \DateTimeZone('America/Chicago'));
                    $start_date->setTimezone(new \DateTimeZone('UTC'));
                    $end_date = new \DateTime($this->request->getQuery('end_date') . ' 23:59:59', new \DateTimeZone('America/Chicago'));
                    $end_date->setTimezone(new \DateTimeZone('UTC'));

                    $event->getSubject()->query
                        ->where(
                            [
                            'Events.part_of_id IS NULL',
                            'Events.event_start >=' => $start_date->format('Y-m-d H:i:s'),
                            'Events.event_start <=' => $end_date->format('Y-m-d H:i:s'),
                            ]
                        )
                        ->orderBy(['Events.created' => 'DESC']);
                } else {
                    $event->getSubject()->query
                        ->where(
                            [
                            'Events.part_of_id IS NULL',
                            ]
                        )
                        ->orderBy(['Events.created' => 'DESC']);
                }
                $this->paginate['limit'] = 50;
            }
        );

        return $this->Crud->execute();
    }

    public function index()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                // Midnight (Chicago) today, expressed in UTC. Cake 3's mutable Time was changed in place; the Cake 5
                // DateTime is immutable, so the result has to be assigned.
                $today = (new DateTime('America/Chicago'))->startOfDay()->setTimezone('UTC');

                $event->getSubject()->query
                    ->select(
                        [
                        'Events.id',
                        'Events.event_start',
                        'Events.event_end',
                        'Events.name',
                        'Events.cost',
                        'Events.short_description',
                        'Events.created',
                        'Events.free_spaces',
                        'Events.paid_spaces',
                        'Events.room_id',
                        'Rooms.id',
                        'Rooms.name',
                        'Contacts.name',
                        'registration_count' => 'count(Registrations.id)',
                        ]
                    )
                    ->leftJoinWith('Registrations', function ($q) {
                        return $q->where(['Registrations.status NOT IN' => ['cancelled', 'rejected']]);
                    })
                    ->where(
                        [
                        'Events.event_start >=' => $today,
                        'Events.status' => 'approved',
                        ]
                    )
                    ->groupBy('Events.id')
                    ->contain(['Rooms', 'Contacts']);

                $this->__applyQueryFilters($event);

                $this->paginate['limit'] = 2147483647;
                $this->paginate['order'] = ['Events.event_start' => 'ASC'];
            }
        );

        $this->Crud->on('afterPaginate', [$this, '_applyAddress']);

        $this->Crud->on('beforeRender', [$this, '_filterContent']);

        return $this->Crud->execute();
    }

    public function embed()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $today = (new DateTime('America/Chicago'))->startOfDay()->setTimezone('UTC');

                $event->getSubject()->query
                    ->select(
                        [
                        'Events.id',
                        'Events.event_start',
                        'Events.event_end',
                        'Events.name',
                        'Events.short_description',
                        'Events.room_id',
                        'Rooms.id',
                        'Rooms.name',
                        'Contacts.name',
                        ]
                    )
                    ->where(
                        [
                        'Events.event_start >=' => $today,
                        'Events.status' => 'approved',
                        ]
                    )
                    ->contain(['Rooms', 'Contacts'])
                    ->orderBy(['event_start' => 'ASC']);

                $this->__applyQueryFilters($event);

                $this->paginate['limit'] = 2147483647;
            }
        );

        $this->Crud->on('afterPaginate', [$this, '_applyAddress']);

        $this->Crud->on('beforeRender', [$this, '_filterContent']);

        return $this->Crud->execute();
    }

    public function calendar($year = null, $month = null, $day = null)
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $pass = $this->request->getParam('pass');
                $year = empty($pass[0]) ? null : $pass[0];
                $month = empty($pass[1]) ? null : $pass[1];
                $day = empty($pass[2]) ? null : $pass[2];

                $now = new DateTime('America/Chicago');
                $highlight = true;

                if ($day != null && $day >= 1 && $day <= 31) {
                    $now = $now->day((int)$day);

                    if ($year != null && $year != $now->year && $year >= 2016 && $year <= 2166) {
                        $now = $now->year((int)$year);
                        $highlight = false;
                    }

                    if ($month != null && $month != $now->month && $month >= 1 && $month <= 12) {
                        $now = $now->month((int)$month);
                        $highlight = false;
                    }

                    $start = $now->startOfDay();
                    $end = $now->endOfDay();

                    // KNOWN BUG PRESERVED (handoff doc section 5): templates/Events/index_daily.php does not exist,
                    // so the day view has always failed with a missing-template error (500).
                    $this->render('index_daily');
                } else {
                    $now = $now->day(1);

                    if ($year != null && $year != $now->year && $year >= 2016 && $year <= 2166) {
                        $now = $now->year((int)$year);
                        $highlight = false;
                    }

                    if ($month != null && $month != $now->month && $month >= 1 && $month <= 12) {
                        $now = $now->month((int)$month);
                        $highlight = false;
                    }

                    $start = $now->startOfMonth();
                    $end = $now->endOfMonth();

                    // Chronos 3 numbers the weekdays 1 (Monday) to 7 (Sunday); Chronos 1 used 0 (Sunday) to 6.
                    $this->set('currentMonth', $start->setTimezone('America/Chicago'));
                    $this->set('startOfMonth', $start->dayOfWeek < 7 ? $start->dayOfWeek : 0);
                }

                $event->getSubject()->query
                    ->select(
                        [
                        'Events.id',
                        'Events.event_start',
                        'Events.event_end',
                        'Events.name',
                        'Events.short_description',
                        'Events.room_id',
                        'Rooms.id',
                        'Rooms.name',
                        ]
                    )
                    ->where(
                        [
                        'Events.event_start >=' => $start->setTimezone('UTC'),
                        'Events.event_start <=' => $end->setTimezone('UTC'),
                        'Events.status IN' => ['approved', 'completed'],
                        ]
                    )
                    ->contain(['Rooms'])
                    ->orderBy(['event_start' => 'ASC']);

                $this->__applyQueryFilters($event);

                $this->paginate['limit'] = 2147483647;

                $this->set('currentDate', $now);
                $this->set('highlight', $highlight);
            }
        );

        $this->Crud->on('afterPaginate', [$this, '_applyAddress']);

        $this->Crud->on('beforeRender', [$this, '_filterContent']);

        return $this->Crud->execute();
    }

    public function submitted()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $event->getSubject()->query
                    ->select(
                        [
                        'Events.id',
                        'Events.event_start',
                        'Events.event_end',
                        'Events.name',
                        'Events.short_description',
                        'Events.status',
                        'Events.created',
                        'Events.room_id',
                        'Rooms.id',
                        'Rooms.name',
                        ]
                    )
                    ->where(
                        [
                        'Events.status IN' => ['approved', 'cancelled', 'completed', 'pending', 'rejected'],
                        'Events.created_by' => $this->currentUsername(),
                        ]
                    )
                    ->contain(['Rooms'])
                    ->orderBy(['event_start' => 'DESC']);

                $this->paginate['limit'] = 2147483647;
            }
        );

        $this->Crud->on('afterPaginate', [$this, '_applyAddress']);

        return $this->Crud->execute();
    }

    public function attending()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $event->getSubject()->query
                    ->select(
                        [
                        'Events.id',
                        'Events.event_start',
                        'Events.event_end',
                        'Events.name',
                        'Events.short_description',
                        'Events.room_id',
                        'Rooms.id',
                        'Rooms.name',
                        ]
                    )
                    ->where(
                        [
                        'Events.status IN' => ['approved', 'completed', 'pending'],
                        ]
                    )
                    ->innerJoinWith(
                        'Registrations',
                        function ($q) {
                            return $q->where(['Registrations.ad_username' => $this->currentUsername()]);
                        }
                    )
                    ->contain(['Rooms'])
                    ->orderBy(['event_start' => 'DESC']);

                $this->paginate['limit'] = 2147483647;
            }
        );

        $this->Crud->on('afterPaginate', [$this, '_applyAddress']);

        return $this->Crud->execute();
    }

    public function pending()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $event->getSubject()->query
                    ->where(
                        [
                        'Events.part_of_id IS NULL',
                        'Events.status' => 'pending',
                        'Honoraria.id IS NULL',
                        ]
                    )
                    ->contain(['Honoraria'])
                    ->orderBy(['Events.created' => 'ASC']);

                $this->paginate['limit'] = 50;
            }
        );

        return $this->Crud->execute();
    }

    public function view($id = null)
    {
        // Redirect to primary event if event is a continuation of a multi-part event
        if ($baseId = $this->Events->get($id)->part_of_id) {
            return $this->redirect(['action' => 'view', $baseId]);
        }

        $config = $this->fetchTable('Configurations')->find('list')->toArray();
        $this->set('config', $config);

        $this->Crud->on(
            'beforeFind',
            function (EventInterface $event) {
                $event->getSubject()->query->contain(
                    [
                        'Categories',
                        'Contacts',
                        'Files',
                        'FulfillsPrerequisites',
                        'Registrations',
                        'RequiresPrerequisites',
                        'Rooms',
                        'Tools',
                    ]
                );
            }
        );

        $this->Crud->on(
            'beforeRender',
            function (EventInterface $event) {
                $eventId = $this->request->getParam('pass')[0];

                $continuedDates = $this->Events->find('all')
                    ->select(['class_number', 'event_start', 'event_end'])
                    ->where(['part_of_id' => $event->getSubject()->entity->id])
                    ->orderBy('class_number ASC')
                    ->toArray();
                $this->set('continuedDates', $continuedDates);
                $this->set('authUsername', $this->currentUsername());

                $registrations = $this->fetchTable('Registrations');
                if ($this->currentUser()) {
                    $this->set('hasRegistration', $registrations->exists(['event_id' => $eventId, 'ad_username' => $this->currentUsername()]));
                } else {
                    $this->set('hasRegistration', false);
                }

                $totalSpaces = $this->Events->getTotalSpaces($eventId);
                $filledSpaces = $this->Events->getFilledSpaces($eventId);
                if (is_int($totalSpaces) && is_int($filledSpaces)) {
                    $openSpaces = $totalSpaces - $filledSpaces;
                } else {
                    $openSpaces = true;
                }

                $this->set('hasOpenSpaces', $this->Events->hasOpenSpaces($eventId));
                $this->set('openSpaces', $openSpaces);
                $this->set('totalSpaces', $totalSpaces);

                // Add to calendar links
                $this->set('addToCalLinks', $this->getAddToCalLinks($event->getSubject()->entity));
            }
        );

        $this->Crud->on('beforeRender', [$this, '_applyAddress']);

        return $this->Crud->execute();
    }

    public function _applyAddress(EventInterface $event)
    {
        if (isset($event->getSubject()->entities)) {
            foreach ($event->getSubject()->entities as $entity) {
                $entity->address = '1825 Monetary Ln #104 Carrollton, TX 75006';

                # WARNING: Ugly hack, relies on room_ids which could change
                # Don't put our address for off-site and online classes
                if ($entity->room && in_array($entity->room->id, [23, 58])) {
                    $entity->address = null;
                }
            }
        } else {
            $event->getSubject()->entity->address = '1825 Monetary Ln #104 Carrollton, TX 75006';

            # WARNING: Ugly hack, relies on room_ids which could change
            # Don't put our address for off-site and online classes
            if ($event->getSubject()->entity->room && in_array($event->getSubject()->entity->room->id, [23, 58])) {
                $event->getSubject()->entity->address = null;
            }
        }
    }

    public function _filterContent(EventInterface $event)
    {
        $categories = $this->Events->Categories->find('list')->where(['id >' => 2])->orderBy('name ASC')->toArray();
        $tools = $this->Events->Tools->find('list')->orderBy('name ASC')->toArray();
        $rooms = $this->Events->Rooms->find('list')->orderBy('name ASC')->toArray();
        $this->set(compact('categories', 'tools', 'rooms'));
    }

    private function __applyQueryFilters(&$event)
    {
        if (!empty($this->request->getQuery('tool'))) {
            $event->getSubject()->query->matching(
                'Tools',
                function ($q) {
                    return $q->where(['Tools.id' => $this->request->getQuery('tool')]);
                }
            );
        }

        if (!empty($this->request->getQuery('room'))) {
            $event->getSubject()->query->matching(
                'Rooms',
                function ($q) {
                    return $q->where(['Rooms.id' => $this->request->getQuery('room')]);
                }
            );
        }

        if (!empty($this->request->getQuery('type')) || !empty($this->request->getQuery('category'))) {
            $event->getSubject()->query->matching(
                'Categories',
                function ($q) {
                    $categories = [];

                    if (!empty($this->request->getQuery('type'))) {
                        $categories[] = $this->request->getQuery('type');
                    }

                    if (!empty($this->request->getQuery('category'))) {
                        $categories[] = $this->request->getQuery('category');
                    }

                    return $q->where(['Categories.id IN' => $categories]);
                }
            );
        }

        if (!empty($this->request->getQuery('type')) && !empty($this->request->getQuery('category'))) {
            $event->getSubject()->query
                ->groupBy('Events.id')
                ->having(
                    [
                    $event->getSubject()->query->newExpr('COUNT(DISTINCT Categories.id) = 2'),
                    ]
                );
        }
    }
}
