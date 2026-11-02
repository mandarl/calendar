<?php
/**
 * Flash used by the authentication/authorization redirects. The Cake 3 AuthComponent flash rendered as a bare
 * alert div with class "error" (no Bootstrap alert styling, no close button).
 *
 * @var \App\View\AppView $this
 * @var string $message
 */
echo $this->Html->div('error', h($message), ['role' => 'alert']);
