<?php

namespace async\guzzle;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\HostValidator;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle handler that runs each transfer through async\curl\start(). Structure follows
 * GuzzleHttp\Handler\CurlHandler: CurlFactory::create(), the transfer, errno, CurlFactory::finish().
 *
 * Inside async\run() the transfer starts immediately when the request is sent and the returned
 * promise's wait() suspends only the calling task. Outside run() it is exactly CurlHandler: a
 * blocking curl_exec().
 */
final class Handler
{
    private CurlFactoryInterface $factory;

    /** @param array{handle_factory?: CurlFactoryInterface} $options */
    public function __construct(array $options = [])
    {
        $this->factory = $options['handle_factory'] ?? new CurlFactory(3);
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        HostValidator::assertRequestHost($request);
        $inRun = \async\Registry::$active !== [];

        if (!$inRun) {
            if (isset($options['delay'])) {
                \usleep($options['delay'] * 1000);
            }
            $easy = $this->factory->create($request, $options);
            \curl_exec($easy->handle);
            $easy->errno = \curl_errno($easy->handle);
            return CurlFactory::finish($this, $easy, $this->factory);
        }

        // 'stream' => true: the promise resolves once the headers are in, and the body is a
        // StreamingBody fed while the transfer runs. $ready settles on the headers or on completion.
        $body = $ready = null;
        if (!empty($options['stream']) && !isset($options['sink'])) {
            $ready = new \async\Deferred();
            $options['sink'] = $body = new StreamingBody();
            $onHeaders = $options['on_headers'] ?? null;
            $options['on_headers'] = static function ($response) use ($onHeaders, $ready): void {
                if ($onHeaders !== null) {
                    $onHeaders($response);
                }
                if ($response->getStatusCode() >= 200) {
                    $ready->settle(true, 'headers');
                }
            };
        }

        // A delay only postpones this transfer: a task sleeps, then starts it, so sending stays eager.
        $easy = null;
        $begin = function () use ($request, $options, &$easy, $body, $ready): object {
            $easy = $this->factory->create($request, $options);
            if ($body !== null) {
                $body->handle = $easy->handle;
            }
            $t = \async\curl\start($easy->handle);
            if ($ready !== null) {
                // Native drivers return a task, not a then-able, so a task watches for completion.
                // A failed transfer only settles $ready here; the normal path below reports it.
                \async\go(static function () use ($t, $ready): void {
                    try {
                        \async\await($t);
                    } catch (\Throwable) {
                    }
                    $ready->settle(true, 'done');
                });
            }
            return $t;
        };
        $transfer = isset($options['delay']) && $options['delay'] > 0
            ? \async\go(static function () use ($begin, $options) {
                \async\sleep($options['delay'] / 1000);
                return \async\await($begin());
            })
            : $begin();

        $promise = new Promise(function () use (&$promise, &$easy, $transfer, $body, $ready): void {
            if ($body !== null && \async\await($ready) === 'headers') {
                // Finish in the background so a read() sees the end, or the error, of the transfer.
                \async\go(function () use ($easy, $transfer, $body): void {
                    try {
                        \async\await($transfer);
                        $easy->errno = \curl_errno($easy->handle);
                        CurlFactory::finish($this, $easy, $this->factory)->wait();
                        $body->end(null);
                    } catch (\Throwable $e) {
                        $body->end($e);
                    }
                });
                $promise->resolve($easy->response);
                return;
            }
            \async\await($transfer);
            $easy->errno = \curl_errno($easy->handle);
            $promise->resolve(CurlFactory::finish($this, $easy, $this->factory));
        });
        return $promise;
    }
}
