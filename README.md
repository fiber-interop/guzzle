# fiber-interop/guzzle

A [Guzzle](https://github.com/guzzle/guzzle) handler for [fiber-interop/api](https://github.com/fiber-interop/api):
inside `async\run()` each request suspends only the task that waits for it, on whichever adapter is
installed. Transfers go through [fiber-interop/curl](https://github.com/fiber-interop/curl).

```
composer require fiber-interop/guzzle
```

```php
use function async\{run, go, await_all};

$client = async\guzzle\client(['base_uri' => 'https://example.com']);

$bodies = run(fn() => await_all(array_map(
    fn($path) => go(fn() => (string) $client->get($path)->getBody()),
    ['/a', '/b', '/c'],
)));
```

`getAsync()` and `Utils::all()` work too: the transfer starts when the request is sent, so promises
created in one task overlap, and `wait()` suspends that task only.

## One line of configuration, and why

Guzzle picks its default handler in `GuzzleHttp\Utils::chooseHandler()`, a static method with no
override, so installing a package cannot change what `new GuzzleHttp\Client()` uses. Today you
create the client with `async\guzzle\client($config)`, or pass the handler yourself:

```php
new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create(new async\guzzle\Handler())]);
```

A `Utils::setDefaultHandler()` (or similar hook) in Guzzle would let this package install itself
from its Composer autoload, so `composer require` alone would be enough. That is the upstream ask.

## API

- `async\guzzle\Handler` — a callable handler `(RequestInterface, array $options): PromiseInterface`
  with the structure of Guzzle's `CurlHandler` (`CurlFactory::create()`, the transfer, `errno`,
  `CurlFactory::finish()`), so responses, errors, redirects, `on_stats`, `sink` and the
  `ConnectException`/`RequestException` mapping are Guzzle's own. Inside `async\run()` it calls
  `async\curl\start()` when the request is sent and returns a promise whose `wait()` awaits the
  transfer. Outside `run()` it is exactly `CurlHandler`: a blocking `curl_exec()`.
  The `delay` option is honoured with `async\sleep()` in a task, so it delays that request only.
  Constructor option: `handle_factory` (a `CurlFactoryInterface`), as with `CurlHandler`.
- `async\guzzle\client(array $config = []): GuzzleHttp\Client` — `$config` with `handler`
  defaulting to `HandlerStack::create(new Handler())`.

## Streaming responses

With `'stream' => true` inside `async\run()`, the request returns as soon as the response headers
are in, and the body is read while the transfer runs:

```php
async\run(function () {
    $response = async\guzzle\client()->get('https://example.com/big.ndjson', ['stream' => true]);
    $body = $response->getBody();
    while (!$body->eof()) {
        handle($body->read(8192));   // suspends this task until data arrives; other tasks run
    }
});
```

- The body is fed from curl's write callback. With the generic `MultiDriver`, the transfer is
  paused while more than 1 MiB is buffered unread, so a slow reader does not grow memory. A native
  driver runs a cooperative `curl_exec()` that does not resume after `curl_pause()`, so there the
  body is buffered without that limit.
- A transfer error after the headers (for example a connection cut mid-body, cURL error 18) is
  thrown from `read()` once the data received before it is consumed, as Guzzle's
  `RequestException`. `on_headers` and `on_stats` work as usual.
- The body is not seekable and has no size.
- Outside `run()`, or with a `sink` option, `stream` is ignored and the body is buffered as with
  `CurlHandler`.
- Cancelling the promise, or abandoning the body, does not abort the transfer; it keeps running
  in the background and `run()` waits for it. An abandoned body that is paused at 1 MiB never
  finishes, so call `$body->close()` on a body you stop reading: the rest is then discarded.

## How it waits

As fiber-interop/curl does: through a native driver where the runtime has one (phasync with the
phasync extension, Swoole with native curl hooking), otherwise through the generic `MultiDriver`,
which polls a shared `curl_multi` handle with `async\sleep()` (1–10 ms adaptive). PHP exposes no
file descriptor for `curl_multi`, so there is nothing to hand to the event loop; see
[fiber-interop/curl](https://github.com/fiber-interop/curl#how-it-waits-polling-honestly).

## Limits

- A promise settles when someone calls `wait()` on it (directly, or through `Utils::all()`,
  a synchronous `get()`, etc.). `then()` callbacks on a promise nobody waits for do not run, the
  same as with Guzzle's `CurlMultiHandler` when nothing ticks it. The transfer itself completes
  regardless.
- `cancel()` does not abort a transfer that has started; it runs to completion in the background.
- Guzzle's `transport_sharing` / connection-cap handler options are not implemented; each transfer
  uses its own easy handle from `CurlFactory`.

## Tests

```
composer update
FI_RUNTIME=revolt vendor/bin/phpunit         # revolt | amp | react | phasync | swoole
FI_RUNTIME=phasync FI_DRIVER=native php -d extension=phasync.so vendor/bin/phpunit
```

A `php -S` fixture serves the requests, so no network is needed.

## License

MIT
