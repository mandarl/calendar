<?php

declare(strict_types=1);

namespace Dms\Kit\Directory;

use RuntimeException;

/** EventsController.php ~L215: the old code indexed `$grouprec['dn']` on null (a PHP 8 warning, then Group::find(null)). */
final class GroupNotFound extends RuntimeException
{
}
