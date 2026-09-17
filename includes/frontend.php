<?php
/**
 * Date selection on the product page and add-to-cart handling.
 */

if (!defined('WPINC')) die;

add_action('wp_enqueue_scripts', function() {
    if (!is_product()) return;
    wp_enqueue_style('bs-booking-frontend', BS_BOOTSCHULE_URL . 'build/frontend.css', array(), BS_BOOTSCHULE_VERSION);
    wp_register_script('bs-booking-frontend', BS_BOOTSCHULE_URL . 'build/frontend.js', array(), BS_BOOTSCHULE_VERSION, true);
});

add_action('woocommerce_single_product_summary', 'bs_bootsschule_display', 25);
function bs_bootsschule_display() {
    global $product;
    if (!$product) return;

    $product_id = $product->get_id();
    if (get_post_meta($product_id, '_bs_is_booking', true) !== 'yes') return;

    $courses = bs_get_courses_with_events($product_id);
    if (empty($courses)) return;

    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);

    // A course is bookable if at least one of its dates still has seats.
    $bookable = true;
    foreach ($courses as $i => $course) {
        $courses[$i]['has_seats'] = false;
        foreach ($course['events'] as $event_id => $event) {
            $availability = bs_event_availability($event);
            $courses[$i]['events'][$event_id]['availability'] = $availability;
            if (!$availability['full']) $courses[$i]['has_seats'] = true;
        }
        if (!$courses[$i]['has_seats']) $bookable = false;
    }

    echo '<div class="bs-bootsschule-booking">';
    echo '<h3>Terminwahl</h3>';
    echo '<p>' . (count($courses) > 1 ? 'Bitte wähle pro Kurs einen Termin.' : 'Bitte wähle einen Termin.') . '</p>';

    foreach ($courses as $index => $course) {
        $location = bs_course_location($course);

        echo '<div class="bs-template" data-course-index="' . $index . '" data-course-title="' . esc_attr($course['title']) . '">';
        echo '<h4>' . esc_html($course['title']) . '</h4>';
        if ($location) echo '<p class="loc">📍 ' . esc_html($location) . '</p>';

        if (empty($course['events'])) {
            echo '<p class="bs-no-dates">Für diesen Kurs sind derzeit keine Termine verfügbar.</p>';
        } else {
            if (!$course['has_seats']) {
                echo '<p class="bs-no-dates">Alle Termine für diesen Kurs sind ausgebucht.</p>';
            }
            echo '<div class="bs-dates">';
            foreach ($course['events'] as $event) {
                $full = $event['availability']['full'];
                echo '<label' . ($full ? ' class="disabled"' : '') . '>';
                echo '<input type="radio" name="bs_course[' . $index . ']" value="' . $event['id'] . '"'
                    . ' data-days="' . esc_attr(implode(',', array_column($event['days'], 'date'))) . '"'
                    . ($full ? ' disabled data-full="1"' : '') . '>';
                echo '<span class="bs-option">';
                echo '<span class="bs-badges">';
                foreach (bs_availability_badges($event['availability']) as $badge) {
                    echo '<small class="bs-badge bs-badge--' . $badge['type'] . '">' . esc_html($badge['text']) . '</small>';
                }
                echo '</span>';
                // Keep each day ("Sa. 26.09.2026") together when the line wraps.
                $days = array_map(function($day) { return '<span class="bs-nowrap">' . esc_html($day) . '</span>'; }, explode(' + ', bs_format_days($event['days'])));
                echo '<strong>' . implode(' + ', $days) . '</strong>';
                echo '<span class="bs-option__time">' . esc_html(bs_format_times($event['days'])) . '</span>';
                if (!$location && $event['location']) {
                    echo '<span class="bs-option__loc">📍 ' . esc_html($event['location']) . '</span>';
                }
                echo '</span>';
                echo '</label>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    if ($bookable) {
        echo '<button type="button" class="bs-add-cart" disabled>In Warenkorb</button>';
        echo '<div class="bs-messages" role="alert"></div>';

        wp_enqueue_script('bs-booking-frontend');
        wp_localize_script('bs-booking-frontend', 'bsBookingFrontend', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('bs_bootsschule'),
            'productId' => $product_id,
            'i18n'      => array(
                'addToCart'   => 'In Warenkorb',
                'chooseFor'   => 'Bitte Termin für %s wählen',
                'adding'      => 'Wird hinzugefügt...',
                'serverError' => 'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.',
                'overlap'     => 'Überschneidet sich mit deinem %s-Termin',
            ),
        ));
    } else {
        echo '<div class="bs-messages error">Dieser Kurs ist derzeit nicht buchbar, weil nicht für alle Kurse freie Termine verfügbar sind.</div>';
    }

    echo '</div>';
}

add_action('wp_ajax_bs_bootsschule_add_cart', 'bs_bootsschule_ajax');
add_action('wp_ajax_nopriv_bs_bootsschule_add_cart', 'bs_bootsschule_ajax');
function bs_bootsschule_ajax() {
    check_ajax_referer('bs_bootsschule');

    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $selection  = isset($_POST['selection']) ? json_decode(wp_unslash($_POST['selection']), true) : null;

    if (!$product_id || !is_array($selection) || get_post_meta($product_id, '_bs_is_booking', true) !== 'yes') {
        wp_send_json_error(array('message' => 'Bitte wähle deine Termine aus.'));
    }

    $booked = array();
    foreach (bs_get_courses_with_events($product_id) as $index => $course) {
        $event_id = isset($selection[$index]) ? absint($selection[$index]) : 0;
        if (!$event_id) {
            wp_send_json_error(array('message' => 'Bitte wähle einen Termin für ' . $course['title'] . '.'));
        }
        // Only events that currently match this course are accepted.
        if (!isset($course['events'][$event_id])) {
            wp_send_json_error(array('message' => 'Der gewählte Termin für ' . $course['title'] . ' ist nicht mehr verfügbar. Bitte lade die Seite neu.'));
        }

        $event = $course['events'][$event_id];
        if (bs_event_availability($event)['full']) {
            wp_send_json_error(array('message' => 'Der gewählte Termin für ' . $course['title'] . ' ist leider ausgebucht. Bitte wähle einen anderen.'));
        }
        $booked[] = array(
            'course'   => $course['title'],
            'event_id' => $event['id'],
            'days'     => $event['days'],
            'location' => $course['location'] !== '' ? $course['location'] : $event['location'],
        );
    }

    $overlap = bs_find_overlap($booked);
    if ($overlap) {
        wp_send_json_error(array('message' => 'Die Termine für ' . $overlap[0] . ' und ' . $overlap[1] . ' überschneiden sich. Bitte wähle andere Termine.'));
    }

    if (empty($booked)) {
        wp_send_json_error(array('message' => 'Für dieses Produkt sind keine Kurse hinterlegt.'));
    }

    $added = WC()->cart->add_to_cart($product_id, 1, 0, array(), array('bs_courses' => $booked));

    if ($added) {
        wp_send_json_success(array('cart_url' => wc_get_cart_url()));
    }
    wp_send_json_error(array('message' => 'Fehler beim Hinzufügen zum Warenkorb.'));
}
