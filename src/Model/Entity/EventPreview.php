<?php
namespace App\Model\Entity;

use Cake\I18n\Time;

class EventPreview
{

    public $id;
    public $name;
    public $short_description;
    public $long_description;
    public $advisories;
    public $event_start;
    public $event_end;
    public $booking_start;
    public $booking_end;
    public $cost;
    public $free_spaces;
    public $paid_spaces;
    public $total_spaces;
    public $members_only;
    public $age_restriction;
    public $attendees_require_approval;
    public $attendee_cancellation;
    public $class_number;
    public $sponsored;
    public $status;
    public $room_id;
    public $room;
    public $contact_id;
    public $contact;
    public $eventbrite_link;
    public $fulfills_prerequisite_id;
    public $fulfills_prerequisite;
    public $requires_prerequisite_id;
    public $requires_prerequisite;
    public $part_of_id;
    public $part_of;
    public $copy_of_id;
    public $copy_of;
    public $rejected_by;
    public $rejection_reason;
    public $created_by;
    public $created;
    public $modified;
    public $honoraria;
    public $categories;
    public $tools;
    public $notifyInstructorRegistrations;
    public $notifyInstructorCancellations;

    public function __construct($data = [], $host)
    {
        foreach ($data as $key => $value) {
            $keyPreview = '';
            $keyPreview = str_replace('_preview', '', $key);
            if (property_exists($this, $key)) {
                $this->$key = $this->getFormValue($key, $value);
            } else if (property_exists($this, $keyPreview)) {
                $this->$keyPreview = $this->getFormValue($keyPreview, $value);
            }
        }
        $this->room = $this->room_id;
        $this->address = '1825 Monetary Ln #104 Carrollton, TX 75006';
        $this->contact = $host;

        // Process categories
        $this->categories = [];
        $cat_string = $data['optional_categories']['_ids'][0] ?? "";
        // String from form is ';' delimited so we split it
        if ($cat_string) {
            $cat_array = explode(';', $cat_string);
            foreach ($cat_array as $cat) {
                $this->categories[] = explode(':', $cat);
            }
        }

        // Process tools
        $this->tools = [];
        $tool_string = $data['tools']['_ids'][0] ?? "";
        // String from form is ';' delimited so we split it
        if ($tool_string) {
            $tool_array = explode(';', $tool_string);
            foreach ($tool_array as $tool) {
                $this->tools[] = explode(':', $tool);
            }
        }

        // Process timestamps
        $this->attendee_cancellation = $this->convertToOffset($this->event_start, $this->attendee_cancellation, 'attendee_cancellation');
        $this->total_spaces = max($this->free_spaces, $this->paid_spaces);

        $this->files = [];
        
    }

    public function getFormValue(String $key, $value) {
        if (($key == 'event_start' || $key == 'event_end') && is_string($value)) {
            return $this->convertToDate($value);
        } else {
            return $value; 
        }
    }

    public function convertToDate(String $date) : Time {
        if (isset($date)) {
            $dateObj = Time::createFromFormat(
                'm/d/Y h:i A',
                $date,
                'America/Chicago'
            );

            return $dateObj;
        }

        return false;
    }

    public function convertToOffset(Time $dateObj, String $relation, String $field)
    {
        $operation = ($field == 'booking_end' ? '+' : '-');
        $measure = ($field == 'attendee_cancellation' ? 'Days' : 'Minutes');

        if (isset($dateObj) && isset($relation)) {
            return $dateObj->modify($operation . $relation . ' ' . $measure);
        }

        return false;
    }
}
