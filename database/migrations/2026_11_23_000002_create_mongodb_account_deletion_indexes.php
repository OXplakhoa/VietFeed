<?php

use App\Accounts\AccountLifecycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    public function up(): void
    {
        $mongo = DB::connection('mongodb');
        // Worker discovery: due workflows by state and purge date, and pending workflows still owing the Stripe step.
        $workflows = $mongo->getCollection(AccountLifecycle::WORKFLOWS);
        $workflows->createIndex(['state' => 1, 'purge_after' => 1], ['name' => 'state_1_purge_after_1']);
        $workflows->createIndex(['state' => 1, 'requested_at' => 1], ['name' => 'state_1_requested_at_1']);
        // Billing rows are looked up by owner when cancelling and pseudonymizing.
        $mongo->getCollection('subscriptions')->createIndex(['user_id' => 1, 'status' => 1], ['name' => 'user_id_1_status_1']);
    }

    public function down(): void
    {
        $this->dropIndex(AccountLifecycle::WORKFLOWS, null);
        $this->dropIndex('subscriptions', 'user_id_1_status_1');
    }

    /** A rollback tolerates an index (or collection) that is already gone. */
    private function dropIndex(string $collection, ?string $index): void
    {
        try {
            $index === null
                ? DB::connection('mongodb')->getCollection($collection)->dropIndexes()
                : DB::connection('mongodb')->getCollection($collection)->dropIndex($index);
        } catch (CommandException $e) {
            if (! in_array($e->getCode(), [26, 27], true)) { // NamespaceNotFound, IndexNotFound
                throw $e;
            }
        }
    }
};
