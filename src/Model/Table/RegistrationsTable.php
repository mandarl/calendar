<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Registration;
use Cake\Core\Configure;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Registrations Model
 *
 * @property \Cake\ORM\Association\BelongsTo $Events
 */
class RegistrationsTable extends Table
{

    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('registrations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Events', ['joinType' => 'INNER']);
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
            ->requirePresence('event_id', 'create')
            ->notEmptyString('event_id');

        $validator
            ->requirePresence('type', 'create')
            ->notEmptyString('type')
            ->add('type', 'inList', [
                'rule' => ['inList', ['free', 'paid']]
            ]);

        $validator
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

         $validator
            ->add('email', 'validFormat', [
                'rule' => 'email',
                'message' => 'The provided email is not valid.'
            ])
            ->requirePresence('email', 'create')
            ->notEmptyString('email')
            ->add('email', ['unique' => [
                'rule' => ['validateUnique', ['scope' => 'event_id']],
                'provider' => 'table',
                'message' => 'The email address is already associated with a registration for this event.'
            ]]);

        $validator
            ->allowEmptyString('phone');

        $validator
            ->allowEmptyString('ad_username')
            ->add('ad_username', ['unique' => [
                'rule' => ['validateUnique', ['scope' => 'event_id']], 'provider' => 'table']
            ]);

        $validator
            ->boolean('send_text')
            ->requirePresence('send_text', 'create')
            ->notEmptyString('send_text');

        $validator
            ->alphaNumeric('edit_key')
            ->requirePresence('edit_key', 'create')
            ->notEmptyString('edit_key');

        $validator
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->add('status', 'inList', [
                'rule' => ['inList', ['confirmed', 'cancelled', 'pending']]
            ]);

        $validator
            ->allowEmptyString('attended');

        $validator
            ->boolean('attended')
            ->allowEmptyString('attended');

        $validator
            ->boolean('ad_assigned')
            ->allowEmptyString('ad_assigned');

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
        $rules->add($rules->existsIn(['event_id'], 'Events'));

        return $rules;
    }

    public function refund($id)
    {
        if ($this->exists(['id' => $id, 'status IN' => ['confirmed', 'pending']])) {
            $reg = $this->get($id);

            if ($reg->transaction_id) {
                // Slice R kit port (merged): void when still submitted for
                // settlement, otherwise refund; same semantics as the Cake 3
                // Braintree_* statics this replaces, via Dms\Kit\Payments.
                $braintree = \Dms\Kit\Payments\BraintreePayments::fromConfig(new \Dms\Kit\Payments\BraintreeConfig(
                    (string)Configure::read('Braintree.environment'),
                    (string)Configure::read('Braintree.merchantId'),
                    (string)Configure::read('Braintree.publicKey'),
                    (string)Configure::read('Braintree.privateKey')
                ));

                return $braintree->voidOrRefund($reg->transaction_id);
            }

            return true;
        }

        return true;
    }

    /**
     * Returns a boolean indictating whether or not a given registration is owned
     * by a user with a given AD Username or edit key.
     *
     * @param int $id The id of the event to check.
     * @param array $authorizations The username and edit key to check against.
     * @return boolean
     */
    public function isOwnedBy($id, $authorizations)
    {
        if (isset($authorizations['edit_key'])) {
            return $this->exists(['id' => $id, 'edit_key' => $authorizations['edit_key']]);
        }

        if (isset($authorizations['ad_username'])) {
            return $this->exists(['id' => $id, 'ad_username' => $authorizations['ad_username']]);
        }

        return false;
    }
}
