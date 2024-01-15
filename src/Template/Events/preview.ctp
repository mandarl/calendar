<?php use Cake\I18n\Time; ?>
<div class="events view">
    <div class="page-header">
        <h1>
            <?= h($event->name) ?>
        </h1>
    </div>
    <div class="alert alert-danger">
        <strong>Preview: </strong> This is an approximate preview or what the event will look like after submission. The actual event may look different depending on a number of factors.
    </div>
    <div class="row">
        <div class="col-sm-5">
            <table class="table table-condensed table-striped borderless">
                <tr>
                    <td><strong>When</strong></td>
                    <td>
                    <?php
                            $startdate = $this->Time->fromString($event->event_start, 'America/Chicago')->format('Ymd');
                            $enddate = $this->Time->fromString($event->event_end, 'America/Chicago')->format('Ymd');
                            if ($startdate == $enddate) {
                                $secondFormat = "h:mma";
                            } else {
                                $secondFormat = "E MMM d h:mma";
                            }
                        ?><?= str_replace(
                            [':00', 'AM', 'PM'],
                            ['', 'am', 'pm'],
                            $this->Time->format(
                                $event->event_start,
                                'E MMM d h:mma',
                                null,
                                'America/Chicago'
                            )
                        ) ?> —
                        <?= str_replace(
                            [':00', 'AM', 'PM'],
                            ['', 'am', 'pm'],
                            $this->Time->format(
                                $event->event_end,
                                $secondFormat,
                                null, 'America/Chicago'
                            )
                        )?>
                        
                    </td>
                </tr>
                <tr>
                    <td><strong>Where</strong></td>
                    <td>
                        <?php if ($event->room): ?>
                            <?= $event->room ?><br/>
                        <?php endif; ?>
                        <?php if ($event->address): ?>
                            <?= $event->address ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>What</strong></td>
                    <td><?= h($event->short_description) ?></td>
                </tr>
                <tr>
                    <td><strong>Host</strong></td>
                    <td><?= $event->contact ?></td>
                </tr>
                <tr>
                    <td><strong>Categories</strong></td>
                    <td>
                        <ul class="list-inline">
                            <?php foreach ($event->categories as $category): ?>
                                <?php if ($category[0] < 3): ?>
                                    <li><?= $this->Html->link($category[1], [
                                        'action' => 'index',
                                        '?' => ['type' => $category[0]]
                                    ]) ?></li>
                                <?php else: ?>
                                    <li><?= $this->Html->link($category[1], [
                                        'action' => 'index',
                                        '?' => ['category' => $category[0]]
                                    ]) ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
                <?php if ($event->tools): ?>
                    <tr>
                        <td><strong>Tools</strong></td>
                        <td>
                            <ul class="list-inline">
                                <?php foreach ($event->tools as $tool): ?>
                                    <li><?= $this->Html->link($tool[1], [
                                        'action' => 'index',
                                        '?' => ['tool' => $tool[0]]
                                    ]) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>

            <h3>Registration</h3>
            <ul class="list-unstyled">
				<?php
					$cost = $event->cost > 0 ? '$' . $event->cost . '.00' : 'Free';
					if ($event->eventbrite_link) {
						$cost = 'Paid through Eventbrite';
					}
				?>
                <li><strong>Cost:</strong> <?= $cost ?></li>
				<?php if ($event->eventbrite_link): ?>
					<li><strong>Eventbrite Registration:</strong> <a href="<?= $event->eventbrite_link ?>" target="_blank">Complete required third party registration</a></li>
					<li><strong>Considerations:</strong> This event's required fee is not processed through the DMS payment system and is completed through the host's Eventbrite account. Due to this, the host is responsible for any refunds that may be needed for this event.</li>
				<?php endif; ?>
                <?php if ($event->members_only || $event->attendees_require_approval || $event->age_restriction > 0): ?>
                    <li>
                        <strong>Restrictions:</strong>
                        <ul>
                            <?php if ($event->members_only): ?>
                                <li>DMS members only</li>
                            <?php endif; ?>
                            <?php if ($event->attendees_require_approval): ?>
                                <li>Attendees require approval from the event host</li>
                            <?php endif; ?>
                            <?php if ($event->age_restriction > 0): ?>
                                <li>Ages <?= $event->age_restriction ?>+</li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <p>
                <strong>Cancellations for this event must be made before <?= str_replace(
                    ['AM', 'PM'],
                    ['am', 'pm'],
                    $this->Time->format(
                        $event->attendee_cancellation,
                        'MMMM d, y — h:mma',
                        null, 'America/Chicago'
                    )
                )?>.</strong>
            </p>


            <?= $this->Html->link('Register for this Event', [
                'controller' => 'Registrations',
                'action' => 'event',
                $event->id
            ], [
                'class' => 'btn btn-lg btn-success',
                'style' => 'margin-top: 30px'
            ]) ?><?php
            if (is_int($event->total_spaces) && $event->total_spaces > 0):
                ?><p class="spaces_avaliable"><?= $event->total_spaces ?> spaces of <?= $event->total_spaces ?> available</p><?php
            endif;
            ?>
                
            <div>
                    <div>Add to calendar:</div>
                    <?php foreach ($addToCalLinks as $link): ?>
                        <span>
                            <a target="_blank" href="<?= $link['url'] ?>"><img alt="<?= $link['hint'] ?>" src="/img/<?= $link["icon"] ?>"></a>
                        </span>
                    <?php endforeach; ?>
            </div>
        </div>
        <div class="col-sm-7">
            <h3 class="column-heading">About this Event</h3>
            <?= nl2br(preg_replace(
                "~[[:alpha:]]+://[^<>[:space:]]+[[:alnum:]/]~",
                "<a href=\"\\0\" target=\"_blank\">\\0</a>",
                h($event->long_description)
            )) ?>

            <?php if ($event->advisories): ?>
                <h3>Special Considerations and Warnings</h3>
                <div class="alert alert-warning">
                    <?= nl2br(h($event->advisories)) ?>
                </div>
            <?php endif; ?>


            <?php
            $imageEndings = ["png", "jpeg", "jpg", "gif", "webp", "svg"];
            $images = [];
            $otherFiles = [];

            foreach ($event->files as $file) {
                if ($file->private && !$canManageEvents && $event->created_by != $authUsername) {
                    continue;
                }

                $exploded = explode(".", $file->file);
                $imageEnding = ($exploded !== false && count($exploded) >= 2) ? $exploded[count($exploded) - 1] : '';
                if (in_array($imageEnding, $imageEndings)) {
                    $images[] = $file;
                } else {
                    $otherFiles[] = $file;
                }
            }
            ?>

            <?php if (count($otherFiles) > 0 || count($images) > 0) { ?>
                <hr>

                <?php if (count($otherFiles) > 0 ) { ?>
                <h3>File Attachments</h3>
                <div>
                    <ul>
                    <?php
                    foreach ($otherFiles as $otherFile) { ?>
                        <li><a href="/<?= str_replace("webroot/", "", $otherFile->dir) . $otherFile->file ?>"
                               target="_new"><?= h($otherFile->file) ?></a></li>
                    <?php } ?>
                    </ul>
                </div>
                <?php } ?>

                <?php if (count($images) > 0 ) { ?>
                <h3>Image Attachments</h3>
                <div>
                    <ul class="nav nav-tabs" role="tablist">
                        <?php
                        $index = 0;
                        foreach ($images as $image) {
                            $index++;
                            ?>
                            <li role="presentation" class="<?= $index == 1 ? "active" : ""; ?>">
                                <a href="#<?= $index ?>"
                                   aria-controls="<?= $index ?>"
                                   role="tab"
                                   data-toggle="tab">Image <?= $index ?></a>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                    <div class="tab-content">
                    <?php
                    $index = 0;
                    foreach ($images as $image) {
                        $index++;
                        ?>
                        <div role="tabpanel" class="tab-pane <?= $index == 1 ? "active" : "" ?>" id="<?= $index ?>">
                            <img class="img-responsive img-rounded" style="padding: 5px"
                                 src="/<?= str_replace("webroot/", "", $image->dir) . $image->file ?>">
                        </div>
                        <?php
                    }
                    ?>
                    </div>
                </div>
                <?php } ?>

            <?php } ?>
        </div>
    </div>
    <br/>
    <hr>
    <div>

    
</div>
