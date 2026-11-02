<?php
namespace App\Controller;

use App\Controller\AppController;
use Cake\Event\EventInterface;

/**
 * Contacts Controller
 *
 * @property \App\Model\Table\ContactsTable $Contacts
 */
class ContactsController extends AppController
{
    public array $paginate = [
        'maxLimit' => PHP_INT_MAX
    ];

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
    }


    public function index()
    {
        $this->Crud->on('beforePaginate', function (\Cake\Event\EventInterface $event) {
            $event->getSubject()->query->orderBy(['name' => 'ASC']);

            $this->paginate['limit'] = 2147483647;
        });

        return $this->Crud->execute();
    }

    /**
     * View method
     *
     * @return \Cake\Network\Response|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Network\Exception\NotFoundException When record not found.
     */
    public function view($id)
    {
        $this->Crud->on('beforeFind', function (\Cake\Event\EventInterface $event) {
            $event->getSubject()->query = $this->fetchTable('Contacts')->find()
                ->where(['Contacts.ad_username' => $event->getSubject()->id]);
        });

        $attended = $this->fetchTable('Registrations')->find()
            ->where(['Registrations.ad_username' => $id])
            ->contain(['Events'])
            ->all();
        $this->set('attended', $attended);

        $hosted = $this->fetchTable('Events')->find()
            ->where(['Events.created_by' => $id])
            ->all();
        $this->set('hosted', $hosted);

        return $this->Crud->execute();
    }
}
