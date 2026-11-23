<?php

use App\Authorization\AuthorizationAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection('mongodb');
        $database->getCollection('users')->createIndex([AuthorizationAudit::USER_OUTBOX.'.0' => 1], [
            'name' => 'authorization_audit_embedded_pending', 'sparse' => true,
        ]);
        $database->getCollection(AuthorizationAudit::OUTBOX)->createIndex(['dead_at' => 1, 'attempts' => 1, '_id' => 1], [
            'name' => 'authorization_audit_delivery_queue',
        ]);
    }

    public function down(): void
    {
        $database = DB::connection('mongodb');
        foreach (['users' => 'authorization_audit_embedded_pending', AuthorizationAudit::OUTBOX => 'authorization_audit_delivery_queue'] as $collection => $index) {
            try {
                $database->getCollection($collection)->dropIndex($index);
            } catch (CommandException $e) {
                if (! in_array($e->getCode(), [26, 27], true)) {
                    throw $e;
                }
            }
        }
    }
};
