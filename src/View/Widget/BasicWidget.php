<?php
declare(strict_types=1);

namespace App\View\Widget;

use Cake\View\Form\ContextInterface;
use Cake\View\Widget\BasicWidget as CoreBasicWidget;

/**
 * Text-like input with `form-control` and input-group support (BootstrapUI 1.4.3 port).
 */
class BasicWidget extends CoreBasicWidget
{
    use InputgroupTrait;

    /**
     * @inheritDoc
     */
    public function render(array $data, ContextInterface $context): string
    {
        return $this->_withInputGroup($data, $context);
    }
}
