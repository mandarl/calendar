<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper\PaginatorHelper as CorePaginatorHelper;
use Cake\View\View;

/**
 * Bootstrap 3 paginator markup (`ul.pagination`, `li.previous/next/active/disabled`).
 * Port of friendsofcake/bootstrap-ui 1.4.3's PaginatorHelper (MIT).
 */
class PaginatorHelper extends CorePaginatorHelper
{
    /**
     * @param \Cake\View\View $view The View this helper is being attached to.
     * @param array<string, mixed> $config Configuration settings for the helper.
     */
    public function __construct(View $view, array $config = [])
    {
        $this->_defaultConfig['templates'] = [
            'nextActive' => '<li class="next"><a rel="next" aria-label="Next" href="{{url}}">' .
                            '<span aria-hidden="true">{{text}}</span></a></li>',
            'nextDisabled' => '<li class="next disabled"><a><span aria-hidden="true">{{text}}</span></a></li>',
            'prevActive' => '<li class="previous"><a rel="prev" aria-label="Previous" href="{{url}}">' .
                            '<span aria-hidden="true">{{text}}</span></a></li>',
            'prevDisabled' => '<li class="previous disabled"><a><span aria-hidden="true">{{text}}</span></a></li>',
            'current' => '<li class="active"><span>{{text}} <span class="sr-only">(current)</span></span></li>',
        ] + $this->_defaultConfig['templates'];

        parent::__construct($view, $config + [
            'labels' => [
                'prev' => '&laquo;',
                'next' => '&raquo;',
            ],
        ]);
    }

    /**
     * Returns a set of numbers for the paged result set, wrapped in a Bootstrap `pagination` list.
     *
     * Extra options: `size` (lg, sm), `prev` / `next` (true for the default labels, or a label).
     *
     * @param array<string, mixed> $options Options
     * @return string
     */
    public function numbers(array $options = []): string
    {
        $class = 'pagination';

        $options += [
            'class' => $class,
            'after' => '</ul>',
            'size' => null,
        ];

        $options['class'] = implode(' ', (array)$options['class']);
        if (!empty($options['size'])) {
            $options['class'] .= " {$class}-{$options['size']}";
        }

        $options += [
            'before' => '<ul class="' . $options['class'] . '">',
        ];
        unset($options['class'], $options['size']);

        if (isset($options['prev'])) {
            if ($options['prev'] === true) {
                $options['prev'] = $this->getConfig('labels.prev');
            }
            $options['before'] .= $this->prev($options['prev'], ['escape' => false]);
        }

        if (isset($options['next'])) {
            if ($options['next'] === true) {
                $options['next'] = $this->getConfig('labels.next');
            }
            $options['after'] = $this->next($options['next'], ['escape' => false]) . $options['after'];
        }

        return parent::numbers($options);
    }
}
