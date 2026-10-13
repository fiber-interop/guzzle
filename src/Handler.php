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

        // A delay only postpones this transfer: a task sleeps, then starts it, so sending stays eager.
        $easy = null;
        $begin = function () use ($request, $options, &$easy): object {
            $easy = $this->factory->create($request, $options);
            return \async\curl\start($easy->handle);
        };
        $transfer = isset($options['delay']) && $options['delay'] > 0
            ? \async\go(static function () use ($begin, $options) {
                \async\sleep($options['delay'] / 1000);
                return \async\await($begin());
            })
            : $begin();

        $promise = new Promise(function () use (&$promise, &$easy, $transfer): void {
            \async\await($transfer);
            $easy->errno = \curl_errno($easy->handle);
            $promise->resolve(CurlFactory::finish($this, $easy, $this->factory));
        });
        return $promise;
    }
}
