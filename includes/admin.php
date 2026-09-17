<?php
/**
 * Product meta box: enable booking, define courses and preview the
 * Amelia events each course picks up.
 */

if (!defined('WPINC')) die;

add_action('add_meta_boxes', function() {
    add_meta_box('bs_bootsschule_product', 'Bootsschule Booking (Kombi-Termine aus Amelia)', 'bs_bootsschule_product_meta', 'product', 'normal', 'high');
});

function bs_bootsschule_product_meta($post) {
    wp_nonce_field('bs_bootsschule_save', 'bs_bootsschule_nonce');

    $is_booking = get_post_meta($post->ID, '_bs_is_booking', true) === 'yes';
    $courses    = bs_get_courses($post->ID);
    $events     = bs_amelia_upcoming_events();

    echo '<p><label><input type="checkbox" name="_bs_is_booking" value="yes" ' . checked($is_booking, true, false) . '> <strong>Buchung aktivieren</strong></label></p>';

    if (!bs_amelia_is_available()) {
        echo '<div class="notice notice-error inline"><p>Amelia wurde nicht gefunden. Es können keine Termine angezeigt werden.</p></div>';
    }
    if (bs_is_legacy_config($post->ID)) {
        echo '<div class="notice notice-warning inline"><p>Die Kurse wurden aus der alten Terminverwaltung übernommen. Bitte die Vorschau prüfen und das Produkt einmal speichern.</p></div>';
    }

    echo '<p class="description">Die Termine kommen automatisch aus Amelia. Pro Kurs legst du fest, welche Amelia-Events dazugehören: per Tag (exakter Tag-Name) oder per Namensbestandteil. Die Reihenfolge gilt auf der Produktseite und im Warenkorb. Leere Zeilen werden ignoriert.</p>';

    // Always offer one empty row to add a course.
    $rows = $courses;
    $rows[] = array('title' => '', 'match_type' => 'name', 'match' => '', 'location' => '');

    echo '<table class="widefat striped" style="margin:12px 0;">';
    echo '<thead><tr><th>Kurs (Anzeigename)</th><th>Amelia-Filter</th><th>Suchbegriff</th><th>Ort (optional, sonst aus Amelia)</th></tr></thead><tbody>';
    foreach ($rows as $i => $course) {
        echo '<tr>';
        echo '<td><input type="text" class="widefat" name="_bs_courses[' . $i . '][title]" value="' . esc_attr($course['title']) . '" placeholder="z.B. SBF Binnen"></td>';
        echo '<td><select name="_bs_courses[' . $i . '][match_type]">';
        echo '<option value="name" ' . selected($course['match_type'], 'name', false) . '>Name enthält</option>';
        echo '<option value="tag" ' . selected($course['match_type'], 'tag', false) . '>Tag</option>';
        echo '</select></td>';
        echo '<td><input type="text" class="widefat" name="_bs_courses[' . $i . '][match]" value="' . esc_attr($course['match']) . '" placeholder="z.B. SBF Binnen"></td>';
        echo '<td><input type="text" class="widefat" name="_bs_courses[' . $i . '][location]" value="' . esc_attr($course['location']) . '"></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Upsell on the single course products
    $upsell_ids = bs_upsell_product_ids($post->ID);
    $products = get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post__not_in' => array($post->ID)));
    echo '<h4 style="margin:16px 0 4px;">Upsell-Box auf Einzelprodukten</h4>';
    echo '<p class="description" style="margin:0 0 6px;">Auf diesen Produkten erscheint ein Hinweis auf das Kombi-Produkt mit der echten Ersparnis (Summe der Einzelpreise minus Kombi-Preis). Wähle alle Einzelkurse, aus denen die Kombi besteht (Strg/Cmd für Mehrfachauswahl). Leer = keine Box.</p>';
    echo '<select name="_bs_upsell_products[]" multiple size="6" style="min-width:320px;">';
    foreach ($products as $p) {
        echo '<option value="' . $p->ID . '"' . (in_array($p->ID, $upsell_ids, true) ? ' selected' : '') . '>' . esc_html($p->post_title) . '</option>';
    }
    echo '</select>';

    // Preview of the saved configuration
    if (!empty($courses)) {
        echo '<h4 style="margin:16px 0 8px;">Vorschau (gespeicherter Stand)</h4>';
        foreach (bs_get_courses_with_events($post->ID) as $course) {
            $count = count($course['events']);
            echo '<p style="margin:10px 0 4px;"><strong>' . esc_html($course['title']) . '</strong> ';
            echo $count
                ? '<span style="color:#00a32a;">' . $count . ' Termin' . ($count === 1 ? '' : 'e') . '</span>'
                : '<span style="color:#d63638;">Keine kommenden Termine gefunden</span>';
            echo '</p>';
            if ($count) {
                echo '<ul style="margin:0 0 0 18px;list-style:disc;">';
                foreach ($course['events'] as $event) {
                    echo '<li>' . esc_html(bs_format_days($event['days']) . ', ' . bs_format_times($event['days']))
                        . ' <span style="color:#646970;">(Amelia #' . $event['id'] . ': ' . esc_html($event['name']) . ')</span>'
                        . ' <strong>' . esc_html(bs_admin_seats_summary($event)) . '</strong></li>';
                }
                echo '</ul>';
            }
        }
    }

    // Helper for finding the right filter terms
    echo '<details style="margin-top:16px;"><summary style="cursor:pointer;"><strong>Alle kommenden Amelia-Events anzeigen (' . count($events) . ')</strong></summary>';
    if (empty($events)) {
        echo '<p>Keine kommenden Events in Amelia gefunden.</p>';
    } else {
        echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>ID</th><th>Name</th><th>Tags</th><th>Termine</th><th>Plätze</th><th>Ort</th></tr></thead><tbody>';
        foreach ($events as $event) {
            echo '<tr>';
            echo '<td>' . $event['id'] . '</td>';
            echo '<td>' . esc_html($event['name']) . '</td>';
            echo '<td>' . esc_html(implode(', ', $event['tags'])) . '</td>';
            echo '<td>' . esc_html(bs_format_days($event['days']) . ', ' . bs_format_times($event['days'])) . '</td>';
            echo '<td>' . esc_html(bs_admin_seats_summary($event)) . '</td>';
            echo '<td>' . esc_html($event['location']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</details>';
}

/** "3 frei (15 Plätze, 10 Amelia, 2 Kombi)" */
function bs_admin_seats_summary(array $event) {
    if ($event['capacity'] === null) return 'Kapazität unbekannt';
    $kombi = bs_kombi_booked_counts();
    $kombi_count = isset($kombi[$event['id']]) ? $kombi[$event['id']] : 0;
    $availability = bs_event_availability($event);
    return $availability['free'] . ' frei (' . $event['capacity'] . ' Plätze, ' . $event['booked'] . ' Amelia, ' . $kombi_count . ' Kombi)';
}

add_action('save_post_product', function($post_id) {
    if (!isset($_POST['bs_bootsschule_nonce']) || !wp_verify_nonce($_POST['bs_bootsschule_nonce'], 'bs_bootsschule_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_product', $post_id)) return;

    update_post_meta($post_id, '_bs_is_booking', isset($_POST['_bs_is_booking']) ? 'yes' : 'no');

    $courses = array();
    $rows = isset($_POST['_bs_courses']) && is_array($_POST['_bs_courses']) ? wp_unslash($_POST['_bs_courses']) : array();
    foreach ($rows as $row) {
        $title = sanitize_text_field(isset($row['title']) ? $row['title'] : '');
        $match = sanitize_text_field(isset($row['match']) ? $row['match'] : '');
        if ($match === '') continue;

        $courses[] = array(
            'title'      => $title !== '' ? $title : $match,
            'match_type' => (isset($row['match_type']) && $row['match_type'] === 'tag') ? 'tag' : 'name',
            'match'      => $match,
            'location'   => sanitize_text_field(isset($row['location']) ? $row['location'] : ''),
        );
    }
    update_post_meta($post_id, '_bs_courses', $courses);

    $upsell = isset($_POST['_bs_upsell_products']) ? array_filter(array_map('absint', (array) $_POST['_bs_upsell_products'])) : array();
    update_post_meta($post_id, '_bs_upsell_products', $upsell ? ',' . implode(',', $upsell) . ',' : '');
});
