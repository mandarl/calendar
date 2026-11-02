<?php
namespace App\Controller;

use App\Controller\AppController;
use Cake\Event\EventInterface;

/**
 * Prerequisites Controller
 *
 * @property \App\Model\Table\PrerequisitesTable $Prerequisites
 */
class PrerequisitesController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $this->Crud->disable(['View']);
    }


    public function index()
    {
        $this->Crud->on('beforePaginate', function (\Cake\Event\EventInterface $event) {
            $event->getSubject()->query->orderBy(['name' => 'ASC']);

            $this->paginate['limit'] = 2147483647;
        });

        return $this->Crud->execute();
    }
}
