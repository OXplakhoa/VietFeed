<?php

// Local NoSQL connection stubs (Gate 1 / GitHub #1).
// Env-based only: no secrets committed. Placeholders live in .env.example;
// throwaway dev-defaults live in docker-compose.yml (local-only, 127.0.0.1).
// No client libraries are wired yet (T2 spikes Cassandra first).

return [

    'mongo' => [
        'host' => env('MONGO_HOST', '127.0.0.1'),
        'port' => env('MONGO_PORT', 27017),
        'username' => env('MONGO_ROOT_USER'),
        'password' => env('MONGO_ROOT_PASSWORD'),
        'database' => env('MONGO_DB', 'vietfeed'),
    ],

    'cassandra' => [
        // Local CQL runs WITHOUT auth (loopback-bound dev default, see docker-compose.yml).
        'host' => env('CASSANDRA_HOST', '127.0.0.1'),
        'port' => env('CASSANDRA_PORT', 9042),
        'cluster' => env('CASSANDRA_CLUSTER_NAME', 'vietfeed'),
        'keyspace' => env('CASSANDRA_KEYSPACE', 'vietfeed'),
    ],

    'neo4j' => [
        'host' => env('NEO4J_HOST', '127.0.0.1'),
        'bolt_port' => env('NEO4J_BOLT_PORT', 7687),
        'http_port' => env('NEO4J_HTTP_PORT', 7474),
        'username' => env('NEO4J_USER'),
        'password' => env('NEO4J_PASSWORD'),
    ],

    'redis_nosql' => [
        // The app's own cache/session/queue stay on the `database` driver.
        // This stub is for the future ephemeral realtime store (same server, own DB index).
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        'database' => env('REDIS_NOSQL_DB', 1),
    ],

];
