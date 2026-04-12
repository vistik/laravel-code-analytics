<?php

namespace Example;

class DatabaseLogger implements LoggerInterface
{
    public function logOrderPlaced(string $orderId, string $customerId, float $amount): void
    {
        $this->insert('order.placed', [
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'amount' => $amount,
        ]);
    }

    public function logOrderCancelled(string $orderId, string $reason): void
    {
        $this->insert('order.cancelled', [
            'order_id' => $orderId,
            'reason' => $reason,
        ]);
    }

    public function logPaymentProcessed(string $orderId, float $amount): void
    {
        $this->insert('payment.processed', [
            'order_id' => $orderId,
            'amount' => $amount,
        ]);
    }

    public function logPaymentRefunded(string $orderId): void
    {
        $this->insert('payment.refunded', [
            'order_id' => $orderId,
        ]);
    }

    public function logInventoryReserved(string $orderId, array $items): void
    {
        $this->insert('inventory.reserved', [
            'order_id' => $orderId,
            'item_count' => count($items),
        ]);
    }

    public function logInventoryReleased(string $orderId): void
    {
        $this->insert('inventory.released', [
            'order_id' => $orderId,
        ]);
    }

    public function logShipmentScheduled(string $orderId, string $trackingCode): void
    {
        $this->insert('shipment.scheduled', [
            'order_id' => $orderId,
            'tracking_code' => $trackingCode,
        ]);
    }

    private function insert(string $event, array $context): void
    {
        // Stub: INSERT INTO audit_log (event, context, created_at) VALUES (...)
    }
}
