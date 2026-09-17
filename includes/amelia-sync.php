<?php
/**
 * Writes Kombi orders back to Amelia as real event bookings.
 *
 * Uses the same internal call Amelia's own WooCommerce integration uses
 * (EventReservationService::processRequest with gateway "wc"), once per
 * course of the Kombi line item. Verified against Amelia 9.8.
 *
 * - Order on-hold / processing / completed: create missing bookings
 * - Order cancelled / failed / refunded: cancel created bookings
 *
 * Failures never block the order; they are written as order notes.
 * Booking ids are stored in the item meta `_bs_amelia_bookings`
 * ({"event_id": booking_id}), which makes every run idempotent.
 */

if (!defined('WPINC')) die;

use AmeliaBooking\Application\Commands\CommandResult;

function bs_amelia_sync_booking_statuses() {
    return (array) apply_filters('bs_booking_amelia_sync_statuses', array('on-hold', 'processing', 'completed'));
}

function bs_amelia_sync_cancel_statuses() {
    return (array) apply_filters('bs_booking_amelia_cancel_statuses', array('cancelled', 'failed', 'refunded'));
}

function bs_amelia_container() {
    static $container = null;
    if ($container === null) {
        $file = defined('AMELIA_PATH') ? AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php' : '';
        if (!$file || !file_exists($file)) return null;
        $container = require $file;
    }
    return $container;
}

add_action('woocommerce_order_status_changed', function($order_id, $from, $to, $order) {
    if (!$order instanceof WC_Order) $order = wc_get_order($order_id);
    if (!$order) return;

    if (in_array($to, bs_amelia_sync_booking_statuses(), true)) {
        bs_amelia_create_bookings($order);
    } elseif (in_array($to, bs_amelia_sync_cancel_statuses(), true)) {
        bs_amelia_cancel_bookings($order);
    }
}, 20, 4);

/**
 * Kombi line items of an order: [item_id => WC_Order_Item_Product].
 */
function bs_kombi_order_items(WC_Order $order) {
    $items = array();
    foreach ($order->get_items() as $item_id => $item) {
        if ($item->get_meta('_bs_amelia_event_ids')) $items[$item_id] = $item;
    }
    return $items;
}

function bs_amelia_create_bookings(WC_Order $order) {
    foreach (bs_kombi_order_items($order) as $item_id => $item) {
        // Status changes can fire twice in quick succession (gateway webhook + return page).
        $lock = 'bs_amelia_sync_lock_' . $item_id;
        if (!add_option($lock, time(), '', 'no')) {
            if ((int) get_option($lock) > time() - 120) continue;
            update_option($lock, time(), false);
        }

        try {
            $event_ids = array_map('intval', (array) json_decode($item->get_meta('_bs_amelia_event_ids'), true));
            $bookings  = (array) json_decode((string) $item->get_meta('_bs_amelia_bookings'), true);
            $titles    = bs_kombi_course_titles($item);
            $amounts   = bs_split_amount((float) $item->get_total() + (float) $item->get_total_tax(), bs_amelia_event_prices($event_ids));

            foreach ($event_ids as $event_id) {
                if (!empty($bookings[$event_id])) continue;

                $label = isset($titles[$event_id]) ? $titles[$event_id] : 'Amelia-Event #' . $event_id;
                try {
                    $bookings[$event_id] = bs_amelia_book_event($order, $item_id, $event_id, $item->get_quantity(), $amounts[$event_id]);
                    $order->add_order_note(sprintf('Amelia-Buchung für %s angelegt (Buchung #%d).', $label, $bookings[$event_id]));
                } catch (Throwable $e) {
                    $order->add_order_note(sprintf('Amelia-Buchung für %s fehlgeschlagen: %s', $label, $e->getMessage()));
                }
            }

            $item->update_meta_data('_bs_amelia_bookings', wp_json_encode($bookings));
            $item->save();
        } finally {
            delete_option($lock);
        }
    }
}

function bs_amelia_cancel_bookings(WC_Order $order) {
    $container = bs_amelia_container();

    foreach (bs_kombi_order_items($order) as $item) {
        $bookings = (array) json_decode((string) $item->get_meta('_bs_amelia_bookings'), true);
        if (empty($bookings)) continue;

        $titles = bs_kombi_course_titles($item);
        foreach ($bookings as $event_id => $booking_id) {
            $label = isset($titles[$event_id]) ? $titles[$event_id] : 'Amelia-Event #' . $event_id;
            try {
                if (!$container) throw new RuntimeException('Amelia ist nicht verfügbar.');

                $booking = $container->get('domain.booking.customerBooking.repository')->getById((int) $booking_id);
                if ($booking->getStatus()->getValue() === 'canceled') continue;

                $container->get('application.reservation.service')->get('event')->updateStatus($booking, 'canceled', false);
                $order->add_order_note(sprintf('Amelia-Buchung für %s storniert (Buchung #%d).', $label, $booking_id));
            } catch (Throwable $e) {
                $order->add_order_note(sprintf('Amelia-Buchung für %s konnte nicht storniert werden: %s', $label, $e->getMessage()));
            }
        }
    }
}

/**
 * Creates one Amelia event booking and returns its id.
 *
 * @throws RuntimeException
 */
function bs_amelia_book_event(WC_Order $order, $item_id, $event_id, $persons, $amount) {
    $container = bs_amelia_container();
    if (!$container) throw new RuntimeException('Amelia ist nicht verfügbar.');

    $service = $container->get('application.reservation.service')->get('event');

    $payment = array(
        'gateway'       => 'wc',
        'wcOrderId'     => $order->get_id(),
        'wcOrderItemId' => $item_id,
        'orderStatus'   => $order->get_status(),
        'gatewayTitle'  => $order->get_payment_method_title(),
        'amount'        => 0,
        'status'        => $order->get_payment_method() === 'cod' ? 'pending' : 'paid',
    );

    // Marks the booking as "actions completed", so Amelia neither sends its own
    // notifications now nor later via its undelivered-notifications cron.
    // WooCommerce and bs-custom-mail send the confirmation instead.
    if (!apply_filters('bs_booking_amelia_notifications', false)) {
        $payment['isBackendBooking'] = true;
    }

    $data = array(
        'type'       => 'event',
        'eventId'    => (int) $event_id,
        'couponCode' => '',
        'bookings'   => array(array(
            'customerId'   => null,
            'customer'     => array(
                'id'              => null,
                'type'            => 'customer',
                'email'           => $order->get_billing_email(),
                'firstName'       => $order->get_billing_first_name(),
                'lastName'        => $order->get_billing_last_name(),
                'phone'           => $order->get_billing_phone(),
                'countryPhoneIso' => $order->get_billing_country() ? strtolower($order->get_billing_country()) : null,
                'externalId'      => null,
            ),
            'persons'      => max(1, (int) $persons),
            'extras'       => array(),
            'customFields' => null,
            'utcOffset'    => null,
            'deposit'      => false,
        )),
        'payment'    => $payment,
        'locale'     => get_locale(),
        'timeZone'   => wp_timezone_string(),
        'recurring'  => array(),
        'package'    => array(),
        'isCart'     => false,
    );

    $reservation = $service->getNew(false, false, false);
    $result = $service->processRequest($data, $reservation, true);

    if ($result->getResult() === CommandResult::RESULT_ERROR) {
        $message = $result->getMessage();
        if (!$message) $message = wp_json_encode($result->getData());
        throw new RuntimeException($message ?: 'Unbekannter Fehler');
    }

    $booking = $reservation->getBooking();
    if (!$booking || !$booking->getId()) throw new RuntimeException('Amelia hat keine Buchungs-ID geliefert.');

    // Amelia records the full event price; store this course's share of the Kombi price.
    if ($booking->getPayments() && $booking->getPayments()->length()) {
        $amelia_payment = $booking->getPayments()->getItem($booking->getPayments()->keys()[0]);
        $container->get('domain.payment.repository')->updateFieldById($amelia_payment->getId()->getValue(), $amount, 'amount');
    }

    return (int) $booking->getId()->getValue();
}

/** [event_id => course title] from the order item meta. */
function bs_kombi_course_titles($item) {
    $titles = array();
    foreach ((array) json_decode((string) $item->get_meta('_bs_courses'), true) as $course) {
        if (isset($course['event_id'], $course['course'])) $titles[(int) $course['event_id']] = $course['course'];
    }
    return $titles;
}

/** [event_id => Amelia list price] */
function bs_amelia_event_prices(array $event_ids) {
    global $wpdb;
    $prices = array_fill_keys($event_ids, 0.0);
    if (empty($event_ids) || !bs_amelia_is_available()) return $prices;

    $ids = implode(',', array_map('intval', $event_ids));
    foreach ((array) $wpdb->get_results('SELECT id, price FROM ' . bs_amelia_table('events') . " WHERE id IN ($ids)", ARRAY_A) as $row) {
        $prices[(int) $row['id']] = (float) $row['price'];
    }
    return $prices;
}

/**
 * Splits a total proportionally to the given weights, rounded to cents.
 * The last entry takes the rounding difference. Equal split if all weights are 0.
 */
function bs_split_amount($total, array $weights) {
    $keys = array_keys($weights);
    if (empty($keys)) return array();

    $sum = array_sum($weights);
    $shares = array();
    $allocated = 0.0;
    foreach ($keys as $i => $key) {
        if ($i === count($keys) - 1) {
            $shares[$key] = round($total - $allocated, 2);
        } else {
            $shares[$key] = round($sum > 0 ? $total * $weights[$key] / $sum : $total / count($keys), 2);
            $allocated += $shares[$key];
        }
    }
    return $shares;
}
