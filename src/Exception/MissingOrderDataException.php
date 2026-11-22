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
     * ground. The merchant states a category only for an order that ships to a
     * business in another EU country or outside the EU; any other tax-free order
     * does not say which category applies.
     *
     * @param string $cause why the order is none of those two, as a clause
     */
    public static function taxFreeOrder(string $order, string $cause): self
    {
        return new self(sprintf(
            'Shopware marked order %s as tax free, and %s, so the plugin cannot tell which VAT category applies and generates no e-invoice for the order.',
            $order,
            $cause,
        ));
    }

    /**
     * @param string $destination where the order ships, as a phrase after "ships"
     * @param string $setting the label of the setting that states the category
     */
    public static function taxFreeCategoryNotSet(string $order, string $destination, string $setting): self
    {
        return new self(sprintf(
            'Shopware marked order %s as tax free, and it ships %s. Choose the VAT category for such orders in "%s", then generate the invoice again.',
            $order,
            $destination,
            $setting,
        ));
    }

    public static function outOfScopeSellerId(string $order): self
    {
        return new self(sprintf(
            'Order %s is in VAT category O, so the invoice carries no VAT ID (BR-O-02) and names the seller by its registration number (BT-30, BR-CO-26). Set it in the plugin settings, then generate the invoice again.',
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
                : 'The plugin settings hold no exemption reason for this category.',
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
