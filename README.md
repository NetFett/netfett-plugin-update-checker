# Netfett Plugin Update Checker

[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D%205.0-21759b.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-blue.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A lightweight, dependency-free PHP library that enables WordPress plugins to deliver automatic updates directly from GitHub Releases — without dedicated update servers, Composer dependencies, or third-party bloat.

---

## Features

- **Zero External Dependencies:** Self-contained in a single PHP file. No Composer, no external SDKs.
- **Minimal Integration:** Simple one-line initialization in your main plugin file.
- **Native WordPress Integration:** Hooks into core WordPress update transients and the native dashboard upgrader.
- **Rich "View Details" Modal:** Automatically generates the WordPress plugin info modal, including header banners, icons, description tabs, and installation instructions.
- **Markdown-to-HTML Release Notes:** Automatically parses GitHub Release notes and formats them into clean HTML bullet points.
- **Historical Changelog Support:** Combines current GitHub Release notes with any historical changelog defined in your plugin's `readme.txt`.
- **Automatic Directory Normalization:** Corrects GitHub's default `{repo}-{tag}` folder naming to the exact plugin slug during extraction.
- **Private Repository Support:** Supports private GitHub repositories using an optional Personal Access Token (PAT).
- **Intelligent Transient Caching:** Prevents GitHub API rate limiting by caching responses (default: 6 hours) with automatic cache invalidation on update or manual check.

---

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher (fully compatible with PHP 8.0, 8.1, 8.2, 8.3, and 8.5)

---

## Installation

Copy `class-netfett-update-checker.php` into your plugin directory (for example, into an `includes/` folder).

### 1. Public GitHub Repository

Add the following initialization code to your main plugin file:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/class-netfett-update-checker.php';

new Netfett_Update_Checker(
    __FILE__,
    'your-github-username/your-repository'
);
```

### 2. Private GitHub Repository (with Access Token)

If your repository is private, pass a GitHub Personal Access Token (classic token with `repo` scope or fine-grained token with Contents read access) as the third parameter.

Best practice: define the token as a constant in `wp-config.php` to avoid committing credentials into version control:

```php
// In wp-config.php:
define( 'MY_PLUGIN_GITHUB_TOKEN', 'ghp_your_secret_access_token_here' );

// In your plugin main file:
$github_token = defined( 'MY_PLUGIN_GITHUB_TOKEN' ) ? MY_PLUGIN_GITHUB_TOKEN : null;

$checker = new Netfett_Update_Checker(
    __FILE__,
    'your-github-username/your-private-repository',
    $github_token
);
```

---

## GitHub Release Workflow

To trigger automatic updates for your plugin users:

1. **Semantic Tagging:** Create a Git release tag using semantic versioning (e.g., `v1.2.0` or `1.2.0`). Leading `v` characters are automatically stripped.
2. **Plugin Header:** Update the `Version:` header in your main plugin file to match your release tag.
3. **Release Notes:** Write your release notes in Markdown on GitHub. These will be parsed into clean HTML and displayed in the WordPress update modal.
4. **Release ZIP Asset (Recommended):** Attach a clean ZIP archive (e.g., `your-plugin.zip`) to the release. The update checker automatically prioritizes attached `.zip` release assets over GitHub's auto-generated source code archive.

---

## Modal Customization (Banners, Icons & Readme)

The update checker automatically looks for local assets to customize the WordPress "View version details" modal:

### Header Banners & Icons
Place the following files in your plugin's `assets/` directory:
- `assets/banner-1544x500.png` (High-DPI / Retina header banner)
- `assets/banner-772x250.png` (Standard-resolution header banner)
- `assets/icon-256x256.png` (High-DPI plugin icon)
- `assets/icon-128x128.png` (Standard-resolution plugin icon)

### Readme Parsing
If your plugin includes a `readme.txt` (or `README.md`), the update checker automatically extracts standard sections:
- `== Description ==` & `=== Features ===` -> Rendered under the **Description** tab.
- `== Installation ==` -> Rendered under the **Installation** tab.
- `== Changelog ==` -> Appended below the latest release notes in the **Changelog** tab.

---

## Advanced Options

```php
$checker = new Netfett_Update_Checker( __FILE__, 'owner/repo' );

// Customize cache duration in seconds (Default: 6 hours = 21600 seconds)
$checker->set_cache_ttl( 12 * HOUR_IN_SECONDS );

// Manually clear transient cache (e.g., upon settings save or plugin activation)
$checker->purge_cache();

// Retrieve detected installed version
$installed_version = $checker->get_installed_version();
```

---

## Under the Hood

The update checker hooks into core WordPress APIs:

- `pre_set_site_transient_update_plugins` & `site_transient_update_plugins`: Compares the installed version against GitHub's latest release via `version_compare()` and injects update payloads.
- `plugins_api`: Returns the plugin information object (banners, icons, description tabs, changelog) when the user clicks "View version details".
- `upgrader_source_selection`: Renames GitHub's extracted folder (e.g. `{repo}-{tag}`) to the expected plugin slug before WordPress moves it into place.
- `upgrader_process_complete`: Automatically clears the update transient cache after a successful update.
- `load-update-core.php`: Automatically flushes cached update data when an administrator clicks "Check again" in the WordPress dashboard.

---

## License

This project is open-source software licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
