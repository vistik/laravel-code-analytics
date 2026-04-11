<?php

namespace Example;

class OrderRepository
{
    public function save(
        string $orderId,
        string $customerId,
        array $items,
        float $total,
        string $status,
    ): void {
        // Stub: persist to database
    }

    public function findById(string $orderId): ?array
    {
        // Stub: query database
        return null;
    }

    public function findByCustomerId(string $customerId): array
    {
        // Stub: query database
        return [];
    }

    public function updateStatus(string $orderId, string $status): void
    {
        // Stub: update record in database
    }
}
