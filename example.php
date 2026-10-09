<?php
/**
 * Example Usage: Netfett Plugin Update Checker
 *
 * This file demonstrates how to integrate the update checker into
 * your WordPress plugin.
 *
 * @package Netfett\PluginUpdateChecker
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * --------------------------------------------------------------------------
 * 1. Standard Integration (Public GitHub Repository)
 * --------------------------------------------------------------------------
 * This is the recommended one-liner in your main plugin file.
 */
require_once __DIR__ . '/class-netfett-update-checker.php';

// Replace 'your-username/your-plugin' with your GitHub repository:
new Netfett_Update_Checker(
	__FILE__,                      // Full path to main plugin file
	'your-username/your-plugin'    // GitHub repository in 'owner/repo' format
);


/**
 * --------------------------------------------------------------------------
 * 2. Private GitHub Repository (with Personal Access Token)
 * --------------------------------------------------------------------------
 * If your repository is private, pass a GitHub Personal Access Token (PAT)
 * with read access to repository contents/releases as the third parameter.
 *
 * TIP: Define the token in wp-config.php as a constant so it is not
 * committed to your version control repository!
 *
 * In wp-config.php:
 * define( 'MY_PLUGIN_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
 */
$github_token = defined( 'MY_PLUGIN_GITHUB_TOKEN' ) ? MY_PLUGIN_GITHUB_TOKEN : null;

$checker = new Netfett_Update_Checker(
	__FILE__,
	'your-username/your-private-plugin',
	$github_token
);


/**
 * --------------------------------------------------------------------------
 * 3. Optional Configuration
 * --------------------------------------------------------------------------
 */

// Custom cache duration in seconds (Default: 6 hours = 21600 seconds)
$checker->set_cache_ttl( 12 * HOUR_IN_SECONDS );

// Manually clear transient cache (e.g. on plugin activation or settings update)
// $checker->purge_cache();
