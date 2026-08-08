<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// PHPUnit applique la section <php> de la configuration après ce bootstrap : sans cette ligne,
// le kernel démarrerait dans l'environnement de .env (dev) et la configuration when@test
// (framework.test, base _test, DAMA) ne serait jamais chargée.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
