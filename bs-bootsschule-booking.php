<?php
/**
 * Plugin Name: BS Bootsschule Booking
 * Description: Bootsschul-Buchungssystem mit Kombi-Produkt Unterstützung
 * Version: 1.2.3
 * Author: Julio Litzenberg
 * Text Domain: bs-bootsschule-booking
 */

if (!defined('WPINC')) die;

// Check for WooCommerce
add_action('admin_init', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>BS Bootsschule Booking:</strong> WooCommerce ist nicht aktiviert. Bitte installieren und aktivieren Sie WooCommerce.</p></div>';
        });
    }
});

define('BS_BOOTSCHULE_VERSION', '1.2.3');
define('BS_BOOTSCHULE_DIR', plugin_dir_path(__FILE__));

// Database tables
function bs_bootsschule_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    
    // Event Templates
    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bs_event_templates (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        description text,
        location varchar(255),
        is_two_day tinyint(1) DEFAULT 0,
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";
    
    // Template <> Product Relations (many-to-many)
    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bs_template_products (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        template_id bigint(20) unsigned NOT NULL,
        product_id bigint(20) unsigned NOT NULL,
        is_required tinyint(1) DEFAULT 1,
        sort_order int(11) DEFAULT 0,
        PRIMARY KEY (id),
        KEY template_id (template_id),
        KEY product_id (product_id)
    ) $charset;";
    
    // Event Dates
    $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bs_event_dates (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        template_id bigint(20) unsigned NOT NULL,
        event_date date NOT NULL,
        start_time time DEFAULT '09:00:00',
        end_time time DEFAULT '17:00:00',
        max_participants int(11) DEFAULT 8,
        booked_count int(11) DEFAULT 0,
        status varchar(20) DEFAULT 'active',
        PRIMARY KEY (id),
        KEY template_id (template_id),
        KEY event_date (event_date)
    ) $charset;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

    bs_bootsschule_run_migrations();
}

function bs_bootsschule_run_migrations() {
    global $wpdb;
    // Migration: add is_two_day if missing (tables created before v1.2.0)
    $col = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}bs_event_templates LIKE 'is_two_day'");
    if (empty($col)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}bs_event_templates ADD COLUMN is_two_day tinyint(1) DEFAULT 0 AFTER location");
    }
    update_option('bs_bootsschule_db_version', BS_BOOTSCHULE_VERSION);
}
register_activation_hook(__FILE__, 'bs_bootsschule_activate');

// Run migrations on every load if DB version is outdated
add_action('plugins_loaded', function() {
    if (get_option('bs_bootsschule_db_version') !== BS_BOOTSCHULE_VERSION) {
        bs_bootsschule_run_migrations();
    }
});

// Admin Menu
add_action('admin_menu', function() {
    add_menu_page('Kombi-Produkte', 'Kombi-Produkte', 'manage_options', 'bs-bootsschule', 'bs_bootsschule_page', 'dashicons-tickets-alt', 30);
});

add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'toplevel_page_bs-bootsschule') return;
    
    $build_url = plugin_dir_url(__FILE__) . 'build/';
    $build_path = plugin_dir_path(__FILE__) . 'build/';
    
    // Asset-File mit Dependencies (generiert von WP-Scripts)
    $asset_file = $build_path . 'index.asset.php';
    $asset = file_exists($asset_file) ? require($asset_file) : ['dependencies' => [], 'version' => BS_BOOTSCHULE_VERSION];
    
    // Haupt-Script mit WP-Dependencies (react, wp-element, wp-api-fetch)
    wp_enqueue_script('bs-booking-admin', $build_url . 'index.js', $asset['dependencies'], $asset['version'], true);
    
    // CSS
    wp_enqueue_style('bs-booking-admin', $build_url . 'index.css', [], $asset['version']);
    
    // Lokalisierung
    wp_localize_script('bs-booking-admin', 'bsBooking', [
        'restUrl'      => esc_url_raw(rest_url('bs-booking/v1')),
        'nonce'        => wp_create_nonce('wp_rest'),
        'productsUrl'  => esc_url(admin_url('edit.php?post_type=product')),
    ]);
});

function bs_bootsschule_page() {
    echo '<div class="wrap"><div id="bs-booking-app"></div></div>';
    
    // Inline Loading-State für bessere UX während JS lädt
    echo '<script>
    document.getElementById("bs-booking-app").innerHTML = "<div style=\'padding:40px;text-align:center;color:#666;\'>Lädt...</div>";
    </script>';
}

// Add meta box to products
add_action('add_meta_boxes', function() {
    add_meta_box('bs_bootsschule_product', 'Bootsschule Booking', 'bs_bootsschule_product_meta', 'product', 'side', 'high');
});

function bs_bootsschule_product_meta($post) {
    wp_nonce_field('bs_bootsschule_save', 'bs_bootsschule_nonce');
    
    $is_booking = get_post_meta($post->ID, '_bs_is_booking', true) === 'yes';
    $is_kombi = get_post_meta($post->ID, '_bs_is_kombi', true) === 'yes';
    $selected_templates = get_post_meta($post->ID, '_bs_templates', true) ?: array();
    if (!is_array($selected_templates)) $selected_templates = array();
    
    global $wpdb;
    $templates = $wpdb->get_results("SELECT id, title, is_two_day FROM {$wpdb->prefix}bs_event_templates WHERE status = 'active' ORDER BY title");
    
    echo '<p><label><input type="checkbox" name="_bs_is_booking" value="yes" ' . checked($is_booking, true, false) . '> Buchung aktivieren</label></p>';
    echo '<hr style="margin:10px 0;">';
    echo '<p><label><input type="checkbox" name="_bs_is_kombi" value="yes" ' . checked($is_kombi, true, false) . '> <strong>Kombi-Produkt</strong></label></p>';
    echo '<p class="description">Bei Kombi-Produkten muss der Kunde je einen Termin pro Kurs auswählen. 2-Tage-Kurse werden automatisch erkannt.</p>';
    
    if (!empty($templates)) {
        echo '<hr style="margin:10px 0;">';
        echo '<p><strong>Verfügbare Events:</strong></p>';
        echo '<div style="max-height:150px;overflow-y:auto;border:1px solid #ddd;padding:8px;background:#fff;">';
        foreach ($templates as $t) {
            $checked = in_array($t->id, $selected_templates) ? 'checked' : '';
            $two_day_badge = $t->is_two_day ? ' <span style="color:#f59e0b;font-size:11px;">(2-Tage)</span>' : '';
            echo '<label style="display:block;margin:4px 0;">';
            echo '<input type="checkbox" name="_bs_templates[]" value="' . $t->id . '" ' . $checked . '> ';
            echo esc_html($t->title) . $two_day_badge;
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="description">Wählen Sie die Events, die für dieses Produkt verfügbar sein sollen.</p>';
    }
}

add_action('save_post_product', function($post_id) {
    if (!isset($_POST['bs_bootsschule_nonce']) || !wp_verify_nonce($_POST['bs_bootsschule_nonce'], 'bs_bootsschule_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_product', $post_id)) return;
    
    update_post_meta($post_id, '_bs_is_booking', isset($_POST['_bs_is_booking']) ? 'yes' : 'no');
    update_post_meta($post_id, '_bs_is_kombi', isset($_POST['_bs_is_kombi']) ? 'yes' : 'no');
    
    $templates = isset($_POST['_bs_templates']) ? array_map('intval', $_POST['_bs_templates']) : array();
    update_post_meta($post_id, '_bs_templates', $templates);
    
    // Also save to relation table for easier queries
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'bs_template_products', array('product_id' => $post_id));
    foreach ($templates as $template_id) {
        $wpdb->insert($wpdb->prefix . 'bs_template_products', array(
            'template_id' => $template_id,
            'product_id' => $post_id,
        ));
    }
});

// REST API
add_action('rest_api_init', function() {
    $perm = function() { return current_user_can('manage_options'); };

    register_rest_route('bs-booking/v1', '/stats', [
        'methods' => 'GET', 'callback' => 'bs_rest_stats', 'permission_callback' => $perm,
    ]);
    register_rest_route('bs-booking/v1', '/templates', [
        ['methods' => 'GET',  'callback' => 'bs_rest_get_templates', 'permission_callback' => $perm],
        ['methods' => 'POST', 'callback' => 'bs_rest_save_template', 'permission_callback' => $perm],
    ]);
    register_rest_route('bs-booking/v1', '/templates/(?P<id>\d+)', [
        ['methods' => 'PUT',    'callback' => 'bs_rest_update_template', 'permission_callback' => $perm],
        ['methods' => 'DELETE', 'callback' => 'bs_rest_delete_template', 'permission_callback' => $perm],
    ]);
    register_rest_route('bs-booking/v1', '/templates/(?P<id>\d+)/dates', [
        ['methods' => 'GET',  'callback' => 'bs_rest_get_dates',  'permission_callback' => $perm],
        ['methods' => 'POST', 'callback' => 'bs_rest_save_date',  'permission_callback' => $perm],
    ]);
    register_rest_route('bs-booking/v1', '/dates/(?P<id>\d+)', [
        'methods' => 'DELETE', 'callback' => 'bs_rest_delete_date', 'permission_callback' => $perm,
    ]);
});

function bs_rest_stats() {
    global $wpdb;
    return [
        'templates'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bs_event_templates WHERE status='active'"),
        'upcoming_dates'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bs_event_dates WHERE event_date >= CURDATE() AND status='active'"),
        'booking_products' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_bs_is_booking' AND meta_value='yes'"),
        'kombi_products'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_bs_is_kombi' AND meta_value='yes'"),
    ];
}

function bs_rest_get_templates() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT t.*, COUNT(d.id) as date_count
         FROM {$wpdb->prefix}bs_event_templates t
         LEFT JOIN {$wpdb->prefix}bs_event_dates d ON d.template_id = t.id AND d.status = 'active'
         WHERE t.status = 'active'
         GROUP BY t.id
         ORDER BY t.title"
    );
    foreach ($rows as $r) {
        $r->id = (int)$r->id;
        $r->is_two_day = (bool)$r->is_two_day;
        $r->date_count = (int)$r->date_count;
    }
    return $rows;
}

function bs_rest_save_template(WP_REST_Request $req) {
    global $wpdb;
    $data = [
        'title'       => sanitize_text_field($req['title']),
        'description' => sanitize_textarea_field($req['description'] ?? ''),
        'location'    => sanitize_text_field($req['location'] ?? ''),
        'is_two_day'  => (int)($req['is_two_day'] ?? 0),
        'status'      => 'active',
    ];
    if (empty($data['title'])) return new WP_Error('missing_title', 'Titel erforderlich', ['status' => 400]);
    $wpdb->insert($wpdb->prefix . 'bs_event_templates', $data);
    $data['id'] = $wpdb->insert_id;
    $data['date_count'] = 0;
    $data['is_two_day'] = (bool)$data['is_two_day'];
    return $data;
}

function bs_rest_update_template(WP_REST_Request $req) {
    global $wpdb;
    $id = (int)$req['id'];
    $data = [
        'title'       => sanitize_text_field($req['title']),
        'description' => sanitize_textarea_field($req['description'] ?? ''),
        'location'    => sanitize_text_field($req['location'] ?? ''),
        'is_two_day'  => (int)($req['is_two_day'] ?? 0),
    ];
    if (empty($data['title'])) return new WP_Error('missing_title', 'Titel erforderlich', ['status' => 400]);
    $wpdb->update($wpdb->prefix . 'bs_event_templates', $data, ['id' => $id]);
    return ['success' => true];
}

function bs_rest_delete_template(WP_REST_Request $req) {
    global $wpdb;
    $id = (int)$req['id'];
    $wpdb->delete($wpdb->prefix . 'bs_event_dates', ['template_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'bs_template_products', ['template_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'bs_event_templates', ['id' => $id]);
    return ['success' => true];
}

function bs_rest_get_dates(WP_REST_Request $req) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bs_event_dates WHERE template_id = %d AND status = 'active' ORDER BY event_date",
        (int)$req['id']
    ));
    foreach ($rows as $r) {
        $r->id = (int)$r->id;
        $r->template_id = (int)$r->template_id;
        $r->max_participants = (int)$r->max_participants;
        $r->booked_count = (int)$r->booked_count;
    }
    return $rows;
}

function bs_rest_save_date(WP_REST_Request $req) {
    global $wpdb;
    $template_id = (int)$req['id'];
    $data = [
        'template_id'      => $template_id,
        'event_date'       => sanitize_text_field($req['event_date']),
        'start_time'       => sanitize_text_field($req['start_time'] ?? '09:00:00'),
        'end_time'         => sanitize_text_field($req['end_time'] ?? '17:00:00'),
        'max_participants' => (int)($req['max_participants'] ?? 8),
        'status'           => 'active',
    ];
    if (empty($data['event_date'])) return new WP_Error('missing_date', 'Datum erforderlich', ['status' => 400]);
    $wpdb->insert($wpdb->prefix . 'bs_event_dates', $data);
    $data['id'] = $wpdb->insert_id;
    $data['booked_count'] = 0;
    return $data;
}

function bs_rest_delete_date(WP_REST_Request $req) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'bs_event_dates', ['id' => (int)$req['id']]);
    return ['success' => true];
}


// Frontend CSS
add_action('wp_enqueue_scripts', function() {
    if (!is_product()) return;
    wp_enqueue_style('bs-booking-frontend', plugin_dir_url(__FILE__) . 'build/frontend.css', [], BS_BOOTSCHULE_VERSION);
});

// Frontend Display
add_action('woocommerce_single_product_summary', 'bs_bootsschule_display', 25);
function bs_bootsschule_display() {
    global $product;
    if (!$product) return;
    
    $product_id = $product->get_id();
    $is_booking = get_post_meta($product_id, '_bs_is_booking', true) === 'yes';
    if (!$is_booking) return;
    
    $is_kombi = get_post_meta($product_id, '_bs_is_kombi', true) === 'yes';
    $selected_templates = get_post_meta($product_id, '_bs_templates', true) ?: array();
    if (empty($selected_templates)) return;
    
    global $wpdb;
    $templates_table = $wpdb->prefix . 'bs_event_templates';
    $dates_table = $wpdb->prefix . 'bs_event_dates';
    
    // Get templates with future dates
    $templates = array();
    foreach ($selected_templates as $template_id) {
        $template = $wpdb->get_row($wpdb->prepare("SELECT * FROM $templates_table WHERE id = %d", $template_id));
        if (!$template) continue;
        
        $dates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $dates_table WHERE template_id = %d AND event_date >= %s AND status = 'active' ORDER BY event_date",
            $template_id,
            date('Y-m-d')
        ));
        
        if (!empty($dates)) {
            $template->dates = $dates;
            $templates[] = $template;
        }
    }
    
    if (empty($templates)) {
        echo '<div style="margin:20px 0;padding:15px;background:#fef3c7;border-radius:6px;">Derzeit sind keine Termine verfügbar.</div>';
        return;
    }
    
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    
    // Build templates config for JS
    $templates_config = array();
    foreach ($templates as $t) {
        $templates_config[$t->id] = array(
            'id' => $t->id,
            'title' => $t->title,
            'is_two_day' => (bool)$t->is_two_day
        );
    }
    
    $selection_text = $is_kombi ? 'Bitte wählen Sie einen Termin pro Kurs aus. 2-Tage-Kurse erfordern zwei aufeinanderfolgende Tage.' : 'Bitte wählen Sie einen Termin aus.';
    
    echo '<div class="bs-bootsschule-booking" data-is-kombi="' . ($is_kombi ? '1' : '0') . '">';
    echo '<h3>Terminwahl</h3>';
    echo '<p>' . $selection_text . '</p>';
    
    foreach ($templates as $template) {
        echo '<div class="bs-template" data-template-id="' . $template->id . '" data-is-two-day="' . ($template->is_two_day ? '1' : '0') . '">';
        echo '<h4>' . esc_html($template->title);
        if ($template->is_two_day) echo ' <span>(2-Tage-Kurs)</span>';
        echo '</h4>';
        if ($template->location) echo '<p class="loc">📍 ' . esc_html($template->location) . '</p>';
        
        // For 2-day templates: pair consecutive days into one selectable weekend block
        if ($template->is_two_day) {
            $days_de = ['Sunday'=>'Sonntag','Monday'=>'Montag','Tuesday'=>'Dienstag','Wednesday'=>'Mittwoch','Thursday'=>'Donnerstag','Friday'=>'Freitag','Saturday'=>'Samstag'];
            $pairs = [];
            $dates_arr = array_values($template->dates);
            for ($i = 0; $i < count($dates_arr) - 1; $i++) {
                $d1 = $dates_arr[$i];
                $d2 = $dates_arr[$i + 1];
                if (strtotime($d2->event_date) - strtotime($d1->event_date) === 86400) {
                    $pairs[] = [$d1, $d2];
                    $i++;
                }
            }

            if (empty($pairs)) {
                echo '<p class="bs-no-dates">Keine Wochenend-Termine verfügbar.</p>';
            } else {
                echo '<div class="bs-dates">';
                foreach ($pairs as $pair) {
                    [$d1, $d2] = $pair;
                    $avail = min(
                        $d1->max_participants > 0 ? max(0, $d1->max_participants - $d1->booked_count) : 999,
                        $d2->max_participants > 0 ? max(0, $d2->max_participants - $d2->booked_count) : 999
                    );
                    $full = $avail === 0;
                    $day1_de = $days_de[date('l', strtotime($d1->event_date))];
                    $day2_de = $days_de[date('l', strtotime($d2->event_date))];

                    echo '<label ' . ($full ? 'class="disabled"' : '') . '>';
                    echo '<input type="radio" name="bs_date_pair[' . $template->id . ']" value="' . $d1->id . ',' . $d2->id . '" data-template="' . esc_attr($template->title) . '" data-day1="' . $d1->event_date . '" data-day2="' . $d2->event_date . '" ' . ($full ? 'disabled' : '') . ' onchange="BSBooking.updateSelection(this)">';
                    echo '<span>';
                    echo '<strong>' . date('d.m.Y', strtotime($d1->event_date)) . ' (' . $day1_de . ')</strong>';
                    echo '<span>+</span>';
                    echo '<strong>' . date('d.m.Y', strtotime($d2->event_date)) . ' (' . $day2_de . ')</strong>';
                    echo '<span>' . substr($d1->start_time, 0, 5) . ' – ' . substr($d1->end_time, 0, 5) . '</span>';
                    if ($full) echo ' <span class="bs-full">AUSGEBUCHT</span>';
                    elseif ($d1->max_participants > 0) echo ' <small>(' . $avail . ' frei)</small>';
                    echo '</span>';
                    echo '</label>';
                }
                echo '</div>';
            }
        } else {
            // Normal 1-day template
            echo '<div class="bs-dates">';
            foreach ($template->dates as $date) {
                $available = $date->max_participants > 0 ? max(0, $date->max_participants - $date->booked_count) : 999;
                $full = $available === 0;
                $input_name = $is_kombi ? 'bs_date[' . $template->id . ']' : 'bs_date';
                
                echo '<label ' . ($full ? 'class="disabled"' : '') . '>';
                
                echo '<input type="radio" name="' . $input_name . '" value="' . $date->id . '" data-template="' . esc_attr($template->title) . '" ' . ($full ? 'disabled' : '') . ' onchange="BSBooking.updateSelection(this)">';
                
                echo '<strong>' . date('d.m.Y (l)', strtotime($date->event_date)) . '</strong> ';
                echo '<span>' . substr($date->start_time, 0, 5) . ' - ' . substr($date->end_time, 0, 5) . '</span> ';
                
                if ($full) echo '<span class="bs-full">AUSGEBUCHT</span>';
                elseif ($date->max_participants > 0) echo '<small>(' . $available . ' frei)</small>';
                
                echo '</label>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    
    echo '<button type="button" class="bs-add-cart" disabled>In Warenkorb</button>';
    echo '<div class="bs-messages"></div>';
    echo '</div>';
    
    // JavaScript
    echo '<script>
    var BSBooking = {
        isKombi: ' . ($is_kombi ? 'true' : 'false') . ',
        templates: ' . json_encode($templates_config) . ',
        
        updateSelection: function(input) {
            var $label = input.closest("label");
            var $datesContainer = input.closest(".bs-dates");
            var $container = input.closest(".bs-bootsschule-booking");
            
            // Visual feedback - reset only within this dates container
            if (input.type === "radio") {
                $datesContainer.querySelectorAll("label").forEach(function(l) {
                    l.classList.remove("selected");
                });
            }
            
            if (input.checked) {
                $label.classList.add("selected");
            } else {
                $label.classList.remove("selected");
            }
            
            this.validate($container);
        },
        
        validate: function($container) {
            var $btn = $container.querySelector(".bs-add-cart");
            var allValid = true;
            var errorMsg = "Bitte alle Events wählen";
            
            // Check each template
            for (var templateId in this.templates) {
                var template = this.templates[templateId];
                var $templateEl = $container.querySelector(".bs-template[data-template-id=\'" + templateId + "\']");
                
                if (template.is_two_day) {
                    var pair = $templateEl.querySelector("input[name=\"bs_date_pair[" + templateId + "]\"]:checked");
                    if (!pair) {
                        allValid = false;
                        errorMsg = "Bitte einen Termin wählen für " + template.title;
                    }
                } else {
                    // 1-day template: single selection
                    if (this.isKombi) {
                        var hasSelection = $templateEl.querySelector("input[name=\"bs_date[" + templateId + "]\"]:checked");
                        if (!hasSelection) {
                            allValid = false;
                        }
                    } else {
                        var hasSelection = $container.querySelector("input[name=\"bs_date\"]:checked");
                        if (!hasSelection) {
                            allValid = false;
                        }
                    }
                }
            }
            
            $btn.disabled = !allValid;
            $btn.textContent = allValid ? "In Warenkorb" : errorMsg;
        }
    };
    
    // Add to cart handler
    document.querySelector(".bs-add-cart").addEventListener("click", function() {
        var $container = this.closest(".bs-bootsschule-booking");
        var $btn = this;
        var selectedDates = [];
        
        // Collect all selected dates
        for (var templateId in BSBooking.templates) {
            var template = BSBooking.templates[templateId];
            
            if (template.is_two_day) {
                var pair = $container.querySelector("input[name=\"bs_date_pair[" + templateId + "]\"]:checked");
                if (pair) {
                    var ids = pair.value.split(",");
                    selectedDates.push({ id: ids[0], template: pair.dataset.template, day: 1 });
                    selectedDates.push({ id: ids[1], template: pair.dataset.template, day: 2 });
                }
            } else {
                var input = $container.querySelector("input[name=\"bs_date[" + templateId + "]\"]:checked") || 
                           $container.querySelector("input[name=\"bs_date\"]:checked");
                if (input) {
                    selectedDates.push({ id: input.value, template: input.dataset.template });
                }
            }
        }
        
        if (selectedDates.length === 0) {
            alert("Bitte wählen Sie mindestens einen Termin aus.");
            return;
        }
        
        $btn.disabled = true;
        $btn.textContent = "Wird hinzugefügt...";
        
        fetch("' . admin_url('admin-ajax.php') . '", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                action: "bs_bootsschule_add_cart",
                product_id: ' . $product_id . ',
                dates: JSON.stringify(selectedDates),
                _ajax_nonce: "' . wp_create_nonce('bs_bootsschule') . '"
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location = data.data.cart_url;
            } else {
                alert(data.data.message || "Fehler");
                $btn.disabled = false;
                $btn.textContent = "In Warenkorb";
                BSBooking.validate($container);
            }
        })
        .catch(function() {
            alert("Server-Fehler");
            $btn.disabled = false;
            $btn.textContent = "In Warenkorb";
        });
    });
    </script>';
}

// AJAX Handler
add_action('wp_ajax_bs_bootsschule_add_cart', 'bs_bootsschule_ajax');
add_action('wp_ajax_nopriv_bs_bootsschule_add_cart', 'bs_bootsschule_ajax');
function bs_bootsschule_ajax() {
    check_ajax_referer('bs_bootsschule');
    
    $product_id = intval($_POST['product_id']);
    $date_ids = json_decode(stripslashes($_POST['dates']), true);
    
    if (!$product_id || empty($date_ids)) {
        wp_send_json_error(array('message' => 'Bitte wählen Sie Termine aus.'));
    }
    
    global $wpdb;
    $dates_table = $wpdb->prefix . 'bs_event_dates';
    $templates_table = $wpdb->prefix . 'bs_event_templates';
    
    // Validate dates and collect info
    $event_data = array();
    foreach ($date_ids as $selected) {
        $date_id = intval($selected['id']);
        $day = isset($selected['day']) ? intval($selected['day']) : null;
        
        $date = $wpdb->get_row($wpdb->prepare(
            "SELECT d.*, t.title as template_title, t.location FROM $dates_table d 
            JOIN $templates_table t ON d.template_id = t.id 
            WHERE d.id = %d",
            $date_id
        ));
        
        if (!$date) {
            wp_send_json_error(array('message' => 'Termin nicht gefunden.'));
        }
        
        $available = $date->max_participants > 0 ? max(0, $date->max_participants - $date->booked_count) : 999;
        if ($available === 0) {
            wp_send_json_error(array('message' => 'Termin "' . $date->template_title . '" ist ausgebucht.'));
        }
        
        $event_item = array(
            'id' => $date->id,
            'template' => $date->template_title,
            'date' => $date->event_date,
            'time' => substr($date->start_time, 0, 5) . ' - ' . substr($date->end_time, 0, 5),
            'location' => $date->location,
        );
        
        if ($day) {
            $event_item['day'] = $day;
        }
        
        $event_data[] = $event_item;
    }
    
    $is_kombi = get_post_meta($product_id, '_bs_is_kombi', true) === 'yes';
    
    $added = WC()->cart->add_to_cart($product_id, 1, 0, array(), array(
        'bs_events' => $event_data,
        'bs_is_kombi' => $is_kombi,
    ));
    
    if ($added) {
        wp_send_json_success(array('cart_url' => wc_get_cart_url()));
    } else {
        wp_send_json_error(array('message' => 'Fehler beim Hinzufügen zum Warenkorb.'));
    }
}

// Cart display
add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    if (!isset($cart_item['bs_events'])) return $item_data;
    
    foreach ($cart_item['bs_events'] as $i => $event) {
        // Show day info if present in the event data
        $day_label = isset($event['day']) ? ' (Tag ' . $event['day'] . ')' : '';
        $label = ($cart_item['bs_is_kombi'] ? 'Kurs ' . ($i + 1) : 'Termin') . $day_label;
        
        $item_data[] = array(
            'key' => $label,
            'value' => '<strong>' . esc_html($event['template']) . '</strong><br>' .
                      date('d.m.Y', strtotime($event['date'])) . ' ' . $event['time'] .
                      ($event['location'] ? '<br>📍 ' . esc_html($event['location']) : '')
        );
    }
    return $item_data;
}, 10, 2);

// Order meta
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values) {
    if (!isset($values['bs_events'])) return;
    
    foreach ($values['bs_events'] as $event) {
        $item->add_meta_data($event['template'], date('d.m.Y', strtotime($event['date'])) . ' ' . $event['time']);
    }
    $item->add_meta_data('_bs_event_ids', wp_json_encode(array_column($values['bs_events'], 'id')));
}, 10, 3);

// Update booked count on payment
add_action('woocommerce_order_status_completed', function($order_id) {
    $order = wc_get_order($order_id);
    global $wpdb;
    
    foreach ($order->get_items() as $item) {
        $event_ids_json = $item->get_meta('_bs_event_ids');
        if (!$event_ids_json) continue;
        
        $event_ids = json_decode($event_ids_json, true);
        foreach ($event_ids as $event_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}bs_event_dates SET booked_count = booked_count + 1 WHERE id = %d",
                $event_id
            ));
        }
    }
});
