<?php declare(strict_types=1);

namespace Beliq\Shopware\Retry;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: InvoiceRetryTask::class)]
final class InvoiceRetryTaskHandler extends ScheduledTaskHandler
{
    /** @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly InvoiceAttempts $attempts,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $this->attempts->runDue(Context::createCLIContext());
    }
}
