<?php

namespace Example;

class OrderService
{
    public function __construct(
        private readonly PaymentProcessor $paymentProcessor,
        private readonly InventoryChecker $inventoryChecker,
        private readonly NotificationSender $notificationSender,
        private readonly OrderRepository $orderRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly PriceCalculator $priceCalculator,
        private readonly CouponValidator $couponValidator,
        private readonly OrderValidator $orderValidator,
        private readonly FulfillmentService $fulfillmentService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function placeOrder(
        array $items,
        string $customerId,
        string $shippingMethod = 'standard',
        ?string $couponCode = null,
    ): string {
        if (! $this->orderValidator->validateItems($items)) {
            throw new \InvalidArgumentException('Invalid items in order');
        }

        if (! $this->orderValidator->validateCustomer($customerId)) {
            throw new \InvalidArgumentException('Invalid or inactive customer');
        }

        $customer = $this->customerRepository->findById($customerId);
        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        $address = $this->customerRepository->getShippingAddress($customerId);

        if (! $this->orderValidator->validateShippingAddress($address)) {
            throw new \InvalidArgumentException('Invalid shipping address');
        }

        if (! $this->inventoryChecker->hasStock($items)) {
            throw new \RuntimeException('Insufficient stock for one or more items');
        }

        $total = $this->calculateTotal($items, $shippingMethod, $couponCode);

        $orderId = $this->generateOrderId();

        $charged = $this->paymentProcessor->charge($customerId, $total);
        if (! $charged) {
            throw new \RuntimeException('Payment failed');
        }

        $this->inventoryChecker->reserveItems($items, $orderId);
        $this->orderRepository->save($orderId, $customerId, $items, $total, 'confirmed');
        $this->customerRepository->updateOrderHistory($customerId, $orderId);

        if ($couponCode !== null && $this->couponValidator->validate($couponCode)) {
            $this->couponValidator->markAsUsed($couponCode, $orderId);
        }

        $this->fulfillmentService->scheduleShipment($orderId, $items, $address, $shippingMethod);
        $this->notificationSender->sendConfirmation($customerId, $orderId, $total);
        $this->auditLogger->logOrderPlaced($orderId, $customerId, $total);
        $this->auditLogger->logPaymentProcessed($orderId, $total);
        $this->auditLogger->logInventoryReserved($orderId, $items);

        return $orderId;
    }

    public function cancelOrder(string $orderId, string $customerId, string $reason = ''): void
    {
        $order = $this->orderRepository->findById($orderId);
        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        if ($order['customer_id'] !== $customerId) {
            throw new \RuntimeException('Order does not belong to this customer');
        }

        $this->paymentProcessor->refund($orderId);
        $this->inventoryChecker->releaseItems($orderId);
        $this->orderRepository->updateStatus($orderId, 'cancelled');
        $this->fulfillmentService->cancelShipment($orderId);
        $this->notificationSender->sendCancellation($customerId, $orderId);
        $this->auditLogger->logOrderCancelled($orderId, $reason ?: 'Customer request');
    }

    public function calculateTotal(
        array $items,
        string $shippingMethod = 'standard',
        ?string $couponCode = null,
        string $region = 'EU',
    ): float {
        $subtotal = $this->priceCalculator->calculateSubtotal($items);

        if ($couponCode !== null && $this->couponValidator->validate($couponCode)) {
            $subtotal = $this->priceCalculator->applyDiscount($subtotal, $couponCode);
        }

        $tax = $this->priceCalculator->calculateTax($subtotal, $region);
        $shipping = $this->priceCalculator->calculateShipping($items, $shippingMethod);

        return round($subtotal + $tax + $shipping, 2);
    }

    public function getOrderSummary(string $orderId): array
    {
        $order = $this->orderRepository->findById($orderId);
        if ($order === null) {
            throw new \RuntimeException('Order not found');
        }

        $estimate = $this->fulfillmentService->getShippingEstimate(
            $order['items'],
            $order['shipping_method'] ?? 'standard',
        );

        return [
            'order' => $order,
            'estimated_delivery' => $estimate->format('Y-m-d'),
        ];
    }

    private function generateOrderId(): string
    {
        return 'ORD-'.strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }
}
