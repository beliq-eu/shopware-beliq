<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

use Beliq\Shopware\Config\PluginConfig;
use Beliq\Shopware\Invoice\Address;
use Beliq\Shopware\Invoice\Party;
use Beliq\Shopware\Invoice\SourceLine;
use Beliq\Shopware\Invoice\SourceOrder;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Extracts a normalized SourceOrder from a Shopware OrderEntity. This is the
 * Shopware-specific half of the plugin; everything downstream (the mapper, the
 * client) is platform-agnostic.
 *
 * Line figures are converted to the net basis EN 16931 works in: Shopware reports
 * order figures in the order's tax status (gross, net, or tax-free), so a gross
 * order has its per-line tax subtracted back out. The seller comes from plugin
 * config, the buyer from the order's billing address and customer.
 */
final class OrderAdapter
{
    public function toSourceOrder(OrderEntity $order, PluginConfig $config): SourceOrder
    {
        $taxStatus = $order->getTaxStatus() ?? $order->getPrice()->getTaxStatus();

        $lines = [];
        foreach ($order->getLineItems() ?? [] as $item) {
            // Container line items are grouping wrappers; their children carry the
            // priced rows, so including both would double-count.
            if ($item->getType() === LineItem::CONTAINER_LINE_ITEM) {
                continue;
            }
            $lines[] = $this->toSourceLine($item, $taxStatus);
        }

        $customer = $order->getOrderCustomer();
        $billing = $order->getBillingAddress();

        $company = $this->firstNonEmpty([
            $billing?->getCompany(),
            $customer?->getCompany(),
        ]);
        $vatIds = $customer?->getVatIds() ?? [];
        $vatId = $this->firstNonEmpty($vatIds);

        $accountType = $customer?->getCustomer()?->getAccountType();
        $flaggedBusiness = $accountType === CustomerEntity::ACCOUNT_TYPE_BUSINESS
            || $company !== null;

        return new SourceOrder(
            number: $order->getOrderNumber() ?? '',
            issueDate: $order->getOrderDateTime()->format('Y-m-d'),
            currencyCode: $order->getCurrency()?->getIsoCode() ?? '',
            seller: $config->seller,
            buyer: $this->buildBuyer($order, $company, $vatId),
            lines: $lines,
            buyerFlaggedBusiness: $flaggedBusiness,
            zeroRateCategory: $config->zeroRateCategory,
        );
    }

    private function toSourceLine(OrderLineItemEntity $item, string $taxStatus): SourceLine
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
                itemId: $this->itemId($item),
            );
        }

        $total = $price->getTotalPrice();
        $taxes = $price->getCalculatedTaxes();
        $rate = $this->dominantRate($taxes);

        if ($taxStatus === CartPrice::TAX_STATE_FREE) {
            $net = $total;
            $rate = 0.0;
        } elseif ($taxStatus === CartPrice::TAX_STATE_GROSS) {
            $net = $total - $taxes->getAmount();
        } else {
            $net = $total;
        }

        return new SourceLine(
            description: $item->getLabel(),
            quantity: $quantity,
            unitNetPrice: $quantity > 0.0 ? $net / $quantity : $net,
            lineNetTotal: $net,
            vatRate: $rate,
            itemId: $this->itemId($item),
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
