<?php

/**
 * Plugin Name:       ScreamingRabbit Connector
 * Description:       Connects this site to ScreamingRabbit for SEO and security monitoring. Read-only: it reports versions, plugins, themes and settings, and changes nothing.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Serhan Demirel
 * Author URI:        https://serhandemirel.com
 * License:           GPL-2.0-or-later
 * Text Domain:       screamingrabbit-connector
 */
defined('ABSPATH') || exit;

define('SR_CONNECTOR_VERSION', '0.1.0');

require_once __DIR__.'/includes/class-sr-signature.php';
require_once __DIR__.'/includes/class-sr-settings.php';
require_once __DIR__.'/includes/class-sr-inventory.php';
require_once __DIR__.'/includes/class-sr-api.php';

add_action('rest_api_init', ['SR_Api', 'register']);
add_action('admin_menu', ['SR_Settings', 'register']);
add_action('admin_post_sr_connect', ['SR_Settings', 'connect']);
add_action('admin_post_sr_disconnect', ['SR_Settings', 'disconnect']);

register_uninstall_hook(__FILE__, 'sr_connector_uninstall');

function sr_connector_uninstall()
{
    delete_option('sr_connector');
}
