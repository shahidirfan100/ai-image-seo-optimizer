<?php
/**
 * Plugin Name: AI Image SEO Optimizer
 * Description: BYOK AI image SEO for WordPress. Generates ALT text, Media Library title, caption, description, keyword data and SEO filename suggestions using OpenRouter and NVIDIA vision models. Supports new uploads, bulk existing media, WooCommerce context, SEO focus keywords, background processing, history and safe upload-time filename renaming.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Shahid Irfan
 * Text Domain: ai-image-seo-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AIISO_VERSION', '1.0.0' );
define( 'AIISO_FILE', __FILE__ );
define( 'AIISO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIISO_URL', plugin_dir_url( __FILE__ ) );

require_once AIISO_DIR . 'includes/class-aiiso-settings.php';
require_once AIISO_DIR . 'includes/class-aiiso-logger.php';
require_once AIISO_DIR . 'includes/class-aiiso-provider.php';
require_once AIISO_DIR . 'includes/class-aiiso-processor.php';
require_once AIISO_DIR . 'includes/class-aiiso-queue.php';
require_once AIISO_DIR . 'includes/class-aiiso-admin.php';
require_once AIISO_DIR . 'includes/class-aiiso-plugin.php';

register_activation_hook( __FILE__, array( 'AIISO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIISO_Plugin', 'deactivate' ) );

AIISO_Plugin::instance();
