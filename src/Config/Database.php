<?php
namespace App\Config;

use MongoDB\Client;
use MongoDB\Database as MongoDatabase;
use RuntimeException;

class Database {
    public static function getDatabase(): MongoDatabase {
        $host = getenv('MONGO_HOST') ?: '127.0.0.1';
        $port = getenv('MONGO_PORT') ?: '27017';
        $db   = getenv('MONGO_DB') ?: 'agrolocal';
        $user = getenv('MONGO_USER') ?: 'agro_user';
        $pass = getenv('MONGO_PASS');
        $authSource = getenv('MONGO_AUTH_SOURCE') ?: 'agrolocal';

        if (empty($pass)) {
            throw new RuntimeException("SECURITATE: Conexiunea la baza de date MongoDB fără parolă este strict interzisă!");
        }

        $uri = sprintf(
            "mongodb://%s:%s@%s:%s/%s?authSource=%s",
            rawurlencode($user),
            rawurlencode($pass),
            $host,
            $port,
            $db,
            $authSource
        );

        $client = new Client($uri);
        return $client->selectDatabase($db);
    }
}
