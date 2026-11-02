<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

/**
 * Feeds (RSS/JSON/iCalendar, including the "atom returns RSS" quirk), the
 * JSON event list, the single-event ICS download, and the add-to-calendar
 * links on the event page. All of these are public (no login).
 */
class EventsFeedControllerTest extends CalendarAdminTestSupport
{
    use EventsSeedTrait;

    private string $diskPath = '';
    private string $diskDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskDir = WWW_ROOT . 'files/ahl-feed-test';
        $this->diskPath = $this->diskDir . '/test.png';
        if (!is_dir($this->diskDir)) {
            mkdir($this->diskDir, 0777, true);
        }
        file_put_contents($this->diskPath, '0123456789AB');
    }

    protected function tearDown(): void
    {
        if ($this->diskPath !== '' && is_file($this->diskPath)) {
            unlink($this->diskPath);
        }
        if ($this->diskDir !== '' && is_dir($this->diskDir)) {
            rmdir($this->diskDir);
        }
        parent::tearDown();
    }

    /**
     * An approved event starting tomorrow (UTC) with a room, a contact, a
     * category, one private file (skipped by feeds) and one public image.
     */
    private function seedFeedEvent(): array
    {
        $contact = $this->seedContact(['name' => 'Feed Contact']);
        $room = $this->seedRoom(['name' => 'Feed Room']);
        $event = $this->seedEvent([
            'name' => 'Feed Event',
            'short_description' => 'Feed short description',
            'long_description' => 'Feed long description',
            'contact' => $contact, 'room' => $room,
            'status' => 'approved',
            'event_start' => '2031-01-15 16:00:00', 'event_end' => '2031-01-15 18:00:00',
        ]);
        $category = $this->make('Categories', ['name' => 'Feed Category']);
        $this->linkCategory($event->id, $category->id);
        $this->make('Files', [
            'file' => 'private.pdf', 'dir' => 'webroot/files/ahl-feed-test/',
            'type' => 'application/pdf', 'event_id' => $event->id, 'private' => 1,
        ]);
        $this->make('Files', [
            'file' => 'test.png', 'dir' => 'webroot/files/ahl-feed-test/',
            'type' => 'image/png', 'event_id' => $event->id, 'private' => 0,
        ]);

        return [$event, $contact, $room];
    }

    public function testFeedRssContainsItemWithDescriptionAndEnclosure(): void
    {
        [$event, $contact, $room] = $this->seedFeedEvent();
        $this->seedEvent(['name' => 'Past Feed Event', 'status' => 'approved',
            'event_start' => '2020-01-15 16:00:00', 'event_end' => '2020-01-15 18:00:00']);
        $this->get('/events/feed/rss');
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'text/xml');
        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('<rss version="2.0">', $body);
        $this->assertStringContainsString('Feed Event', $body);
        $this->assertStringContainsString('/events/view/' . $event->id, $body);
        $this->assertStringContainsString('Feed Contact', $body);
        $this->assertStringContainsString('Feed short description', $body);
        $this->assertStringContainsString('Feed Category', $body);
        $this->assertStringContainsString('test.png', $body);
        $this->assertStringContainsString('length="12"', $body);
        $this->assertStringNotContainsString('Past Feed Event', $body);
        $this->assertStringNotContainsString('private.pdf', $body);
    }

    public function testFeedAtomAndUnknownTypesReturnRss(): void
    {
        $this->seedFeedEvent();
        foreach (['atom', 'bogus'] as $type) {
            $this->cleanup();
            $this->get('/events/feed/' . $type);
            $this->assertResponseOk('feed/' . $type);
            $this->assertHeaderContains('Content-Type', 'text/xml', 'feed/' . $type);
            $this->assertStringContainsString('<rss version="2.0">', (string)$this->_response->getBody(), 'feed/' . $type);
        }
    }

    public function testFeedJsonIsJsonFeed(): void
    {
        [$event, $contact, $room] = $this->seedFeedEvent();
        $this->get('/events/feed/json');
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'application/json');
        $decoded = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('https://jsonfeed.org/version/1', $decoded['version']);
        $this->assertCount(1, $decoded['items']);
        $this->assertSame('Feed Event', $decoded['items'][0]['title']);
        $this->assertStringContainsString('/events/view/' . $event->id, $decoded['items'][0]['id']);
        $this->assertSame('Feed Contact', $decoded['items'][0]['author']['name']);
        $this->assertStringContainsString('test.png', $decoded['items'][0]['image']);
    }

    public function testFeedVcalIsICalendar(): void
    {
        [$event, $contact, $room] = $this->seedFeedEvent();
        $this->get('/events/feed/vcal');
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'text/calendar');
        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('DTSTART:20310115T160000Z', $body);
        $this->assertStringContainsString('DTEND:20310115T180000Z', $body);
        $this->assertStringContainsString('UID:dmsevtv3' . $event->id . '@calendar.dallasmakerspace.org', $body);
        $this->assertStringContainsString('SUMMARY:Feed Event', $body);
        $this->assertStringContainsString('LOCATION:Feed Room', $body);
    }

    public function testFeedNewJsonListsUpcomingEventsWithCorsHeader(): void
    {
        [$event, $contact, $room] = $this->seedFeedEvent();
        $this->get('/events/feed/newJson');
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'application/json');
        $this->assertHeader('Access-Control-Allow-Origin', '*');
        $decoded = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(1, $decoded);
        $this->assertSame('Feed Event', $decoded[0]['name']);
        $this->assertSame('Feed Room', $decoded[0]['room']);
        $this->assertSame('Feed Contact', $decoded[0]['contact']);
        $this->assertSame('$0.00', $decoded[0]['cost']);
        $this->assertSame('test.png', basename($decoded[0]['images'][0]['url']));
        $this->assertSame(12, $decoded[0]['images'][0]['length']);
    }

    public function testIcsDownload(): void
    {
        [$event, $contact, $room] = $this->seedFeedEvent();
        $this->get('/events/ics/' . $event->id);
        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Disposition', 'dmsevtv3_' . $event->id . '.ics');
        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('SUMMARY:Feed Event', $body);
        $this->assertStringContainsString('Carrollton', $body);
    }

    public function testEventViewContainsAddToCalendarLinks(): void
    {
        [$event, $contact, $room] = $this->seedFeedEvent();
        $this->get('/events/view/' . $event->id);
        $this->assertResponseOk();
        $this->assertResponseContains('calendar.google.com/calendar/render');
        $this->assertResponseContains('outlook.live.com/calendar/0/deeplink/compose');
        $this->assertResponseContains('/events/ics/' . $event->id);
        $this->assertResponseContains('Add to Google Calendar');
    }

    public function cleanup(): void
    {
        parent::cleanup();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
    }
}
