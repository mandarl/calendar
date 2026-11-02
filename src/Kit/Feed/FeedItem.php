<?php

declare(strict_types=1);

namespace Dms\Kit\Feed;

use DateTimeInterface;

/**
 * One <item> of the events feed. Mirrors what EventsController::feed() (src/Controller/EventsController.php L451-L509) put into
 * a FeedIo\Feed\Item.
 */
final class FeedItem
{
    /**
     * @param string                    $title        $event->name
     * @param string                    $link         event view URL
     * @param string                    $publicId     same URL (rendered as <guid>, JSON `id`)
     * @param string                    $description  rendered Events/feed_contents HTML (escaped by the writer)
     * @param DateTimeInterface|null    $lastModified see FeedWriter::legacyModified() for how the old code derived it
     * @param string|null               $authorName   $event->contact->name
     * @param string|null               $authorUri    the old code always set "infra@..." here (JSON `author.url`; not in RSS)
     * @param list<FeedCategory>        $categories
     * @param list<FeedMedia>           $media
     */
    public function __construct(
        public readonly string $title,
        public readonly string $link,
        public readonly string $publicId,
        public readonly string $description,
        public readonly ?DateTimeInterface $lastModified = null,
        public readonly ?string $authorName = null,
        public readonly ?string $authorUri = null,
        public readonly array $categories = [],
        public readonly array $media = [],
    ) {
    }
}
