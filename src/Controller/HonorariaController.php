<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * Honoraria Controller
 *
 * Plain bake scaffold from the Cake 3 build, reachable at /honoraria[/action] through the DashedRoute fallbacks (not
 * linked from any menu). Calendar Admins only (RequestPolicy default rule). The honoraria workflow that the
 * Honorarium Admins use lives in EventsController (EventsHonorariaActionsTrait).
 *
 * @property \App\Model\Table\HonorariaTable $Honoraria
 */
class HonorariaController extends AppController
{
    /**
     * Index method
     *
     * @return void
     */
    public function index()
    {
        // Cake 3 passed 'contain' in $this->paginate; Cake 5 ignores that key, so it goes on the query.
        $honoraria = $this->paginate($this->Honoraria->find()->contain(['Events', 'Committees']));

        $this->set(compact('honoraria'));
        $this->viewBuilder()->setOption('serialize', ['honoraria']);
    }

    /**
     * View method
     *
     * @param string|null $id Honorarium id.
     * @return void
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $honorarium = $this->Honoraria->get($id, contain: ['Events', 'Committees']);

        $this->set('honorarium', $honorarium);
        $this->viewBuilder()->setOption('serialize', ['honorarium']);
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $honorarium = $this->Honoraria->newEmptyEntity();
        if ($this->request->is('post')) {
            $honorarium = $this->Honoraria->patchEntity($honorarium, $this->request->getData());
            if ($this->Honoraria->save($honorarium)) {
                $this->Flash->success(__('The honorarium has been saved.'));

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('The honorarium could not be saved. Please, try again.'));
            }
        }
        $this->setFormLists($honorarium);
    }

    /**
     * Edit method
     *
     * @param string|null $id Honorarium id.
     * @return \Cake\Http\Response|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $honorarium = $this->Honoraria->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $honorarium = $this->Honoraria->patchEntity($honorarium, $this->request->getData());
            if ($this->Honoraria->save($honorarium)) {
                $this->Flash->success(__('The honorarium has been saved.'));

                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('The honorarium could not be saved. Please, try again.'));
            }
        }
        $this->setFormLists($honorarium);
    }

    /**
     * Delete method
     *
     * @param string|null $id Honorarium id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $honorarium = $this->Honoraria->get($id);
        if ($this->Honoraria->delete($honorarium)) {
            $this->Flash->success(__('The honorarium has been deleted.'));
        } else {
            $this->Flash->error(__('The honorarium could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * The select lists of the add and edit forms.
     *
     * The Cake 3 templates also read $contacts, which the controller never set (an "undefined variable" notice and an
     * empty contact select). It is set to an empty list so the page renders the same without the notice.
     *
     * @param \App\Model\Entity\Honorarium $honorarium Entity
     * @return void
     */
    private function setFormLists($honorarium): void
    {
        $events = $this->Honoraria->Events->find('list')->limit(200);
        $committees = $this->Honoraria->Committees->find('list')->limit(200);
        $contacts = [];
        $this->set(compact('honorarium', 'events', 'committees', 'contacts'));
        $this->viewBuilder()->setOption('serialize', ['honorarium']);
    }
}
