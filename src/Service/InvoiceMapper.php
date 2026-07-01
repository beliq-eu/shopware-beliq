<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

use Beliq\Shopware\Invoice\SourceLine;
use Beliq\Shopware\Invoice\SourceOrder;

/**
 * Turns a normalized SourceOrder into a beliq generate body. This is the part a
 * store plugin actually owns: mapping order figures onto EN 16931 semantics so
 * the generated document passes the business rules.
 *
 * VAT is computed per category group (BR-CO-17): the category tax amount is the
 * category taxable amount times the rate, rounded once. Grand totals are derived
 * from those group sums so net + tax = gross holds exactly (BR-CO-15).
 */
final class InvoiceMapper
{
    private const DEFAULT_STANDARD_CATEGORY = 'S';

    /**
     * @return array<string, mixed> the JSON body for POST /v1/generate
     */
    public function toGenerateBody(
        SourceOrder $order,
        string $standard,
        string $output = 'xml',
        ?string $profile = null,
    ): array {
        if ($order->lines === []) {
            throw new \InvalidArgumentException('Cannot build an invoice with no lines.');
        }

        $lines = [];
        foreach ($order->lines as $line) {
            $category = $this->deriveCategory($line, $order);
            $lines[] = [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unitCode' => $line->unitCode,
                'unitPrice' => $this->round2($line->unitNetPrice),
                'lineTotal' => $this->round2($line->lineNetTotal),
                'vatRate' => $line->vatRate,
                'vatCategoryCode' => $category,
                ...($line->itemId !== null ? ['itemId' => $line->itemId] : []),
            ];
        }

        $taxSummary = $this->buildTaxSummary($order->lines, $order);

        $totalNet = $this->round2(array_sum(array_column($taxSummary, 'taxableAmount')));
        $totalTax = $this->round2(array_sum(array_column($taxSummary, 'taxAmount')));
        $totalGross = $this->round2($totalNet + $totalTax);

        $invoice = [
            'number' => $order->number,
            'issueDate' => $order->issueDate,
            'currencyCode' => $order->currencyCode,
            'seller' => $order->seller->toArray(),
            'buyer' => $order->buyer->toArray(),
            'lines' => $lines,
            'taxSummary' => $taxSummary,
            'totalNetAmount' => $totalNet,
            'totalTaxAmount' => $totalTax,
            'totalGrossAmount' => $totalGross,
        ];

        if ($order->dueDate !== null) {
            $invoice['dueDate'] = $order->dueDate;
        }
        if ($order->buyerReference !== null && $order->buyerReference !== '') {
            $invoice['buyerReference'] = $order->buyerReference;
        }
        if ($order->orderReference !== null && $order->orderReference !== '') {
            $invoice['orderReference'] = $order->orderReference;
        }
        if ($order->note !== null && $order->note !== '') {
            $invoice['note'] = $order->note;
        }
        if ($order->paymentMeans !== null) {
            $invoice['paymentMeans'] = $order->paymentMeans->toArray();
        }
        if ($order->paymentTerms !== null && $order->paymentTerms !== '') {
            $invoice['paymentTerms'] = $order->paymentTerms;
        }

        $body = [
            'standard' => $standard,
            'output' => $output,
            'invoice' => $invoice,
        ];
        if ($profile !== null) {
            $body['profile'] = $profile;
        }

        return $body;
    }

    /**
     * A line taxed above 0% is standard rated. A 0% line takes the order's
     * configured zero-rate category. An explicit per-line category always wins.
     */
    public function deriveCategory(SourceLine $line, SourceOrder $order): string
    {
        if ($line->vatCategoryCode !== null && $line->vatCategoryCode !== '') {
            return $line->vatCategoryCode;
        }

        return $line->vatRate > 0.0
            ? self::DEFAULT_STANDARD_CATEGORY
            : $order->zeroRateCategory;
    }

    /**
     * Group lines by category and rate, then compute the category tax once on the
     * summed taxable amount. Sorted by category then rate for a stable result.
     *
     * @param list<SourceLine> $lines
     * @return list<array{vatCategoryCode: string, vatRate: float, taxableAmount: float, taxAmount: float}>
     */
    private function buildTaxSummary(array $lines, SourceOrder $order): array
    {
        /** @var array<string, array{vatCategoryCode: string, vatRate: float, taxableAmount: float}> $groups */
        $groups = [];
        foreach ($lines as $line) {
            $category = $this->deriveCategory($line, $order);
            $key = $category . '@' . number_format($line->vatRate, 4, '.', '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'vatCategoryCode' => $category,
                    'vatRate' => $line->vatRate,
                    'taxableAmount' => 0.0,
                ];
            }
            $groups[$key]['taxableAmount'] += $line->lineNetTotal;
        }

        uasort($groups, static function (array $a, array $b): int {
            return [$a['vatCategoryCode'], $a['vatRate']] <=> [$b['vatCategoryCode'], $b['vatRate']];
        });

        $summary = [];
        foreach ($groups as $group) {
            $taxable = $this->round2($group['taxableAmount']);
            $summary[] = [
                'vatCategoryCode' => $group['vatCategoryCode'],
                'vatRate' => $group['vatRate'],
                'taxableAmount' => $taxable,
                'taxAmount' => $this->round2($taxable * $group['vatRate'] / 100),
            ];
        }

        return $summary;
    }

    private function round2(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }
}
