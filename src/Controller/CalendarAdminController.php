<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;

/**
 * Calendar Admin Controller: the "Super Calendar Admin" settings page (Calendar Super Admins only, RequestPolicy).
 *
 * The page edits two rows that are addressed by id, as in the Cake 3 build: configuration 7 ("Allow Honoraria") and
 * calendar super configuration 1 (the honoraria message).
 *
 * @property \App\Model\Table\ConfigurationsTable $Configurations
 */
class CalendarAdminController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $this->FormProtection->setConfig('unlockedActions', ['edit']);
    }

    public function edit()
    {
        $configurations = $this->fetchTable('Configurations');
        $calendarConfigurations = $this->fetchTable('CalendarSuperConfigurations');

        if ($this->request->getData('id')) {
            $configuration = $configurations->get(7);

            $configuration->value = $this->request->getData('value');
            $configurations->save($configuration);

            $message = $calendarConfigurations->get(1);

            $message->value = $this->request->getData('Honoraria.message');
            $calendarConfigurations->save($message);

            $this->Flash->success('Updated successfully');
        }

        // get the message
        $message = $calendarConfigurations->find()->where(['id' => 1])->first();

        $this->set('message', $message->value);

        $this->set('configuration', $configurations->find()->where(['id' => 7])->first());
    }
}
