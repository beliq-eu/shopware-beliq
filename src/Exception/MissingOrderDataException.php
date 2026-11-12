<?php declare(strict_types=1);

namespace Beliq\Shopware\Exception;

/**
 * Raised when the order lacks a value the invoice needs and no specification
 * prescribes one. No invoice is generated; the message is what the order's
 * failure fields show, so it names the missing value and where to set it.
 */
final class MissingOrderDataException extends \RuntimeException
{
    public static function shippingMethodName(string $order): self
    {
        return new self(sprintf(
            'Order %s has shipping costs on a delivery whose shipping method has no name, which the invoice needs as the shipping line\'s item name (BT-153). Give the shipping method a name, then generate the invoice again.',
            $order,
        ));
    }

    public static function noChargedDelivery(string $order): self
    {
        return new self(sprintf(
            'Order %s has shipping costs but no delivery that carries them, so no shipping method names the shipping line (BT-153).',
            $order,
        ));
    }

    /**
     * @param list<string> $names
     */
    public static function severalShippingMethods(string $order, array $names): self
    {
        return new self(sprintf(
            'Order %s has deliveries with different shipping methods, so its shipping costs have no one name for the shipping line\'s item name (BT-153): %s',
            $order,
            implode(', ', $names),
        ));
    }
}
