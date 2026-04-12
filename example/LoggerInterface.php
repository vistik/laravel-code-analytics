<?php

namespace Example;

interface LoggerInterface
{
    public function logOrderPlaced(string $orderId, string $customerId, float $amount): void;

    public function logOrderCancelled(string $orderId, string $reason): void;

    public function logPaymentProcessed(string $orderId, float $amount): void;

    public function logPaymentRefunded(string $orderId): void;

    public function logInventoryReserved(string $orderId, array $items): void;

    public function logInventoryReleased(string $orderId): void;

    public function logShipmentScheduled(string $orderId, string $trackingCode): void;
}
