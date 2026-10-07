<?php

namespace App\Services\Websites;

/**
 * What came back from fetching one page of a customer's website.
 */
final class FetchResult
{
    /**
     * @param  array<string, string>  $headers  lower-case names
     */
    public function __construct(
        public readonly string $url,
        public readonly ?int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly int $milliseconds = 0,
        public readonly ?string $error = null,
        public readonly bool $truncated = false,
    ) {}

    public function ok(): bool
    {
        return $this->error === null && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function isHtml(): bool
    {
        return str_contains(strtolower($this->headers['content-type'] ?? 'text/html'), 'html');
    }
}
