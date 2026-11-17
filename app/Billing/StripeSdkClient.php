<?php

namespace App\Billing;

use Stripe\Exception\InvalidRequestException;

class StripeSdkClient implements StripeClient
{
    private function client(): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient(['api_key' => config('services.stripe.secret'), 'max_network_retries' => 1]);
    }

    public function createCustomer(array $params, string $idempotencyKey): string
    {
        return $this->client()->customers->create($params, ['idempotency_key' => $idempotencyKey])->id;
    }

    public function updateCustomer(string $id, array $params): void
    {
        $this->client()->customers->update($id, $params);
    }

    public function createCheckout(array $params): string
    {
        return $this->client()->checkout->sessions->create($params)->url;
    }

    public function createPortal(array $params): string
    {
        return $this->client()->billingPortal->sessions->create($params)->url;
    }

    public function retrieveSubscription(string $id): array
    {
        try {
            return $this->client()->subscriptions->retrieve($id)->toArray();
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                throw new SubscriptionMissing('Stripe subscription not found');
            }
            throw $e;
        }
    }
}
