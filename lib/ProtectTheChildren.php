<?php

namespace ProtectTheChildren;

class ProtectTheChildren
{
    /**
     * The plugin directory path.
     *
     * @var string
     */
    protected $path;

    /**
     * The plugin directory URI.
     *
     * @var string
     */
    protected $uri;

    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public function __construct(string $path, string $uri)
    {
        $this->path = $path;
        $this->uri = $uri;

        register_activation_hook($this->path, [$this, 'update_db_check']);

        if (get_option('PTC_plugin_version', '') != PROTECT_THE_CHILDREN_PLUGIN_VERSION) {
            $password_pages = get_pages(['meta_key' => '_protect_children', 'meta_value' => 'on']);

            foreach ($password_pages as $page) {
                update_post_meta($page->ID, 'protect_children', '1');
                delete_post_meta($page->ID, '_protect_children');
            }
        }

        update_option('PTC_plugin_version', PROTECT_THE_CHILDREN_PLUGIN_VERSION);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('save_post', [$this, 'ptc_save_post_meta'], 10, 3);
        add_action('post_submitbox_misc_actions', [$this, 'add_classic_checkbox']);
        add_action('init', [$this, 'register_post_meta_gutenberg']);
        add_action('admin_init', [$this, 'adjust_visibility']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_filter('is_protected_meta', [$this, 'protect_meta'], 10, 2);
        add_filter('register_meta_args', [$this, 'allow_meta_editing_for_admin'], 10, 4);
    }

    /**
     * Allows users with the 'edit_posts' capability to edit the protected protect_children meta key
     * This is required only when Gutenberg is active.
     *
     * @param $args
     * @param $defaults
     * @param $object_type
     * @param $meta_key
     * @return array
     */
    public function allow_meta_editing_for_admin($args, $defaults, $object_type, $meta_key)
    {
        if ($meta_key === 'protect_children' && is_array($args)) {
            $args['auth_callback'] = function () {
                return current_user_can('edit_posts');
            };
        }

        return $args;
    }

    /**
     * Protect the 'protect_children' meta key from begin edited in custom fields
     *
     * @param   bool        $protected              Whether meta key is protected or not
     * @param   string      $meta_key               The meta key being checked
     * @return  bool
     */
    public function protect_meta($protected, $meta_key)
    {
        if ($meta_key === 'protect_children') {
            return true;
        }

        return $protected;
    }

    /**
     * Enqueue admin scripts and stylesheets
     *
     * @return void
     */
    public function enqueue_scripts()
    {
        wp_enqueue_style('ptc-admin–css', $this->uri.'assets/css/admin.css');

        // load classic editor js if gutenberg is disabled
        if (! function_exists('is_gutenberg_page') || (function_exists('is_gutenberg_page') && ! is_gutenberg_page())) {
            wp_enqueue_script('ptc-admin-js', $this->uri.'assets/js/admin.js');
        }
    }

    /**
     * Handle admin option to password protect child posts
     *
     * @return void
     */
    public function ptc_save_post_meta($post_id, $post, $update)
    {
        if (! Helpers::supportsPTC($post)) {
            return;
        }

        // When gutenberg is active, some themes such as Jupiter use
        // old style meta data which is saved in such a way it calls
        // the save_post hook and runs this method. But on a gutenberg
        // editor our option is not included as post data, so we need
        // to return or the setting will always be saved as off
        if (empty($_POST)) {
            add_action('rest_after_insert_page', [$this, 'update_pages_meta'], 10, 1);

            return;
        }

        if (isset($_GET['meta-box-loader'])) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['protect_children']) && $_POST['protect_children']) {
            $protect_children = '1';
        } else {
            $protect_children = '';
        }

        update_post_meta($post_id, 'protect_children', $protect_children);
    }

    /**
     * Add the option to protect child posts - for classic editor
     *
     * @return void
     */
    public function add_classic_checkbox($post)
    {
        if (! Helpers::supportsPTC($post)) {
            return;
        }

        if (Helpers::isPasswordProtected($post)) {
            $checked = get_post_meta($post->ID, 'protect_children', true) ? 'checked' : '';
            echo '<div id="protect-children-div"><input type="checkbox" '.$checked.' name="protect_children" /><strong>Password Protect</strong> all child posts</div>';
        }
    }

    /**
     * Register post meta field for Gutenberg post updates
     *
     * @return void;
     */
    public function register_post_meta_gutenberg()
    {
        register_post_meta('', 'protect_children', [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'boolean',
        ]);
    }

    /**
     * On admin page load of a child post, change the 'Visibility' for children post if
     * they are protected. There is no hook for that part of the admin section we have
     * to edit the outputted HTML.
     *
     * @param  string $buffer  The outputted HTML of the edit post page
     * @return string $buffer  Original or modified HTML
     */
    public function adjust_visibility($buffer)
    {
        // Abort on ajax requests
        if (wp_doing_ajax()) {
            return;
        }

        global $pagenow;

        // On post list page
        if ('edit.php' === $pagenow) {
            ob_start(function ($buffer) {
                // @todo Not working yet below

                // Find children posts
                if (preg_match_all('/<tr id="post-(\d*?)".*? level-[12345].*?>/', $buffer, $matches)) {
                    if (empty($matches[1])) {
                        return $buffer;
                    }

                    foreach ($matches[1] as $child_post) {
                        $parent_post_ids = get_post_ancestors($child_post);

                        if ($post_id = Helpers::isEnabled($parent_post_ids)) {
                            $preg_pattern = sprintf('/(<\/strong>\n*<div.*?inline_%d">)/i', $child_post);
                            $buffer = preg_replace($preg_pattern, ' — <span class="post-state">Password protected by parent</span>$1', $buffer);
                        }
                    }
                }

                return $buffer;
            });
        }

        // On single post edit page
        if ('post.php' === $pagenow && isset($_GET['post'])) {
            if (! Helpers::supportsPTC(get_post($_GET['post']))) {
                return $buffer;
            }

            ob_start(function ($buffer) {
                $post = get_post($_GET['post']);

                // Check if it is a child post and if any parent/grandparent post has a password set
                $parent_ids = get_post_ancestors($post);

                if ($protected_parent = Helpers::isEnabled($parent_ids)) {
                    // Change the wording to 'Password Protected' if the post is protected
                    $buffer = preg_replace('/(<span id="post-visibility-display">)(\n*.*)(<\/span>)/i', '$1Password protected$3', $buffer);

                    // Remove Edit button post visibility (post needs to be updated from parent post)
                    $buffer = preg_replace('/<a href="#visibility".*?><\/a>/i', '', $buffer);

                    // Add 'Password protect by parent post' notice under visibility section
                    $regex_pattern = '/(<\/div>)(<\!-- \.misc-pub-section -->)(\n*.*)(<div class="misc-pub-section curtime misc-pub-curtime">)/i';
                    $admin_edit_link = sprintf(admin_url('post.php?post=%d&action=edit'), $protected_parent);
                    $update_pattern = sprintf('<br><span class="wp-media-buttons-icon password-protect-admin-notice">Password protected by <a href="%s">parent post</a></span>$1$2$3$4$5', $admin_edit_link);
                    $buffer = preg_replace($regex_pattern, $update_pattern, $buffer);
                }

                return $buffer;
            });
        }
    }

    /**
     * Include Gutenberg specific script to add post editor checkbox in post status area.
     *
     * @return void
     */
    public function enqueue_block_editor_assets()
    {
        global $post;

        if (! Helpers::supportsPTC($post)) {
            return;
        }

        wp_enqueue_script(
            'ptc-myguten-script',
            $this->uri.'build/index.js',
            ['wp-blocks', 'wp-element', 'wp-components']
        );
    }

    /**
     * Handle admin option to password protect child posts (Gutenberg Editor)
     *
     * @return void
     */
    public function update_pages_meta($post)
    {
        if (! Helpers::supportsPTC($post)) {
            return;
        }

        $protect_children = get_post_meta($post->ID, 'protect_children', true);
        update_post_meta($post->ID, 'protect_children', $protect_children);
    }
}
