<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;

/**
 * Logs Controller: the audit log (every page view of a logged-in user), Calendar Super Admins only.
 */
class LogsController extends AppController
{
    public function index()
    {
        $this->Crud->on(
            'beforePaginate',
            function (EventInterface $event) {
                // The Cake 3 code read $_GET directly; the request holds the same values.
                $query = $this->request->getQuery();
                $startDate = $query['start_date'] ?? null;
                $endDate = $query['end_date'] ?? null;
                $userName = $query['user_name'] ?? null;
                $searchString = $query['search_string'] ?? null;

                if (!empty($startDate) || !empty($endDate) || !empty($userName) || !empty($searchString)) {
                    $where = [];

                    if (!empty($startDate) && !empty($endDate)) {
                        // Kept from Cake 3: the range starts at 23:59:59 of the start day.
                        $start = new \DateTime($startDate . ' 23:59:59', new \DateTimeZone('America/Chicago'));
                        $start->setTimezone(new \DateTimeZone('UTC'));

                        $end = new \DateTime($endDate . ' 23:59:59', new \DateTimeZone('America/Chicago'));
                        $end->setTimezone(new \DateTimeZone('UTC'));

                        $where['Logs.date_time >='] = $start->format('Y-m-d H:i:s');
                        $where['Logs.date_time <='] = $end->format('Y-m-d H:i:s');
                    }

                    if ($userName) {
                        $where['Logs.user LIKE'] = '%' . $userName . '%';
                    }

                    if ($searchString) {
                        $where['Logs.description LIKE'] = '%' . $searchString . '%';
                    }

                    $event->getSubject()->query
                        ->where($where)
                        ->orderBy(['Logs.date_time' => 'DESC']);
                    $this->paginate['limit'] = 50;
                } else {
                    $event->getSubject()->query->select([
                        'Logs.date_time',
                        'Logs.description',
                        'Logs.user',
                        'Logs.ip_address',
                        'Logs.url',
                        'Logs.controller',
                        'Logs.action',
                    ])
                        ->orderBy(['Logs.date_time' => 'DESC']);
                    $this->paginate['limit'] = 50;
                }
            }
        );

        return $this->Crud->execute();
    }
}
