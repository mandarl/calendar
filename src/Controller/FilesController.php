<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;

/**
 * Files Controller
 *
 * Only Crud's Delete action is enabled (called from the event edit page: /files/delete/FILE_ID/EVENT_ID).
 *
 * @property \App\Model\Table\FilesTable $Files
 */
class FilesController extends AppController
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $this->Crud->disable(['Index', 'Add', 'Edit', 'View']);
    }

    public function delete($id = null, $eventId = null)
    {
        // Remove file relation from related events (multi-part events)
        $this->Crud->on('beforeDelete', function (EventInterface $event) {
            $events = $this->fetchTable('Events');
            // Cake 3: $this->request->params['pass'][1]. A missing event id (null) selects every event that is not part
            // of a series, as it did before.
            $relatedEvents = $events->find('all')
                ->select(['id'])
                ->where(['part_of_id' => $this->request->getParam('pass')[1] ?? null]);

            $ids = [];

            foreach ($relatedEvents as $relatedEvent) {
                $ids[] = $relatedEvent->id;
            }

            if (!empty($ids)) {
                $this->Files->deleteAll([
                    'file' => $event->getSubject()->entity->file,
                    'event_id IN' => $ids,
                ]);
            }
        });

        $this->Crud->on('afterDelete', function (EventInterface $event) {
            // Delete the file if there are no remaining references to it
            $remainingReferences = $this->Files->find('all')
                ->where(['file' => $event->getSubject()->entity->file])
                ->count();

            if (!$remainingReferences) {
                // Cake 3: new \Cake\Filesystem\File(str_replace('webroot/', '', $dir) . $file)->delete(), a path relative to
                // the web root (the process working directory). Cake\Filesystem is gone in Cake 5: same target, absolute.
                $entity = $event->getSubject()->entity;
                $path = WWW_ROOT . str_replace('webroot/', '', (string)$entity->dir) . $entity->file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });

        return $this->Crud->execute();
    }
}
