<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

use Beliq\Shopware\Config\PluginConfig;
use Beliq\Shopware\Exception\MissingOrderDataException;
use Beliq\Shopware\Invoice\Address;
use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\SourceLine;
use Beliq\Shopware\Invoice\SourceOrder;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Country\CountryEntity;

/**
 * Extracts a normalized SourceOrder from a Shopware OrderEntity. This is the
 * Shopware-specific half of the plugin; everything downstream (the mapper, the
 * client) is platform-agnostic.
 *
 * Shopware keeps shipping on the order rather than among its line items, so it is
 * added as its own line; without it the invoice understates what the customer paid.
 * The line is named by the order's shipping method, in the language the order was
 * loaded in. The method's current name is read, so a method renamed after the
 * order gives the new name on a later attempt or a regenerate.
 *
 * Line figures are converted to the net basis EN 16931 works in: Shopware reports
 * order figures in the order's tax status, so a gross order has its per-line tax
 * subtracted back out. An order Shopware marks tax free puts every line at 0% in
 * the VAT category the merchant stated for where the order ships; Shopware records
 * that no VAT was charged, not why, and the category depends on why. In category
 * K (intra-community supply) the order also carries the actual delivery date and
 * the deliver-to country. The seller comes from plugin config, the buyer from the
 * order's billing address and customer.
 */
final class OrderAdapter
{
    /**
     * Order custom field a merchant can set to route to a public-sector buyer:
     * its Leitweg-ID becomes the buyer reference (BT-10).
     */
    private const BUYER_REFERENCE_FIELD = 'beliq_buyer_reference';

    /** The labels of the tax-free settings in config.xml, named in a failure. */
    private const TAX_FREE_EU_BUSINESS_SETTING = 'Tax-free orders to businesses in other EU countries';
    private const TAX_FREE_NON_EU_SETTING = 'Tax-free orders outside the EU';

    private const INTRA_COMMUNITY_CATEGORY = 'K';

    /**
     * @param (\Closure(list<string>): array<string, list<string>>)|null $shippedDates
     *     per delivery id, the Y-m-d dates the delivery was set to shipped
     *     (DeliveryShippedDates); read only for an order in category K
     */
    public function toSourceOrder(OrderEntity $order, PluginConfig $config, ?\Closure $shippedDates = null): SourceOrder
    {
        $taxStatus = $order->getTaxStatus() ?? $order->getPrice()->getTaxStatus();
        $taxFreeCategory = $taxStatus === CartPrice::TAX_STATE_FREE
            ? $this->taxFreeCategory($order, $config)
            : null;

        $deliveryDate = null;
        $deliverToCountry = null;
        if ($taxFreeCategory === self::INTRA_COMMUNITY_CATEGORY) {
            $deliveryDate = $this->shippedDate($order, $shippedDates ?? static fn (array $ids): array => []);
            $deliverToCountry = strtoupper($this->shippingCountry($order)?->getIso() ?? '');
        }

        $lines = [];
        foreach ($order->getLineItems() ?? [] as $item) {
            // Container line items are grouping wrappers; their children carry the
            // priced rows, so including both would double-count.
            if ($item->getType() === LineItem::CONTAINER_LINE_ITEM) {
                continue;
            }
            $lines[] = $this->toSourceLine($item, $taxStatus, $taxFreeCategory);
        }
        foreach ($this->shippingLines($order, $taxStatus, $taxFreeCategory) as $line) {
            $lines[] = $line;
        }

        return $this->sourceOrder($order, $config, $lines, $deliveryDate, $deliverToCountry);
    }

    /**
     * The order with its parties and none of its lines: what the business-only
     * setting decides on. Deciding on this first means an order the setting skips
     * is never checked for values only its invoice would need.
     */
    public function toGateOrder(OrderEntity $order, PluginConfig $config): SourceOrder
    {
        return $this->sourceOrder($order, $config, []);
    }

    /**
     * @param list<SourceLine> $lines
     */
    private function sourceOrder(
        OrderEntity $order,
        PluginConfig $config,
        array $lines,
        ?string $deliveryDate = null,
        ?string $deliverToCountry = null,
    ): SourceOrder {
        $customer = $order->getOrderCustomer();
        $billing = $order->getBillingAddress();

        $company = $this->firstNonEmpty([
            $billing?->getCompany(),
            $customer?->getCompany(),
        ]);
        $vatId = $this->buyerVatId($order);

        $accountType = $customer?->getCustomer()?->getAccountType();
        $flaggedBusiness = $accountType === CustomerEntity::ACCOUNT_TYPE_BUSINESS
            || $company !== null;

        $number = $order->getOrderNumber() ?? '';

        return new SourceOrder(
            number: $number,
            issueDate: $order->getOrderDateTime()->format('Y-m-d'),
            currencyCode: $order->getCurrency()?->getIsoCode() ?? '',
            seller: $config->seller,
            buyer: $this->buildBuyer($order, $company, $vatId),
            lines: $lines,
            paymentMeans: $config->paymentMeans?->withReference($number),
            buyerReference: $this->buyerReference($order, $customer, $number),
            buyerFlaggedBusiness: $flaggedBusiness,
            zeroRateCategory: $config->zeroRateCategory,
            exemptionReasons: $config->exemptionReasons,
            deliveryDate: $deliveryDate,
            deliverToCountryCode: $deliverToCountry,
        );
    }

    /**
     * The VAT category the merchant stated for a tax-free order that ships where
     * this one does. Shopware's tax detector makes an order tax free from its
     * shipping country, so that country decides, by its own EU flag: another EU
     * country than the seller's, with a buyer VAT ID, or a country outside the
     * EU. The shop does not record the ground (goods or services, for one), so
     * the category is the merchant's setting. Without one, and for any other
     * tax-free order, there is no invoice.
     */
    private function taxFreeCategory(OrderEntity $order, PluginConfig $config): string
    {
        $label = $order->getOrderNumber() ?? $order->getId();

        // Shopware creates no delivery for a cart of digital products only, so
        // such an order names no shipping country.
        $deliveries = $order->getDeliveries();
        if ($deliveries === null || $deliveries->count() === 0) {
            throw MissingOrderDataException::taxFreeOrder($label, 'it has no delivery to name its shipping country');
        }
        $country = $this->shippingCountry($order);
        if ($country === null) {
            throw MissingOrderDataException::taxFreeOrder($label, 'its deliveries name no one shipping country');
        }

        if (!$country->getIsEu()) {
            return $config->taxFreeNonEuCategory
                ?? throw MissingOrderDataException::taxFreeCategoryNotSet($label, 'outside the EU', self::TAX_FREE_NON_EU_SETTING);
        }
        if (strtoupper($country->getIso() ?? '') === $config->seller->address->countryCode) {
            throw MissingOrderDataException::taxFreeOrder($label, 'it ships within the seller\'s country');
        }
        if ($this->buyerVatId($order) === null) {
            throw MissingOrderDataException::taxFreeOrder($label, 'it ships to another EU country to a buyer with no VAT ID');
        }

        return $config->taxFreeEuBusinessCategory
            ?? throw MissingOrderDataException::taxFreeCategoryNotSet($label, 'to a business in another EU country', self::TAX_FREE_EU_BUSINESS_SETTING);
    }

    /**
     * The actual delivery date (BT-72) of an order in category K, which BR-IC-11
     * requires unless the invoice has an invoicing period, which the plugin does
     * not map. It is the day every delivery of the order was set to shipped. A
     * delivery never set to shipped, or deliveries set to shipped on different
     * days, give no one date, and there is no invoice.
     *
     * @param \Closure(list<string>): array<string, list<string>> $shippedDates
     */
    private function shippedDate(OrderEntity $order, \Closure $shippedDates): string
    {
        $label = $order->getOrderNumber() ?? $order->getId();
        $deliveries = $order->getDeliveries()?->getElements() ?? [];
        $byDelivery = $shippedDates(array_values(array_map(static fn ($delivery): string => $delivery->getId(), $deliveries)));

        $days = [];
        foreach ($deliveries as $delivery) {
            $own = $byDelivery[$delivery->getId()] ?? [];
            if ($own === []) {
                throw MissingOrderDataException::intraCommunityNotShipped($label);
            }
            foreach ($own as $day) {
                $days[$day] = true;
            }
        }
        if (count($days) !== 1) {
            throw MissingOrderDataException::intraCommunitySeveralShippedDates($label);
        }

        return (string) array_key_first($days);
    }

    /**
     * The one country the order's deliveries ship to; null when a delivery has
     * no shipping address with a country, or they ship to several.
     */
    private function shippingCountry(OrderEntity $order): ?CountryEntity
    {
        $found = null;
        foreach ($order->getDeliveries() ?? [] as $delivery) {
            $country = $delivery->getShippingOrderAddress()?->getCountry();
            if ($country === null || ($found !== null && $found->getId() !== $country->getId())) {
                return null;
            }
            $found = $country;
        }

        return $found;
    }

    private function buyerVatId(OrderEntity $order): ?string
    {
        return $this->firstNonEmpty($order->getOrderCustomer()?->getVatIds() ?? []);
    }

    /**
     * The buyer reference (BT-10). XRechnung requires it (BR-DE-15) and Peppol BIS
     * accepts it in place of an order reference. A merchant routing to a public
     * administration sets the buyer's Leitweg-ID in the order custom field; for a
     * plain commercial order it falls back to the buyer's customer number, then to
     * the order number, so the field is always present.
     */
    private function buyerReference(OrderEntity $order, ?OrderCustomerEntity $customer, string $orderNumber): string
    {
        $custom = $order->getCustomFields();
        $leitweg = is_array($custom) ? ($custom[self::BUYER_REFERENCE_FIELD] ?? null) : null;

        return $this->firstNonEmpty([
            is_string($leitweg) ? $leitweg : null,
            $customer?->getCustomerNumber(),
            $orderNumber,
        ]) ?? $orderNumber;
    }

    /**
     * @param string|null $taxFreeCategory the category of a tax-free order, whose
     *     lines carry no VAT: their total is their net, at 0%
     */
    private function toSourceLine(OrderLineItemEntity $item, string $taxStatus, ?string $taxFreeCategory): SourceLine
    {
        $quantity = (float) $item->getQuantity();
        $price = $item->getPrice();

        if (!$price instanceof CalculatedPrice) {
            $total = $item->getTotalPrice();

            return new SourceLine(
                description: $item->getLabel(),
                quantity: $quantity,
                unitNetPrice: $quantity > 0.0 ? $total / $quantity : $total,
                lineNetTotal: $total,
                vatRate: 0.0,
                vatCategoryCode: $taxFreeCategory,
                itemId: $this->itemId($item),
            );
        }

        $total = $price->getTotalPrice();
        $taxes = $price->getCalculatedTaxes();

        if ($taxFreeCategory !== null) {
            $net = $total;
            $rate = 0.0;
        } else {
            $net = $taxStatus === CartPrice::TAX_STATE_GROSS ? $total - $taxes->getAmount() : $total;
            $rate = $this->dominantRate($taxes);
        }

        return new SourceLine(
            description: $item->getLabel(),
            quantity: $quantity,
            unitNetPrice: $quantity > 0.0 ? $net / $quantity : $net,
            lineNetTotal: $net,
            vatRate: $rate,
            vatCategoryCode: $taxFreeCategory,
            itemId: $this->itemId($item),
        );
    }

    /**
     * One line per tax component of the order's shipping costs. A shipping method
     * on tax type `auto` spreads its tax across the cart's rates, one component per
     * rate, so labelling the whole charge with a single rate would file part of it
     * in the wrong VAT group. On a tax-free order the whole charge is one line at
     * 0% in the order's category. Free shipping adds nothing.
     *
     * @return list<SourceLine>
     */
    private function shippingLines(OrderEntity $order, string $taxStatus, ?string $taxFreeCategory): array
    {
        $shipping = $order->getShippingCosts();
        $total = $shipping->getTotalPrice();
        if ($total === 0.0) {
            return [];
        }

        $name = $this->shippingMethodName($order);

        $taxes = $shipping->getCalculatedTaxes();
        if ($taxFreeCategory !== null || $taxes->count() === 0) {
            return [$this->shippingLine($name, $total, 0.0, $taxFreeCategory)];
        }

        $lines = [];
        foreach ($taxes->getElements() as $tax) {
            // A component's price is the portion its tax was computed on: gross on
            // a gross order, net on a net one.
            $net = $taxStatus === CartPrice::TAX_STATE_GROSS
                ? $tax->getPrice() - $tax->getTax()
                : $tax->getPrice();
            $lines[] = $this->shippingLine($name, $net, $tax->getTaxRate());
        }

        return $lines;
    }

    /**
     * The name of the shipping method the order's shipping costs belong to, read
     * from the deliveries that carry part of those costs. Every invoice line
     * needs an item name (BT-153, rule BR-25 of EN 16931), and no specification
     * prescribes one for shipping, so the name is the shop's own or there is no
     * invoice: an order whose charged deliveries name no method, or name
     * different ones, stops here rather than carrying a made-up name.
     */
    private function shippingMethodName(OrderEntity $order): string
    {
        $orderLabel = $order->getOrderNumber() ?? $order->getId();

        $names = [];
        foreach ($order->getDeliveries() ?? [] as $delivery) {
            if ($delivery->getShippingCosts()->getTotalPrice() === 0.0) {
                continue;
            }
            $name = $delivery->getShippingMethod()?->getTranslation('name');
            if (!is_string($name) || trim($name) === '') {
                throw MissingOrderDataException::shippingMethodName($orderLabel);
            }
            $names[trim($name)] = true;
        }

        if ($names === []) {
            throw MissingOrderDataException::noChargedDelivery($orderLabel);
        }
        if (count($names) > 1) {
            throw MissingOrderDataException::severalShippingMethods($orderLabel, array_map('strval', array_keys($names)));
        }

        return (string) array_key_first($names);
    }

    private function shippingLine(string $name, float $net, float $rate, ?string $category = null): SourceLine
    {
        return new SourceLine(
            description: $name,
            quantity: 1.0,
            unitNetPrice: $net,
            lineNetTotal: $net,
            vatRate: $rate,
            vatCategoryCode: $category,
        );
    }

    /**
     * The rate of the largest tax component on the line. A single order line
     * normally carries one rate; when it carries more (mixed bundle), the
     * dominant component's rate labels the line and the net figure still balances
     * because it is derived from the line total minus the full tax amount.
     */
    private function dominantRate(CalculatedTaxCollection $taxes): float
    {
        $rate = 0.0;
        $largest = null;
        foreach ($taxes->getElements() as $tax) {
            if ($largest === null || abs($tax->getPrice()) > $largest) {
                $largest = abs($tax->getPrice());
                $rate = $tax->getTaxRate();
            }
        }

        return $rate;
    }

    private function buildBuyer(OrderEntity $order, ?string $company, ?string $vatId): Party
    {
        $customer = $order->getOrderCustomer();
        $billing = $order->getBillingAddress();

        $personName = trim(($customer?->getFirstName() ?? '') . ' ' . ($customer?->getLastName() ?? ''));
        $name = $company ?? ($personName !== '' ? $personName : 'Customer');

        $address = new Address(
            city: $billing?->getCity() ?? '',
            postalCode: $billing?->getZipcode() ?? '',
            countryCode: $billing?->getCountry()?->getIso() ?? '',
            street: $this->emptyToNull($billing?->getStreet()),
            additionalStreet: $billing?->getAdditionalAddressLine1(),
        );

        return new Party(
            name: $name,
            address: $address,
            vatId: $vatId,
            email: $this->emptyToNull($customer?->getEmail()),
            contactName: $company !== null && $personName !== '' ? $personName : null,
        );
    }

    private function itemId(OrderLineItemEntity $item): ?string
    {
        $payload = $item->getPayload();
        $productNumber = is_array($payload) ? ($payload['productNumber'] ?? null) : null;

        return is_string($productNumber) && $productNumber !== '' ? $productNumber : null;
    }

    /**
     * @param array<int, string|null> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function emptyToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
