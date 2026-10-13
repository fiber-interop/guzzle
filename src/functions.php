<?php

namespace async\guzzle;

if (\function_exists('async\guzzle\client')) {
    return;
}

/**
 * A Guzzle client whose handler stack sits on async\guzzle\Handler. $config is passed to
 * GuzzleHttp\Client unchanged, except that 'handler' defaults to HandlerStack::create(new Handler()).
 */
function client(array $config = []): \GuzzleHttp\Client
{
    $config['handler'] ??= \GuzzleHttp\HandlerStack::create(new Handler());
    return new \GuzzleHttp\Client($config);
}
