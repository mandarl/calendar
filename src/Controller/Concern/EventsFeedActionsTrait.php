<?php
declare(strict_types=1);

namespace App\Controller\Concern;

use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\I18n\DateTime;
use Cake\Routing\Router;
use Dms\Kit\Feed\FeedCategory;
use Dms\Kit\Feed\FeedChannel;
use Dms\Kit\Feed\FeedItem;
use Dms\Kit\Feed\FeedMedia;
use Dms\Kit\Feed\FeedWriter;

/**
 * EventsController actions: FeedActions (RSS / JSON feed, iCalendar download, the JSON event list and the
 * "add to calendar" links of the event page).
 *
 * Split out of the Cake 3 EventsController so that several people can port the Events controller in parallel. Used by
 * App\Controller\EventsController. Ported to Cake 5 by slice L: the FeedIo block is replaced by the kit's FeedWriter
 * (byte-identical output, see calendar-cake5-libkit/README.md F-1).
 */
trait EventsFeedActionsTrait
{
    /**
     * JSON list of the upcoming approved events (served as /events/feed/newJson, CORS open).
     *
     * @return string
     */
    public function eventsJson()
    {
        $this->disableAutoRender();

        $events = $this->upcomingFeedQuery();

        $output = [];
        /** @var \App\Model\Entity\Event $event */
        foreach ($events as $event) {
            $initialTotalSpaces = $this->Events->getTotalSpaces($event->id);
            if ($initialTotalSpaces === true) {
                $initialTotalSpaces = -1;
            }
            $initialFilledSpaces = $this->Events->getFilledSpaces($event->id);
            if ($initialFilledSpaces === true) {
                $initialFilledSpaces = -1;
            }

            $evtObj = [
                'id' => $event->id,
                'name' => $event->name,
                'cost' => "\${$event->cost}.00",
                'eventStart' => $event->event_start,
                'eventEnd' => $event->event_end,
                'room' => $event->room->name,
                'contact' => $event->contact->name,
                'shortDescription' => $event->short_description,
                'longDescription' => $event->long_description,
                'totalSpaces' => $initialTotalSpaces,
                'filledSpaces' => $initialFilledSpaces,
            ];

            $imageEndings = ['png', 'jpeg', 'jpg', 'gif', 'webp', 'svg'];

            /** @var \App\Model\Entity\File $file */
            foreach ($event->files as $file) {
                if ($file->private) {
                    continue;
                }

                $exploded = explode('.', $file->file);
                $imageEnding = ($exploded !== false && count($exploded) >= 2) ? $exploded[count($exploded) - 1] : '';
                $filePayload = [
                    'url' => Configure::read('App.fullBaseUrl') . '/' . str_replace('webroot/', '', $file->dir) . rawurlencode($file->file),
                    'type' => $file->type,
                    'length' => filesize(ROOT . DS . $file->dir . $file->file),
                ];
                if (in_array($imageEnding, $imageEndings)) {
                    $evtObj['images'][] = $filePayload;
                } else {
                    $evtObj['files'][] = $filePayload;
                }
            }

            $output[] = (object)$evtObj;
        }

        return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Event feed. $feedtype: "vcal" (default, iCalendar), "json" (JSON Feed), "newJson" (eventsJson()), "rss";
     * anything else ("atom" included) returns RSS, as in the Cake 3 build.
     *
     * @param string $feedtype Feed type
     * @return \Cake\Http\Response
     */
    public function feed($feedtype = 'vcal')
    {
        if ($feedtype === 'newJson') {
            $this->response = $this->response
                ->withStringBody($this->eventsJson())
                ->withType('application/json')
                ->withAddedHeader('Access-Control-Allow-Origin', '*');

            return $this->response;
        }

        // TODO: Accept arguments like calendar view and include location
        $this->disableAutoRender();

        $now = DateTime::now();
        $event_query = $this->upcomingFeedQuery();

        // This allows us to use the applyQueryFilters method for the feed
        // It creates an event object so that applyQueryFilters is happy
        $feed_event_subject = new \stdClass();
        $feed_event_subject->query = $event_query;
        $feed_event = new Event('feed_event', $feed_event_subject);

        $this->__applyQueryFilters($feed_event);

        $events = $feed_event->getSubject()->query;

        if ($feedtype === 'vcal') {
            $vcalendar = '';

            $vcalendar .= "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\nCALSCALE:GREGORIAN\r\n";

            foreach ($events as $event) {
                $event_url = Router::url(['controller' => 'Events', 'action' => 'view', 'id' => $event->id], true);

                $vcalendar .= "BEGIN:VEVENT\r\n";
                $vcalendar .= 'DTSTART:' . $this->__icsDate($event->event_start) . "\r\n";
                $vcalendar .= 'DTEND:' . $this->__icsDate($event->event_end) . "\r\n";
                $vcalendar .= 'DTSTAMP:' . $this->__icsDate($now) . "\r\n";
                $vcalendar .= 'UID:dmsevtv3' . $event->id . "@calendar.dallasmakerspace.org\r\n";
                $vcalendar .= 'SUMMARY:' . $this->__icsEscapeString($event->name) . "\r\n";
                $vcalendar .= 'DESCRIPTION:' . $this->__icsEscapeString($event->short_description . ' Event details at ' . $event_url) . "\r\n";
                $vcalendar .= 'LOCATION:' . $event->room->name . "\r\n";
                $vcalendar .= 'URL;VALUE=URI:' . $this->__icsEscapeString($event_url) . "\r\n";
                $vcalendar .= "END:VEVENT\r\n";
            }

            $vcalendar .= 'END:VCALENDAR';

            $this->response = $this->response
                ->withStringBody($vcalendar)
                ->withType('text/calendar');
        } else {
            // get id's for categories and tools
            $type = $this->request->getQuery('type');
            $category = $this->request->getQuery('category');
            $tool = $this->request->getQuery('tool');
            $room = $this->request->getQuery('room');

            // get names for categories and tools
            $description_addon = '';

            $subjects = [];

            // is_string(): ctype_digit(null) is deprecated since PHP 8.1 and was false in the Cake 3 build.
            if (is_string($type) && ctype_digit($type)) {
                $subjects[] = $this->Events->Categories->find('list')->where(['id' => $type])->first();
            }

            if (is_string($category) && ctype_digit($category)) {
                $subjects[] = $this->Events->Categories->find('list')->where(['id' => $category])->first();
            }

            if (is_string($tool) && ctype_digit($tool)) {
                $subjects[] = $this->Events->Tools->find('list')->where(['id' => $tool])->first();
            }

            if (is_string($room) && ctype_digit($room)) {
                $subjects[] = $this->Events->Rooms->find('list')->where(['id' => $room])->first();
            }

            if (!empty($subjects)) {
                $description_addon = ' - Subjects: ' . implode(', ', $subjects);
            } else {
                $description_addon = ' - Subjects: All Included';
            }

            $items = [];
            /** @var \App\Model\Entity\Event $event */
            foreach ($events as $event) {
                // The description is the rendered Events/feed_contents template (needs the Time helper: createView()).
                // Cake 5's createView() leaves the template path empty, so the template must be named with its folder;
                // Cake 3 resolved the bare name through the controller's template path.
                $view = $this->createView();
                $view->set('event', $event);
                $desc_html = $view->render('Events/feed_contents', false);

                $url = Router::url(['controller' => 'Events', 'action' => 'view', 'id' => $event->id], true);

                // The Cake 3 code added every category twice: with term, scheme and label, then with the label only.
                $categories = [];
                foreach ($event->categories as $category) {
                    $categories[] = new FeedCategory($category->name, 'event:category', $category->name);
                }

                $media = [];
                /** @var \App\Model\Entity\File $file */
                foreach ($event->files as $file) {
                    if ($file->private) {
                        continue;
                    }

                    $exploded = explode('.', $file->file);
                    $imageEnding = ($exploded !== false && count($exploded) >= 2) ? $exploded[count($exploded) - 1] : '';
                    if (in_array($imageEnding, ['png', 'jpeg', 'jpg', 'gif', 'webp', 'svg'])) {
                        $media[] = new FeedMedia(
                            Configure::read('App.fullBaseUrl') . '/' . str_replace('webroot/', '', $file->dir) . rawurlencode($file->file),
                            (string)$file->type,
                            filesize(ROOT . DS . $file->dir . $file->file)
                        );
                    }
                }

                foreach ($event->categories as $category) {
                    $categories[] = new FeedCategory(null, null, $category->name);
                }

                $items[] = new FeedItem(
                    (string)$event->name,
                    $url,
                    $url,
                    $desc_html,
                    // Cake 3: new \DateTime($event->modified) went through the short locale format (no seconds).
                    FeedWriter::legacyModified($event->modified),
                    (string)$event->contact->name,
                    'infra@dallasmakerspace.org',
                    $categories,
                    $media
                );
            }

            // output feed in format specified
            $description = 'Events and Classes available at the Dallas Makerspace' . $description_addon;
            $writer = new FeedWriter();

            if ($feedtype === 'json') {
                $jsonUrl = $this->feedUrl('json');
                $this->response = $this->response
                    ->withStringBody($writer->toJson(new FeedChannel($jsonUrl, $description, $jsonUrl), $items))
                    ->withType('application/json');
            } else {
                // "rss", and the default for every other type (atom included).
                $rssUrl = $this->feedUrl('rss');
                $this->response = $this->response
                    ->withStringBody($writer->toRss(new FeedChannel($rssUrl, $description, $rssUrl), $items, DateTime::now()))
                    ->withType('text/xml');
            }
        }

        return $this->response;
    }

    /**
     * Links of the "add to calendar" buttons on the event page (Google, Outlook, iCalendar). Called by
     * EventsController::view(), keep the signature.
     *
     * @param object $event Event entity
     * @return array<int, array<string, string>>
     */
    public function getAddToCalLinks(object $event)
    {
        $start_date_iso8601 = $this->getISO8601Date($event->event_start);
        $end_date_iso8601 = $this->getISO8601Date($event->event_end);
        // DateTime is immutable: setTimezone() returns a copy and leaves $event untouched (as FrozenTime did).
        $start_date_c = urlencode($event->event_start->setTimezone('America/Chicago')->i18nFormat("yyyy-MM-dd'T'HH:mm:ss")); // 2020-04-19T13:30:00Z
        $end_date_c = urlencode($event->event_end->setTimezone('America/Chicago')->i18nFormat("yyyy-MM-dd'T'HH:mm:ss"));
        $title = urlencode($event->name);
        $event_url = Router::url(['controller' => 'Events', 'action' => 'view', 'id' => $event->id], true);
        $description = urlencode($event->short_description . "\r\n\r\n" . $event_url);
        $address = '1825%20Monetary%20Ln%20%23104%20Carrollton%2C%20TX%2075006';
        $gcal = [
            'icon' => 'gcal.svg',
            'url' => "https://calendar.google.com/calendar/render?action=TEMPLATE&dates=$start_date_iso8601%2F$end_date_iso8601&details=$description&location=$address&text=$title",
            'hint' => 'Add to Google Calendar',
        ];
        $outlook = [
            'icon' => 'outlook.svg',
            'url' => "https://outlook.live.com/calendar/0/deeplink/compose?allday=false&body=$description&enddt=$end_date_c&location=$address&path=%2Fcalendar%2Faction%2Fcompose&rru=addevent&startdt=$start_date_c&subject=$title",
            'hint' => 'Add to Outlook',
        ];
        $apple = [
            'icon' => 'apple.svg',
            'url' => Router::url(['controller' => 'Events', 'action' => 'ics', $event->id], true),
            'hint' => 'Add to iPhone / mac',
        ];

        return [$gcal, $outlook, $apple];
    }

    /**
     * UTC timestamp in the basic ISO 8601 form used by the Google Calendar link (20310115T160000Z). The time is
     * formatted in its own zone, the event times come out of the database in UTC.
     *
     * @param \Cake\I18n\DateTime $start_date Date
     * @return string
     */
    public function getISO8601Date(DateTime $start_date)
    {
        return $start_date->i18nFormat("yyyyMMdd'T'HHmmss'Z'");
    }

    /**
     * iCalendar download of one event (/events/ics/ID). Public.
     *
     * @param string|int|null $id Event id
     * @return void
     */
    public function ics($id = null)
    {
        $this->disableAutoRender();
        $now = DateTime::now();
        $event = $this->Events->find()
            ->where(['id' => $id])->first();
        $vcalendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\nCALSCALE:GREGORIAN\r\n";

        $event_url = Router::url(['controller' => 'Events', 'action' => 'view', $event->id], true);

        $vcalendar .= "BEGIN:VEVENT\r\n";
        $vcalendar .= 'DTSTART:' . $this->__icsDate($event->event_start) . "\r\n";
        $vcalendar .= 'DTEND:' . $this->__icsDate($event->event_end) . "\r\n";
        $vcalendar .= 'DTSTAMP:' . $this->__icsDate($now) . "\r\n";
        $vcalendar .= 'UID:dmsevtv3' . $event->id . "@calendar.dallasmakerspace.org\r\n";
        $vcalendar .= 'SUMMARY:' . $this->__icsEscapeString($event->name) . "\r\n";
        $vcalendar .= 'DESCRIPTION:' . $this->__icsEscapeString($event->short_description . ' Event details at ' . $event_url) . "\r\n";
        $vcalendar .= 'LOCATION:' . $this->__icsEscapeString('1825 Monetary Ln #104 Carrollton, TX 75006') . "\r\n";
        $vcalendar .= 'URL;VALUE=URI:' . $this->__icsEscapeString($event_url) . "\r\n";
        $vcalendar .= "END:VEVENT\r\n";

        $vcalendar .= 'END:VCALENDAR';

        $filename = 'dmsevtv3_' . $event->id . '.ics';

        $this->response = $this->response
            ->withType('ics')
            ->withDownload($filename)
            ->withStringBody("$vcalendar");
    }

    /**
     * @param \DateTimeInterface $date Date (UTC)
     * @return string
     */
    private function __icsDate($date)
    {
        return $date->format('Ymd\THis\Z');
    }

    /**
     * @param string|null $string Text
     * @return string
     */
    private function __icsEscapeString($string)
    {
        return preg_replace('/([\,;])/', '\\\$1', (string)$string);
    }

    /**
     * Query shared by eventsJson() and feed(): approved events from midnight (Chicago) today on.
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    private function upcomingFeedQuery()
    {
        // Cake 3 changed the mutable Time in place; the Cake 5 DateTime is immutable, so assign the result.
        $today = (new DateTime('America/Chicago'))->startOfDay()->setTimezone('UTC');

        return $this->Events->find('all')
            ->select(
                [
                    'Events.id',
                    'Events.event_start',
                    'Events.event_end',
                    'Events.name',
                    'Events.cost',
                    'Events.room_id',
                    'Events.short_description',
                    'Events.long_description',
                    'Rooms.name',
                    'Contacts.name',
                    'Events.modified',
                ]
            )
            ->where(
                [
                    'Events.event_start >=' => $today,
                    'Events.status' => 'approved',
                ]
            )
            ->contain(['Rooms', 'Contacts', 'Categories', 'Files'])
            ->orderBy(['event_start' => 'ASC']);
    }

    /**
     * Absolute https URL of a feed (the Cake 3 code asked for `_ssl`, whatever the request scheme was).
     *
     * @param string $feedtype Feed type
     * @return string
     */
    private function feedUrl(string $feedtype): string
    {
        return Router::url(
            ['controller' => 'Events', 'action' => 'feed', 'feedtype' => $feedtype, '_scheme' => 'https'],
            true
        );
    }
}
