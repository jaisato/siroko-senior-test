<?php

declare(strict_types=1);

// Gives phpstan-doctrine the real entity manager (mapping, custom types) so it
// can type-check DQL and repository calls against the actual metadata.

use Siroko\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Dotenv's second argument is only a fallback: .env sets APP_ENV=dev, so the
// test environment has to be forced before the files are read.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');
(new Dotenv())->usePutenv(true)->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel('test', false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
