<?php
/**
 * Shared Bootstrap 3 alert markup for the flash elements (was BootstrapUI.Flash/default in the Cake 3 build).
 *
 * @var \App\View\AppView $this
 * @var string $message
 * @var array $params
 * @var string $type alert style: info, danger, success, warning
 */
$classes = array_unique(array_merge(
    ['alert', 'alert-dismissible', 'fade', 'in', 'alert-' . $type],
    (array)($params['class'] ?? [])
));
$message = (isset($params['escape']) && $params['escape'] === false) ? $message : h($message);

$button = <<<BUTTON
<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
BUTTON;

echo $this->Html->div(implode(' ', $classes), $button . $message, ['role' => 'alert']);
