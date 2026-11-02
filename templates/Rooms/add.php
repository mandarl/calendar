<div class="rooms form">
    <h2>
        Add Room
        <?= $this->Html->link('List Rooms',
            ['action' => 'index'],
            ['class' => 'btn btn-primary pull-right']
        ) ?>
    </h2>
    <?= $this->Form->create($room) ?>
    <fieldset>
        <?= $this->Form->control('name') ?>
        <?= $this->Form->control('exclusive', ['label' => 'Exclusive Use - Only one event at a time in this room.']) ?>
    </fieldset>
    <?= $this->Form->button(__('Add New Room'), ['class' => 'btn btn-success']) ?>
    <?= $this->Form->end() ?>
</div>
