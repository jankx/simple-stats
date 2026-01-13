# Jankx Simple Stats

A lightweight and efficient WordPress library for tracking post views with advanced user logic.

## Logic Overview

The library tracks post views based on a combination of **User ID** and **IP Address**.

1.  **Unique Tracking**:
    *   If a user (Guest or Logged in) visits a post, the system checks for an existing record matching their User ID and IP.
    *   If **no record exists**, a new row is inserted (`views_count` = 1).

2.  **Session/Interval Update**:
    *   If a record **exists**, the system checks the `updated_at` timestamp.
    *   **Within 24 Hours** (default): The existing record is updated. `views_count` increments by 1, and `updated_at` is set to the current time.
    *   **After 24 Hours**: A completely new record is created to start a new tracking session.

## Installation & Setup

 The database table `wp_jankx_simple_stats` is automatically created via the plugin's activation hook or can be manually created via the Admin Dashboard if missing.

```php
// In your plugin activation hook
if (class_exists(\Jankx\SimpleStats\Database::class)) {
    \Jankx\SimpleStats\Database::createTable();
}
```

## Public API

### 1. Initialization

The stats manager is typically initialized in your bootstrap file. It automatically hooks into `wp` action to track views for singular posts.

```php
use Jankx\SimpleStats\StatsManager;

if (class_exists(StatsManager::class)) {
    StatsManager::getInstance()->init();
}
```

### 2. Get Post Views

Retrieve the total aggregated views for a specific post. This method includes 1-hour caching for performance.

```php
use Jankx\SimpleStats\StatsManager;

$post_id = 123;
$views = StatsManager::getInstance()->getPostViews($post_id);

echo "Total Views: " . $views;
```

## Hooks & Customization

### Change Tracking Interval

By default, a new session is started if the user returns after 24 hours. You can customize this interval using the `jankx_simple_stats_tracking_interval` filter.

**Example: Change session timeout to 1 Hour**

```php
add_filter('jankx_simple_stats_tracking_interval', function($seconds) {
    return 1 * HOUR_IN_SECONDS; // 3600 seconds
});
```

## Database Schema

Table name: `wp_jankx_simple_stats`

| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | bigint(20) | Primary Key |
| `post_id` | bigint(20) | ID of the viewed post |
| `user_id` | bigint(20) | User ID (0 for guests) |
| `ip_address` | varchar(100) | User IP address |
| `user_agent` | text | Browser User Agent string |
| `browser` | varchar(50) | Detected Browser name |
| `device` | varchar(50) | Detected Device type (Desktop, Mobile, Tablet) |
| `views_count` | int(11) | Number of hits in this session |
| `created_at` | datetime | Session start time |
| `updated_at` | datetime | Last hit time |

## Example Usage in Theme

```php
// single.php

// Display views count
if (class_exists(\Jankx\SimpleStats\StatsManager::class)) {
    $views = \Jankx\SimpleStats\StatsManager::getInstance()->getPostViews(get_the_ID());
    echo '<div class="post-views">';
    echo '<span class="count">' . number_format_i18n($views) . '</span> views';
    echo '</div>';
}
```
