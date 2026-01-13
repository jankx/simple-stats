<?php
namespace Jankx\SimpleStats;

class Database
{
    public static function getTableName()
    {
        global $wpdb;
        return $wpdb->prefix . 'jankx_simple_stats';
    }

    public static function createTable()
    {
        global $wpdb;
        $table_name = self::getTableName();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            ip_address varchar(100) NOT NULL,
            user_agent text DEFAULT NULL,
            browser varchar(50) DEFAULT NULL,
            device varchar(50) DEFAULT NULL,
            views_count int(11) DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY user_ip_post (user_id, ip_address, post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function tableExists()
    {
        global $wpdb;
        $table_name = self::getTableName();
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }
}
