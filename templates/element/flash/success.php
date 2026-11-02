<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var array $params
 */
echo $this->element('flash/_alert', ['message' => $message, 'params' => $params, 'type' => 'success']);
