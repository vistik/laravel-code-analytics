<?php

namespace Example;

class OrderValidator
{
    private const MAX_ITEMS_PER_ORDER = 50;
    private const MAX_QTY_PER_ITEM = 20;

    public function __construct(
        private readonly CustomerRepository $customerRepository,
        private readonly InventoryChecker $inventoryChecker,
    ) {}

    public function validateItems(array $items): bool
    {
        if (empty($items) || count($items) > self::MAX_ITEMS_PER_ORDER) {
            return false;
        }

        foreach ($items as $item) {
            if (empty($item['sku']) || empty($item['price']) || empty($item['qty'])) {
                return false;
            }

            if ($item['qty'] > self::MAX_QTY_PER_ITEM || $item['price'] <= 0) {
                return false;
            }

            if (!$this->inventoryChecker->hasStock([$item])) {
                return false;
            }
        }

        return true;
    }

    public function validateCustomer(string $customerId): bool
    {
        return $this->customerRepository->isActive($customerId);
    }

    public function validateShippingAddress(array $address): bool
    {
        $required = ['street', 'city', 'country', 'postal_code'];

        foreach ($required as $field) {
            if (empty($address[$field])) {
                return false;
            }
        }

        return $this->isSupportedCountry($address['country']);
    }

    private function isSupportedCountry(string $countryCode): bool
    {
        $supported = ['DK', 'SE', 'NO', 'DE', 'FR', 'GB', 'NL', 'US', 'CA'];

        return in_array(strtoupper($countryCode), $supported, true);
    }
}
