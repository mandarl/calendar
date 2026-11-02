<?php
declare(strict_types=1);

namespace App\View\Widget;

use Cake\View\Form\ContextInterface;
use Cake\View\Widget\RadioWidget as CoreRadioWidget;

/**
 * Radio group with Bootstrap 3 `radio-inline` support (BootstrapUI 1.4.3 port).
 */
// _renderLabel signature matches the Cake 5 parent (array $radio,
// array|string|bool|null $label); the previous ArrayAccess|array / false
// variant fataled on every page using radio widgets (e.g. /events/edit).
class RadioWidget extends CoreRadioWidget
{
    protected bool $_inline = false;

    /**
     * @inheritDoc
     */
    public function render(array $data, ContextInterface $context): string
    {
        $data += [
            'inline' => false,
        ];

        $this->_inline = (bool)$data['inline'];
        unset($data['inline']);

        return parent::render($data, $context);
    }

    /**
     * @inheritDoc
     */
    protected function _renderLabel(
        array $radio,
        array|string|bool|null $label,
        string $input,
        ContextInterface $context,
        bool $escape,
    ): string|false {
        if ($this->_inline) {
            $label = [
                'text' => $radio['text'],
                'class' => 'radio-inline',
            ];
        }

        return parent::_renderLabel($radio, $label, $input, $context, $escape);
    }
}
