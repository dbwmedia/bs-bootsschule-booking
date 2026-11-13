<?php
/**
 * Early booking campaign ("Frühbucher-Aktion").
 *
 * While no course dates are online, each course page shows a box that sells
 * the course voucher at a discount. The voucher itself keeps the full course
 * value, so the customer pays e.g. 272 € and receives a 320 € voucher for
 * SBF Binnen, valid for three calendar years (bs-custom-mail).
 *
 * Settings: WooCommerce > Frühbucher-Aktion (option `bs_early_settings`).
 * The campaign is reusable: change dates, year and texts and run it again,
 * e.g. as a Christmas offer.
 */

if (!defined('WPINC')) die;

function bs_early_defaults() {
    return array(
        'active'     => 'no',
        'start'      => '',
        'end'        => '',
        'percent'    => 15,
        'round'      => 'yes',
        'year'       => (int) date('Y') + 1,
        'display'    => 'no_dates',
        'eyebrow'    => 'Frühbucher-Aktion',
        'title'      => '{kurs}: jetzt für {jahr} sichern',
        'text'       => "Buche deinen Kurs „{kurs}“ noch {jahr_jetzt} zum Vorteilspreis und sichere dir deine Teilnahme für {jahr}. Die konkreten Kurstermine für {jahr} werden im Dezember veröffentlicht. Danach kannst du ganzjährig an einem der angebotenen Kurstage teilnehmen.",
        'note'       => 'So funktioniert es: Du bekommst sofort einen Gutschein über den vollen Kurswert von {preis}. Damit buchst du deinen Wunschtermin, sobald er online ist. Der Gutschein ist bis {gueltig_bis} gültig.',
        'cta'        => 'Jetzt Platz für {jahr} sichern',
        'cart_label' => 'Frühbucher-Aktion {jahr}',
        'map'        => array(),
    );
}

function bs_early_settings() {
    $settings = wp_parse_args((array) get_option('bs_early_settings', array()), bs_early_defaults());
    $settings['map'] = is_array($settings['map']) ? array_map('intval', $settings['map']) : array();
    return $settings;
}

/** Campaign switched on and today inside the optional start/end window. */
function bs_early_is_running() {
    $s = bs_early_settings();
    if ($s['active'] !== 'yes' || (float) $s['percent'] <= 0) return false;
    $today = current_time('Y-m-d');
    if ($s['start'] !== '' && $today < $s['start']) return false;
    if ($s['end'] !== '' && $today > $s['end']) return false;
    return true;
}

/** Full value of a voucher product (fixed voucher value, else regular price). */
function bs_early_voucher_value($voucher_id) {
    if (get_post_meta($voucher_id, '_bs_custom_mail_voucher_fixed_price', true) === 'yes') {
        $value = (float) get_post_meta($voucher_id, '_bs_custom_mail_voucher_fixed_price_value', true);
        if ($value > 0) return $value;
    }
    $product = wc_get_product($voucher_id);
    return $product ? (float) $product->get_regular_price() : 0.0;
}

/** Discounted price. Rounding to whole euros goes down, in the customer's favour. */
function bs_early_price($value) {
    $s = bs_early_settings();
    $price = $value * (1 - min(100, max(0, (float) $s['percent'])) / 100);
    return $s['round'] === 'yes' ? floor($price) : round($price, 2);
}

/** Same calculation bs-custom-mail uses for voucher expiry (end of year + 3). */
function bs_early_valid_until() {
    $years = (int) apply_filters('bs_custom_mail_voucher_validity_years', 3);
    return sprintf('31.12.%d', (int) date('Y') + max(1, $years));
}

// ---------------------------------------------------------------------------
// Course products and their vouchers
// ---------------------------------------------------------------------------

/** Amelia tags in the product's shortcode ([ameliaeventslistbooking tag="X"]). */
function bs_early_shortcode_tags($product_id) {
    $shortcode = (string) get_post_meta($product_id, '_custom_shortcode_field', true);
    if (stripos($shortcode, 'amelia') === false) return array();
    if (!preg_match('/\btag\s*=\s*["\']?([^"\'\]]+)/i', $shortcode, $m)) return array();
    return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
}

/** Course products: Amelia event lists and Kombi booking products. */
function bs_early_course_products() {
    static $courses = null;
    if ($courses !== null) return $courses;
    $ids = get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));
    $courses = array_values(array_map('intval', array_filter($ids, function($id) {
        return bs_early_shortcode_tags($id) || get_post_meta($id, '_bs_is_booking', true) === 'yes';
    })));
    return $courses;
}

function bs_early_voucher_products() {
    return get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => array(array('key' => '_bs_custom_mail_voucher', 'value' => 'yes')),
    ));
}

/** Voucher for a course: saved mapping, else "Gutschein <course name>". */
function bs_early_voucher_for($course_id) {
    $map = bs_early_settings()['map'];
    if (array_key_exists($course_id, $map)) return $map[$course_id];

    $name = mb_strtolower(trim(get_the_title($course_id)));
    foreach (bs_early_voucher_products() as $voucher_id) {
        if (mb_strtolower(trim(get_the_title($voucher_id))) === 'gutschein ' . $name) return $voucher_id;
    }
    return 0;
}

/** True if the course has at least one upcoming Amelia event. */
function bs_early_course_has_dates($course_id) {
    $tags = bs_early_shortcode_tags($course_id);
    if ($tags) {
        $wanted = array_map('mb_strtolower', $tags);
        foreach (bs_amelia_upcoming_events() as $event) {
            if (array_intersect($wanted, array_map('mb_strtolower', $event['tags']))) return true;
        }
        return false;
    }
    if (get_post_meta($course_id, '_bs_is_booking', true) === 'yes') {
        $courses = bs_get_courses_with_events($course_id);
        if (empty($courses)) return false;
        foreach ($courses as $course) {
            if (empty($course['events'])) return false;
        }
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Box on the course page
// ---------------------------------------------------------------------------

// Same priority trick as the upsell box (see upsell.php): Blocksy only runs
// priority 30 around the event list.
add_action('after_setup_theme', function() {
    add_action('woocommerce_single_product_summary', 'bs_early_display', 30);
}, 101);

function bs_early_offer($course_id) {
    if (!bs_early_is_running()) return null;
    // Only Amelia courses: directly purchasable trainings would undercut themselves.
    if (!in_array((int) $course_id, bs_early_course_products(), true)) return null;

    $voucher_id = bs_early_voucher_for($course_id);
    $voucher = $voucher_id ? wc_get_product($voucher_id) : null;
    if (!$voucher || !$voucher->is_purchasable()) return null;

    $s = bs_early_settings();
    if ($s['display'] === 'no_dates' && bs_early_course_has_dates($course_id)) return null;

    $value = bs_early_voucher_value($voucher_id);
    if ($value <= 0) return null;
    $price = bs_early_price($value);
    if ($price >= $value) return null;

    return array(
        'course'     => get_the_title($course_id),
        'voucher_id' => $voucher_id,
        'value'      => $value,
        'price'      => $price,
        'saving'     => $value - $price,
        'percent'    => (float) $s['percent'],
        'has_dates'  => bs_early_course_has_dates($course_id),
        'url'        => add_query_arg(array('add-to-cart' => $voucher_id, 'bs_early' => 1), wc_get_cart_url()),
    );
}

function bs_early_display() {
    global $product;
    if (!$product) return;
    $offer = bs_early_offer($product->get_id());
    if ($offer) bs_early_render($offer);
}

/** Replaces {platzhalter} in admin texts. Values are escaped, money stays HTML. */
function bs_early_fill($template, array $offer = array()) {
    $s = bs_early_settings();
    $vars = array(
        '{kurs}'        => isset($offer['course']) ? esc_html($offer['course']) : '',
        '{jahr}'        => esc_html($s['year']),
        '{jahr_jetzt}'  => esc_html(current_time('Y')),
        '{rabatt}'      => esc_html(rtrim(rtrim(number_format((float) $s['percent'], 1, ',', ''), '0'), ',')) . ' %',
        '{preis}'       => isset($offer['value']) ? bs_upsell_money($offer['value']) : '',
        '{aktionspreis}'=> isset($offer['price']) ? bs_upsell_money($offer['price']) : '',
        '{ersparnis}'   => isset($offer['saving']) ? bs_upsell_money($offer['saving']) : '',
        '{gueltig_bis}' => esc_html(bs_early_valid_until()),
        '{ende}'        => $s['end'] !== '' ? esc_html(date('d.m.Y', strtotime($s['end']))) : '',
    );
    return strtr(esc_html($template), $vars);
}

function bs_early_render(array $offer) {
    $s = bs_early_settings();

    // Without dates the empty Amelia list ("Es gibt keinen Eintrag") is hidden
    // via CSS (.bs-early--replaces), the box takes its place.
    $class = $offer['has_dates'] ? 'bs-upsell bs-early' : 'bs-upsell bs-early bs-early--replaces';
    echo '<aside class="' . esc_attr($class) . '" aria-label="' . esc_attr($s['eyebrow']) . '">';
    echo '<span class="bs-upsell__eyebrow">' . esc_html($s['eyebrow']) . '</span>';
    echo '<h3 class="bs-upsell__title">' . wp_kses_post(bs_early_fill($s['title'], $offer)) . '</h3>';
    echo '<p class="bs-upsell__text">' . wp_kses_post(nl2br(bs_early_fill($s['text'], $offer))) . '</p>';

    echo '<div class="bs-upsell__price">';
    echo '<del class="bs-upsell__old">' . wp_kses_post(bs_upsell_money($offer['value'])) . '</del>';
    echo '<strong class="bs-upsell__new">' . wp_kses_post(bs_upsell_money($offer['price'])) . '</strong>';
    echo '<span class="bs-upsell__save">-' . wp_kses_post(bs_early_fill('{rabatt}')) . '</span>';
    echo '</div>';
    echo '<p class="bs-early__saving">Du sparst ' . wp_kses_post(bs_upsell_money($offer['saving'])) . ' gegenüber dem regulären Preis.';
    if ($s['end'] !== '') echo ' Nur bis ' . esc_html(date('d.m.Y', strtotime($s['end']))) . '.';
    echo '</p>';

    if (trim($s['note']) !== '') {
        echo '<p class="bs-early__note">' . wp_kses_post(bs_early_fill($s['note'], $offer)) . '</p>';
    }

    echo '<a class="bs-upsell__cta" href="' . esc_url($offer['url']) . '" rel="nofollow">' . wp_kses_post(bs_early_fill($s['cta'], $offer)) . '</a>';
    echo '</aside>';
}

// ---------------------------------------------------------------------------
// Cart, price and order
// ---------------------------------------------------------------------------

/** Flag the voucher as early booking when it comes in through the box link. */
add_filter('woocommerce_add_cart_item_data', function($data, $product_id) {
    if (empty($_GET['bs_early']) && empty($_POST['bs_early'])) return $data;
    if (!bs_early_is_running() || !in_array((int) $product_id, array_map('intval', bs_early_voucher_products()), true)) return $data;

    $s = bs_early_settings();
    $value = bs_early_voucher_value($product_id);
    $data['bs_early'] = array(
        'percent' => (float) $s['percent'],
        'year'    => (int) $s['year'],
    );
    // The voucher must carry the full course value, not the discounted price.
    if (empty($data['bs_voucher_value'])) $data['bs_voucher_value'] = $value;
    return $data;
}, 20, 2);

/** Go straight to the cart without the add-to-cart query (no double add on reload). */
add_filter('woocommerce_add_to_cart_redirect', function($url) {
    return !empty($_GET['bs_early']) ? wc_get_cart_url() : $url;
});

/** After bs-custom-mail (priority 10) set the voucher value as price. */
add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    foreach ($cart->get_cart() as $key => $item) {
        if (empty($item['bs_early'])) continue;
        $value = !empty($item['bs_voucher_value']) ? (float) $item['bs_voucher_value'] : bs_early_voucher_value($item['product_id']);
        if (!bs_early_is_running()) {
            // Campaign ended while the voucher sat in the cart: normal price.
            $item['data']->set_price($value);
            continue;
        }
        $item['data']->set_price(bs_early_price($value));
    }
}, 20);

add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    if (empty($cart_item['bs_early']) || !bs_early_is_running()) return $item_data;
    $value = !empty($cart_item['bs_voucher_value']) ? (float) $cart_item['bs_voucher_value'] : 0;
    $item_data[] = array(
        'key'   => bs_early_fill(bs_early_settings()['cart_label']),
        'value' => 'Gutscheinwert ' . wp_strip_all_tags(bs_upsell_money($value)) . ', gültig bis ' . bs_early_valid_until(),
    );
    return $item_data;
}, 10, 2);

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values) {
    if (empty($values['bs_early'])) return;
    $item->add_meta_data(bs_early_fill(bs_early_settings()['cart_label']), sprintf('-%s %% auf den Gutschein', $values['bs_early']['percent']));
    $item->add_meta_data('_bs_early', wp_json_encode($values['bs_early']));
}, 10, 3);

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

add_action('admin_menu', function() {
    add_submenu_page('woocommerce', 'Frühbucher-Aktion', 'Frühbucher-Aktion', 'manage_woocommerce', 'bs-early-booking', 'bs_early_admin_page');
}, 61);

function bs_early_admin_save() {
    if (!isset($_POST['bs_early_save']) || !check_admin_referer('bs_early_save') || !current_user_can('manage_woocommerce')) return;

    $in = wp_unslash($_POST);
    $date = function($v) { $v = sanitize_text_field($v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
    $settings = array(
        'active'     => !empty($in['active']) ? 'yes' : 'no',
        'start'      => $date(isset($in['start']) ? $in['start'] : ''),
        'end'        => $date(isset($in['end']) ? $in['end'] : ''),
        'percent'    => max(0, min(90, (float) str_replace(',', '.', isset($in['percent']) ? $in['percent'] : 0))),
        'round'      => !empty($in['round']) ? 'yes' : 'no',
        'year'       => max(2000, (int) (isset($in['year']) ? $in['year'] : 0)),
        'display'    => (isset($in['display']) && $in['display'] === 'always') ? 'always' : 'no_dates',
        'map'        => array(),
    );
    foreach (array('eyebrow', 'title', 'cta', 'cart_label') as $key) {
        $settings[$key] = sanitize_text_field(isset($in[$key]) ? $in[$key] : '');
    }
    foreach (array('text', 'note') as $key) {
        $settings[$key] = sanitize_textarea_field(isset($in[$key]) ? $in[$key] : '');
    }
    if (!empty($in['map']) && is_array($in['map'])) {
        foreach ($in['map'] as $course_id => $voucher_id) {
            $settings['map'][(int) $course_id] = (int) $voucher_id;
        }
    }
    update_option('bs_early_settings', $settings, false);
    echo '<div class="notice notice-success"><p>Gespeichert.</p></div>';
}

function bs_early_admin_page() {
    bs_early_admin_save();
    $s = bs_early_settings();
    $running = bs_early_is_running();
    $vouchers = bs_early_voucher_products();

    echo '<div class="wrap"><h1>Frühbucher-Aktion</h1>';
    echo '<p>Solange die Aktion läuft, erscheint auf den Kursseiten eine Box, die den passenden Kurs-Gutschein zum Aktionspreis verkauft. Der Gutschein behält den vollen Kurswert und ist 3 Kalenderjahre gültig. Für die nächste Aktion (z.B. Weihnachten) einfach Zeitraum, Jahr und Texte anpassen.</p>';
    echo '<p><strong>Status:</strong> ' . ($running ? '<span style="color:#008a20;">läuft gerade</span>' : '<span style="color:#b32d2e;">aus</span>') . '</p>';

    echo '<form method="post">';
    wp_nonce_field('bs_early_save');
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th>Aktion aktiv</th><td><label><input type="checkbox" name="active" value="1"' . checked($s['active'], 'yes', false) . '> Box auf den Kursseiten zeigen</label></td></tr>';
    echo '<tr><th>Zeitraum</th><td><input type="date" name="start" value="' . esc_attr($s['start']) . '"> bis <input type="date" name="end" value="' . esc_attr($s['end']) . '"><p class="description">Leer lassen = unbegrenzt. Endet die Aktion, verschwindet die Box automatisch.</p></td></tr>';
    echo '<tr><th>Rabatt</th><td><input type="number" name="percent" min="0" max="90" step="0.5" value="' . esc_attr($s['percent']) . '" style="width:80px;"> % &nbsp; <label><input type="checkbox" name="round" value="1"' . checked($s['round'], 'yes', false) . '> auf volle Euro abrunden</label></td></tr>';
    echo '<tr><th>Kursjahr</th><td><input type="number" name="year" value="' . esc_attr($s['year']) . '" style="width:90px;"><p class="description">Platzhalter {jahr} in den Texten.</p></td></tr>';
    echo '<tr><th>Anzeigen</th><td><select name="display"><option value="no_dates"' . selected($s['display'], 'no_dates', false) . '>nur bei Kursen ohne Termine</option><option value="always"' . selected($s['display'], 'always', false) . '>bei allen Kursen</option></select></td></tr>';

    $fields = array(
        'eyebrow'    => array('Etikett', 'input'),
        'title'      => array('Überschrift', 'input'),
        'text'       => array('Text', 'textarea'),
        'note'       => array('Hinweis (klein)', 'textarea'),
        'cta'        => array('Button', 'input'),
        'cart_label' => array('Bezeichnung im Warenkorb', 'input'),
    );
    foreach ($fields as $key => $field) {
        echo '<tr><th>' . esc_html($field[0]) . '</th><td>';
        if ($field[1] === 'textarea') {
            echo '<textarea name="' . esc_attr($key) . '" rows="4" class="large-text">' . esc_textarea($s[$key]) . '</textarea>';
        } else {
            echo '<input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($s[$key]) . '" class="large-text">';
        }
        echo '</td></tr>';
    }
    echo '<tr><th>Platzhalter</th><td><code>{kurs}</code> <code>{jahr}</code> <code>{jahr_jetzt}</code> <code>{rabatt}</code> <code>{preis}</code> <code>{aktionspreis}</code> <code>{ersparnis}</code> <code>{gueltig_bis}</code> <code>{ende}</code></td></tr>';
    echo '</table>';

    echo '<h2>Kurse und Gutscheine</h2>';
    echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>Kurs</th><th>Gutschein</th><th>Gutscheinwert</th><th>Aktionspreis</th><th>Termine online</th><th>Box sichtbar</th></tr></thead><tbody>';
    foreach (bs_early_course_products() as $course_id) {
        $current = bs_early_voucher_for($course_id);
        echo '<tr><td><a href="' . esc_url(get_permalink($course_id)) . '" target="_blank">' . esc_html(get_the_title($course_id)) . '</a></td><td>';
        echo '<select name="map[' . (int) $course_id . ']"><option value="0">- keine Aktion -</option>';
        foreach ($vouchers as $voucher_id) {
            echo '<option value="' . (int) $voucher_id . '"' . selected($current, $voucher_id, false) . '>' . esc_html(get_the_title($voucher_id)) . '</option>';
        }
        echo '</select></td>';
        $value = $current ? bs_early_voucher_value($current) : 0;
        echo '<td>' . ($value ? wp_kses_post(wc_price($value)) : '-') . '</td>';
        echo '<td>' . ($value ? wp_kses_post(wc_price(bs_early_price($value))) : '-') . '</td>';
        echo '<td>' . (bs_early_course_has_dates($course_id) ? 'ja' : 'nein') . '</td>';
        echo '<td>' . (bs_early_offer($course_id) ? 'ja' : 'nein') . '</td></tr>';
    }
    echo '</tbody></table>';

    submit_button('Speichern', 'primary', 'bs_early_save');
    echo '</form>';

    // Orders placed through the campaign
    echo '<h2>Verkauft</h2>';
    $orders = wc_get_orders(array('limit' => 50, 'status' => array('processing', 'completed', 'on-hold'), 'date_created' => '>' . (time() - YEAR_IN_SECONDS)));
    $rows = array();
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_bs_early')) {
                $rows[] = array($order, $item);
            }
        }
    }
    if (!$rows) {
        echo '<p>Noch keine Frühbucher-Gutscheine verkauft (letzte 12 Monate).</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>Bestellung</th><th>Datum</th><th>Gutschein</th><th>Bezahlt</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            list($order, $item) = $row;
            echo '<tr><td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($order->get_date_created()->date_i18n('d.m.Y')) . '</td>';
            echo '<td>' . esc_html($item->get_name()) . '</td>';
            echo '<td>' . wp_kses_post(wc_price($item->get_total() + $item->get_total_tax())) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}
