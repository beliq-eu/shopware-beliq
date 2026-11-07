<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Beliq\Shopware\Config\PluginConfigProvider;
use Beliq\Shopware\Document\BeliqInvoiceRenderer;
use Beliq\Shopware\Document\InvoiceDocumentLookup;
use Beliq\Shopware\Service\AttemptHttpClient;
use Shopware\Core\Checkout\Document\DocumentGenerationResult;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Framework\Context;

/**
 * An attempt through DocumentGenerator, which runs BeliqInvoiceRenderer and
 * stores the result as a first-class order document. Automatic generation never
 * overwrites: an order that has a beliq invoice by the time of the attempt (a
 * manual generate from its Documents card, say) is left as it is.
 */
final class DocumentInvoiceGeneration implements InvoiceGeneration
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly DocumentGenerator $documentGenerator,
        private readonly InvoiceDocumentLookup $documents,
        private readonly AttemptHttpClient $http,
    ) {
    }

    public function generate(string $orderId, string $salesChannelId, bool $laterAttempt, Context $context): bool
    {
        $config = $this->configProvider->get($salesChannelId);
        if (!$config->enabled || $config->apiKey === '') {
            return false;
        }
        if ($this->documents->countForOrder($orderId, $context) > 0) {
            return true;
        }

        $operation = new DocumentGenerateOperation($orderId, $config->expectedFileType());
        $run = fn (): DocumentGenerationResult => $this->documentGenerator->generate(
            BeliqInvoiceRenderer::TYPE,
            [$orderId => $operation],
            $context,
        );
        $result = $laterAttempt ? $this->http->runAsLaterAttempt($run) : $run();

        foreach ($result->getErrors() as $error) {
            throw $error;
        }

        // False when the business-only setting skipped the order.
        return $this->documents->countForOrder($orderId, $context) > 0;
    }
}
