<?php

declare(strict_types=1);

namespace Dms\Kit\Feed;

/**
 * The old controller added every category twice: once with term+scheme+label (`scheme` = "event:category") and once with
 * only a label. feed-io's RSS rule renders text = term ?? label and domain = scheme (empty string when null), which gives
 * <category domain="event:category">X</category><category domain="">X</category>.
 */
final class FeedCategory
{
    public function __construct(
        public readonly ?string $term = null,
        public readonly ?string $scheme = null,
        public readonly ?string $label = null,
    ) {
    }

    public function text(): string
    {
        return $this->term ?? $this->label ?? '';
    }
}
