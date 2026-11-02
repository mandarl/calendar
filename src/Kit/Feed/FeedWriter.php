<?php

declare(strict_types=1);

namespace Dms\Kit\Feed;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;

/**
 * Replacement for debril/feed-io 3.1.5 as used by EventsController::feed() (rss / json branches, L520-L545):
 *
 *     $feedIo = \FeedIo\Factory::create()->getFeedIo();
 *     $feedIo->format($feed, 'rss');   // text/xml
 *     $feedIo->format($feed, 'json');  // application/json (JSON Feed 1.0)
 *
 * `/events/feed/atom` and any unknown type also fall through to the RSS branch in the old code (there is no Atom output).
 *
 * No third-party library: feed-io 3.x is abandoned and its successor (php-feed-io/feed-io 6.x) needs a PSR-18 client and
 * different element/whitespace conventions (see README.md, Known incompatibilities). This class uses ext-dom only and is verified byte-for-byte against
 * the baseline golden (tests/Unit/Feed/FeedWriterTest) and against output generated with the real old library
 * (tests/fixtures/feed/old-lib-*, produced by tools/generate-old-feed-fixtures.php).
 *
 * RSS structure reproduced (feed-io Rss standard rule order):
 *   <?xml version="1.0" encoding="utf-8"?>\n<rss version="2.0"><channel>
 *     <title/> <link> <description> <pubDate>(now)</pubDate>
 *     <item> <title> <link> <description> <pubDate> <category domain=..>* <author> <guid> <enclosure/> </item>*
 *   </channel></rss>\n
 * with no pretty-printing. Item <enclosure> is emitted for the FIRST media only (feed-io returns inside its loop).
 */
final class FeedWriter
{
    /**
     * @param iterable<FeedItem> $items
     * @param DateTimeInterface|null $now channel <pubDate> (default: current time); injectable for deterministic tests
     */
    public function toRss(FeedChannel $channel, iterable $items, ?DateTimeInterface $now = null): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $main = $doc->createElement('channel');
        $rss->appendChild($main);
        $doc->appendChild($rss);

        $this->add($main, self::el($doc, 'title', $channel->title));
        $this->add($main, self::el($doc, 'link', $channel->link));
        $this->add($main, self::el($doc, 'description', $channel->description));
        $this->add($main, self::el($doc, 'pubDate', ($now ?? new DateTimeImmutable())->format(DATE_RSS)));

        foreach ($items as $item) {
            $node = $doc->createElement('item');
            $this->add($node, self::el($doc, 'title', $item->title));
            $this->add($node, self::el($doc, 'link', $item->link));
            $this->add($node, self::el($doc, 'description', $item->description));
            $this->add($node, self::el($doc, 'pubDate', ($item->lastModified ?? new DateTimeImmutable())->format(DATE_RSS)));
            foreach ($item->categories as $category) {
                $el = self::el($doc, 'category', $category->text());
                $el->setAttribute('domain', $category->scheme ?? '');
                $this->add($node, $el);
            }
            if ($item->authorName !== null) {
                $this->add($node, self::el($doc, 'author', $item->authorName));
            }
            $this->add($node, self::el($doc, 'guid', $item->publicId));
            if ($item->media !== []) {
                $first = $item->media[0];
                $enclosure = $doc->createElement('enclosure');
                $enclosure->setAttribute('url', $first->url);
                $enclosure->setAttribute('type', $first->type);
                $enclosure->setAttribute('length', (string)$first->length);
                $this->add($node, $enclosure);
            }
            $main->appendChild($node);
        }

        return (string)$doc->saveXML();
    }

    /**
     * JSON Feed 1.0 exactly as feed-io 3.1.5's JsonFormatter: json_encode() with default flags (so "/" and non-ASCII are
     * escaped) and array_filter() on the top level and on every item (empty/zero values dropped, including an empty `items`).
     *
     * @param iterable<FeedItem> $items
     */
    public function toJson(FeedChannel $channel, iterable $items): string
    {
        $out = [];
        foreach ($items as $item) {
            $isHtml = $item->description !== strip_tags($item->description);
            $row = [
                'id' => $item->publicId,
                'title' => $item->title,
                ($isHtml ? 'content_html' : 'content_txt') => $item->description,
                'url' => $item->link,
            ];
            if ($item->authorName !== null) {
                $row['author'] = array_filter(['name' => $item->authorName, 'url' => $item->authorUri]);
            }
            if ($item->media !== []) {
                $row['image'] = $item->media[0]->url;
            }
            if ($item->lastModified !== null) {
                $row['date_published'] = $item->lastModified->format(DATE_RFC3339);
            }
            $out[] = array_filter($row);
        }

        return (string)json_encode(array_filter([
            'version' => 'https://jsonfeed.org/version/1',
            'title' => $channel->title,
            'description' => $channel->description,
            'home_page_url' => $channel->link,
            'id' => $channel->publicId,
            'items' => $out,
        ]));
    }

    /**
     * The old controller did `setLastModified(new \DateTime($event->modified))`, where $event->modified is a Cake FrozenTime
     * that gets cast to string through the locale "short" format ("12/16/30, 4:07 PM"). That drops the seconds and re-parses in
     * the PHP default timezone (UTC in the container). To reproduce the golden `pubDate` values from a full-precision
     * database timestamp, pass it through this helper: seconds -> 0, timezone -> UTC.
     */
    public static function legacyModified(DateTimeInterface $modified): DateTimeImmutable
    {
        $utc = DateTimeImmutable::createFromInterface($modified)->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int)$utc->format('G'), (int)$utc->format('i'), 0);
    }

    /**
     * Element with text content. The old rules passed `htmlspecialchars($value)` (title, description) or the raw value (link,
     * guid, category, author) to DOMDocument::createElement(), whose value argument is parsed as XML (an "&" in a raw value
     * produced "unterminated entity reference" warnings and truncated text). Escaping every value gives the old output for
     * all inputs that worked and the correct output for the ones that did not.
     */
    private static function el(DOMDocument $doc, string $name, ?string $value): DOMElement
    {
        return $doc->createElement($name, htmlspecialchars(mb_scrub((string)$value)));
    }

    private function add(DOMElement $parent, DOMElement $child): void
    {
        $parent->appendChild($child);
    }
}
