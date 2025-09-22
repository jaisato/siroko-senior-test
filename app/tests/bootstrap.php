<?php
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';
$_SERVER['SYMFONY_DEPRECATIONS_HELPER'] = 'verbose';
putenv('APP_ENV=test');
putenv('APP_DEBUG=0');
putenv('SYMFONY_DEPRECATIONS_HELPER=verbose');

$dotenv = new Dotenv();
$dotenv->usePutenv(true)->bootEnv(dirname(__DIR__).'/.env');

if (!getenv('DATABASE_URL')) {
    $dsn = 'mysql://root:sVlPsF32847@db:3306/siroko_cart?serverVersion=8.0.43&charset=utf8mb4';
    putenv('DATABASE_URL='.$dsn);
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $dsn;
}
