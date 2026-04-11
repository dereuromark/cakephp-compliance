<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('ROOT', dirname(__DIR__));
define('APP_DIR', 'tests');
define('TMP', sys_get_temp_dir() . DS . 'cakephp-compliance' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('SESSIONS', TMP . 'sessions' . DS);
define('CONFIG', ROOT . DS . 'tests' . DS . 'config' . DS);

foreach ([TMP, LOGS, CACHE, SESSIONS] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

Configure::write('App', [
    'namespace' => 'Compliance\\Test\\App',
    'encoding' => 'UTF-8',
    'defaultLocale' => 'en_US',
]);

Configure::write('debug', true);

Cache::setConfig([
    '_cake_core_' => ['className' => 'Null'],
    '_cake_model_' => ['className' => 'Null'],
    'default' => ['className' => 'Null'],
]);

ConnectionManager::setConfig('test', [
    'className' => 'Cake\\Database\\Connection',
    'driver' => 'Cake\\Database\\Driver\\Sqlite',
    'database' => ':memory:',
    'timezone' => 'UTC',
    'quoteIdentifiers' => false,
    'cacheMetadata' => false,
]);

ConnectionManager::alias('test', 'default');
