<?php

declare(strict_types=1);

namespace Dms\Kit\Feed;

/** An item image (public event file with an image extension), rendered as <enclosure url type length/>. */
final class FeedMedia
{
    public function __construct(
        public readonly string $url,
        public readonly string $type,
        public readonly int|string $length,
    ) {
    }
}
