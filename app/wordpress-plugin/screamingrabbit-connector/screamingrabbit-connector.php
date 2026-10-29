<?php

/**
 * Plugin Name:       ScreamingRabbit Connector
 * Description:       Connects this site to ScreamingRabbit for SEO and security monitoring. It reports versions, plugins, themes and settings; it changes a page's SEO title, description or noindex, an image's alt text or a 301 redirect only when an administrator allows it and the change is approved in ScreamingRabbit.
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Serhan Demirel
 * Author URI:        https://serhandemirel.com
 * License:           GPL-2.0-or-later
 * Text Domain:       screamingrabbit-connector
 */
defined('ABSPATH') || exit;

define('SR_CONNECTOR_VERSION', '0.6.0');

require_once __DIR__.'/includes/class-sr-signature.php';
require_once __DIR__.'/includes/class-sr-settings.php';
require_once __DIR__.'/includes/class-sr-inventory.php';
require_once __DIR__.'/includes/class-sr-api.php';
require_once __DIR__.'/includes/class-sr-updater.php';
require_once __DIR__.'/includes/class-sr-events.php';
require_once __DIR__.'/includes/class-sr-changes.php';

add_action('rest_api_init', ['SR_Api', 'register']);
SR_Updater::register();
SR_Events::register();
add_action('template_redirect', ['SR_Changes', 'redirect'], 1);
add_action('admin_menu', ['SR_Settings', 'register']);
add_action('admin_post_sr_connect', ['SR_Settings', 'connect']);
add_action('admin_post_sr_disconnect', ['SR_Settings', 'disconnect']);
add_action('admin_post_sr_writes', ['SR_Settings', 'writes']);

register_uninstall_hook(__FILE__, 'sr_connector_uninstall');

function sr_connector_uninstall()
{
    delete_option('sr_connector');
    delete_option('sr_change_log');
    delete_option('sr_redirects');
    delete_transient('sr_core_integrity');
    delete_transient('sr_connector_latest');
}
