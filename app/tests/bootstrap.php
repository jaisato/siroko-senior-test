<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Siroko\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// The test environment is not negotiable from the outside; the database is.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';
putenv('APP_ENV=test');
putenv('APP_DEBUG=0');

// Loads .env then .env.test; a DATABASE_URL already present in the process
// environment (CI's MySQL service) is left untouched.
(new Dotenv())->usePutenv(true)->bootEnv(dirname(__DIR__) . '/.env');

// Against SQLite the schema is created from the mapping, since the migrations
// are MySQL SQL. Against anything else the database is expected to be migrated
// already (`doctrine:migrations:migrate --env=test`), which is exactly what CI
// wants to exercise.
$kernel = new Kernel('test', false);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine.orm.entity_manager');

if ($em->getConnection()->getDatabasePlatform() instanceof SqlitePlatform) {
    $schemaTool = new SchemaTool($em);
    $schemaTool->dropDatabase();
    $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
}

$em->getConnection()->close();
$kernel->shutdown();
