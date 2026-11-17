<?php

namespace Tests\Doubles;

use App\Billing\StripeClient;
use App\Billing\SubscriptionMissing;

class FakeStripeClient implements StripeClient
{
    public array $calls = [];

    public ?\Closure $onRetrieve = null;

    public bool $down = false;

    public bool $updateDown = false;

    public bool $missing = false;

    public array $subscription = [];

    public function createCustomer(array $params, string $idempotencyKey): string
    {
        $this->calls[] = ['customer', $params, $idempotencyKey];
        if ($this->down) {
            throw new \RuntimeException('sensitive synthetic diagnostic');
        }

        return 'cus_gate7';
    }

    public function updateCustomer(string $id, array $params): void
    {
        $this->calls[] = ['update_customer', $id, $params];
        if ($this->updateDown || $this->down) {
            throw new \RuntimeException('sensitive synthetic diagnostic');
        }
    }

    public function createCheckout(array $params): string
    {
        $this->calls[] = ['checkout', $params];
        if ($this->down) {
            throw new \RuntimeException('sensitive synthetic diagnostic');
        }

        return 'https://checkout.stripe.com/test_gate7';
    }

    public function createPortal(array $params): string
    {
        $this->calls[] = ['portal', $params];
        if ($this->down) {
            throw new \RuntimeException('sensitive synthetic diagnostic');
        }

        return 'https://billing.stripe.com/test_gate7';
    }

    public function retrieveSubscription(string $id): array
    {
        $this->calls[] = ['retrieve', $id];
        $snapshot = $this->subscription;
        if ($this->onRetrieve) {
            ($this->onRetrieve)();
        }
        if ($this->missing) {
            throw new SubscriptionMissing('Synthetic missing subscription');
        }
        if ($this->down) {
            throw new \RuntimeException('sensitive synthetic diagnostic');
        }

        return $snapshot;
    }
}
