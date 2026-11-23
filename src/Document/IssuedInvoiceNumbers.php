<?php declare(strict_types=1);

namespace Beliq\Shopware\Document;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * The invoice numbers that beliq documents of an order were issued under, kept in
 * an order custom field. A merchant can delete a document while the buyer still
 * holds the invoice, so the next number is worked out from these as well as from
 * the documents that remain. The field belongs to no custom field set, so the
 * Administration offers no input for it, and an uninstall leaves it on the order.
 */
final class IssuedInvoiceNumbers
{
    public const FIELD = 'beliq_invoice_issued_numbers';

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(private readonly EntityRepository $orderRepository)
    {
    }

    /**
     * Read from the database on each call, not from an order loaded earlier: the
     * renderer calls this under the order's lock, after another create may have
     * recorded a number.
     *
     * @return list<string>
     */
    public function forOrder(string $orderId, Context $context): array
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->getEntities()->first();
        $numbers = $order instanceof OrderEntity ? ($order->getCustomFields()[self::FIELD] ?? null) : null;
        if (!is_array($numbers)) {
            return [];
        }

        return array_values(array_filter($numbers, 'is_string'));
    }

    /**
     * @param list<string> $issued the numbers forOrder returned under the same lock
     */
    public function record(string $orderId, array $issued, string $number, Context $context): void
    {
        // Custom fields merge on update, so the order's other custom fields stay.
        // System scope: an automatic invoice is created in a storefront or webhook request.
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($orderId, $issued, $number): void {
            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => [self::FIELD => [...$issued, $number]],
            ]], $context);
        });
    }
}
