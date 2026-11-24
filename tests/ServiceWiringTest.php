<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Loads the plugin's service file as Shopware's Bundle does and checks each
 * definition against the constructor it feeds, so a swapped, missing or
 * mistyped argument or a lost tag fails here instead of in a merchant's
 * container build.
 */
final class ServiceWiringTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../src/Resources/config';

    /**
     * Services the file takes from Shopware and Symfony by id, with the type
     * each id provides there.
     */
    private const EXTERNAL_IDS = [
        'logger' => LoggerInterface::class,
        'lock.factory' => LockFactory::class,
        'order.repository' => EntityRepository::class,
        'document.repository' => EntityRepository::class,
        'state_machine_history.repository' => EntityRepository::class,
        'scheduled_task.repository' => EntityRepository::class,
    ];

    private const TAG_FOR_PARENT = [
        AbstractDocumentRenderer::class => 'document.renderer',
        EventSubscriberInterface::class => 'kernel.event_subscriber',
        ScheduledTask::class => 'shopware.scheduled.task',
        ScheduledTaskHandler::class => 'messenger.message_handler',
    ];

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        (new YamlFileLoader($this->container, new FileLocator(self::CONFIG_DIR)))->load('services.yaml');
    }

    public function testOnlyTheYamlServiceFileExists(): void
    {
        // Shopware loads every Resources/config/services.* file, so a second
        // format would register the services twice.
        self::assertSame(
            [self::CONFIG_DIR . '/services.yaml'],
            glob(self::CONFIG_DIR . '/services.*'),
        );
    }

    public function testEveryArgumentMatchesItsConstructorParameter(): void
    {
        $checked = 0;
        foreach ($this->beliqDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            self::assertTrue(class_exists($class), "$id names no class");
            $constructor = (new \ReflectionClass($class))->getConstructor();
            $parameters = $constructor?->getParameters() ?? [];
            $arguments = array_values($definition->getArguments());

            self::assertGreaterThanOrEqual($constructor?->getNumberOfRequiredParameters() ?? 0, \count($arguments), "$id: too few arguments");
            self::assertLessThanOrEqual(\count($parameters), \count($arguments), "$id: too many arguments");

            foreach ($arguments as $i => $argument) {
                self::assertInstanceOf(Reference::class, $argument, "$id argument $i is not a service reference");
                $type = $parameters[$i]->getType();
                self::assertInstanceOf(\ReflectionNamedType::class, $type, "$id parameter $i has no single type");
                $provided = $this->providedType((string) $argument);
                self::assertTrue(
                    is_a($provided, $type->getName(), true),
                    \sprintf('%s parameter $%s takes %s, the file passes %s (%s)', $id, $parameters[$i]->getName(), $type->getName(), $argument, $provided),
                );
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testEachShopwareExtensionPointCarriesItsTag(): void
    {
        $tagged = 0;
        foreach ($this->beliqDefinitions() as $id => $definition) {
            foreach (self::TAG_FOR_PARENT as $parent => $tag) {
                if (is_a($definition->getClass() ?? $id, $parent, true)) {
                    self::assertTrue($definition->hasTag($tag), "$id extends $parent but has no $tag tag");
                    ++$tagged;
                }
            }
        }

        self::assertSame(\count(self::TAG_FOR_PARENT), $tagged);
    }

    /**
     * @return array<string, Definition>
     */
    private function beliqDefinitions(): array
    {
        return array_filter(
            $this->container->getDefinitions(),
            static fn (string $id): bool => str_starts_with($id, 'Beliq\\'),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    private function providedType(string $id): string
    {
        if (isset(self::EXTERNAL_IDS[$id])) {
            return self::EXTERNAL_IDS[$id];
        }

        if (str_starts_with($id, 'Beliq\\')) {
            if ($this->container->hasAlias($id)) {
                $id = (string) $this->container->getAlias($id);
            }
            self::assertTrue($this->container->hasDefinition($id), "$id is referenced but not defined");

            return $this->container->getDefinition($id)->getClass() ?? $id;
        }

        self::assertTrue(class_exists($id) || interface_exists($id), "$id is neither a known service id nor a class");

        return $id;
    }
}
