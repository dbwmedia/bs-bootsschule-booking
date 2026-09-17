<?php
/**
 * Plugin Name: BS Bootsschule Booking
 * Description: Kombi-Buchung für die Bootsschule. Liest die Kurstermine direkt aus Amelia und verkauft sie als WooCommerce-Kombi-Produkt.
 * Version: 2.6.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, ameliabooking
 * Author: Dennis Buchwald
 * Author URI: https://dennisbuchwald.de
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bs-bootsschule-booking
 * Update URI: https://github.com/dbwmedia/bs-bootsschule-booking
 */

if (!defined('WPINC')) die;

define('BS_BOOTSCHULE_VERSION', '2.6.1');
define('BS_BOOTSCHULE_DIR', plugin_dir_path(__FILE__));
define('BS_BOOTSCHULE_URL', plugin_dir_url(__FILE__));

require_once BS_BOOTSCHULE_DIR . 'includes/amelia.php';
require_once BS_BOOTSCHULE_DIR . 'includes/courses.php';
require_once BS_BOOTSCHULE_DIR . 'includes/availability.php';
require_once BS_BOOTSCHULE_DIR . 'includes/admin.php';
require_once BS_BOOTSCHULE_DIR . 'includes/frontend.php';
require_once BS_BOOTSCHULE_DIR . 'includes/cart.php';
require_once BS_BOOTSCHULE_DIR . 'includes/amelia-sync.php';
require_once BS_BOOTSCHULE_DIR . 'includes/upsell.php';

// Dependency checks
add_action('admin_init', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>BS Bootsschule Booking:</strong> WooCommerce ist nicht aktiviert. Bitte installieren und aktivieren Sie WooCommerce.</p></div>';
        });
    }
    if (!bs_amelia_is_available()) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>BS Bootsschule Booking:</strong> Amelia wurde nicht gefunden. Ohne Amelia können keine Kurstermine angezeigt werden.</p></div>';
        });
    }
});
