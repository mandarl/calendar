<div class="contacts form">
    <h2>
        Add Contact
        <?= $this->Html->link('List Contacts',
            ['action' => 'index'],
            ['class' => 'btn btn-primary pull-right']
        ) ?>
    </h2>
    <?= $this->Form->create($contact) ?>
    <fieldset>
        <?= $this->Form->control('name') ?>
        <?= $this->Form->control('email') ?>
        <?= $this->Form->control('phone') ?>
        <?= $this->Form->control('w9_on_file') ?>
    </fieldset>
    <?= $this->Form->button(__('Add New Contact'), ['class' => 'btn btn-success']) ?>
    <?= $this->Form->end() ?>
</div>
