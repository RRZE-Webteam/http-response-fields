<?php
/**
 * Plugin Name: HTTP Response Fields
 * Description: Ermöglich die Verwaltung der HTTP-Antwort-Headerfelder für Post-Type-Ausgaben.
 * Version: 1.0
 * Author: Rolf v. d. Forst
 * Author URI: http://blogs.fau.de/webworking/
 * Text Domain: http-response-fields
 * Network: true
 * License: GPLv2 or later
 */

/*
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

add_action('plugins_loaded', array('HTTP_Response_Fields', 'init'));

register_activation_hook(__FILE__, array('HTTP_Response_Fields', 'activation'));

class HTTP_Response_Fields {

    const version = '1.0'; // Plugin-Version
    const option_name = 'http_response_fields';
    const version_option_name = 'http_response_fields_version';
    const textdomain = 'http-response-fields';
    const php_version = '5.3'; // Minimal erforderliche PHP-Version
    const wp_version = '4.0'; // Minimal erforderliche WordPress-Version

    public static $options;
    public static $page_options;
    
    public static function init() {
        load_plugin_textdomain(self::textdomain, false, sprintf('%slanguages', plugin_dir_path(__FILE__)));

        self::$options = (object) self::get_options();
        self::$page_options = (object) self::page_options();
        
        if (is_multisite()) {
            add_action('network_admin_menu', array(__CLASS__, 'network_admin_menu'));
            add_action('admin_init', array(__CLASS__, 'network_admin_settings') );
            
            if (isset($_GET['update']) && $_GET['update'] == self::$page_options->menu_slug) {
                add_action('network_admin_notices', array(__CLASS__, 'network_admin_notice'));
            }
            
        } else {
            add_action('admin_menu', array(__CLASS__, 'admin_menu'));
            add_action('admin_init', array(__CLASS__, 'admin_settings') );
        }
        
        add_action('init', array(__CLASS__, 'update_version'));

        add_action('init', array(__CLASS__, 'ob_start'));

        add_action('wp_footer', array(__CLASS__, 'ob_end_flush'));
    }

    public static function activation() {
        self::version_compare();
        
        if (is_multisite()) {
            update_site_option(self::version_option_name, self::version);
        } else {
            update_option(self::version_option_name, self::version);
        }        
    }
    
    private static function version_compare() {
        $error = '';

        if (version_compare(PHP_VERSION, self::php_version, '<')) {
            $error = sprintf(__('Ihre PHP-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die PHP-Version %s.', self::textdomain), PHP_VERSION, self::php_version);
        }

        if (version_compare($GLOBALS['wp_version'], self::wp_version, '<')) {
            $error = sprintf(__('Ihre Wordpress-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die Wordpress-Version %s.', self::textdomain), $GLOBALS['wp_version'], self::wp_version);
        }

        if (!empty($error)) {
            deactivate_plugins(plugin_basename(__FILE__), false, true);
            wp_die($error);
        }
    }

    public static function update_version() {
        if (is_multisite() && get_option(self::version_option_name, null) != self::version) {
            update_site_option(self::version_option_name, self::version);
        } elseif (get_option(self::version_option_name, null) != self::version) {
            update_option(self::version_option_name, self::version);
        }
    }

    private static function default_options() {
        $default_options = array(
            'add_etag_header' => 1,
            'generate_weak_etag' => 1,
            'add_last_modified_header' => 1,
            'add_expires_header' => 1,
            'add_cache_control_header' => 1,
            'cache_max_age' => 86400,
            'cache_max_age_for_search_results' => 0,
            'cache_max_age_for_authenticated_users' => 0,
        );
        
        return $default_options;
    }
    
    private static function get_options() {
        $defaults = self::default_options();

        if (is_multisite()) {
            $options = (array) get_site_option(self::option_name);
        } else {
            $options = (array) get_option(self::option_name);
        }

        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return $options;
    }

    private static function page_options() {
        if (is_multisite()) {
            $options = array(
                'page_title' => __('HTTP Antwort-Headerfelder', self::textdomain),
                'capability' => 'manage_network_options',
                'menu_slug' => 'http-response-fields',
                'output' => array(__CLASS__, 'network_page_settings'),
            );
        } else {
            $options = array(
                'page_title' => __('HTTP Antwort-Headerfelder', self::textdomain),
                'capability' => 'manage_options',
                'menu_slug' => 'http-response-fields',
                'output' => array(__CLASS__, 'page_settings'),
                'help_tab' => array(
                    'id' => 'http-response-fields-overview',
                    'title' => __('Übersicht', self::textdomain),
                    'content' => '',
                ),
                'help_sidebar' => __('<p><strong>Für mehr Information:</strong></p><p><a href="http://blogs.fau.de/cms">Dokumentation</a></p><p><a href="http://blogs.fau.de/webworking">RRZE-Webworking</a></p><p><a href="https://github.com/RRZE-Webteam">RRZE-Webteam in Github</a></p>', self::textdomain),
            );            
        }

        return $options;
    }
    
    public static function network_admin_menu() {        
        $input = isset($_POST[self::option_name]) ? $_POST[self::option_name] : null;
        if (!empty($input)) {
            $input = self::validate_options($input);                    
            update_site_option(self::option_name, $input);
            wp_redirect(add_query_arg('update', self::$page_options->menu_slug));
            exit;
        }
        
        add_submenu_page('settings.php', self::$page_options->page_title, self::$page_options->page_title, self::$page_options->capability, self::$page_options->menu_slug, self::$page_options->output);
    }
        
    public static function network_admin_settings() {
        self::add_settings_section();        
    }
    
    public static function admin_menu() {
        global $http_response_fields_page;

        $http_response_fields_page = array();
        
        $options_page = add_options_page(self::$page_options->page_title, self::$page_options->page_title, self::$page_options->capability, self::$page_options->menu_slug, self::$page_options->output);

        $http_response_fields_page[$options_page] = self::$page_options->menu_slug;
        
        add_action("load-{$options_page}", array(__CLASS__, 'action_add_help_tab'));
    }

    public static function admin_settings() {
        $slug = self::$page_options->menu_slug;
        
        register_setting("{$slug}_setting", self::option_name, array(__CLASS__, 'validate_options'));

        self::add_settings_section();
    }
        
    private static function add_settings_section() {
        $slug = self::$page_options->menu_slug;
        
        add_settings_section("{$slug}_settings_section", false, '__return_false', "{$slug}_setting");

        add_settings_field('add_etag_header', __('ETag', self::textdomain), array(__CLASS__, 'add_etag_header_field'), "{$slug}_setting", "{$slug}_settings_section");
        add_settings_field('generate_weak_etag', __('Schwacher ETag', self::textdomain), array(__CLASS__, 'generate_weak_etag_field'), "{$slug}_setting", "{$slug}_settings_section");
        add_settings_field('add_last_modified_header', __('Last-Modified', self::textdomain), array(__CLASS__, 'add_last_modified_header_field'), "{$slug}_setting", "{$slug}_settings_section");
        add_settings_field('add_expires_header', __('Expires', self::textdomain), array(__CLASS__, 'add_expires_header_field'), "{$slug}_setting", "{$slug}_settings_section");
        add_settings_field('add_cache_control_header', __('Cache-Control', self::textdomain), array(__CLASS__, 'add_cache_control_header_field'), "{$slug}_setting", "{$slug}_settings_section");
        add_settings_field('cache_max_age', __('Cacheablaufzeit', self::textdomain), array(__CLASS__, 'cache_max_age_field'), "{$slug}_setting", "{$slug}_settings_section");
        add_settings_field('cache_max_age_for_search_results', __('Cacheablaufzeit der Sucheausgabe', self::textdomain), array(__CLASS__, 'cache_max_age_for_search_results_field'), "{$slug}_setting", "{$slug}_settings_section");
        add_settings_field('cache_max_age_for_authenticated_users', __('Cacheablaufzeit für angemeldete Nutzer', self::textdomain), array(__CLASS__, 'cache_max_age_for_authenticated_users_field'), "{$slug}_setting", "{$slug}_settings_section");        
    }
        
    public static function network_page_settings() {
        $slug = self::$page_options->menu_slug;
        ?>
        <div class="wrap">
            <h2><?php echo esc_html(__('Einstellungen &rsaquo; HTTP Antwort-Headerfelder', self::textdomain)); ?></h2>

            <form method="post">
                <?php
                settings_fields("{$slug}_setting");
                do_settings_sections("{$slug}_setting");
                submit_button();
                ?>
            </form>

        </div>
        <?php
    }
    
    public static function page_settings() {
        $slug = self::$page_options->menu_slug;
        ?>
        <div class="wrap">
            <h2><?php echo esc_html(__('Einstellungen &rsaquo; HTTP Antwort-Headerfelder', self::textdomain)); ?></h2>

            <form method="post" action="options.php">
                <?php
                settings_fields("{$slug}_setting");
                do_settings_sections("{$slug}_setting");
                submit_button();
                ?>
            </form>

        </div>
        <?php
    }
    
    public static function network_admin_notice() {
        ?><div id="message" class="updated"><p><?php _e('Einstellungen gespeichert.', self::textdomain) ?></p></div><?php
    }
    
    public static function validate_options($input) {
        $defaults = self::default_options();       
        
        $input['add_etag_header'] = !empty($input['add_etag_header']) ? 1 : 0;
        $input['generate_weak_etag'] = !empty($input['generate_weak_etag']) ? 1 : 0;
        $input['add_last_modified_header'] = !empty($input['add_last_modified_header']) ? 1 : 0;
        $input['add_expires_header'] = !empty($input['add_expires_header']) ? 1 : 0;
        $input['add_cache_control_header'] = !empty($input['add_cache_control_header']) ? 1 : 0;
        $input['cache_max_age'] = (int) (isset($input['cache_max_age']) && $input['cache_max_age'] >= 0 ) ? $input['cache_max_age'] : self::$options->cache_max_age;
        $input['cache_max_age_for_search_results'] = (int) (isset($input['cache_max_age_for_search_results']) && $input['cache_max_age_for_search_results'] >= 0 ) ? $input['cache_max_age_for_search_results'] : self::$options->cache_max_age_for_search_results;
        $input['cache_max_age_for_authenticated_users'] = (int) (isset($input['cache_max_age_for_authenticated_users']) && $input['cache_max_age_for_authenticated_users'] >= 0 ) ? $input['cache_max_age_for_authenticated_users'] : self::$options->cache_max_age_for_authenticated_users;

        $input = wp_parse_args($input, $defaults);
        $input = array_intersect_key($input, $defaults);

        return $input;
    }
    
    public static function action_add_help_tab() {
        global $http_response_fields_page;

        $screen = get_current_screen();

        if (!isset($http_response_fields_page[$screen->id])) {
            return;
        }

        $slug = $http_response_fields_page[$screen->id];

        $help_tab = self::$page_options->help_tab;

        if ($help_tab) {
            $screen->add_help_tab($help_tab);

            $help_sidebar = self::$page_options->help_sidebar;

            if ($help_sidebar) {
                $screen->set_help_sidebar($help_sidebar);
            }
        }
    }
    
    public static function add_etag_header_field() {
        ?>
        <fieldset><legend class="screen-reader-text"><span><?php _e('ETag', self::textdomain); ?></span></legend>
            <label for="add_etag_header">
                <input type="checkbox" <?php checked(self::$options->add_etag_header, 1); ?> value="1" id="add-etag-header" name="<?php printf('%s[add_etag_header]', self::option_name); ?>">
                <?php _e('Dient zur Bestimmung von Änderungen an der angeforderten Post-Type-Ausgabe und wird hauptsächlich zum Caching verwendet.', self::textdomain); ?>
            </label>
        </fieldset>        
        <?php
    }
       
    public static function generate_weak_etag_field() {
        ?>
        <fieldset><legend class="screen-reader-text"><span><?php _e('Schwacher ETag', self::textdomain); ?></span></legend>
            <label for="generate_weak_etag">
                <input type="checkbox" <?php checked(self::$options->generate_weak_etag, 1); ?> value="1" id="generate-weak-etag" name="<?php printf('%s[generate_weak_etag]', self::option_name); ?>">
                <?php _e('Der ETag darf von mehreren Post-Type-Ausgaben geführt werden, falls diese zueinander äquivalent sind, sich also semantisch nicht signifikant unterscheiden.', self::textdomain); ?>
            </label>
        </fieldset>        
        <?php
    }
    
    public static function add_last_modified_header_field() {
        ?>
        <fieldset><legend class="screen-reader-text"><span><?php _e('Last-Modified', self::textdomain); ?></span></legend>
            <label for="add_last_modified_header">
                <input type="checkbox" <?php checked(self::$options->add_last_modified_header, 1); ?> value="1" id="add-last-modified-header" name="<?php printf('%s[add_last_modified_header]', self::option_name); ?>">
                <?php _e('Zeitpunkt der letzten Änderung an der Post-Type-Ausgabe.', self::textdomain); ?>
            </label>
        </fieldset>        
        <?php
    }
    
    public static function add_expires_header_field() {
        ?>
        <fieldset><legend class="screen-reader-text"><span><?php _e('Expires', self::textdomain); ?></span></legend>
            <label for="add_expires_header">
                <input type="checkbox" <?php checked(self::$options->add_expires_header, 1); ?> value="1" id="add-expires-header" name="<?php printf('%s[add_expires_header]', self::option_name); ?>">
                <?php _e('Ab wann die Post-Type-Ausgabe als veraltet angesehen werden kann.', self::textdomain); ?>
            </label>
        </fieldset>        
        <?php
    }
    
    public static function add_cache_control_header_field() {
        ?>
        <fieldset><legend class="screen-reader-text"><span><?php _e('Cache-Control', self::textdomain); ?></span></legend>
            <label for="add_cache_control_header">
                <input type="checkbox" <?php checked(self::$options->add_cache_control_header, 1); ?> value="1" id="add-cache-control-header" name="<?php printf('%s[add_cache_control_header]', self::option_name); ?>">
                <?php _e('Teilt allen Caching-Systeme (z. B. Proxys) mit, ob und wie lange das Objekt gespeichert werden darf.', self::textdomain); ?>
            </label>
        </fieldset>        
        <?php
    }
    
    public static function cache_max_age_field() {
        ?>
        <input type="text" class="small-text" value="<?php echo self::$options->cache_max_age; ?>" id="cache-max-age" name="<?php printf('%s[cache_max_age]', self::option_name); ?>">
        <p class="description"><?php _e('Ablaufzeit des Caches in Sekunden.', self::textdomain); ?></p>
        <?php
    }
    
    public static function cache_max_age_for_search_results_field() {
        ?>
        <input type="text" class="small-text" value="<?php echo self::$options->cache_max_age_for_search_results; ?>" id="cache-max-age-for-search-results" name="<?php printf('%s[cache_max_age_for_search_results]', self::option_name); ?>">
        <p class="description"><?php _e('Ablaufzeit des Caches in Sekunden.', self::textdomain); ?></p>
        <?php
    }
    
    public static function cache_max_age_for_authenticated_users_field() {
        ?>
        <input type="text" class="small-text" value="<?php echo self::$options->cache_max_age_for_authenticated_users; ?>" id="cache-max-age-for-authenticated-users" name="<?php printf('%s[cache_max_age_for_authenticated_users]', self::option_name); ?>">
        <p class="description"><?php _e('Ablaufzeit des Caches in Sekunden.', self::textdomain); ?></p>
        <?php
    }
    
    public static function get_supported_post_types_singular() {
        $supported_builtin_types = array('post', 'page', 'attachment');
        $public_custom_types = get_post_types(array('public' => true, '_builtin' => false));

        $supported_types = array_merge($supported_builtin_types, $public_custom_types);
        $supported_types = apply_filters( 'http_response_fields_supported_post_types_singular', $supported_types );
        
        return $supported_types;
    }

    public static function get_supported_post_types_archive() {
        $supported_builtin_types = array('post');
        $public_custom_types = get_post_types(array('public' => true, '_builtin' => false));

        $supported_types = array_merge($supported_builtin_types, $public_custom_types);
        $supported_types = apply_filters( 'http_response_fields_supported_post_types_archive', $supported_types );
        
        return $supported_types;
    }

    public static function send_headers($headers_arr) {
        foreach ($headers_arr as $header_data) {
            $header_data = trim($header_data);
            if (!empty($header_data)) {
                header($header_data);
            }
        }
    }

    public static function generate_etag_header($post, $mtime) {
        global $wp;

        if (self::$options->add_etag_header) {
            $to_hash = array($mtime, $post->post_date_gmt, $post->guid, $post->ID, serialize($wp->query_vars));
            $header_etag_value = sha1(serialize($to_hash));

            if (self::$options->generate_weak_etag) {
                return sprintf('ETag: W/"%s"', $header_etag_value);
            } else {
                return sprintf('ETag: "%s"', $header_etag_value);
            }
        }
    }

    public static function generate_last_modified_header($post, $mtime) {
        if (self::$options->add_last_modified_header) {
            $header_last_modified_value = str_replace('+0000', 'GMT', gmdate('r', $mtime));
            return 'Last-Modified: ' . $header_last_modified_value;
        }
    }

    public static function generate_expires_header($post, $mtime) {
        if (self::$options->add_expires_header) {
            $header_expires_value = str_replace('+0000', 'GMT', gmdate('r', time() + self::$options->cache_max_age));
            return 'Expires: ' . $header_expires_value;
        }
    }

    public static function generate_cache_control_header($post, $mtime) {
        if (self::$options->add_cache_control_header) {
            if (intval(self::$options->cache_max_age) > 0) {
                $cache_control_template = 'public, max-age=%s';
                $header_cache_control_value = sprintf($cache_control_template, self::$options->cache_max_age);
                return 'Cache-Control: ' . $header_cache_control_value;
            } else {
                return 'Cache-Control: no-cache, must-revalidate, max-age=0';
            }
        }
    }

    public function generate_pragma_header($post, $mtime) {
        if (self::$options->add_cache_control_header) {
            if (intval(self::$options->cache_max_age) > 0) {
                return 'Pragma: cache';
            } else {
                return 'Pragma: no-cache';
            }
        }
    }

    public static function batch_generate_headers($post, $mtime) {
        $headers_arr = array();

        $headers_arr[] = self::generate_etag_header($post, $mtime);
        $headers_arr[] = self::generate_last_modified_header($post, $mtime);
        $headers_arr[] = self::generate_expires_header($post, $mtime);
        $headers_arr[] = self::generate_cache_control_header($post, $mtime);
        $headers_arr[] = self::generate_pragma_header($post, $mtime);

        self::send_headers($headers_arr);
    }

    public static function set_headers_for_object() {
        $post = get_queried_object();
        
        if (!is_object($post) || !isset($post->post_type) || !in_array(get_post_type($post), self::get_supported_post_types_singular())) {
            return;
        }

        if (post_password_required()) {
            return;
        }

        $post_mtime = $post->post_modified_gmt;
        $post_mtime_unix = strtotime($post_mtime);

        $mtime = $post_mtime_unix;

        if (intval($post->comment_count) > 0) {
            $comments = get_comments(array(
                'status' => 'approve',
                'orderby' => 'comment_date_gmt',
                'number' => '1',
                'post_id' => $post->ID));

            if (!empty($comments)) {
                $comment = $comments[0];
                $comment_mtime = $comment->comment_date_gmt;
                $comment_mtime_unix = strtotime($comment_mtime);

                if ($comment_mtime_unix > $post_mtime_unix) {
                    $mtime = $comment_mtime_unix;
                }
            }
        }

        self::batch_generate_headers($post, $mtime);
    }

    public static function set_headers_for_archive() {
        global $posts;
        
        $post = $posts[0];

        if (!is_object($post) || !isset($post->post_type) || !in_array(get_post_type($post), self::get_supported_post_types_archive())) {
            return;
        }

        $post_mtime = $post->post_modified_gmt;
        $mtime = strtotime($post_mtime);

        self::batch_generate_headers($post, $mtime);
    }

    public static function set_headers_for_feed() {
        $headers_arr = array();

        $headers_arr[] = self::generate_expires_header(null, null);
        $headers_arr[] = self::generate_cache_control_header(null, null);
        $headers_arr[] = self::generate_pragma_header(null, null);

        self::send_headers($headers_arr);
    }

    public static function headers($buffer) {
        if (is_user_logged_in()) {
            self::$options->cache_max_age = self::$options->cache_max_age_for_authenticated_users;
        }

        if (is_feed()) {
            self::set_headers_for_feed();
        } elseif (is_singular()) {
            self::set_headers_for_object();
        } elseif (is_archive() || is_search() || is_home()) {
            if (is_search()) {
                self::$options->cache_max_age = self::$options->cache_max_age_for_search_results;
            }
            self::set_headers_for_archive();
        }

        return $buffer;
    }
    
    public static function ob_start() {
        ob_start(array(__CLASS__, 'headers'));
    }

    public static function ob_end_flush() {
        ob_end_flush();
    }

}
