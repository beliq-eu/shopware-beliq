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
     * Shopware's tax detector marks an order tax free from the shipping country's
     * settings (tax free for every customer, or for companies), not from a VAT
     * ground, so the order does not say which category applies.
     */
    public static function taxFreeOrder(string $order): self
    {
        return new self(sprintf(
            'Shopware marked order %s as tax free, and the plugin cannot tell which VAT category applies (intra-community supply K, export G or reverse charge AE), so it generates no e-invoice for the order.',
            $order,
        ));
    }

    /**
     * @param bool $configurable whether the plugin settings hold a reason for this category
     */
    public static function exemptionReason(string $order, string $category, string $rule, bool $configurable): self
    {
        return new self(sprintf(
            'Order %s has lines in VAT category %s, which needs a VAT exemption reason code (BT-121) or text (BT-120) (%s). %s',
            $order,
            $category,
            $rule,
            $configurable
                ? sprintf('Set the exemption reason for category %s in the plugin settings, then generate the invoice again.', $category)
                : 'The plugin settings hold an exemption reason for categories E and G only.',
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
