<?php

namespace async\guzzle\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Promise\Utils;
use PHPUnit\Framework\TestCase;
use function async\{run, go, await_all};

final class GuzzleTest extends TestCase
{
    private static function client(): \GuzzleHttp\Client
    {
        return \async\guzzle\client(['base_uri' => FI_BASE]);
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
        $this->assertLessThan(0.6, $elapsed, "10 x 200 ms took {$elapsed}s");
    }

    public function testPromisesInOneTaskOverlap(): void
    {
        $c = self::client();
        $t0 = microtime(true);
        $r = run(fn() => Utils::all(array_map(fn() => $c->getAsync('/slow?ms=200'), range(1, 10)))->wait());
        $elapsed = microtime(true) - $t0;
        $this->assertSame(array_fill(0, 10, 'slow'), array_map(fn($x) => (string) $x->getBody(), $r));
        $this->assertLessThan(0.6, $elapsed, "10 x 200 ms took {$elapsed}s");
    }

    public function testOtherTasksRunWhileWaiting(): void
    {
        $ticks = 0;
        run(function () use (&$ticks) {
            go(function () use (&$ticks) { for ($i = 0; $i < 5; $i++) { \async\sleep(0.02); $ticks++; } });
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
        run(fn() => \async\guzzle\client()->get('http://127.0.0.1:1/', ['connect_timeout' => 2]));
    }

    public function testErrorsOutsideRunToo(): void
    {
        $this->expectException(ConnectException::class);
        \async\guzzle\client()->get('http://127.0.0.1:1/');
    }
}
