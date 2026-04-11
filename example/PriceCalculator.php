<?php

namespace Example;

class PriceCalculator
{
    private const TAX_RATES = [
        'EU' => 0.20,
        'US' => 0.08,
        'UK' => 0.20,
    ];

    private const SHIPPING_RATES = [
        'standard' => 4.99,
        'express' => 12.99,
        'overnight' => 24.99,
    ];

    public function __construct(
        private readonly CouponValidator $couponValidator,
    ) {}

    public function calculateSubtotal(array $items): float
    {
        return array_sum(array_map(
            fn(array $item) => $item['price'] * $item['qty'],
            $items,
        ));
    }

    public function applyDiscount(float $subtotal, string $couponCode): float
    {
        if (!$this->couponValidator->validate($couponCode)) {
            return $subtotal;
        }

        $rate = $this->couponValidator->getDiscountRate($couponCode);

        return round($subtotal * (1 - $rate), 2);
    }

    public function calculateTax(float $amount, string $region): float
    {
        $rate = self::TAX_RATES[$region] ?? self::TAX_RATES['EU'];

        return round($amount * $rate, 2);
    }

    public function calculateShipping(array $items, string $shippingMethod): float
    {
        $base = self::SHIPPING_RATES[$shippingMethod] ?? self::SHIPPING_RATES['standard'];
        $weightSurcharge = $this->calculateWeightSurcharge($items);

        return round($base + $weightSurcharge, 2);
    }

    private function calculateWeightSurcharge(array $items): float
    {
        $totalWeight = array_sum(array_map(
            fn(array $item) => ($item['weight_kg'] ?? 0.5) * $item['qty'],
            $items,
        ));

        return $totalWeight > 5.0 ? ($totalWeight - 5.0) * 1.50 : 0.0;
    }
}
