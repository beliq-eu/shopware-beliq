<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests\Support;

/**
 * A real HTTP server on 127.0.0.1, run by PHP's built-in server with
 * stub-router.php, answering from a script of responses. Used where a test needs
 * real sockets and real headers rather than a fake client.
 */
final class StubServer
{
    public readonly string $baseUrl;

    private readonly string $dir;

    /** @var resource */
    private $process;

    /** @param list<array{status: int, headers?: array<string, string>, body?: string, sleep?: int}> $responses */
    public function __construct(array $responses)
    {
        $this->dir = sys_get_temp_dir() . '/beliq-stub-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/responses.json', json_encode($responses, JSON_THROW_ON_ERROR));

        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/stub-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['BELIQ_STUB_DIR' => $this->dir],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the stub server.');
        }
        $this->process = $process;
        $this->baseUrl = 'http://127.0.0.1:' . $port;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new \RuntimeException('The stub server did not start listening.');
    }

    /** @return list<array{n: int, at: float, method: string, path: string, body: string}> */
    public function requests(): array
    {
        $log = $this->dir . '/requests.log';
        if (!is_file($log)) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($log)))),
        );
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** A port nothing listens on: bound once to let the kernel pick, then released. */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Could not bind a local port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
