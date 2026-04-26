# CHIROBASIX Heatmaps

WordPress plugin that injects the CHIROBASIX Heatmaps tracking script onto client websites, enabling click maps, scroll depth analysis, and mouse movement heatmaps from the Copilot dashboard.

## How it works

1. Install and activate the plugin on a client's WordPress site
2. Go to **Settings → CHIROBASIX Heatmaps** and enter the Site ID from the Copilot dashboard
3. The tracker script loads on every frontend page and sends click, scroll, and mouse movement events to Copilot
4. View heatmaps in Copilot under the client's **Website → Heatmap** tool

## Auto-updates

The plugin self-updates from this repository. When a new release is tagged on GitHub, sites running this plugin will automatically download and apply the update the next time an admin visits the WordPress dashboard.

To trigger a manual update check: add `?cbx_heatmap_update=1` to any WP admin URL (nonce required).

## Installation

Download `chirobasix-heatmap.php` and place it in `wp-content/plugins/chirobasix-heatmap/`, or use the Copilot dashboard's one-click install (recommended).

## Requirements

- WordPress 5.0+
- PHP 7.4+
- A valid Site ID from the ChiroBasix Copilot dashboard
