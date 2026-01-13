<?php
namespace Jankx\SimpleStats;

class StatsManager
{
    protected static $instance;

    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueTracker']);
        add_action('wp_ajax_jankx_track_view', [$this, 'ajaxTrackView']);
        add_action('wp_ajax_nopriv_jankx_track_view', [$this, 'ajaxTrackView']);

        if (is_admin()) {
            add_action('admin_init', [$this, 'checkDatabaseStatus']);
        }
    }

    public function enqueueTracker()
    {
        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (get_post_status($post_id) !== 'publish') {
            return;
        }

        $js_url = plugin_dir_url(__DIR__) . 'assets/js/stats.js';

        // If this is running inside a theme or another plugin context, we might need a better way to resolve URL
        // But assuming standard Composer usage, we'll try to guess relative to the current file structure or use a filter
        $js_url = apply_filters('jankx_simple_stats_js_url', $js_url);

        // Since this file is in src/, __DIR__ is .../src. 
        // We want .../assets/js/stats.js
        // plugin_dir_url might work if it's a main plugin file, but here it's a library.
        // We need a robust way. For now let's construct relative to site_url if possible or rely on the filter.
        // Actually, let's use a simpler approach: assume common structure.

        // Fallback robust path calculation
        if (defined('AKSELOS_CUSTOMIZER_URL')) {
            // Specific for this project context
            $js_url = AKSELOS_CUSTOMIZER_URL . 'vendor/jankx/simple-stats/assets/js/stats.js';
        } else {
            // Fallback for generic usage (might need adjustment)
            $js_url = content_url() . '/plugins/akselos-customizer/vendor/jankx/simple-stats/assets/js/stats.js';
        }

        wp_enqueue_script('jankx-stats', $js_url, [], '1.0.0', true);

        wp_localize_script('jankx-stats', 'jankx_stats_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jankx_track_view_nonce'),
            'post_id' => $post_id
        ]);
    }

    public function checkDatabaseStatus()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // Handle DB creation request
        if (isset($_GET['action']) && $_GET['action'] === 'jankx_create_stats_db' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'jankx_create_stats_db_nonce')) {
            Database::createTable();
            update_option('jankx_simple_stats_db_version', '1.0.0');

            // Redirect to avoid re-submission
            wp_redirect(remove_query_arg(['action', '_wpnonce']));
            exit;
        }

        if (!Database::tableExists()) {
            add_action('admin_notices', [$this, 'renderDatabaseNotice']);
        }
    }

    public function renderDatabaseNotice()
    {
        $create_url = wp_nonce_url(add_query_arg('action', 'jankx_create_stats_db'), 'jankx_create_stats_db_nonce');
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php _e('Jankx Simple Stats', 'jankx_simple_stats'); ?></strong>:
                <?php _e('The statistics database table is missing.', 'jankx_simple_stats'); ?>
                <a href="<?php echo esc_url($create_url); ?>" class="button button-primary"
                    style="margin-left: 10px;"><?php _e('Create Database Table', 'jankx_simple_stats'); ?></a>
            </p>
        </div>
        <?php
    }

    public function ajaxTrackView()
    {
        check_ajax_referer('jankx_track_view_nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error('Invalid Post ID');
        }

        // Don't track if the post is not published
        if (get_post_status($post_id) !== 'publish') {
            wp_send_json_error('Post not published');
        }

        $user_id = get_current_user_id() ?: null;
        $ip = $this->getIpAddress();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->recordView($post_id, $user_id, $ip, $ua);

        wp_send_json_success();
    }

    protected function getIpAddress()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        return sanitize_text_field($ip);
    }

    protected function recordView($post_id, $user_id, $ip, $ua)
    {
        global $wpdb;
        $table_name = Database::getTableName();

        // Standardize guest user_id to 0 for database consistency
        if (empty($user_id)) {
            $user_id = 0;
        }

        // Check for existing record for this user/IP and post
        // Logic: if user_id AND ip equal existing record
        $query = $wpdb->prepare(
            "SELECT id, views_count, updated_at FROM $table_name 
             WHERE post_id = %d AND ip_address = %s AND user_id = %d
             ORDER BY updated_at DESC LIMIT 1",
            $post_id,
            $ip,
            $user_id
        );

        $latest = $wpdb->get_row($query);
        $now = current_time('mysql');

        // Default interval is 24 hours (86400 seconds)
        // User custom hook: jankx_simple_stats_tracking_interval
        $interval = apply_filters('jankx_simple_stats_tracking_interval', 24 * HOUR_IN_SECONDS);

        if ($latest) {
            $last_update = strtotime($latest->updated_at);
            $current_time = strtotime($now);

            // If found and within interval, update existing record (increment views)
            if (($current_time - $last_update) < $interval) {
                $wpdb->update(
                    $table_name,
                    [
                        'views_count' => $latest->views_count + 1,
                        'updated_at' => $now
                    ],
                    ['id' => $latest->id],
                    ['%d', '%s'],
                    ['%d']
                );
                return;
            }
        }

        // Create new record if:
        // 1. No existing record found for this User+IP combination
        // 2. OR Existing record is older than 24h

        $browser_info = $this->detectBrowserDevice($ua);

        $wpdb->insert(
            $table_name,
            [
                'post_id' => $post_id,
                'user_id' => $user_id,
                'ip_address' => $ip,
                'user_agent' => $ua,
                'browser' => $browser_info['browser'],
                'device' => $browser_info['device'],
                'views_count' => 1,
                'created_at' => $now,
                'updated_at' => $now
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    public function getPostViews($post_id)
    {
        global $wpdb;
        $table_name = Database::getTableName();

        // Check cache first
        $cache_key = 'jankx_post_views_' . $post_id;
        $views = get_transient($cache_key);

        if ($views === false) {
            if (Database::tableExists()) {
                $views = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(views_count) FROM $table_name WHERE post_id = %d",
                    $post_id
                ));
            }
            $views = $views ? (int) $views : 0;
            // Cache for 1 hour
            set_transient($cache_key, $views, HOUR_IN_SECONDS);
        }

        return (int) $views;
    }

    protected function detectBrowserDevice($ua)
    {
        $browser = 'Other';
        $device = 'Desktop';

        // Very basic device detection
        if (preg_match('/mobile/i', $ua)) {
            $device = 'Mobile';
        } elseif (preg_match('/tablet/i', $ua)) {
            $device = 'Tablet';
        }

        // Very basic browser detection
        if (preg_match('/MSIE/i', $ua) && !preg_match('/Opera/i', $ua)) {
            $browser = 'Internet Explorer';
        } elseif (preg_match('/Firefox/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari/i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/Opera/i', $ua)) {
            $browser = 'Opera';
        }

        return [
            'browser' => $browser,
            'device' => $device
        ];
    }
}
