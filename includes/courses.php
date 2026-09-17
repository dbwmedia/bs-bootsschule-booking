<?php
/**
 * Course configuration per product and matching against Amelia events.
 *
 * A course is ['title', 'match_type' => 'tag'|'name', 'match', 'location'].
 * Product meta `_bs_courses` holds the list in display order.
 */

if (!defined('WPINC')) die;

function bs_get_courses($product_id) {
    $courses = get_post_meta($product_id, '_bs_courses', true);
    if (is_array($courses)) return array_values($courses);
    return bs_get_legacy_courses($product_id);
}

/**
 * Products configured before v2.0 reference templates of the old, manually
 * maintained date tables. Their titles become "name contains" filters, so an
 * existing Kombi product keeps working until it is saved once.
 */
function bs_get_legacy_courses($product_id) {
    $template_ids = get_post_meta($product_id, '_bs_templates', true);
    if (empty($template_ids) || !is_array($template_ids)) return array();

    global $wpdb;
    $table = $wpdb->prefix . 'bs_event_templates';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return array();

    $courses = array();
    foreach ($template_ids as $template_id) {
        $template = $wpdb->get_row($wpdb->prepare("SELECT title, location FROM $table WHERE id = %d", (int) $template_id));
        if (!$template) continue;
        $courses[] = array(
            'title'      => $template->title,
            'match_type' => 'name',
            'match'      => $template->title,
            'location'   => (string) $template->location,
        );
    }
    return $courses;
}

function bs_is_legacy_config($product_id) {
    return !is_array(get_post_meta($product_id, '_bs_courses', true)) && !empty(bs_get_legacy_courses($product_id));
}

function bs_normalize($text) {
    return function_exists('mb_strtolower') ? mb_strtolower(trim($text)) : strtolower(trim($text));
}

function bs_course_matches(array $course, array $event) {
    $term = bs_normalize($course['match']);
    if ($term === '') return false;

    if ($course['match_type'] === 'tag') {
        foreach ($event['tags'] as $tag) {
            if (bs_normalize($tag) === $term) return true;
        }
        return false;
    }
    return strpos(bs_normalize($event['name']), $term) !== false;
}

/**
 * Courses of a product, each with its matching upcoming Amelia events
 * under 'events' (keyed by event id).
 */
function bs_get_courses_with_events($product_id) {
    $events  = bs_amelia_upcoming_events();
    $courses = bs_get_courses($product_id);
    foreach ($courses as $i => $course) {
        $courses[$i]['events'] = array();
        foreach ($events as $event) {
            if (bs_course_matches($course, $event)) {
                $courses[$i]['events'][$event['id']] = $event;
            }
        }
    }
    return $courses;
}

function bs_weekday($date) {
    $weekdays = array(1 => 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.', 'So.');
    return $weekdays[(int) date('N', strtotime($date))];
}

/** "Sa. 17.10.2026 + So. 18.10.2026" */
function bs_format_days(array $days) {
    $parts = array();
    foreach ($days as $day) {
        $parts[] = bs_weekday($day['date']) . ' ' . date('d.m.Y', strtotime($day['date']));
    }
    return implode(' + ', $parts);
}

/** "09:00 - 16:00 Uhr", or per weekday if the days have different times. */
function bs_format_times(array $days) {
    $ranges = array();
    foreach ($days as $day) {
        $ranges[] = $day['start'] . ' - ' . $day['end'];
    }
    if (count(array_unique($ranges)) === 1) {
        return $ranges[0] . ' Uhr';
    }
    $parts = array();
    foreach ($days as $i => $day) {
        $parts[] = bs_weekday($day['date']) . ' ' . $ranges[$i];
    }
    return implode(', ', $parts) . ' Uhr';
}

/** Location shown under the course title: override, or the one all events share. */
function bs_course_location(array $course) {
    if (!empty($course['location'])) return $course['location'];
    $locations = array_unique(array_filter(array_column($course['events'], 'location')));
    return count($locations) === 1 ? reset($locations) : '';
}

/**
 * First pair of selected dates (from different courses) that share a calendar day.
 * $selected is a list of ['course' => title, 'days' => [...]].
 * Returns [course_a, course_b] or null.
 */
function bs_find_overlap(array $selected) {
    foreach ($selected as $i => $a) {
        $a_dates = array_column($a['days'], 'date');
        foreach (array_slice($selected, $i + 1) as $b) {
            if (array_intersect($a_dates, array_column($b['days'], 'date'))) {
                return array($a['course'], $b['course']);
            }
        }
    }
    return null;
}
