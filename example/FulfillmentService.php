<?php

namespace Example;

class FulfillmentService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly PriceCalculator $priceCalculator,
        private readonly NotificationSender $notificationSender,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function scheduleShipment(
        string $orderId,
        array $items,
        array $address,
        string $shippingMethod = 'standard',
    ): string {
        $trackingCode = $this->generateTrackingCode();
        $shippingCost = $this->priceCalculator->calculateShipping($items, $shippingMethod);

        $order = $this->orderRepository->findById($orderId);
        if ($order === null) {
            throw new \RuntimeException('Cannot schedule shipment: order not found');
        }

        $this->orderRepository->updateStatus($orderId, 'fulfillment_scheduled');
        $this->auditLogger->logShipmentScheduled($orderId, $trackingCode);
        $this->notificationSender->sendShippingUpdate($order['customer_id'], $orderId, $trackingCode);

        return $trackingCode;
    }

    public function cancelShipment(string $orderId): void
    {
        $order = $this->orderRepository->findById($orderId);
        if ($order === null) {
            return;
        }

        $this->orderRepository->updateStatus($orderId, 'shipment_cancelled');
        $this->auditLogger->logOrderCancelled($orderId, 'Shipment cancelled');
    }

    public function getShippingEstimate(array $items, string $shippingMethod): \DateTimeImmutable
    {
        $shippingCost = $this->priceCalculator->calculateShipping($items, $shippingMethod);

        $days = match ($shippingMethod) {
            'overnight' => 1,
            'express' => 3,
            default => 5,
        };

        return new \DateTimeImmutable("+{$days} weekdays");
    }

    private function generateTrackingCode(): string
    {
        return 'TRK-'.strtoupper(substr(md5(uniqid('', true)), 0, 10));
    }
}
