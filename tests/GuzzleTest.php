<?php

namespace fiber\guzzle\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use PHPUnit\Framework\TestCase;
use function fiber\{run, go, await_all};

final class GuzzleTest extends TestCase
{
    private static function client(): \GuzzleHttp\Client
    {
        return \fiber\guzzle\client(['base_uri' => FI_BASE]);
    }

    public function testSyncOutsideRun(): void
    {
        $r = self::client()->get('/');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('hello GET', (string) $r->getBody());
    }

    public function testInsideRun(): void
    {
        $this->assertSame('hello POST', run(fn() => (string) self::client()->post('/')->getBody()));
    }

    public function testTasksOverlap(): void
    {
        $c = self::client();
        $t0 = microtime(true);
        $r = run(fn() => await_all(array_map(
            fn() => go(fn() => (string) $c->get('/slow?ms=200')->getBody()), range(1, 10))));
        $elapsed = microtime(true) - $t0;
        $this->assertSame(array_fill(0, 10, 'slow'), $r);
        $this->assertLessThan(1.5, $elapsed, "10 x 200 ms took {$elapsed}s");
    }

    public function testPromisesInOneTaskOverlap(): void
    {
        $c = self::client();
        $t0 = microtime(true);
        $r = run(fn() => Utils::all(array_map(fn() => $c->getAsync('/slow?ms=200'), range(1, 10)))->wait());
        $elapsed = microtime(true) - $t0;
        $this->assertSame(array_fill(0, 10, 'slow'), array_map(fn($x) => (string) $x->getBody(), $r));
        $this->assertLessThan(1.5, $elapsed, "10 x 200 ms took {$elapsed}s");
    }

    public function testOtherTasksRunWhileWaiting(): void
    {
        $ticks = 0;
        run(function () use (&$ticks) {
            go(function () use (&$ticks) { for ($i = 0; $i < 5; $i++) { \fiber\sleep(0.02); $ticks++; } });
            self::client()->get('/slow?ms=200');
            $this->assertSame(5, $ticks);
        });
    }

    public function testDelayOptionOnlyDelaysThatRequest(): void
    {
        $c = self::client();
        $t0 = microtime(true);
        run(fn() => Utils::all([
            $c->getAsync('/slow?ms=200', ['delay' => 100]),
            $c->getAsync('/slow?ms=200'),
        ])->wait());
        $elapsed = microtime(true) - $t0;
        $this->assertGreaterThan(0.29, $elapsed);
        $this->assertLessThan(0.45, $elapsed);
    }

    public function testHttpErrorIsRequestException(): void
    {
        $this->expectException(ClientException::class);
        run(fn() => self::client()->get('/status?code=404'));
    }

    public function testConnectErrorIsConnectException(): void
    {
        $this->expectException(ConnectException::class);
        run(fn() => \fiber\guzzle\client()->get('http://127.0.0.1:1/', ['connect_timeout' => 2]));
    }

    public function testErrorsOutsideRunToo(): void
    {
        $this->expectException(ConnectException::class);
        \fiber\guzzle\client()->get('http://127.0.0.1:1/');
    }

    /** Reads the body in 64-byte reads, recording [seconds since $t0, chunk] for each. */
    private static function readTimed($body, float $t0): array
    {
        $chunks = [];
        while (!$body->eof()) {
            $chunk = $body->read(64);
            if ($chunk !== '') {
                $chunks[] = [microtime(true) - $t0, $chunk];
            }
        }
        return $chunks;
    }

    public function testStreamReturnsAtHeadersAndChunksArriveIncrementally(): void
    {
        // 5 chunks 300 ms apart: the body takes 1.2 s, the headers come with the first chunk.
        [$headersAt, $chunks] = run(function () {
            $t0 = microtime(true);
            $r = self::client()->get('/chunks?n=5&ms=300', ['stream' => true]);
            $headersAt = microtime(true) - $t0;
            $this->assertSame(200, $r->getStatusCode());
            return [$headersAt, self::readTimed($r->getBody(), $t0)];
        });
        $this->assertSame('chunk0chunk1chunk2chunk3chunk4', implode('', array_column($chunks, 1)));
        $this->assertLessThan(0.6, $headersAt, 'headers should not wait for the 1.2 s body');
        $this->assertGreaterThanOrEqual(3, count($chunks), 'body should arrive in several reads');
        $this->assertGreaterThan(0.8, end($chunks)[0] - $chunks[0][0], 'reads should be spread over the transfer');
    }

    public function testStreamKeepsOnHeadersCallbackAndOtherTasksRun(): void
    {
        $seen = null;
        $ticks = 0;
        $body = run(function () use (&$seen, &$ticks) {
            go(function () use (&$ticks) { for ($i = 0; $i < 5; $i++) { \fiber\sleep(0.05); $ticks++; } });
            $r = self::client()->get('/chunks?n=3&ms=200', [
                'stream' => true,
                'on_headers' => function ($response) use (&$seen) { $seen = $response->getStatusCode(); },
            ]);
            return $r->getBody()->getContents();
        });
        $this->assertSame('chunk0chunk1chunk2', $body);
        $this->assertSame(200, $seen);
        $this->assertSame(5, $ticks);
    }

    public function testStreamTruncatedBodyThrowsFromRead(): void
    {
        $result = run(function () {
            $r = self::client()->get('/chunks?n=2&ms=100&trunc=1', ['stream' => true]);
            $got = '';
            try {
                while (!$r->getBody()->eof()) {
                    $got .= $r->getBody()->read(1024);
                }
            } catch (RequestException $e) {
                return [$got, $e];
            }
            return [$got, null];
        });
        [$got, $e] = $result;
        $this->assertSame('chunk0chunk1', $got, 'the data before the error is delivered');
        $this->assertInstanceOf(RequestException::class, $e);
        $this->assertStringContainsString('cURL error 18', $e->getMessage());
    }

    public function testWithoutStreamTheBodyIsBufferedAsBefore(): void
    {
        [$elapsed, $body] = run(function () {
            $t0 = microtime(true);
            $r = self::client()->get('/chunks?n=3&ms=200');
            $elapsed = microtime(true) - $t0;
            $this->assertNotInstanceOf(\fiber\guzzle\StreamingBody::class, $r->getBody());
            $this->assertTrue($r->getBody()->isSeekable());
            return [$elapsed, (string) $r->getBody()];
        });
        $this->assertSame('chunk0chunk1chunk2', $body);
        $this->assertGreaterThan(0.35, $elapsed, 'without stream the promise waits for the whole body');
    }

    public function testStreamLargeBodyWithSlowReaderAndClose(): void
    {
        // 8 MiB with nobody reading for 0.3 s: the generic driver pauses at 1 MiB and resumes.
        [$full, $partial] = run(function () {
            $r = self::client()->get('/big', ['stream' => true]);
            \fiber\sleep(0.3);
            $full = strlen($r->getBody()->getContents());
            // close() after a partial read lets the rest drain, so run() can return.
            $r = self::client()->get('/big', ['stream' => true]);
            \fiber\sleep(0.3);
            $partial = strlen($r->getBody()->read(10));
            $r->getBody()->close();
            return [$full, $partial];
        });
        $this->assertSame(8 << 20, $full);
        $this->assertSame(10, $partial);
    }
}
