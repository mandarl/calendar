<?php
declare(strict_types=1);

namespace App\View\Widget;

use App\View\Helper\OptionsAwareTrait;
use Cake\View\Form\ContextInterface;
use Cake\View\Widget\ButtonWidget as CoreButtonWidget;

/**
 * Button with the Bootstrap 3 `btn btn-default` classes (BootstrapUI 1.4.3 port).
 */
class ButtonWidget extends CoreButtonWidget
{
    use OptionsAwareTrait;

    /**
     * @inheritDoc
     */
    public function render(array $data, ContextInterface $context): string
    {
        $data = $this->applyButtonClasses($data);

        return parent::render($data, $context);
    }
}
