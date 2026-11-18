<?php

namespace App\Console;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Gate 9.5 slice 5 (REVIEW-1 MAJOR-1): with MongoDB as the default connection, `db:wipe` and `migrate:fresh` drop the
 * whole Mongo database, and `migrate:reset` / `migrate:refresh` / `migrate:rollback` drop the unique indexes that carry
 * the data invariants. They are allowed only in the `testing` environment on the Mongo database `vietfeed_test`
 * (same check style as StoryPipeline::assertSafeTestStores); everywhere else they are refused (Prohibitable flag);
 * the safe reset is to point MONGO_DB / CASSANDRA_KEYSPACE / REDIS_* at fresh names (docs/database/nosql-local.md).
 */
final class DestructiveCommandGuard
{
    public const COMMANDS = ['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'];

    private static bool $listening = false;

    public static function allowed(Application $app): bool
    {
        return $app->environment('testing') && $app['config']->get('database.connections.mongodb.database') === 'vietfeed_test';
    }

    public static function apply(Application $app): void
    {
        DB::prohibitDestructiveCommands(! self::allowed($app));
        if (self::$listening) {
            return;
        }
        self::$listening = true;
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($app): void {
            if (in_array($event->command, self::COMMANDS, true) && ! self::allowed($app)) {
                $event->output->writeln('<error>Refused: '.$event->command.' would drop MongoDB data or indexes. Nothing was changed.</error>');
                $event->output->writeln('Safe reset: set MONGO_DB, CASSANDRA_KEYSPACE and REDIS_* to fresh names, then run '
                    .'`php artisan migrate --force`, the schema commands and `php artisan db:seed --force` '
                    .'(docs/database/nosql-local.md, "Gate 9.5 slice 5").');
            }
        });
    }
}
