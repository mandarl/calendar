<?php
declare(strict_types=1);

namespace App\View;

use App\View\Helper\FormHelper;
use App\View\Helper\HtmlHelper;
use App\View\Helper\PaginatorHelper;
use App\View\Helper\TimeHelper;
use Cake\I18n\DateTime;
use Cake\View\View;

/**
 * Application View
 *
 * Bootstrap 3.3.6 look: the Form and Paginator helpers are local ports of the BootstrapUI 1.x helpers (see the
 * class comments), and the flash messages use the Bootstrap alert elements in templates/element/flash/.
 *
 * @link https://book.cakephp.org/5/en/views.html#the-app-view
 */
class AppView extends View
{
    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        // Cake 3 rendered DateTime::__toString() via the old ICU default ("M/d/yy, h:mm a", plain
        // space, no zero-padding). The Cake 5 image's newer ICU renders the same default with a
        // narrow no-break space (U+202F) before AM/PM, which shows up wherever a template prints a
        // date with h($date) (e.g. Honoraria index, Events submitted "Submitted on:"). Pin the
        // explicit format that reproduces the Cake 3 output byte for byte (verified by differential
        // probe against the Cake 3 image on 5 instants incl. midnight/noon/single-digit dates).
        // If the lead prefers it app-wide rather than view-only, the same one-liner belongs in
        // config/bootstrap.php instead.
        DateTime::setToStringFormat('M/d/yy, h:mm a');

        $this->setLayout('default');
        // Cake 3 printed void elements XHTML-style (`<meta ... />`); keep that markup so pages stay byte-comparable with the baseline.
        $this->loadHelper('Html', ['className' => HtmlHelper::class, 'templates' => [
            'meta' => '<meta{{attrs}}/>',
            'metalink' => '<link href="{{url}}"{{attrs}}/>',
            'image' => '<img src="{{url}}"{{attrs}}/>',
            'css' => '<link rel="{{rel}}" href="{{url}}"{{attrs}}/>',
            'charset' => '<meta charset="{{charset}}"/>',
        ]]);
        $this->loadHelper('Form', ['className' => FormHelper::class]);
        $this->loadHelper('Paginator', ['className' => PaginatorHelper::class]);
        $this->loadHelper('Time', ['className' => TimeHelper::class]);
    }
}
