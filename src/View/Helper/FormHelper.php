<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\View\Widget\BasicWidget;
use App\View\Widget\ButtonWidget;
use App\View\Widget\RadioWidget;
use App\View\Widget\SelectBoxWidget;
use App\View\Widget\TextareaWidget;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Utility\Hash;
use Cake\View\Helper\FormHelper as CoreFormHelper;
use Cake\View\View;
use InvalidArgumentException;

/**
 * Bootstrap 3 FormHelper.
 *
 * Port of friendsofcake/bootstrap-ui 1.4.3's FormHelper (MIT) to CakePHP 5, so the forms keep the Bootstrap 3.3.6
 * markup (`form-group`, `form-control`, `help-block`, glyphicon tooltips, input groups, horizontal/inline forms).
 * BootstrapUI 4.x and 5.x generate Bootstrap 5 markup, which the owner has not approved.
 *
 * Extra control options: `append`, `prepend`, `inline`, `help`, `tooltip`.
 * `input()` no longer exists in Cake 5: templates call `control()`.
 */
class FormHelper extends CoreFormHelper
{
    use OptionsAwareTrait;

    /**
     * Set on `create()` to tell the type of alignment used (`default`, `horizontal`, `inline`).
     */
    protected ?string $_align = null;

    /**
     * Set on `create()` to tell the grid for horizontal forms.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $_grid = null;

    /**
     * Default Bootstrap string templates.
     *
     * @var array<string, string>
     */
    protected array $_bsTemplates = [
        // Cake 3 markup that Cake 5 changed (kept so pages stay comparable with the baseline):
        'hiddenBlock' => '<div style="display:none;">{{content}}</div>',
        'input' => '<input type="{{type}}" name="{{name}}"{{attrs}}/>',
        'inputSubmit' => '<input type="{{type}}"{{attrs}}/>',
        'checkboxFormGroup' => '{{label}}',
        'checkboxWrapper' => '<div class="checkbox">{{label}}</div>',
        'radioWrapper' => '{{label}}',
        'dateWidget' => '<ul class="list-inline"><li class="year">{{year}}</li><li class="month">{{month}}</li><li class="day">{{day}}</li><li class="hour">{{hour}}</li><li class="minute">{{minute}}</li><li class="second">{{second}}</li><li class="meridian">{{meridian}}</li></ul>',
        'error' => '<div class="help-block">{{content}}</div>',
        'label' => '<label{{attrs}}>{{text}}{{tooltip}}</label>',
        'help' => '<div class="help-block">{{content}}</div>',
        'tooltip' => '<span data-toggle="tooltip" title="{{content}}" class="glyphicon glyphicon-info-sign"></span>',
        'inputContainer' => '<div class="form-group {{type}}{{required}}">{{content}}{{help}}</div>',
        'inputContainerError' => '<div class="form-group {{type}}{{required}} has-error">{{content}}{{error}}{{help}}</div>',
        'radioInlineFormGroup' => '{{label}}<div class="radio-inline-wrapper">{{input}}</div>',
        'radioNestingLabel' => '<div class="radio">{{hidden}}<label{{attrs}}>{{input}}{{text}}{{tooltip}}</label></div>',
        'staticControl' => '<p class="form-control-static">{{content}}</p>',
        'inputGroupAddon' => '<span class="{{class}}">{{content}}</span>',
        'inputGroupContainer' => '<div class="input-group">{{prepend}}{{content}}{{append}}</div>',
    ];

    /**
     * Templates set per alignment type.
     *
     * @var array<string, array<string, string>>
     */
    protected array $_templateSet = [
        'default' => [
            'checkboxContainer' => '<div class="checkbox">{{content}}{{help}}</div>',
            'checkboxContainerError' => '<div class="checkbox has-error">{{content}}{{error}}{{help}}</div>',
        ],
        'inline' => [
            'label' => '<label class="sr-only"{{attrs}}>{{text}}{{tooltip}}</label>',
            'inputContainer' => '{{content}}',
        ],
        'horizontal' => [
            'label' => '<label class="control-label %s"{{attrs}}>{{text}}{{tooltip}}</label>',
            'formGroup' => '{{label}}<div class="%s">{{input}}{{error}}{{help}}</div>',
            'checkboxFormGroup' => '<div class="%s"><div class="checkbox">{{label}}</div>{{error}}{{help}}</div>',
            'submitContainer' => '<div class="form-group"><div class="%s">{{content}}</div></div>',
            'inputContainer' => '<div class="form-group {{type}}{{required}}">{{content}}</div>',
            'inputContainerError' => '<div class="form-group {{type}}{{required}} has-error">{{content}}</div>',
            'checkboxContainer' => '<div class="form-group{{required}}">{{content}}</div>',
            'checkboxContainerError' => '<div class="form-group{{required}} has-error">{{content}}</div>',
            'radioContainer' => '<div class="form-group{{required}}">{{content}}</div>',
            'radioContainerError' => '<div class="form-group{{required}} has-error">{{content}}</div>',
        ],
    ];

    /**
     * Default Bootstrap widgets.
     *
     * @var array<string, mixed>
     */
    protected array $_bsWidgets = [
        'button' => ButtonWidget::class,
        'radio' => [RadioWidget::class, 'nestingLabel'],
        'select' => SelectBoxWidget::class,
        'textarea' => TextareaWidget::class,
        '_default' => BasicWidget::class,
    ];

    /**
     * @param \Cake\View\View $view The View this helper is being attached to.
     * @param array<string, mixed> $config Configuration settings for the helper.
     */
    public function __construct(View $view, array $config = [])
    {
        $this->_defaultConfig = [
            'align' => 'default',
            'errorClass' => null,
            // Cake 3 did not emit the HTML5 custom-validity attributes (oninvalid/oninput/data-validity-message).
            'autoSetCustomValidity' => false,
            'grid' => [
                'left' => 2,
                'middle' => 6,
                'right' => 4,
            ],
            'templates' => $this->_bsTemplates + $this->_defaultConfig['templates'],
        ] + $this->_defaultConfig;

        if (isset($this->_defaultConfig['templateSet'])) {
            $this->_defaultConfig['templateSet'] = Hash::merge($this->_templateSet, $this->_defaultConfig['templateSet']);
        } else {
            $this->_defaultConfig['templateSet'] = $this->_templateSet;
        }

        $this->_defaultWidgets = $this->_bsWidgets + $this->_defaultWidgets;

        parent::__construct($view, $config);
    }

    /**
     * @inheritDoc
     */
    public function create(mixed $context = null, array $options = []): string
    {
        $options += [
            'class' => null,
            'role' => 'form',
            'align' => null,
            'templates' => [],
        ];

        $out = parent::create($context, $this->_formAlignment($options));

        // Cake 3 treated a POST form like PUT/DELETE and emitted a hidden `_method=POST` input before the CSRF field.
        $tagEnd = (int)strpos($out, '>') + 1;
        $start = substr($out, 0, $tagEnd);
        $rest = substr($out, $tagEnd);
        if (str_starts_with($start, '<form') && str_contains($start, 'method="post"') && !str_contains($out, 'name="_method"')) {
            $hidden = $this->hidden('_method', ['value' => 'POST', 'secure' => static::SECURE_SKIP]);
            $block = '<div style="display:none;">';
            if (str_starts_with($rest, $block)) {
                $rest = $block . $hidden . substr($rest, strlen($block));
            } else {
                $rest = $block . $hidden . '</div>' . $rest;
            }
            $out = $start . $rest;
        }

        return $out;
    }

    /**
     * Cake 3 marked the CSRF hidden input autocomplete="off" (attribute order: name, autocomplete, value).
     *
     * @inheritDoc
     */
    protected function _csrfField(): string
    {
        $request = $this->_View->getRequest();
        $csrfToken = $request->getAttribute('csrfToken');
        if (!$csrfToken) {
            return '';
        }

        return $this->hidden('_csrfToken', [
            'autocomplete' => 'off',
            'value' => $csrfToken,
            'secure' => static::SECURE_SKIP,
        ]);
    }

    /**
     * Port of Cake 3 `FormHelper::postLink()` markup: a `display:none` form named `post_<uniqid>` and an onclick that
     * submits it (Cake 5 uses the `hidden` attribute and `requestSubmit()`, and adds data-confirm-message).
     *
     * @inheritDoc
     */
    public function postLink(string $title, array|string|null $url = null, array $options = []): string
    {
        $options += ['block' => null, 'confirm' => null];

        $requestMethod = 'POST';
        if (!empty($options['method'])) {
            $requestMethod = strtoupper($options['method']);
            unset($options['method']);
        }

        $confirmMessage = $options['confirm'];
        unset($options['confirm']);

        $formName = str_replace('.', '', uniqid('post_', true));
        $formOptions = [
            'name' => $formName,
            'style' => 'display:none;',
            'method' => 'post',
        ];
        if (isset($options['target'])) {
            $formOptions['target'] = $options['target'];
            unset($options['target']);
        }
        $templater = $this->templater();

        $restoreAction = $this->_lastAction;
        $this->_lastAction($url);

        $action = $templater->formatAttributes([
            'action' => $this->Url->build($url),
            'escape' => false,
        ]);

        $out = $this->formatTemplate('formStart', [
            'attrs' => $templater->formatAttributes($formOptions) . $action,
        ]);
        $out .= $this->hidden('_method', [
            'value' => $requestMethod,
            'secure' => static::SECURE_SKIP,
        ]);
        $out .= $this->_csrfField();

        $formTokenData = $this->_View->getRequest()->getAttribute('formTokenData');
        $restoreFormProtector = $this->formProtector;
        if ($formTokenData !== null) {
            $this->formProtector = $this->createFormProtector($formTokenData);
        }

        $fields = [];
        if (isset($options['data']) && is_array($options['data'])) {
            foreach (Hash::flatten($options['data']) as $key => $value) {
                $fields[$key] = $value;
                $out .= $this->hidden($key, ['value' => $value, 'secure' => static::SECURE_SKIP]);
            }
            unset($options['data']);
        }
        // Cake 3 marked the form-protection hidden inputs autocomplete="off" (end() does this for
        // regular forms; postLink() bypasses end(), so pass it here).
        $out .= $this->secure($fields, ['autocomplete' => 'off']);
        $out .= $this->formatTemplate('formEnd', []);

        $this->_lastAction = $restoreAction;
        $this->formProtector = $restoreFormProtector;

        if ($options['block']) {
            if ($options['block'] === true) {
                $options['block'] = __FUNCTION__;
            }
            $this->_View->append($options['block'], $out);
            $out = '';
        }
        unset($options['block']);

        $url = '#';
        $onClick = 'document.' . $formName . '.submit();';
        if ($confirmMessage) {
            $confirm = $this->_confirmCode($confirmMessage, $onClick);
        } else {
            $confirm = $onClick . ' ';
        }
        $confirm .= 'event.returnValue = false; return false;';
        $options['onclick'] = $this->templater()->format('confirmJs', [
            'confirmMessage' => $this->_cleanConfirmMessage((string)$confirmMessage),
            'formName' => $formName,
            'confirm' => $confirm,
        ]);

        $out .= $this->Html->link($title, $url, $options);

        return $out;
    }

    /**
     * Cake 3 `_confirm()` markup for postLink: `if (confirm(<json>)) { $okCode } `.
     */
    protected function _confirmCode(string $message, string $okCode): string
    {
        return 'if (confirm(' . $this->_cleanConfirmMessage($message) . ")) { {$okCode} } ";
    }

    /**
     * Cake 3 `Helper::_cleanConfirmMessage()`.
     */
    protected function _cleanConfirmMessage(string $message): string
    {
        return str_replace('\\\n', '\n', (string)json_encode($message));
    }

    /**
     * Overrides the parent method to add the `btn` classes to the submit button.
     *
     * @inheritDoc
     */
    public function submit(?string $caption = null, array $options = []): string
    {
        $options = $this->applyButtonClasses($options);

        return parent::submit($caption, $options);
    }

    /**
     * Generates a form control complete with label and wrapper div.
     *
     * Adds extra options besides the ones supported by the parent: `append`, `prepend`, `inline`, `help`, `tooltip`.
     *
     * @inheritDoc
     */
    public function control(string $fieldName, array $options = []): string
    {
        $options += [
            'prepend' => null,
            'append' => null,
            'inline' => null,
            'type' => null,
            'label' => null,
            'error' => null,
            'required' => null,
            'options' => null,
            'help' => null,
            'tooltip' => null,
            'templates' => [],
            'templateVars' => [],
        ];
        $options = $this->_parseOptions($fieldName, $options);

        $newTemplates = $options['templates'];
        if ($newTemplates) {
            $this->templater()->push();
            $templateMethod = is_string($options['templates']) ? 'load' : 'add';
            $this->templater()->{$templateMethod}($options['templates']);
            $options['templates'] = [];
        }

        switch ($options['type']) {
            case 'checkbox':
                if (!isset($options['inline'])) {
                    $options['inline'] = $this->checkClasses($options['type'] . '-inline', (array)$options['label']);
                }

                if ($options['inline']) {
                    $options['label'] = $this->injectClasses($options['type'] . '-inline', (array)$options['label']);
                    if (!isset($newTemplates['checkboxContainer'])) {
                        $options['templates']['checkboxContainer'] = '{{content}}';
                    }
                }
                unset($options['inline']);
                break;

            case 'radio':
                if ($options['inline'] && $this->_align !== 'horizontal') {
                    $options['templates']['formGroup'] = $this->templater()->get('radioInlineFormGroup');
                }
                if (!$options['inline']) {
                    $options['templates']['nestingLabel'] = $this->templater()->get('radioNestingLabel');
                }
                break;

            case 'select':
                if (isset($options['multiple']) && $options['multiple'] === 'checkbox') {
                    $options['type'] = 'multicheckbox';
                } else {
                    if ($options['label'] !== false && !str_contains((string)$this->getTemplates('label'), 'class=')) {
                        $options['label'] = $this->injectClasses('control-label', (array)$options['label']);
                    }
                }
                break;

            case 'multiselect':
                break;

            case 'textarea':
            default:
                if ($options['label'] !== false && !str_contains((string)$this->getTemplates('label'), 'class=')) {
                    $options['label'] = $this->injectClasses('control-label', (array)$options['label']);
                }
        }

        if ($options['help']) {
            $options['help'] = $this->templater()->format(
                'help',
                ['content' => $options['help']]
            );
        }

        if ($options['tooltip']) {
            $tooltip = $this->templater()->format(
                'tooltip',
                ['content' => $options['tooltip']]
            );
            $options['label']['templateVars']['tooltip'] = ' ' . $tooltip;
            unset($options['tooltip']);
        }

        // Cake 3 set maxlength on the input before id/class were appended (Cake 3 FormHelper::text()),
        // so the attribute order was required, maxlength, id, class. Cake 5 appends maxlength last
        // (BasicWidget::setMaxLength), giving required, id, class, maxlength. Pre-set it here so the
        // order matches the baseline; the widget then leaves the existing key alone.
        if (
            !array_key_exists('maxlength', $options)
            && in_array($options['type'], ['text', 'email', 'tel', 'url', 'search'], true)
        ) {
            $maxLength = $this->_getContext()->getMaxLength($fieldName);
            if ($maxLength !== null) {
                $options['maxlength'] = min($maxLength, 100000);
                // The id was already appended by _parseOptions(), so maxlength currently sits after
                // it; Cake 3 had maxlength before id. Reorder so maxlength precedes id.
                if (isset($options['id'])) {
                    $reordered = [];
                    foreach ($options as $key => $value) {
                        if ($key === 'id') {
                            $reordered['maxlength'] = $options['maxlength'];
                        }
                        if ($key !== 'maxlength') {
                            $reordered[$key] = $value;
                        }
                    }
                    $options = $reordered;
                }
            }
        }

        $result = parent::control($fieldName, $options);

        if ($newTemplates) {
            $this->templater()->pop();
        }

        return $result;
    }

    /**
     * Resets the form alignment and grid size.
     *
     * @inheritDoc
     */
    public function end(array $secureAttributes = []): string
    {
        // Cake 3 marked the form-protection hidden inputs autocomplete="off".
        $secureAttributes += ['autocomplete' => 'off'];
        $this->_align = $this->_grid = null;

        return parent::end($secureAttributes);
    }

    /**
     * Used to place plain text next to a label within a form.
     *
     * @param string $fieldName Name of a field, like this "modelname.fieldname"
     * @param array<string, mixed> $options Array of HTML attributes (`hiddenField` adds a hidden input).
     * @return string
     */
    public function staticControl(string $fieldName, array $options = []): string
    {
        $options += [
            'escape' => true,
            'required' => false,
            'secure' => true,
            'hiddenField' => true,
        ];

        $secure = $options['secure'];
        $hiddenField = $options['hiddenField'];
        unset($options['secure'], $options['hiddenField']);

        $options = $this->_initInputField(
            $fieldName,
            ['secure' => static::SECURE_SKIP] + $options
        );

        $content = $options['escape'] ? h($options['val']) : $options['val'];
        $static = $this->formatTemplate('staticControl', [
            'content' => $content,
        ]);

        if (!$hiddenField) {
            return $static;
        }

        if ($secure === true) {
            $this->_secure(true, $this->_secureFieldName($options['name']), (string)$options['val']);
        }

        $options['type'] = 'hidden';

        return $static . $this->widget('hidden', $options);
    }

    /**
     * @inheritDoc
     */
    protected function _getInput(string $fieldName, array $options): array|string
    {
        unset($options['help']);

        return parent::_getInput($fieldName, $options);
    }

    /**
     * @inheritDoc
     */
    protected function _groupTemplate(array $options): string
    {
        $groupTemplate = $options['options']['type'] . 'FormGroup';
        if (!$this->templater()->get($groupTemplate)) {
            $groupTemplate = 'formGroup';
        }

        return $this->templater()->format($groupTemplate, [
            'input' => $options['input'] ?? [],
            'label' => $options['label'],
            'error' => $options['error'],
            'templateVars' => $options['options']['templateVars'] ?? [],
            'help' => $options['options']['help'],
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function _inputContainerTemplate(array $options): string
    {
        $inputContainerTemplate = $options['options']['type'] . 'Container' . $options['errorSuffix'];
        if (!$this->templater()->get($inputContainerTemplate)) {
            $inputContainerTemplate = 'inputContainer' . $options['errorSuffix'];
        }

        return $this->templater()->format($inputContainerTemplate, [
            'content' => $options['content'],
            'error' => $options['error'],
            'required' => $options['options']['required'] ? ' required' : '',
            'type' => $options['options']['type'],
            'templateVars' => $options['options']['templateVars'] ?? [],
            'help' => $options['options']['help'],
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function _parseOptions(string $fieldName, array $options): array
    {
        $options = parent::_parseOptions($fieldName, $options);
        $options += ['id' => $this->_domId($fieldName)];
        if (is_string($options['label'])) {
            $options['label'] = ['text' => $options['label']];
        }

        return $options;
    }

    /**
     * Form alignment detector/switcher.
     *
     * @param array<string, mixed> $options Options.
     * @return array<string, mixed> Modified options.
     */
    protected function _formAlignment(array $options): array
    {
        if (!$options['align']) {
            $options['align'] = $this->_detectFormAlignment($options);
        }

        if (is_array($options['align'])) {
            $this->_grid = $options['align'];
            $options['align'] = 'horizontal';
        } elseif ($options['align'] === 'horizontal') {
            $this->_grid = $this->getConfig('grid');
        }

        if (!in_array($options['align'], ['default', 'horizontal', 'inline'])) {
            throw new InvalidArgumentException('Invalid `align` option value.');
        }

        $this->_align = $options['align'];

        unset($options['align']);

        $templates = $this->_config['templateSet'][$this->_align];
        if (is_string($options['templates'])) {
            $options['templates'] = (new PhpConfig())->read($options['templates']);
        }

        if ($this->_align === 'default') {
            $options['templates'] += $templates;

            return $options;
        }

        $options = $this->injectClasses('form-' . $this->_align, $options);

        if ($this->_align === 'inline') {
            $options['templates'] += $templates;

            return $options;
        }

        $offsetedGridClass = implode(' ', [$this->_gridClass('left', true), $this->_gridClass('middle')]);

        $templates['label'] = sprintf($templates['label'], $this->_gridClass('left'));
        $templates['formGroup'] = sprintf($templates['formGroup'], $this->_gridClass('middle'));
        foreach (['checkboxFormGroup', 'submitContainer'] as $value) {
            $templates[$value] = sprintf($templates[$value], $offsetedGridClass);
        }

        $options['templates'] += $templates;

        return $options;
    }

    /**
     * Returns a Bootstrap grid class (i.e. `col-md-2`).
     *
     * @param string $position One of `left`, `middle` or `right`.
     * @param bool $offset If true, will append `offset-` to the class.
     * @return string Classes.
     */
    protected function _gridClass(string $position, bool $offset = false): string
    {
        $class = 'col-%s-';
        if ($offset) {
            $class .= 'offset-';
        }

        if (isset($this->_grid[$position])) {
            return sprintf($class, 'md') . $this->_grid[$position];
        }

        $classes = [];
        foreach ($this->_grid as $screen => $positions) {
            if (isset($positions[$position])) {
                array_push($classes, sprintf($class, $screen) . $positions[$position]);
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Detects the form alignment when possible.
     *
     * @param array<string, mixed> $options Options.
     * @return string Form alignment type. One of `default`, `horizontal` or `inline`.
     */
    protected function _detectFormAlignment(array $options): string
    {
        foreach (['horizontal', 'inline'] as $align) {
            if ($this->checkClasses('form-' . $align, $options)) {
                return $align;
            }
        }

        return $this->getConfig('align');
    }
}
