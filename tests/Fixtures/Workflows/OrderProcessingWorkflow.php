<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Workflows;

use Maestro\Workflow\Contracts\MergeableOutput;
use Maestro\Workflow\Contracts\SingleJobStep;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Contracts\WorkflowContext;

final readonly class ValidateOrderStep implements SingleJobStep
{
    public function __construct(
        private WorkflowContext $context,
    ) {}

    public function handle(): StepOutput
    {
        return new ValidateOrderOutput(
            orderId: $this->context->get('order_id'),
            isValid: true,
        );
    }
}

final readonly class ValidateOrderOutput implements StepOutput
{
    public function __construct(
        public string $orderId,
        public bool $isValid,
    ) {}
}

final readonly class ReserveInventoryStep implements SingleJobStep
{
    public function __construct(
        private WorkflowContext $context,
        private ValidateOrderOutput $validateOutput,
    ) {}

    public function handle(): StepOutput
    {
        return new ReserveInventoryOutput(
            orderId: $this->validateOutput->orderId,
            reservationId: 'RES-'.uniqid(),
            itemsReserved: 3,
        );
    }
}

final readonly class ReserveInventoryOutput implements StepOutput
{
    public function __construct(
        public string $orderId,
        public string $reservationId,
        public int $itemsReserved,
    ) {}
}

final readonly class ProcessPaymentStep implements SingleJobStep
{
    public function __construct(
        private ValidateOrderOutput $validateOutput,
        private ReserveInventoryOutput $inventoryOutput,
    ) {}

    public function handle(): StepOutput
    {
        return new ProcessPaymentOutput(
            orderId: $this->validateOutput->orderId,
            transactionId: 'TXN-'.uniqid(),
            amount: 99.99,
        );
    }
}

final readonly class ProcessPaymentOutput implements StepOutput
{
    public function __construct(
        public string $orderId,
        public string $transactionId,
        public float $amount,
    ) {}
}

final readonly class ShipOrderStep implements SingleJobStep
{
    public function __construct(
        private ReserveInventoryOutput $inventoryOutput,
        private ProcessPaymentOutput $paymentOutput,
    ) {}

    public function handle(): StepOutput
    {
        return new ShipOrderOutput(
            orderId: $this->paymentOutput->orderId,
            trackingNumber: 'TRACK-'.uniqid(),
            carrier: 'FedEx',
        );
    }
}

final readonly class ShipOrderOutput implements StepOutput
{
    public function __construct(
        public string $orderId,
        public string $trackingNumber,
        public string $carrier,
    ) {}
}

final readonly class SendNotificationStep implements SingleJobStep
{
    public function __construct(
        private ShipOrderOutput $shipOutput,
    ) {}

    public function handle(): StepOutput
    {
        return new SendNotificationOutput(
            orderId: $this->shipOutput->orderId,
            notificationSent: true,
            channel: 'email',
        );
    }
}

final readonly class SendNotificationOutput implements StepOutput
{
    public function __construct(
        public string $orderId,
        public bool $notificationSent,
        public string $channel,
    ) {}
}
