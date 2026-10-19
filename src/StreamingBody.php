<?php

namespace async\guzzle;

use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\StreamInterface;

/**
 * Response body for a 'stream' => true request: a FIFO fed by curl's write callback while the
 * transfer runs. read() suspends the calling task until data arrives or the transfer ends; a
 * transfer error after the headers (a GuzzleHttp\Exception\TransferException, normally the
 * RequestException CurlFactory::finish() produced) is thrown from read() once the buffered data is consumed.
 * While more than 1 MiB is buffered the transfer is paused (CURL_WRITEFUNC_PAUSE), with the generic
 * MultiDriver only.
 */
final class StreamingBody implements StreamInterface
{
    private const HIGH_WATER = 1 << 20;

    private string $buffer = '';
    private bool $ended = false;
    private bool $paused = false;
    private bool $closed = false;
    private ?\Throwable $error = null;
    private ?\async\Deferred $wake = null;

    public ?\CurlHandle $handle = null;

    /**
     * Only the generic MultiDriver resumes a paused handle. A native driver runs a cooperative
     * curl_exec(), which does not wake up on curl_pause(CURLPAUSE_CONT) from another task, so
     * there the body is buffered without a limit.
     */
    private bool $pausable;

    public function __construct()
    {
        $this->pausable = \async\curl\driver() instanceof \async\curl\MultiDriver;
    }

    /** curl write callback. */
    public function write(string $data): int
    {
        if ($this->closed) {
            return \strlen($data);
        }
        if ($this->pausable && \strlen($this->buffer) >= self::HIGH_WATER) {
            $this->paused = true;
            return \CURL_WRITEFUNC_PAUSE;   // curl delivers the same data again after unpausing
        }
        $this->buffer .= $data;
        $this->signal();
        return \strlen($data);
    }

    /** Called once the transfer is over; $error is thrown from read() after the buffered data. */
    public function end(?\Throwable $error): void
    {
        $this->ended = true;
        $this->error = $this->closed ? null : $error;
        $this->signal();
    }

    public function read(int $length): string
    {
        while ($this->buffer === '' && !$this->ended) {
            $this->wake = new \async\Deferred();
            \async\await($this->wake);
        }
        if ($this->buffer === '' && $this->error !== null) {
            // CurlFactory::finish() rejects with a RequestException (PSR-18), e.g. cURL error 18.
            throw $this->error instanceof TransferException
                ? $this->error
                : new TransferException('Transfer failed while reading the response body: ' . $this->error->getMessage(), 0, $this->error);
        }
        $chunk = (string) \substr($this->buffer, 0, $length);
        $this->buffer = (string) \substr($this->buffer, \strlen($chunk));
        if ($this->paused && \strlen($this->buffer) < self::HIGH_WATER) {
            $this->paused = false;
            \curl_pause($this->handle, \CURLPAUSE_CONT);
        }
        return $chunk;
    }

    private function signal(): void
    {
        $w = $this->wake;
        $this->wake = null;
        $w?->settle(true, null);
    }

    public function eof(): bool { return $this->ended && $this->buffer === ''; }
    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $out .= $this->read(65536);
        }
        return $out;
    }
    public function __toString(): string { try { return $this->getContents(); } catch (\Throwable) { return ''; } }
    /** Discards the rest of the body: the transfer, if paused, resumes and drains to the end. */
    public function close(): void
    {
        $this->closed = true;
        $this->buffer = '';
        $this->ended = true;
        if ($this->paused) {
            $this->paused = false;
            \curl_pause($this->handle, \CURLPAUSE_CONT);
        }
    }
    public function detach() { $this->close(); return null; }
    public function getSize(): ?int { return null; }
    public function tell(): int { throw new \RuntimeException('Streaming body is not seekable'); }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = \SEEK_SET): void { throw new \RuntimeException('Streaming body is not seekable'); }
    public function rewind(): void { throw new \RuntimeException('Streaming body is not seekable'); }
    public function isWritable(): bool { return false; }
    public function isReadable(): bool { return true; }
    public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
}
