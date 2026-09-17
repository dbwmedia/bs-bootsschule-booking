<?php
/**
 * Show booked course dates in cart, checkout and order.
 */

if (!defined('WPINC')) die;

add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    if (!empty($cart_item['bs_courses'])) {
        foreach ($cart_item['bs_courses'] as $course) {
            $item_data[] = array(
                'key'   => esc_html($course['course']),
                'value' => esc_html(bs_format_days($course['days'])) . '<br>' .
                           esc_html(bs_format_times($course['days'])) .
                           ($course['location'] ? '<br>📍 ' . esc_html($course['location']) : ''),
            );
        }
        return $item_data;
    }

    // Cart items created before v2.0 (still in running sessions)
    if (!empty($cart_item['bs_events'])) {
        foreach ($cart_item['bs_events'] as $event) {
            $item_data[] = array(
                'key'   => esc_html($event['template']),
                'value' => date('d.m.Y', strtotime($event['date'])) . ' ' . esc_html($event['time']) .
                           ($event['location'] ? '<br>📍 ' . esc_html($event['location']) : ''),
            );
        }
    }
    return $item_data;
}, 10, 2);

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values) {
    if (!empty($values['bs_courses'])) {
        foreach ($values['bs_courses'] as $course) {
            $value = bs_format_days($course['days']) . ' | ' . bs_format_times($course['days']);
            if ($course['location']) $value .= ' | ' . $course['location'];
            $item->add_meta_data($course['course'], $value);
        }
        $item->add_meta_data('_bs_amelia_event_ids', wp_json_encode(array_column($values['bs_courses'], 'event_id')));
        $item->add_meta_data('_bs_courses', wp_json_encode(array_map(function($course) {
            return array('course' => $course['course'], 'event_id' => $course['event_id']);
        }, $values['bs_courses'])));
        if (!empty($values['bs_upsell_source'])) {
            $item->add_meta_data('_bs_upsell_source', (int) $values['bs_upsell_source']);
            bs_upsell_stats_increment((int) $values['bs_upsell_source'], 'orders');
        }
        return;
    }

    // Cart items created before v2.0
    if (!empty($values['bs_events'])) {
        foreach ($values['bs_events'] as $event) {
            $item->add_meta_data($event['template'], date('d.m.Y', strtotime($event['date'])) . ' ' . $event['time']);
        }
        $item->add_meta_data('_bs_event_ids', wp_json_encode(array_column($values['bs_events'], 'id')));
    }
}, 10, 3);
