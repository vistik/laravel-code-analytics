<?php

namespace Example;

class CouponValidator
{
    private const VALID_COUPONS = [
        'SAVE10' => 0.10,
        'SAVE20' => 0.20,
        'HALFOFF' => 0.50,
    ];

    public function validate(string $couponCode): bool
    {
        return isset(self::VALID_COUPONS[$couponCode])
            && !$this->isExpired($couponCode)
            && !$this->isExhausted($couponCode);
    }

    public function getDiscountRate(string $couponCode): float
    {
        return self::VALID_COUPONS[$couponCode] ?? 0.0;
    }

    public function markAsUsed(string $couponCode, string $orderId): void
    {
        // Stub: record coupon usage in database
    }

    private function isExpired(string $couponCode): bool
    {
        // Stub: check expiry date in database
        return false;
    }

    private function isExhausted(string $couponCode): bool
    {
        // Stub: check usage count against limit
        return false;
    }
}
