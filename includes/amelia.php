<?php
/**
 * Read-only access to Amelia events.
 *
 * Amelia is the single source of truth for course dates. This plugin never
 * writes to Amelia tables, so Kombi bookings do not show up in Amelia's
 * participant lists (they live in the WooCommerce orders).
 */

if (!defined('WPINC')) die;

function bs_amelia_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'amelia_' . $name;
}

function bs_amelia_is_available() {
    static $available = null;
    if ($available === null) {
        global $wpdb;
        $table = bs_amelia_table('events');
        $available = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
    return $available;
}

/**
 * Amelia stores event periods in UTC. Filterable in case an installation differs.
 */
function bs_amelia_times_are_utc() {
    return (bool) apply_filters('bs_booking_amelia_times_are_utc', true);
}

function bs_amelia_to_local($datetime) {
    $source_tz = bs_amelia_times_are_utc() ? new DateTimeZone('UTC') : wp_timezone();
    $date = new DateTimeImmutable($datetime, $source_tz);
    return $date->setTimezone(wp_timezone());
}

/**
 * Expands Amelia periods into calendar days.
 * A single period can span several days (daily from start to end time),
 * and an event can consist of several periods.
 */
function bs_amelia_period_days(array $periods) {
    $days = array();
    foreach ($periods as $period) {
        $start = bs_amelia_to_local($period['periodStart']);
        $end   = bs_amelia_to_local($period['periodEnd']);
        $day   = $start->setTime(0, 0);
        $last  = $end->setTime(0, 0);

        // A period ending exactly at midnight belongs to the previous day.
        if ($end == $last && $last > $day) {
            $last = $last->modify('-1 day');
        }

        while ($day <= $last) {
            $days[$day->format('Y-m-d')] = array(
                'date'  => $day->format('Y-m-d'),
                'start' => $start->format('H:i'),
                'end'   => $end->format('H:i'),
            );
            $day = $day->modify('+1 day');
        }
    }
    ksort($days);
    return array_values($days);
}

function bs_amelia_event_location(array $event, array $locations) {
    if (!empty($event['customLocation'])) {
        return trim($event['customLocation']);
    }
    $location_id = isset($event['locationId']) ? (int) $event['locationId'] : 0;
    if ($location_id && isset($locations[$location_id])) {
        $location = $locations[$location_id];
        return trim($location['address'] !== '' ? $location['address'] : $location['name']);
    }
    return '';
}

/**
 * All approved Amelia events that have not started yet, sorted by first day.
 * Returns [event_id => ['id', 'name', 'tags', 'location', 'days']].
 */
function bs_amelia_upcoming_events() {
    static $events = null;
    if ($events !== null) return $events;

    $events = array();
    if (!bs_amelia_is_available()) return $events;

    global $wpdb;
    $events_table    = bs_amelia_table('events');
    $periods_table   = bs_amelia_table('events_periods');
    $tags_table      = bs_amelia_table('events_tags');
    $locations_table = bs_amelia_table('locations');
    $now = bs_amelia_times_are_utc() ? gmdate('Y-m-d H:i:s') : current_time('mysql');

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT e.* FROM $events_table e
         INNER JOIN (SELECT eventId, MIN(periodStart) AS firstStart FROM $periods_table GROUP BY eventId) p ON p.eventId = e.id
         WHERE e.status = 'approved' AND p.firstStart > %s
         ORDER BY p.firstStart",
        $now
    ), ARRAY_A);
    if (empty($rows)) return $events;

    $ids = implode(',', array_map('intval', wp_list_pluck($rows, 'id')));

    $periods_by_event = array();
    foreach ($wpdb->get_results("SELECT eventId, periodStart, periodEnd FROM $periods_table WHERE eventId IN ($ids) ORDER BY periodStart", ARRAY_A) as $period) {
        $periods_by_event[(int) $period['eventId']][] = $period;
    }

    $tags_by_event = array();
    foreach ((array) $wpdb->get_results("SELECT eventId, name FROM $tags_table WHERE eventId IN ($ids)", ARRAY_A) as $tag) {
        $tags_by_event[(int) $tag['eventId']][] = $tag['name'];
    }

    // Booked persons per event (a booking is linked to every period of its event)
    $booked_by_event = array();
    $bookings_table        = bs_amelia_table('customer_bookings');
    $bookings_periods_table = bs_amelia_table('customer_bookings_to_events_periods');
    $booked_rows = $wpdb->get_results(
        "SELECT x.eventId, SUM(cb.persons) AS persons
         FROM (SELECT DISTINCT cbp.customerBookingId, ep.eventId
               FROM $bookings_periods_table cbp
               INNER JOIN $periods_table ep ON ep.id = cbp.eventPeriodId
               WHERE ep.eventId IN ($ids)) x
         INNER JOIN $bookings_table cb ON cb.id = x.customerBookingId
         WHERE cb.status IN ('approved', 'pending')
         GROUP BY x.eventId",
        ARRAY_A
    );
    foreach ((array) $booked_rows as $booked) {
        $booked_by_event[(int) $booked['eventId']] = (int) $booked['persons'];
    }

    $locations = array();
    foreach ((array) $wpdb->get_results("SELECT id, name, address FROM $locations_table", ARRAY_A) as $location) {
        $locations[(int) $location['id']] = array(
            'name'    => (string) $location['name'],
            'address' => (string) $location['address'],
        );
    }

    foreach ($rows as $row) {
        $id = (int) $row['id'];

        // Respect Amelia's "booking closes" setting if one is set.
        if (!empty($row['bookingCloses']) && $row['bookingCloses'] <= $now) continue;

        $days = bs_amelia_period_days(isset($periods_by_event[$id]) ? $periods_by_event[$id] : array());
        if (empty($days)) continue;

        // Capacity is only reliable for plain events. Ticket-based pricing has
        // per-ticket spots, so it is treated as unknown.
        $capacity = null;
        if (empty($row['customPricing']) && isset($row['maxCapacity']) && (int) $row['maxCapacity'] > 0) {
            $capacity = (int) $row['maxCapacity'];
        }

        $events[$id] = array(
            'id'       => $id,
            'name'     => (string) $row['name'],
            'tags'     => isset($tags_by_event[$id]) ? $tags_by_event[$id] : array(),
            'location' => bs_amelia_event_location($row, $locations),
            'days'     => $days,
            'capacity' => $capacity,
            'booked'   => isset($booked_by_event[$id]) ? $booked_by_event[$id] : 0,
        );
    }

    return $events;
}
