<?php declare(strict_types=1);

namespace Beliq\Shopware\Service;

use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Persists a generated invoice document. The bytes are stored as a private media
 * file and the resulting media id is recorded on the order's customFields, so the
 * document is retrievable and linked without a bespoke document type. Promoting
 * this to a first-class order document is Pass 1c; see ROADMAP.md.
 */
final class DocumentStore
{
    /** customFields key holding the id of the generated invoice media file. */
    public const ORDER_FIELD_MEDIA_ID = 'beliq_invoice_media_id';

    public function __construct(
        private readonly MediaService $mediaService,
        private readonly EntityRepository $orderRepository,
    ) {
    }

    /**
     * Store the document and link it on the order. Returns the media id.
     */
    public function store(
        string $orderId,
        string $orderNumber,
        string $bytes,
        string $contentType,
        Context $context,
    ): string {
        $isPdf = str_contains($contentType, 'pdf');
        $extension = $isPdf ? 'pdf' : 'xml';
        $filename = 'invoice-' . $orderNumber;

        $mediaId = $this->mediaService->saveFile(
            $bytes,
            $extension,
            $isPdf ? 'application/pdf' : 'application/xml',
            $filename,
            $context,
            null,
            null,
            true,
        );

        $this->orderRepository->upsert(
            [[
                'id' => $orderId,
                'customFields' => [self::ORDER_FIELD_MEDIA_ID => $mediaId],
            ]],
            $context,
        );

        return $mediaId;
    }
}
