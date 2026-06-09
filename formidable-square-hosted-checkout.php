<?php
/**
 * Plugin Name: Formidable Square Hosted Checkout
 * Description: Entry-first Square hosted checkout flow for Formidable Forms.
 * Version: 1.0.0
 * Author: Codex
 * Requires PHP: 8.3
 * Requires at least: 6.4
 * Text Domain: frm-square-hosted-checkout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('FRM_SQUARE_HC_FILE', __FILE__);
define('FRM_SQUARE_HC_PATH', plugin_dir_path(__FILE__));
define('FRM_SQUARE_HC_URL', plugin_dir_url(__FILE__));
define('FRM_SQUARE_HC_VERSION', '1.0.0');

require_once FRM_SQUARE_HC_PATH . 'includes/helpers.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-settings.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-logger.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-entry-repository.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-payment-access.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-square-client.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-square-status-mapper.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-payment-link-service.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-webhook-service.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-rest-controller.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-shortcode.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-cron.php';
require_once FRM_SQUARE_HC_PATH . 'includes/class-plugin.php';

register_activation_hook(__FILE__, ['FrmSquareHostedCheckout\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['FrmSquareHostedCheckout\\Plugin', 'deactivate']);

\FrmSquareHostedCheckout\Plugin::instance();
