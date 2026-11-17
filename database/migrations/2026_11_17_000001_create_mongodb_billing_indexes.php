<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mongo = DB::connection('mongodb');
        // Preserve the legacy field. Missing/null targets are safe to backfill;
        // an existing new customer ID must never be replaced on a rerun.
        $users = $mongo->getCollection('users');
        // Replace the pre-review sparse index, including on repeated test runs.
        foreach ($users->listIndexes() as $index) {
            if ($index->getName() === 'stripe_customer_id_1' && $index->isSparse()) {
                $users->dropIndex('stripe_customer_id_1');
            }
        }
        $users->updateMany(['stripe_customer_id' => ['$in' => [null, '']]], ['$unset' => ['stripe_customer_id' => '']]);
        $users->updateMany([
            'stripe_id' => ['$type' => 'string', '$ne' => ''],
            '$or' => [['stripe_customer_id' => ['$exists' => false]], ['stripe_customer_id' => null]],
        ], [['$set' => ['stripe_customer_id' => '$stripe_id']]]);
        $users->createIndex(['stripe_customer_id' => 1], ['unique' => true, 'partialFilterExpression' => ['stripe_customer_id' => ['$type' => 'string']]]);
        $mongo->getCollection('subscriptions')->createIndex(['stripe_subscription_id' => 1], ['unique' => true]);
        $mongo->getCollection('subscriptions')->createIndex(['user_id' => 1]);
        // processed_stripe_events uses Stripe event ID as Mongo's unique _id.
        $mongo->getCollection('processed_stripe_events')->createIndex(['type' => 1]);
    }

    public function down(): void
    {
        $mongo = DB::connection('mongodb');
        $mongo->getCollection('users')->dropIndex('stripe_customer_id_1');
        $mongo->getCollection('subscriptions')->dropIndex('stripe_subscription_id_1');
        $mongo->getCollection('subscriptions')->dropIndex('user_id_1');
        $mongo->getCollection('processed_stripe_events')->dropIndex('type_1');
    }
};
