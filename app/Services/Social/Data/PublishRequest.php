<?php

namespace App\Services\Social\Data;

/**
 * Everything one network needs to publish one post.
 */
final class PublishRequest
{
    /**
     * @param  list<array{url: string, disk: string, path: string, mime: string, alt: ?string}>  $images
     * @param  array<string, mixed>  $options
     * @param  list<array{url: string, disk: string, path: string, mime: string, alt: ?string, size: int}>  $videos  at most one (D104)
     */
    public function __construct(
        public readonly string $text,
        public readonly ?string $link = null,
        public readonly array $images = [],
        public readonly array $options = [],
        public readonly array $videos = [],
    ) {}

    /** @return array{url: string, disk: string, path: string, mime: string, alt: ?string, size: int}|null */
    public function video(): ?array
    {
        return $this->videos[0] ?? null;
    }
}
