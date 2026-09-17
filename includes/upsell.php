<?php
/**
 * Kombi upsell box on the single course products (e.g. SBF Binnen, SBF See).
 *
 * Configured on the Kombi product (meta `_bs_upsell_products`, stored as
 * ",12,34," for a simple LIKE lookup). All numbers are real: the sum of the
 * single product prices against the Kombi price, plus the next bookable dates.
 */

if (!defined('WPINC')) die;

/**
 * Rendered right after the Amelia event list: visitors see the course they
 * came for first, undecided ones meet the offer.
 *
 * The child theme hooks the event list at priority 30. Blocksy renders the
 * summary in layers and skips other priorities around it (31 never ran), so
 * the box uses priority 30 as well and is registered after the theme's
 * functions.php has loaded, which puts it right behind the event list.
 */
add_action('after_setup_theme', function() {
    add_action('woocommerce_single_product_summary', 'bs_upsell_display', 30);
}, 100);

function bs_upsell_display() {
    global $product;
    if (!$product) return;

    $current_id = $product->get_id();
    foreach (bs_upsell_kombis_for($current_id) as $kombi_id) {
        $offer = bs_upsell_offer($kombi_id, $current_id);
        if ($offer) {
            bs_upsell_render($offer);
            return;
        }
    }
}

/** Kombi product ids that advertise themselves on the given product. */
function bs_upsell_kombis_for($product_id) {
    return get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post__not_in'   => array($product_id),
        'meta_query'     => array(
            array('key' => '_bs_is_booking', 'value' => 'yes'),
            array('key' => '_bs_upsell_products', 'value' => ',' . (int) $product_id . ',', 'compare' => 'LIKE'),
        ),
    ));
}

function bs_upsell_product_ids($kombi_id) {
    return array_values(array_filter(array_map('intval', explode(',', (string) get_post_meta($kombi_id, '_bs_upsell_products', true)))));
}

/**
 * Offer data, or null if there is nothing honest to show
 * (no saving, Kombi not purchasable or not bookable).
 */
function bs_upsell_offer($kombi_id, $current_id) {
    $kombi = wc_get_product($kombi_id);
    if (!$kombi || !$kombi->is_purchasable()) return null;

    $sum = 0.0;
    $others = array();
    $count = 0;
    $current_price = null;
    foreach (bs_upsell_product_ids($kombi_id) as $id) {
        $single = wc_get_product($id);
        if (!$single) continue;
        $price = (float) wc_get_price_to_display($single);
        $sum += $price;
        $count++;
        if ($id === $current_id) $current_price = $price;
        else $others[] = $single->get_name();
    }

    $kombi_price = (float) wc_get_price_to_display($kombi);
    $saving = $sum - $kombi_price;
    if ($count < 2 || empty($others) || $saving < 1) return null;

    // Next bookable date per course; no box if a course has nothing left.
    $next = array();
    foreach (bs_get_courses_with_events($kombi_id) as $course) {
        $first = null;
        foreach ($course['events'] as $event) {
            if (!bs_event_availability($event)['full']) {
                $first = $event;
                break;
            }
        }
        if (!$first) return null;
        $next[$course['title']] = $first['days'][0]['date'];
    }
    if (empty($next)) return null;

    return array(
        'source'      => $current_id,
        'url'         => add_query_arg('bs_src', $current_id, get_permalink($kombi_id)),
        'extra'       => $current_price !== null ? $kombi_price - $current_price : null,
        'others'      => $others,
        'sum'         => $sum,
        'kombi_price' => $kombi_price,
        'saving'      => $saving,
        'percent'     => (int) floor($saving / $sum * 100),
        'next'        => $next,
    );
}

/** Whole amounts without decimals ("179 €"), others with cents. */
function bs_upsell_money($amount) {
    return wc_price($amount, array('decimals' => fmod($amount, 1) ? 2 : 0));
}

function bs_upsell_render(array $offer) {
    $saving = bs_upsell_money($offer['saving']);
    $others = implode(' und ', $offer['others']);

    echo '<aside class="bs-upsell" aria-label="Kombi-Angebot" data-source="' . (int) $offer['source'] . '">';
    echo '<span class="bs-upsell__eyebrow">Kombi-Vorteil</span>';

    // Upgrade framing: the extra cost for the second course is the strongest
    // argument for someone who already decided on this one.
    if ($offer['extra'] !== null && $offer['extra'] > 0) {
        echo '<h3 class="bs-upsell__title">Für nur ' . wp_kses_post(bs_upsell_money($offer['extra'])) . ' mehr: ' . esc_html($others) . ' dazu</h3>';
        echo '<p class="bs-upsell__text">Du sparst ' . wp_kses_post($saving) . ' gegenüber der Einzelbuchung und machst beide Führerscheine in einem Ablauf. Wer später einzeln nachbucht, zahlt den vollen Preis.</p>';
    } else {
        echo '<h3 class="bs-upsell__title">Nimm den ' . esc_html($others) . ' gleich mit und spar ' . wp_kses_post($saving) . '</h3>';
        echo '<p class="bs-upsell__text">Im Kombi-Kurs machst du beide Führerscheine in einem Ablauf. Wer später einzeln nachbucht, zahlt den vollen Preis.</p>';
    }

    echo '<div class="bs-upsell__price">';
    echo '<del class="bs-upsell__old">' . wp_kses_post(wc_price($offer['sum'])) . ' einzeln</del>';
    echo '<strong class="bs-upsell__new">' . wp_kses_post(wc_price($offer['kombi_price'])) . '</strong>';
    echo '<span class="bs-upsell__save">-' . (int) $offer['percent'] . ' %</span>';
    echo '</div>';

    echo '<ul class="bs-upsell__dates">';
    foreach ($offer['next'] as $course => $date) {
        echo '<li><span>' . esc_html($course) . '</span> nächster Start ' . esc_html(bs_weekday($date) . ' ' . date('d.m.Y', strtotime($date))) . '</li>';
    }
    echo '</ul>';

    echo '<a class="bs-upsell__cta" href="' . esc_url($offer['url']) . '">Zum Kombi-Kurs</a>';
    echo '</aside>';

    wp_enqueue_script('bs-booking-upsell', BS_BOOTSCHULE_URL . 'build/upsell.js', array(), BS_BOOTSCHULE_VERSION, true);
    wp_localize_script('bs-booking-upsell', 'bsUpsell', array('ajaxUrl' => admin_url('admin-ajax.php')));
}
