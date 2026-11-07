<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * The custom field set on the order that shows the last failure of its automatic
 * beliq invoice: when it failed, the beliq error code or the connection failure,
 * and when the next attempt is due (empty when none follows). The Administration
 * shows a custom field set on the order's detail page with no build step of its
 * own. The plugin writes the fields; a merchant can read them there.
 */
final class OrderFailureFields
{
    public const SET_NAME = 'beliq_invoice';
    public const FAILED_AT = 'beliq_invoice_failed_at';
    public const FAILURE = 'beliq_invoice_failure';
    public const NEXT_ATTEMPT_AT = 'beliq_invoice_next_attempt_at';

    /** Fixed ids, so an install after an update, or the other way round, finds the same set. */
    private const SET_ID = '0196f4b2c1a07d3e9b5a8c2f4e6d1a01';
    private const FIELD_IDS = [
        self::FAILED_AT => '0196f4b2c1a07d3e9b5a8c2f4e6d1a02',
        self::FAILURE => '0196f4b2c1a07d3e9b5a8c2f4e6d1a03',
        self::NEXT_ATTEMPT_AT => '0196f4b2c1a07d3e9b5a8c2f4e6d1a04',
    ];

    /** @param EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> $sets */
    public static function install(EntityRepository $sets, Context $context): void
    {
        if ($sets->searchIds(new Criteria([self::SET_ID]), $context)->getTotal() > 0) {
            return;
        }

        $sets->create([[
            'id' => self::SET_ID,
            'name' => self::SET_NAME,
            'config' => ['label' => ['en-GB' => 'beliq e-invoice', 'de-DE' => 'beliq E-Rechnung']],
            'relations' => [['entityName' => 'order']],
            'customFields' => [
                self::field(self::FAILED_AT, CustomFieldTypes::DATETIME, 1, 'Last automatic invoice failed at', 'Letzte automatische Rechnung fehlgeschlagen am'),
                self::field(self::FAILURE, CustomFieldTypes::TEXT, 2, 'Last automatic invoice failure', 'Fehler der letzten automatischen Rechnung'),
                self::field(self::NEXT_ATTEMPT_AT, CustomFieldTypes::DATETIME, 3, 'Next automatic attempt at (empty: none)', 'Nächster automatischer Versuch am (leer: keiner)'),
            ],
        ]], $context);
    }

    /** @param EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> $sets */
    public static function remove(EntityRepository $sets, Context $context): void
    {
        $sets->delete([['id' => self::SET_ID]], $context);
    }

    /** @return array<string, mixed> */
    private static function field(string $name, string $type, int $position, string $en, string $de): array
    {
        return [
            'id' => self::FIELD_IDS[$name],
            'name' => $name,
            'type' => $type,
            'config' => [
                'label' => ['en-GB' => $en, 'de-DE' => $de],
                'customFieldPosition' => $position,
                'componentName' => $type === CustomFieldTypes::DATETIME ? 'sw-datepicker' : 'sw-field',
                'customFieldType' => $type,
                'type' => $type === CustomFieldTypes::DATETIME ? 'datetime' : 'text',
                'dateType' => 'datetime',
            ],
        ];
    }
}
