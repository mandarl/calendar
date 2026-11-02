<?php
declare(strict_types=1);

namespace App\View\Widget;

use App\View\Helper\OptionsAwareTrait;
use Cake\View\Form\ContextInterface;

/**
 * Adds `form-control` and the Bootstrap 3 input-group (`prepend` / `append` addons) to text-like widgets.
 * Ported from friendsofcake/bootstrap-ui 1.4.3 (MIT).
 */
trait InputgroupTrait
{
    use OptionsAwareTrait;

    /**
     * @param array<string, mixed> $data Widget data
     * @param \Cake\View\Form\ContextInterface $context Form context
     * @return string
     */
    protected function _withInputGroup(array $data, ContextInterface $context): string
    {
        $data += [
            'prepend' => null,
            'append' => null,
        ];

        if (!isset($data['type']) || $data['type'] !== 'hidden') {
            $data = $this->injectClasses('form-control', $data);
        }

        $prepend = $data['prepend'];
        $append = $data['append'];
        unset($data['append'], $data['prepend']);

        $input = parent::render($data, $context);

        if ($prepend) {
            $prepend = $this->_addon($prepend, $data);
        }

        if ($append) {
            $append = $this->_addon($append, $data);
        }

        if ($prepend || $append) {
            $input = $this->_templates->format('inputGroupContainer', [
                'append' => $append,
                'prepend' => $prepend,
                'content' => $input,
                'templateVars' => $data['templateVars'],
            ]);
        }

        return $input;
    }

    /**
     * @param array<string>|string $addon Addon html (or list of buttons)
     * @param array<string, mixed> $data Widget data
     * @return string
     */
    protected function _addon(array|string $addon, array $data): string
    {
        if (is_string($addon)) {
            $class = 'input-group-' . ($this->_isButton($addon) ? 'btn' : 'addon');
            $addon = $this->_templates->format('inputGroupAddon', [
                'class' => $class,
                'content' => $addon,
                'templateVars' => $data['templateVars'],
            ]);
        } else {
            $class = 'input-group-btn';
            $addon = $this->_templates->format('inputGroupAddon', [
                'class' => $class,
                'content' => implode('', $addon),
                'templateVars' => $data['templateVars'],
            ]);
        }

        return $addon;
    }

    /**
     * @param string $html Addon html
     * @return bool
     */
    protected function _isButton(string $html): bool
    {
        return str_contains($html, '<button') || str_contains($html, 'type="submit"');
    }
}
