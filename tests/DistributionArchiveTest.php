<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Both published artifacts are built by `git archive` from a commit: the
 * Composer dist Packagist serves (GitHub's zipball) and the Shopware Store zip
 * (`shopware-cli extension package`). These tests build that archive from HEAD,
 * so a `.gitattributes` edit shows up here once it is committed.
 */
final class DistributionArchiveTest extends TestCase
{
    /** Root files an install needs. Everything else that ships lives in src/. */
    private const ROOT_FILES = ['CHANGELOG.md', 'LICENSE', 'README.md', 'composer.json'];

    public function testShipsOnlyThePluginSourceAndItsRootFiles(): void
    {
        $unexpected = array_values(array_filter(
            self::archivedFiles(),
            static fn (string $path): bool => !str_starts_with($path, 'src/')
                && !in_array($path, self::ROOT_FILES, true),
        ));

        self::assertSame([], $unexpected);
    }

    public function testKeepsWhatComposerShopwareAndTheStoreRead(): void
    {
        $files = self::archivedFiles();

        foreach ([...self::ROOT_FILES, 'src/BeliqShopware.php', 'src/Resources/config/plugin.png'] as $path) {
            self::assertContains($path, $files);
        }
    }

    /**
     * @return list<string>
     */
    private static function archivedFiles(): array
    {
        $tar = tempnam(sys_get_temp_dir(), 'beliq-dist-');

        try {
            self::runCommand(['git', '-C', dirname(__DIR__), 'archive', '--format=tar', '--output=' . $tar, 'HEAD']);
            $entries = explode("\n", trim(self::runCommand(['tar', '-tf', $tar])));
        } finally {
            unlink($tar);
        }

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => !str_ends_with($entry, '/'),
        ));
    }

    /**
     * @param list<string> $command
     */
    private static function runCommand(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        if ($status !== 0) {
            self::fail(sprintf('%s exited %d: %s', implode(' ', $command), $status, $stderr));
        }

        return $stdout;
    }
}
