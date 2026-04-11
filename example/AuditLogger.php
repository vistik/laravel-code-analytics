<?php

namespace Example;

class AuditLogger
{
    public function logOrderPlaced(string $orderId, string $customerId, float $amount): void
    {
        $this->write('order.placed', [
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'amount' => $amount,
        ]);
    }

    public function logOrderCancelled(string $orderId, string $reason): void
    {
        $this->write('order.cancelled', [
            'order_id' => $orderId,
            'reason' => $reason,
        ]);
    }

    public function logPaymentProcessed(string $orderId, float $amount): void
    {
        $this->write('payment.processed', [
            'order_id' => $orderId,
            'amount' => $amount,
        ]);
    }

    public function logPaymentRefunded(string $orderId): void
    {
        $this->write('payment.refunded', [
            'order_id' => $orderId,
        ]);
    }

    public function logInventoryReserved(string $orderId, array $items): void
    {
        $this->write('inventory.reserved', [
            'order_id' => $orderId,
            'item_count' => count($items),
        ]);
    }

    public function logInventoryReleased(string $orderId): void
    {
        $this->write('inventory.released', [
            'order_id' => $orderId,
        ]);
    }

    public function logShipmentScheduled(string $orderId, string $trackingCode): void
    {
        $this->write('shipment.scheduled', [
            'order_id' => $orderId,
            'tracking_code' => $trackingCode,
        ]);
    }

    private function write(string $event, array $context): void
    {
        // Stub: write to audit log / event stream
    }
}
