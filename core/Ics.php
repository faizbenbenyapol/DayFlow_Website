<?php
// =====================================================
// core/Ics.php — iCalendar (RFC 5545) reader and writer
//
// Enough of the format to move events between DayFlow and Google Calendar,
// Apple Calendar or Outlook: VEVENT with a summary, description, start, end,
// all-day handling and the four repeat rules the app supports.
// =====================================================

final class Ics
{
    private const CRLF = "\r\n";

    /** Maps DayFlow repeat rules onto RRULE frequencies, and back. */
    private const FREQUENCIES = [
        'daily'   => 'DAILY',
        'weekly'  => 'WEEKLY',
        'monthly' => 'MONTHLY',
        'yearly'  => 'YEARLY',
    ];

    /**
     * Builds a VCALENDAR document from DayFlow event rows.
     *
     * Times are written as floating local times (no Z suffix, no TZID), which
     * is what a calendar app should interpret in the viewer's own zone. The app
     * already stores them that way.
     */
    public static function export(array $events, string $calendarName = 'DayFlow'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//DayFlow//Planner//TH',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escapeText($calendarName),
        ];

        $stamp = gmdate('Ymd\THis\Z');

        foreach ($events as $event) {
            $isAllDay = !empty($event['is_all_day']);
            $start    = new DateTimeImmutable((string)$event['start_datetime']);
            $end      = !empty($event['end_datetime'])
                ? new DateTimeImmutable((string)$event['end_datetime'])
                : $start->modify($isAllDay ? '+1 day' : '+1 hour');

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . (int)($event['id'] ?? 0) . '-dayflow@' . self::hostLabel();
            $lines[] = 'DTSTAMP:' . $stamp;

            if ($isAllDay) {
                // An all-day DTEND is exclusive: a single day ends the next day.
                $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:' . $start->modify('+1 day')->format('Ymd');
            } else {
                $lines[] = 'DTSTART:' . $start->format('Ymd\THis');
                $lines[] = 'DTEND:' . $end->format('Ymd\THis');
            }

            $lines[] = 'SUMMARY:' . self::escapeText((string)($event['title'] ?? ''));
            if (!empty($event['description'])) {
                $lines[] = 'DESCRIPTION:' . self::escapeText((string)$event['description']);
            }

            $rrule = self::buildRrule($event);
            if ($rrule !== null) $lines[] = 'RRULE:' . $rrule;

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map([self::class, 'fold'], $lines)) . self::CRLF;
    }

    /**
     * Parses a VCALENDAR document into rows shaped like CalendarEvent::create()
     * expects. Unknown properties and non-VEVENT components are ignored.
     */
    public static function parse(string $ics): array
    {
        $events  = [];
        $current = null;

        foreach (self::unfold($ics) as $line) {
            $upper = strtoupper($line);

            if ($upper === 'BEGIN:VEVENT') { $current = []; continue; }
            if ($upper === 'END:VEVENT') {
                if ($current !== null && isset($current['start_datetime'], $current['title'])) {
                    $events[] = $current + [
                        'description'  => null,
                        'end_datetime' => null,
                        'is_all_day'   => 0,
                        'repeat_rule'  => 'none',
                        'repeat_until' => null,
                    ];
                }
                $current = null;
                continue;
            }
            if ($current === null) continue;

            [$name, $params, $value] = self::splitLine($line);

            switch ($name) {
                case 'SUMMARY':
                    $current['title'] = mb_substr(self::unescapeText($value), 0, 255);
                    break;

                case 'DESCRIPTION':
                    $current['description'] = mb_substr(self::unescapeText($value), 0, 2000);
                    break;

                case 'DTSTART':
                    $parsed = self::parseDateTime($value, $params);
                    if ($parsed === null) break;
                    $current['start_datetime'] = $parsed['datetime'];
                    $current['is_all_day']     = $parsed['all_day'] ? 1 : 0;
                    break;

                case 'DTEND':
                    $parsed = self::parseDateTime($value, $params);
                    if ($parsed === null) break;
                    // An all-day DTEND is exclusive; store the last actual day.
                    $current['end_datetime'] = $parsed['all_day']
                        ? (new DateTimeImmutable($parsed['datetime']))->modify('-1 day')->format('Y-m-d H:i:s')
                        : $parsed['datetime'];
                    break;

                case 'RRULE':
                    $current += self::parseRrule($value);
                    break;
            }
        }

        return $events;
    }

    // --- RRULE ---

    private static function buildRrule(array $event): ?string
    {
        $rule = (string)($event['repeat_rule'] ?? 'none');
        if (!isset(self::FREQUENCIES[$rule])) return null;

        $rrule = 'FREQ=' . self::FREQUENCIES[$rule];
        if (!empty($event['repeat_until'])) {
            $until = new DateTimeImmutable(substr((string)$event['repeat_until'], 0, 10) . ' 23:59:59');
            $rrule .= ';UNTIL=' . $until->format('Ymd\THis');
        }
        return $rrule;
    }

    /** @return array{repeat_rule?: string, repeat_until?: string|null} */
    private static function parseRrule(string $value): array
    {
        $parts = [];
        foreach (explode(';', $value) as $pair) {
            $bits = explode('=', $pair, 2);
            if (count($bits) === 2) $parts[strtoupper(trim($bits[0]))] = trim($bits[1]);
        }

        $freq = strtoupper($parts['FREQ'] ?? '');
        $rule = array_search($freq, self::FREQUENCIES, true);
        if ($rule === false) return [];

        // INTERVAL other than 1 (every other week, say) has no equivalent here;
        // importing it as a plain weekly repeat would silently be wrong.
        if (isset($parts['INTERVAL']) && (int)$parts['INTERVAL'] !== 1) return [];

        $result = ['repeat_rule' => $rule];

        if (!empty($parts['UNTIL'])) {
            $until = self::parseDateTime($parts['UNTIL'], []);
            if ($until !== null) $result['repeat_until'] = substr($until['datetime'], 0, 10);
        }

        return $result;
    }

    // --- Value helpers ---

    /** @return array{datetime: string, all_day: bool}|null */
    private static function parseDateTime(string $value, array $params): ?array
    {
        $value  = trim($value);
        $allDay = ($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value) === 1;

        if ($allDay) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $value);
            return $date === false ? null : ['datetime' => $date->format('Y-m-d 00:00:00'), 'all_day' => true];
        }

        // A trailing Z means UTC; convert into the app's timezone so the time
        // a person sees matches the one they exported.
        $isUtc = str_ends_with($value, 'Z');
        $date  = DateTimeImmutable::createFromFormat(
            'Ymd\THis' . ($isUtc ? '\Z' : ''),
            $value,
            $isUtc ? new DateTimeZone('UTC') : null
        );
        if ($date === false) return null;

        if ($isUtc) $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return ['datetime' => $date->format('Y-m-d H:i:s'), 'all_day' => false];
    }

    /** @return array{0: string, 1: array<string,string>, 2: string} */
    private static function splitLine(string $line): array
    {
        $colon = strpos($line, ':');
        if ($colon === false) return ['', [], ''];

        $head  = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);

        $pieces = explode(';', $head);
        $name   = strtoupper(array_shift($pieces));

        $params = [];
        foreach ($pieces as $piece) {
            $bits = explode('=', $piece, 2);
            if (count($bits) === 2) $params[strtoupper($bits[0])] = strtoupper($bits[1]);
        }

        return [$name, $params, $value];
    }

    /**
     * Rejoins continuation lines. A folded line starts with a space or tab and
     * belongs to the line before it.
     */
    private static function unfold(string $ics): array
    {
        $lines  = preg_split('/\r\n|\n|\r/', $ics) ?: [];
        $joined = [];

        foreach ($lines as $line) {
            if ($line === '') continue;
            if (($line[0] === ' ' || $line[0] === "\t") && $joined !== []) {
                $joined[count($joined) - 1] .= substr($line, 1);
            } else {
                $joined[] = $line;
            }
        }

        return $joined;
    }

    /** Wraps a line at 75 octets, as the spec requires. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;

        $out   = '';
        $chunk = '';
        // Split on character boundaries so multi-byte text is not cut in half.
        foreach (mb_str_split($line) as $char) {
            if (strlen($chunk) + strlen($char) > 73) {
                $out  .= ($out === '' ? '' : self::CRLF . ' ') . $chunk;
                $chunk = '';
            }
            $chunk .= $char;
        }

        return $out . ($out === '' ? '' : self::CRLF . ' ') . $chunk;
    }

    private static function escapeText(string $value): string
    {
        return str_replace(
            ["\\", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '', '\\;', '\\,'],
            $value
        );
    }

    private static function unescapeText(string $value): string
    {
        return str_replace(
            ['\\n', '\\N', '\\;', '\\,', '\\\\'],
            ["\n", "\n", ';', ',', "\\"],
            $value
        );
    }

    private static function hostLabel(): string
    {
        $host = parse_url(APP_URL, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'dayflow.local';
    }
}
