<?php

namespace App\Threads;

use Carbon\CarbonImmutable;

/**
 * Metrics parsed from one public post page. Every count is nullable:
 * null means "not available", never "zero". caption is the post text (null when the post has none).
 */
final readonly class PostData
{
    public function __construct(
        public string $shortcode,
        public ?string $pk,
        public ?string $username,
        public ?CarbonImmutable $takenAt,
        public ?int $views,
        public ?int $likes,
        public ?int $replies,
        public ?int $reposts,
        public ?int $quotes,
        public ?int $shares,
        public bool $countsHidden,
        public ?string $caption = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'shortcode' => $this->shortcode,
            'pk' => $this->pk,
            'username' => $this->username,
            'taken_at' => $this->takenAt?->toIso8601ZuluString(),
            'views' => $this->views,
            'likes' => $this->likes,
            'replies' => $this->replies,
            'reposts' => $this->reposts,
            'quotes' => $this->quotes,
            'shares' => $this->shares,
            'counts_hidden' => $this->countsHidden,
            'caption' => $this->caption,
        ];
    }
}
