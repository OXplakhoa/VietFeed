<?php

namespace App\Billing;

interface StripeClient
{
    public function createCustomer(array $params, string $idempotencyKey): string;

    public function updateCustomer(string $id, array $params): void;

    public function createCheckout(array $params): string;

    public function createPortal(array $params): string;

    public function retrieveSubscription(string $id): array;

    /** Cancel immediately. A subscription that is already gone or canceled is success (account deletion retries this). */
    public function cancelSubscription(string $id, string $idempotencyKey): void;
}
