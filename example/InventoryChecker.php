<?php

namespace Example;

class InventoryChecker
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function hasStock(array $items): bool
    {
        foreach ($items as $item) {
            if ($this->getStockLevel($item['sku']) < $item['qty']) {
                return false;
            }
        }

        return true;
    }

    public function reserveItems(array $items, string $orderId): void
    {
        foreach ($items as $item) {
            $this->decrementStock($item['sku'], $item['qty']);
        }

        $this->auditLogger->logInventoryReserved($orderId, $items);
    }

    public function releaseItems(string $orderId): void
    {
        // Look up reserved items by orderId and return stock
        $this->auditLogger->logInventoryReleased($orderId);
    }

    public function getStockLevel(string $sku): int
    {
        // Stub: query warehouse system
        return 100;
    }

    private function decrementStock(string $sku, int $qty): void
    {
        // Stub: update warehouse system
    }
}
