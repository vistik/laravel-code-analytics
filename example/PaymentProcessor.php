<?php

namespace Example;

class PaymentProcessor
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function charge(string $customerId, float $amount): bool
    {
        if ($amount <= 0 || $customerId === '') {
            return false;
        }

        // Simulate calling payment gateway
        $success = $this->callGateway('charge', $customerId, $amount);

        if ($success) {
            $this->auditLogger->logPaymentProcessed('pending-'.$customerId, $amount);
        }

        return $success;
    }

    public function refund(string $orderId): bool
    {
        $success = $this->callGateway('refund', $orderId, 0.0);

        if ($success) {
            $this->auditLogger->logPaymentRefunded($orderId);
        }

        return $success;
    }

    public function verifyPaymentMethod(string $customerId): bool
    {
        return $this->callGateway('verify', $customerId, 0.0);
    }

    private function callGateway(string $action, string $reference, float $amount): bool
    {
        // Stub: in production this calls Stripe, Adyen, etc.
        return true;
    }
}
