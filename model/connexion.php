<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/EnvLoader.php';

EnvLoader::load(dirname(__DIR__) . '/.env');

class Database
{
    private ?MongoDB\Client $client = null;

    public function getClient(): MongoDB\Client
    {
        $uri = EnvLoader::get('MONGODB_URI');
        $databaseName = EnvLoader::get('MONGODB_DATABASE');

        if ($uri === null || $uri === '' || $databaseName === null || $databaseName === '') {
            throw new RuntimeException('MONGODB_URI et MONGODB_DATABASE doivent être configurés.');
        }

        $this->client ??= new MongoDB\Client($uri, [], [
            'serverSelectionTimeoutMS' => 5000,
        ]);

        return $this->client;
    }

    public function getConnection(): MongoDB\Database
    {
        $databaseName = EnvLoader::get('MONGODB_DATABASE');
        if ($databaseName === null || $databaseName === '') {
            throw new RuntimeException('MONGODB_DATABASE doit être configuré.');
        }

        return $this->getClient()->selectDatabase($databaseName);
    }
}
