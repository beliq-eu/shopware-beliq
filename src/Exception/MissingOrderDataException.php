<?php declare(strict_types=1);

namespace Beliq\Shopware\Exception;

/**
 * Raised when the order lacks a value the invoice needs and no specification
 * prescribes one. No invoice is generated; the message is what the order's
 * failure fields show, so it names the missing value and where to set it.
 */
final class MissingOrderDataException extends \RuntimeException
{
    public static function shippingMethodName(string $orderNumber): self
    {
        return new self(sprintf(
            'Order %s has shipping costs but no shipping method with a name, which the invoice needs as the shipping line\'s item name (BT-153). Give the shipping method a name, then generate the invoice again.',
            $orderNumber,
        ));
    }

    /**
     * @param list<string> $names
     */
    public static function severalShippingMethods(string $orderNumber, array $names): self
    {
        return new self(sprintf(
            'Order %s has deliveries with different shipping methods, so its shipping costs have no one name for the shipping line\'s item name (BT-153): %s',
            $orderNumber,
            implode(', ', $names),
        ));
    }
}
