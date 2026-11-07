<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/** Writes the last failure into the order's beliq custom fields (OrderFailureFields). */
final class OrderCustomFieldRecorder implements FailureRecorder
{
    /** @param EntityRepository<OrderCollection> $orderRepository */
    public function __construct(private readonly EntityRepository $orderRepository)
    {
    }

    public function recordFailure(string $orderId, string $description, int $at, ?int $nextAttemptAt, Context $context): void
    {
        $this->write($orderId, [
            OrderFailureFields::FAILED_AT => self::time($at),
            OrderFailureFields::FAILURE => $description,
            OrderFailureFields::NEXT_ATTEMPT_AT => $nextAttemptAt === null ? null : self::time($nextAttemptAt),
        ], $context);
    }

    public function clear(string $orderId, Context $context): void
    {
        $this->write($orderId, [
            OrderFailureFields::FAILED_AT => null,
            OrderFailureFields::FAILURE => null,
            OrderFailureFields::NEXT_ATTEMPT_AT => null,
        ], $context);
    }

    public function stopRetrying(string $orderId, Context $context): void
    {
        $this->write($orderId, [OrderFailureFields::NEXT_ATTEMPT_AT => null], $context);
    }

    /** @param array<string, ?string> $fields */
    private function write(string $orderId, array $fields, Context $context): void
    {
        // Custom fields merge on update, so the order's other custom fields stay.
        // System scope: the first attempt runs in a storefront or webhook request.
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($orderId, $fields): void {
            $this->orderRepository->update([['id' => $orderId, 'customFields' => $fields]], $context);
        });
    }

    private static function time(int $unix): string
    {
        return (new \DateTimeImmutable('@' . $unix))->format(\DateTimeInterface::ATOM);
    }
}
