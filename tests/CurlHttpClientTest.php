<?php declare(strict_types=1);

namespace Beliq\Shopware\Tests;

use Beliq\Shopware\Exception\BeliqApiException;
use Beliq\Shopware\Exception\BeliqTransportException;
use Beliq\Shopware\Service\BeliqClient;
use Beliq\Shopware\Service\CurlHttpClient;
use Beliq\Shopware\Tests\Support\StubServer;
use PHPUnit\Framework\TestCase;

/**
 * Real sockets, real cURL: no double stands in for the thing under test.
 *
 * A connect stall is reproduced by filling a listening socket's accept queue.
 * With backlog=1 and the server never calling accept(), Linux drops further
 * SYNs instead of refusing them, which is what a black-holed host looks like
 * from the client side.
 */
final class CurlHttpClientTest extends TestCase
{
    private const TOTAL_TIMEOUT = 4;
    private const CONNECT_TIMEOUT = 1;

    /** @var list<resource> */
    private array $pending = [];

    private $server;

    private string $blackHole;

    protected function setUp(): void
    {
        $context = stream_context_create(['socket' => ['backlog' => 1]]);
        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        if ($server === false) {
            self::fail('Could not open a local listening socket: ' . $errstr);
        }

        $this->server = $server;
        $this->blackHole = 'http://' . stream_socket_get_name($server, false) . '/';

        // Fill the accept queue. Nothing ever accepts, so once it is full the
        // kernel silently drops the next SYN.
        for ($i = 0; $i < 8; $i++) {
            $client = @stream_socket_client(
                'tcp://' . stream_socket_get_name($server, false),
                $n,
                $ns,
                0.4,
            );
            if ($client !== false) {
                $this->pending[] = $client;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->pending as $client) {
            fclose($client);
        }
        $this->pending = [];

        if (is_resource($this->server)) {
            fclose($this->server);
        }
    }

    public function testConnectTimeoutBoundsAStalledConnectWellBelowTheTotalTimeout(): void
    {
        // The control arm, and the precondition: with no connect timeout the
        // same connect burns the whole total timeout. If this does not stall,
        // the harness is not reproducing a black hole and the arm below would
        // pass for the wrong reason.
        $uncapped = $this->timeRequest(new CurlHttpClient(self::TOTAL_TIMEOUT, 0));
        self::assertGreaterThanOrEqual(
            self::TOTAL_TIMEOUT - 0.5,
            $uncapped,
            'The accept queue did not stall the connect, so this test proves nothing.',
        );

        $capped = $this->timeRequest(new CurlHttpClient(self::TOTAL_TIMEOUT, self::CONNECT_TIMEOUT));
        self::assertLessThan(self::TOTAL_TIMEOUT - 1.0, $capped);
    }

    public function testAStalledConnectIsAConnectTimeout(): void
    {
        self::assertSame(
            BeliqTransportException::CONNECT_TIMEOUT,
            $this->failureKind(new CurlHttpClient(self::TOTAL_TIMEOUT, self::CONNECT_TIMEOUT), $this->blackHole),
        );
    }

    public function testARefusedConnectionIsAConnectFailure(): void
    {
        $closedPort = 'http://127.0.0.1:' . StubServer::freePort() . '/';

        self::assertSame(
            BeliqTransportException::CONNECT_FAILED,
            $this->failureKind(new CurlHttpClient(self::TOTAL_TIMEOUT, self::CONNECT_TIMEOUT), $closedPort),
        );
    }

    public function testAServerThatAcceptsAndNeverAnswersIsTheRequestsOwnTimeout(): void
    {
        // A listening socket with room in its backlog completes the handshake in
        // the kernel, so the request is sent and then waits for an answer.
        $silent = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($silent);
        $url = 'http://' . stream_socket_get_name($silent, false) . '/';

        try {
            self::assertSame(
                BeliqTransportException::TIMEOUT,
                $this->failureKind(new CurlHttpClient(2, self::CONNECT_TIMEOUT), $url),
            );
        } finally {
            fclose($silent);
        }
    }

    public function testA503CarriesItsRetryAfterThroughTheClient(): void
    {
        $server = new StubServer([[
            'status' => 503,
            'headers' => ['Content-Type' => 'application/json', 'Retry-After' => '30'],
            'body' => '{"success":false,"error":{"code":"DATABASE_UNAVAILABLE","message":"The database is unavailable."}}',
        ]]);

        try {
            (new BeliqClient('blq_test', $server->baseUrl, new CurlHttpClient(self::TOTAL_TIMEOUT, self::CONNECT_TIMEOUT)))
                ->generate(['standard' => 'xrechnung', 'invoice' => []]);
            self::fail('Expected the 503 to throw.');
        } catch (BeliqApiException $exception) {
            self::assertSame('DATABASE_UNAVAILABLE', $exception->apiCode);
            self::assertSame(503, $exception->status);
            self::assertSame(30, $exception->retryAfterSeconds);
        } finally {
            $server->stop();
        }
    }

    private function failureKind(CurlHttpClient $client, string $url): string
    {
        try {
            $client->request('GET', $url);
        } catch (BeliqTransportException $exception) {
            return $exception->kind;
        }
        self::fail('Expected a transport failure from ' . $url);
    }

    private function timeRequest(CurlHttpClient $client): float
    {
        $started = microtime(true);
        try {
            $client->request('GET', $this->blackHole);
            self::fail('Expected the request to a black-holed host to fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('beliq request failed', $exception->getMessage());
        }

        return microtime(true) - $started;
    }
}
