<?php

declare(strict_types=1);

namespace Dms\Kit\Feed;

/** Channel level data. The old code never set a title (so the output has an empty <title/> and no JSON `title`). */
final class FeedChannel
{
    public function __construct(
        public readonly string $link,
        public readonly string $description,
        public readonly string $publicId,
        public readonly ?string $title = null,
    ) {
    }
}
