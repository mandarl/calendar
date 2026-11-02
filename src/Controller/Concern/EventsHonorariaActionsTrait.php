<?php
declare(strict_types=1);

namespace App\Controller\Concern;

use Cake\Event\EventInterface;
use Cake\I18n\DateTime;

/**
 * EventsController actions: HonorariaActions (lists of events that request a honorarium, the finance export and the
 * CSV download).
 *
 * Split out of the Cake 3 EventsController so that several people can port the Events controller in parallel. Used by
 * App\Controller\EventsController. Ported to Cake 5 by slice H; the behaviour of the Cake 3 build is kept, including
 * the quirks listed in calendar-cake5-ahl-CHANGES.md.
 *
 * The Cake 3 code read $_GET / $_POST directly; the request object holds the same values and is what the integration
 * tests can set, so it is used here.
 */
trait EventsHonorariaActionsTrait
{
    /**
     * Finance view: honoraria of the completed events in a date range, with a form that saves the "paid" state.
     *
     * Unlocked in FormProtection (EventsController::beforeFilter), the CSRF cookie middleware still applies.
     *
     * @return void
     */
    public function exportHonoraria()
    {
        $startParam = $this->request->getQuery('start_date');
        $endParam = $this->request->getQuery('end_date');
        if ($startParam !== null && $endParam !== null) {
            $honoraria = $this->fetchTable('Honoraria');

            $paid = $this->request->getData('paid');
            if (is_array($paid)) {
                foreach ($paid as $id => $status) {
                    $honorarium = $honoraria->get($id);
                    $honorarium->paid = $status;
                    $honoraria->save($honorarium);
                }
            }

            $this->set('honoraria', $this->exportQuery((string)$startParam, (string)$endParam));
            $this->set('oldCutoff', new DateTime('2017-01-01 00:00', 'America/Chicago'));
        }
    }

    /**
     * CSV download of the same list (the finance report).
     *
     * @return void
     */
    public function exportHonorariaCsv()
    {
        $export = [];
        $export[] = [
            'Event ID',
            'Event Time',
            'Event Name',
            'Committee',
            'Contact Name',
            'Contact Email',
            'Contact Phone',
            'Attendees',
            'Requirements Met',
            'Pay Contact?',
            'Paid',
        ];

        $data = $this->exportQuery(
            (string)$this->request->getQuery('start_date'),
            (string)$this->request->getQuery('end_date')
        );

        $payTypes = [
            'Not Paid',
            'Paid',
            'Pending',
            'Missing Info',
            'Denied',
            'Paid by Script',
        ];

        foreach ($data as $row) {
            if (count($row->registrations) > 2) {
                $export[] = [
                    $row->id,
                    $row->event_start->i18nFormat('MM-dd-yyyy h:mma', 'America/Chicago'),
                    $row->name,
                    $row->honorarium->committee->name,
                    $row->contact->name,
                    $row->contact->email,
                    $row->contact->phone,
                    count($row->registrations),
                    'Honoraria Met',
                    ($row->honorarium->pay_contact ? 'Yes' : 'No'),
                    $payTypes[$row->honorarium->paid],
                ];
            } else {
                $export[] = [
                    $row->id,
                    $row->event_start->i18nFormat('MM-dd-yyyy h:mma', 'America/Chicago'),
                    $row->name,
                    $row->honorarium->committee->name,
                    $row->contact->name,
                    $row->contact->email,
                    $row->contact->phone,
                    count($row->registrations),
                    'Honoraria Not Met',
                    "Don't Pay",
                    'N/A',
                ];
            }
        }

        // The file name is misspelt ("honoria") in the Cake 3 build; it is what users and scripts already know.
        $this->response = $this->response->withDownload('honoria_export.csv');
        // CsvView 5: `serialize` replaces the `_serialize` view var. `escape` is the PHP 7.4 fputcsv() default that the
        // Cake 3 CsvView relied on (CsvView 5 defaults to '' = RFC 4180); keeping it keeps the bytes identical for values
        // that contain a backslash before a quote. Passing it explicitly does not trigger PHP 8.4's deprecation.
        $this->viewBuilder()
            ->setClassName('CsvView.Csv')
            ->setOptions(['serialize' => 'export', 'escape' => '\\']);
        $this->set(compact('export'));
    }

    /**
     * Pending events that ask for a honorarium (approve / reject links).
     *
     * @return \Cake\Http\Response|null
     */
    public function pendingHonoraria()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $event->getSubject()->query->where(
                    [
                        'Events.part_of_id IS NULL',
                        'Events.status' => 'pending',
                        'Honoraria.id IS NOT NULL',
                    ]
                )
                    ->contain(['Honoraria' => ['Committees'], 'Contacts']);

                // isset($_GET['sort']) in the Cake 3 build: an empty "?sort=" suppresses the default order.
                if ($this->request->getQuery('sort') === null) {
                    $event->getSubject()->query->orderBy(['event_start' => 'DESC']);
                }

                $this->paginate['limit'] = 50;
            }
        );

        return $this->Crud->execute();
    }

    /**
     * Number of upcoming events with a honorarium, for this and the next three months.
     *
     * Quirks of the Cake 3 build that are kept: "+N month" from the 29th to 31st skips a short month, and the month
     * end is always the 31st.
     *
     * @return void
     */
    public function upcomingHonoraria()
    {
        $months = [];
        for ($i = 0; $i < 4; $i++) {
            $nextMonth = date('M', strtotime('+' . $i . ' month'));
            $nextMonthStart = date('Y-m-01 00:00:00', strtotime('+' . $i . ' month'));
            $nextMonthEnd = date('Y-m-31 23:59:59', strtotime('+' . $i . ' month'));
            $events = $this->fetchTable('Events');
            $results = $events->find('all')
                ->where(
                    [
                        'Events.part_of_id IS NULL',
                        'Events.status IN' => ['approved', 'completed'],
                        'Events.event_start >=' => $nextMonthStart,
                        'Events.event_end <=' => $nextMonthEnd,
                        'Honoraria.id IS NOT NULL',
                    ]
                )->contain(['Honoraria'])->all()->toList();
            $months[$nextMonth] = count($results);
        }

        $this->set(compact('months'));
    }

    /**
     * Approved and completed events that ask for a honorarium.
     *
     * @return \Cake\Http\Response|null
     */
    public function acceptedHonoraria()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $event->getSubject()->query->where(
                    [
                        'Events.part_of_id IS NULL',
                        'Events.status IN' => ['approved', 'completed'],
                        'Honoraria.id IS NOT NULL',
                    ]
                )
                    ->contain(['Honoraria']);

                if (!$this->request->getQuery('sort')) {
                    $event->getSubject()->query->orderBy(['event_start' => 'DESC']);
                }

                $this->paginate['limit'] = 50;
            }
        );

        return $this->Crud->execute();
    }

    /**
     * Rejected events that asked for a honorarium.
     *
     * @return \Cake\Http\Response|null
     */
    public function rejectedHonoraria()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                $event->getSubject()->query->where(
                    [
                        'Events.part_of_id IS NULL',
                        'Events.status' => 'rejected',
                        'Honoraria.id IS NOT NULL',
                    ]
                )
                    ->contain(['Honoraria']);

                if (!$this->request->getQuery('sort')) {
                    $event->getSubject()->query->orderBy(['event_start' => 'DESC']);
                }

                $this->paginate['limit'] = 50;
            }
        );

        return $this->Crud->execute();
    }

    /**
     * Completed events with a honorarium whose start lies in the (Chicago) date range, oldest first. Shared by the
     * finance view and the CSV export (they were two copies of this query in the Cake 3 build).
     *
     * @param string $startDate Y-m-d, first day (Chicago time)
     * @param string $endDate Y-m-d, last day (Chicago time)
     * @return \Cake\ORM\Query\SelectQuery
     */
    private function exportQuery(string $startDate, string $endDate)
    {
        $start = new \DateTime($startDate . ' 00:00:00', new \DateTimeZone('America/Chicago'));
        $start->setTimezone(new \DateTimeZone('UTC'));
        $end = new \DateTime($endDate . ' 23:59:59', new \DateTimeZone('America/Chicago'));
        $end->setTimezone(new \DateTimeZone('UTC'));

        return $this->Events->find('all')
            ->contain(
                [
                    'Honoraria',
                    'Contacts',
                    'Honoraria.Committees',
                    'Registrations' => function ($q) {
                        return $q->where(['Registrations.attended' => 1]);
                    },
                    'OldRegistrations' => function ($q) {
                        return $q->where(['OldRegistrations.status' => 'confirmed']);
                    },
                ]
            )
            ->where(
                [
                    'Events.status' => 'completed',
                    'Events.event_start >=' => $start->format('Y-m-d H:i:s'),
                    'Events.event_start <=' => $end->format('Y-m-d H:i:s'),
                    'Honoraria.id IS NOT' => null,
                ]
            )
            ->orderBy(
                [
                    'Events.event_start' => 'ASC',
                ]
            );
    }
}
