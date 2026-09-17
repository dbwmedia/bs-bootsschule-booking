<?php
/**
 * Seat availability and the badges shown per date.
 *
 * Free seats = Amelia capacity - Amelia bookings - Kombi orders from WooCommerce.
 * Kombi bookings are not written to Amelia, so they are subtracted here.
 *
 * Every badge states a fact (real free seats, real days until start).
 * No artificial scarcity: that would be misleading advertising (UWG §5).
 */

if (!defined('WPINC')) die;

/** Days before start from which the start date is shown as a hint. */
function bs_urgency_days() {
    return (int) apply_filters('bs_booking_urgency_days', 14);
}

/** Free seats at or below this number are shown as "Nur noch X Plätze frei". */
function bs_low_seats_threshold() {
    return (int) apply_filters('bs_booking_low_seats', 5);
}

/**
 * Seats taken by Kombi orders per Amelia event id.
 * Counts orders that are paid or awaiting payment confirmation.
 */
function bs_kombi_booked_counts() {
    static $counts = null;
    if ($counts !== null) return $counts;

    $counts = array();
    if (!function_exists('wc_get_order')) return $counts;

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT oi.order_id, ids.meta_value AS event_ids, qty.meta_value AS qty
         FROM {$wpdb->prefix}woocommerce_order_itemmeta ids
         INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = ids.order_item_id
         LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON qty.order_item_id = ids.order_item_id AND qty.meta_key = '_qty'
         WHERE ids.meta_key = '_bs_amelia_event_ids'",
        ARRAY_A
    );

    $statuses = (array) apply_filters('bs_booking_counted_order_statuses', array('processing', 'completed', 'on-hold'));
    $order_counts = array();
    foreach ((array) $rows as $row) {
        $order_id = (int) $row['order_id'];
        if (!isset($order_counts[$order_id])) {
            $order = wc_get_order($order_id);
            $order_counts[$order_id] = $order && $order->has_status($statuses);
        }
        if (!$order_counts[$order_id]) continue;

        $qty = max(1, (int) $row['qty']);
        foreach ((array) json_decode($row['event_ids'], true) as $event_id) {
            $event_id = (int) $event_id;
            $counts[$event_id] = (isset($counts[$event_id]) ? $counts[$event_id] : 0) + $qty;
        }
    }
    return $counts;
}

function bs_days_until(array $event) {
    $today = new DateTimeImmutable('today', wp_timezone());
    $start = new DateTimeImmutable($event['days'][0]['date'], wp_timezone());
    return (int) $today->diff($start)->format('%r%a');
}

/**
 * Availability of one event:
 * ['free' => int|null, 'full' => bool, 'low' => bool, 'soon' => bool, 'days_until' => int]
 */
function bs_event_availability(array $event) {
    $kombi = bs_kombi_booked_counts();
    $free  = null;
    if ($event['capacity'] !== null) {
        $taken = $event['booked'] + (isset($kombi[$event['id']]) ? $kombi[$event['id']] : 0);
        $free  = max(0, $event['capacity'] - $taken);
    }
    $days_until = bs_days_until($event);

    return array(
        'free'       => $free,
        'full'       => $free === 0,
        'low'        => $free !== null && $free > 0 && $free <= bs_low_seats_threshold(),
        'soon'       => $days_until <= bs_urgency_days(),
        'days_until' => $days_until,
    );
}

function bs_format_start_hint($days_until) {
    if ($days_until <= 0) return 'Startet heute';
    if ($days_until === 1) return 'Startet morgen';
    return 'Startet in ' . $days_until . ' Tagen';
}

/**
 * Badges for one date option, most important first.
 * Returns [['text' => ..., 'type' => 'full'|'low'|'soon'|'open']].
 */
function bs_availability_badges(array $availability) {
    if ($availability['full']) {
        return array(array('text' => 'Ausgebucht', 'type' => 'full'));
    }

    $badges = array();
    if ($availability['low']) {
        $free = $availability['free'];
        $badges[] = array('text' => 'Nur noch ' . $free . ($free === 1 ? ' Platz' : ' Plätze') . ' frei', 'type' => 'low');
    }
    if ($availability['soon']) {
        $badges[] = array('text' => bs_format_start_hint($availability['days_until']), 'type' => 'soon');
    }
    if (empty($badges)) {
        $badges[] = array('text' => 'Plätze frei', 'type' => 'open');
    }
    return $badges;
}
