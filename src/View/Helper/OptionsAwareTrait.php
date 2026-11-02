<?php
declare(strict_types=1);

namespace App\View\Helper;

/**
 * CSS class helpers shared by the Bootstrap 3 form helper and widgets.
 *
 * Ported from friendsofcake/bootstrap-ui 1.4.3 (MIT), the Cake 3 plugin that gave the app its Bootstrap 3 markup.
 * BootstrapUI 4.x/5.x target Bootstrap 5, so the pieces the app uses were copied here instead of upgraded.
 */
trait OptionsAwareTrait
{
    /**
     * Class names that mark a button as already styled.
     *
     * @var array<string>
     */
    public array $buttonClasses = [
        'default', 'btn-default',
        'success', 'btn-success',
        'warning', 'btn-warning',
        'danger', 'btn-danger',
        'info', 'btn-info',
        'primary', 'btn-primary',
    ];

    /**
     * Short class names and the Bootstrap class they stand for.
     *
     * @var array<string, string>
     */
    public array $buttonClassAliases = [
        'default' => 'btn-default',
        'success' => 'btn-success',
        'warning' => 'btn-warning',
        'danger' => 'btn-danger',
        'info' => 'btn-info',
        'primary' => 'btn-primary',
    ];

    /**
     * Add `btn` (and `btn-default` unless a style class is given) to a button's options.
     *
     * @param array<string, mixed> $data Options
     * @return array<string, mixed>
     */
    public function applyButtonClasses(array $data): array
    {
        if ($this->hasAnyClass($this->buttonClasses, $data)) {
            $data = $this->injectClasses(['btn'], $data);
        } else {
            $data = $this->injectClasses(['btn', 'btn-default'], $data);
        }

        return $this->renameClasses($this->buttonClassAliases, $data);
    }

    /**
     * Rename classes through a map.
     *
     * @param array<string, string> $classMap Old => new
     * @param array<string, mixed> $options Options holding `class`
     * @return array<string, mixed>
     */
    public function renameClasses(array $classMap, array $options): array
    {
        $options += ['class' => []];
        $options['class'] = $this->_toClassArray($options['class']);

        $classes = [];
        foreach ($options['class'] as $name) {
            $classes[] = array_key_exists($name, $classMap) ? $classMap[$name] : $name;
        }

        $options['class'] = trim(implode(' ', $classes));

        return $options;
    }

    /**
     * True when any of the classes is present in the options' `class`.
     *
     * @param array<string>|string $classes Classes to look for
     * @param array<string, mixed> $options Options
     * @return bool
     */
    public function hasAnyClass(array|string $classes, array $options): bool
    {
        $options += ['class' => []];
        $options['class'] = $this->_toClassArray($options['class']);
        $classes = $this->_toClassArray($classes);

        foreach ($classes as $class) {
            if (in_array($class, $options['class'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add classes to the options' `class` (skipping duplicates and anything in `skip`).
     *
     * @param array<string>|string $classes Classes to add
     * @param array<string, mixed> $options Options
     * @return array<string, mixed>
     */
    public function injectClasses(array|string $classes, array $options): array
    {
        $options += ['class' => [], 'skip' => []];
        $options['class'] = $this->_toClassArray($options['class']);
        $options['skip'] = $this->_toClassArray($options['skip']);
        $classes = $this->_toClassArray($classes);

        foreach ($classes as $class) {
            if (!in_array($class, $options['class']) && !in_array($class, $options['skip'])) {
                array_push($options['class'], $class);
            }
        }

        unset($options['skip']);
        $options['class'] = trim(implode(' ', $options['class']));

        return $options;
    }

    /**
     * True when all the classes are present in the options' `class`.
     *
     * @param array<string>|string $classes Classes to look for
     * @param array<string, mixed> $options Options
     * @return bool
     */
    public function checkClasses(array|string $classes, array $options): bool
    {
        if (empty($options['class'])) {
            return false;
        }

        $options['class'] = $this->_toClassArray($options['class']);
        $classes = $this->_toClassArray($classes);

        foreach ($classes as $class) {
            if (!in_array($class, $options['class'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $mixed Class string or list
     * @return array<string>
     */
    protected function _toClassArray(mixed $mixed): array
    {
        if (!is_array($mixed)) {
            $mixed = explode(' ', (string)$mixed);
        }

        return $mixed;
    }
}
