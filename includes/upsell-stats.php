<?php
/**
 * Anonymous upsell statistics: views, clicks and resulting Kombi orders
 * per single product. No cookies, no IPs, no personal data; only counters
 * in the option `bs_upsell_stats`. Admin page: WooCommerce > Kombi-Upsell.
 *
 * - View: box scrolled into sight (beacon from build/upsell.js)
 * - Click: CTA clicked (beacon)
 * - Order: Kombi added to cart after arriving via ?bs_src=<product id>,
 *          counted when the order is created
 */

if (!defined('WPINC')) die;

function bs_upsell_stats_increment($product_id, $field) {
    $stats = get_option('bs_upsell_stats', array());
    if (empty($stats['since'])) $stats['since'] = current_time('mysql');
    $current = isset($stats['products'][$product_id][$field]) ? (int) $stats['products'][$product_id][$field] : 0;
    $stats['products'][$product_id][$field] = $current + 1;
    update_option('bs_upsell_stats', $stats, false);
}

add_action('wp_ajax_bs_upsell_track', 'bs_upsell_track');
add_action('wp_ajax_nopriv_bs_upsell_track', 'bs_upsell_track');
function bs_upsell_track() {
    $type       = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

    // Shop staff browsing the site would skew the numbers.
    $valid = in_array($type, array('view', 'click'), true) && $product_id && bs_upsell_kombis_for($product_id);
    if ($valid && !current_user_can('edit_products')) {
        bs_upsell_stats_increment($product_id, $type === 'view' ? 'views' : 'clicks');
    }
    wp_die('', '', array('response' => 204));
}

add_action('admin_menu', function() {
    add_submenu_page('woocommerce', 'Kombi-Upsell', 'Kombi-Upsell', 'manage_woocommerce', 'bs-upsell-stats', 'bs_upsell_stats_page');
}, 60);

function bs_upsell_stats_page() {
    if (isset($_POST['bs_upsell_reset']) && check_admin_referer('bs_upsell_reset')) {
        delete_option('bs_upsell_stats');
        echo '<div class="notice notice-success"><p>Statistik zurückgesetzt.</p></div>';
    }

    $stats    = get_option('bs_upsell_stats', array());
    $products = isset($stats['products']) ? $stats['products'] : array();

    echo '<div class="wrap"><h1>Kombi-Upsell</h1>';
    echo '<p>Wie oft die Box "Kombi-Vorteil" auf den Einzelprodukten gesehen und geklickt wurde, und wie viele Kombi-Bestellungen danach entstanden sind. Anonym gezählt, ohne Cookies. Besuche von eingeloggten Shop-Mitarbeitern zählen nicht.</p>';
    if (!empty($stats['since'])) {
        echo '<p><strong>Gezählt seit:</strong> ' . esc_html(date_i18n('d.m.Y H:i', strtotime($stats['since']))) . ' Uhr</p>';
    }

    echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>Seite</th><th>Aufrufe</th><th>Klicks</th><th>Klickrate</th><th>Kombi-Bestellungen</th><th>Aus Klick bestellt</th></tr></thead><tbody>';
    if (empty($products)) {
        echo '<tr><td colspan="6">Noch keine Daten.</td></tr>';
    }
    $totals = array('views' => 0, 'clicks' => 0, 'orders' => 0);
    foreach ($products as $product_id => $row) {
        $views  = isset($row['views']) ? (int) $row['views'] : 0;
        $clicks = isset($row['clicks']) ? (int) $row['clicks'] : 0;
        $orders = isset($row['orders']) ? (int) $row['orders'] : 0;
        foreach (array('views', 'clicks', 'orders') as $k) $totals[$k] += $$k;

        echo '<tr>';
        echo '<td>' . esc_html(get_the_title($product_id) ?: '#' . $product_id) . '</td>';
        echo '<td>' . $views . '</td><td>' . $clicks . '</td>';
        echo '<td>' . bs_upsell_percent($clicks, $views) . '</td>';
        echo '<td>' . $orders . '</td>';
        echo '<td>' . bs_upsell_percent($orders, $clicks) . '</td>';
        echo '</tr>';
    }
    if (count($products) > 1) {
        echo '<tr style="font-weight:600;"><td>Gesamt</td><td>' . $totals['views'] . '</td><td>' . $totals['clicks'] . '</td><td>' . bs_upsell_percent($totals['clicks'], $totals['views']) . '</td><td>' . $totals['orders'] . '</td><td>' . bs_upsell_percent($totals['orders'], $totals['clicks']) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<p class="description" style="margin-top:12px;">Klickrate = Klicks / Aufrufe. "Aus Klick bestellt" = Kombi-Bestellungen / Klicks. Eine Bestellung zählt, wenn das Kombi-Produkt direkt nach dem Klick in den Warenkorb gelegt und bestellt wurde (auch unbezahlte oder später stornierte Bestellungen).</p>';

    echo '<form method="post" style="margin-top:20px;" onsubmit="return confirm(\'Statistik wirklich zurücksetzen?\');">';
    wp_nonce_field('bs_upsell_reset');
    echo '<button class="button" name="bs_upsell_reset" value="1">Statistik zurücksetzen</button></form>';
    echo '</div>';
}

function bs_upsell_percent($part, $total) {
    return $total > 0 ? number_format_i18n($part / $total * 100, 1) . ' %' : '-';
}
