<?php

namespace Example;

class NotificationSender
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
    ) {}

    public function sendConfirmation(string $customerId, string $orderId, float $total): void
    {
        $customer = $this->customerRepository->findById($customerId);
        if ($customer === null) {
            return;
        }

        $this->dispatch('order_confirmed', $customer['email'], [
            'order_id' => $orderId,
            'total' => $total,
        ]);
    }

    public function sendCancellation(string $customerId, string $orderId): void
    {
        $customer = $this->customerRepository->findById($customerId);
        if ($customer === null) {
            return;
        }

        $this->dispatch('order_cancelled', $customer['email'], [
            'order_id' => $orderId,
        ]);
    }

    public function sendShippingUpdate(string $customerId, string $orderId, string $trackingCode): void
    {
        $customer = $this->customerRepository->findById($customerId);
        if ($customer === null) {
            return;
        }

        $this->dispatch('order_shipped', $customer['email'], [
            'order_id' => $orderId,
            'tracking_code' => $trackingCode,
        ]);
    }

    private function dispatch(string $template, string $email, array $data): void
    {
        // Stub: send via Mailgun / SES / etc.
    }
}
