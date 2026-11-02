<?php
/** In-memory Cake 3 log engine for the differential tests (namespaced: Cake 3 cannot resolve a global class name). */
namespace DiffTest;

class CaptureLog extends \Cake\Log\Engine\BaseLog
{
    public static $lines = [];

    public function log($level, $message, array $context = [])
    {
        self::$lines[] = $level . ': ' . $message;
    }
}
