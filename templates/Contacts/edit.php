<div class="contacts form">
    <h2>
        Edit Contact
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
        <?= $this->Form->control('blacklisted') ?>
    </fieldset>
    <?= $this->Form->button(__('Save Contact'), ['class' => 'btn btn-success']) ?>
    <?= $this->Form->end() ?>
</div>
