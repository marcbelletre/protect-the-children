<?php
/**
 * Plugin Name: Protect the Children!
 * Description: Easily password protect the child pages/posts of a post that is password protected.
 * Version: 1.5.0
 * Author: Miller Media (Matt Miller)
 * Author URI: www.millermedia.io
 */

namespace ProtectTheChildren;

add_action('plugins_loaded', function () {
    if (file_exists($composer = __DIR__.'/vendor/autoload.php')) {
        require_once $composer;
    }

    if (! defined('PROTECT_THE_CHILDREN_PLUGIN_VERSION')) {
        define('PROTECT_THE_CHILDREN_PLUGIN_VERSION', '1.5.0');
    }

    if (version_compare(PHP_VERSION, '5.6', '<')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>'.__('Protect the Children requires PHP 5.6 and greater to function properly. Please upgrade PHP or deactivate Protect the Children.', 'protect-the-children').'</p></div>';
        });

        return;
    }

    define('PTC_PLUGIN_PATH', plugin_dir_path(__FILE__));
    define('PTC_PLUGIN_URL', plugin_dir_url(__FILE__));

    require_once __DIR__.'/deprecated.php';

    new ProtectTheChildren(
        plugin_dir_path(__FILE__),
        plugin_dir_url(__FILE__)
    );
});

/**
 * On front-end page load, check the post's parent ID
 *
 * @return bool
 */
add_action('template_redirect', function () {
    $post_id = get_the_ID();
    $parent_ids = get_post_ancestors($post_id);

    if (! $parent_ids || empty($parent_ids)) {
        return;
    }

    $parent_post = Helpers::isEnabled($parent_ids);

    if (! $parent_post) {
        return;
    }

    $parent_post_object = is_int($parent_post) ? get_post($parent_post) : $parent_post;
    $parent_password = $parent_post_object->post_password;

    // If password cookie does not exist, return password check
    if (! array_key_exists('wp-postpass_'.COOKIEHASH, $_COOKIE)) {
        add_filter('post_password_required', function () {
            return true;
        });

        return;
    }

    // Check the cookie (hashed password)
    require_once ABSPATH.WPINC.'/class-phpass.php';

    $hasher = new \PasswordHash(8, true);
    $hash = wp_unslash($_COOKIE['wp-postpass_'.COOKIEHASH]);
    $required = ! $hasher->CheckPassword($parent_password, $hash);

    // If password has already been entered on the parent post, continue to page
    if (! $required) {
        return;
    }

    add_filter('post_password_required', function () {
        return true;
    });
});
