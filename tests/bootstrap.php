<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

define('ROOT', dirname(__DIR__));
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('PLUGIN_ROOT', dirname(__DIR__));
define('APP', __DIR__);
define('TMP', sys_get_temp_dir() . DS);
define('LOGS', TMP . 'logs' . DS);
define('CONFIG', PLUGIN_ROOT . DS . 'config' . DS);
