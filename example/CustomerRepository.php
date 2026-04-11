<?php

namespace Example;

class CustomerRepository
{
    public function findById(string $customerId): ?array
    {
        // Stub: query database
        return null;
    }

    public function findByEmail(string $email): ?array
    {
        // Stub: query database
        return null;
    }

    public function getShippingAddress(string $customerId): array
    {
        // Stub: query customer's default shipping address
        return [];
    }

    public function updateOrderHistory(string $customerId, string $orderId): void
    {
        // Stub: append orderId to customer's order history
    }

    public function isActive(string $customerId): bool
    {
        $customer = $this->findById($customerId);

        return $customer !== null && ($customer['active'] ?? false);
    }
}
