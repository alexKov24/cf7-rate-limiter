<?php

/**
 * Plugin Name: CF7 Rate Limiter
 * Plugin URI: https://github.com/alexKov24/cf7-rate-limiter/tree/main
 * Description: Adds rate limiting functionality to Contact Form 7 submissions.
 * Version: 1.0
 * Author: Alex Kovalev
 * Author URI: https://github.com/alexKov24/
 * License: GPL2
 */
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class CF7_Rate_Limiter
{
    private $options;

    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init()
    {
        $this->options = get_option('cf7_rate_limiter_options');
        if ($this->is_cf7_active()) {
            add_filter('wpcf7_validate', array($this, 'limit_cf7_submission_rate'), 10, 2);
            add_action('wp_footer', array($this, 'add_rate_limit_script'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('wpcf7_admin_menu', array($this, 'add_admin_menu'));
        } else {
            add_action('admin_notices', array($this, 'cf7_not_active_notice'));
        }
    }

    private function is_cf7_active()
    {
        return is_plugin_active('contact-form-7/wp-contact-form-7.php');
    }

    public function limit_cf7_submission_rate($result, $tags)
    {
        // Skip rate limiting for admins
        if (current_user_can('manage_options')) return $result;

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return $result;
        }

        $form_id = $submission->get_contact_form()->id();
        $user_ip = $submission->get_meta('remote_ip');
        $time_limit = isset($this->options['time_limit']) ? intval($this->options['time_limit']) : 3600;
        $max_submissions = isset($this->options['max_submissions']) ? intval($this->options['max_submissions']) : 3;
        $transient_key = 'cf7_rate_limit_' . $form_id . '_' . md5($user_ip);
        $submission_count = get_transient($transient_key);

        if ($submission_count === false) {
            set_transient($transient_key, 1, $time_limit);
        } elseif ($submission_count < $max_submissions) {
            set_transient($transient_key, $submission_count + 1, $time_limit);
        } else {
            $result->invalidate('', "You've exceeded the maximum number of submissions. Please try again later.");
        }

        return $result;
    }

    public function add_rate_limit_script()
    {
?>
        <script type="text/javascript">
            document.addEventListener('wpcf7invalid', function(event) {
                var responseText = event.detail.apiResponse.message;
                if (responseText.includes("You've exceeded the maximum number of submissions")) {
                    alert(responseText);
                }
            }, false);
        </script>
    <?php
    }

    public function cf7_not_active_notice()
    {
        echo '<div class="error"><p>Contact Form 7 is not active. The CF7 Rate Limiter plugin requires Contact Form 7 to function.</p></div>';
    }

    public function register_settings()
    {
        register_setting('cf7_rate_limiter_options', 'cf7_rate_limiter_options', array($this, 'sanitize_options'));
    }

    public function sanitize_options($input)
    {
        $sanitized_input = array();
        $sanitized_input['max_submissions'] = isset($input['max_submissions']) ? intval($input['max_submissions']) : 3;
        $sanitized_input['time_limit'] = isset($input['time_limit']) ? intval($input['time_limit']) : 3600;
        return $sanitized_input;
    }

    public function add_admin_menu()
    {
        $admin_menu_hook = add_submenu_page(
            'wpcf7',
            'Rate Limit Settings',
            'Rate Limit',
            'manage_options',
            'cf7-rate-limit',
            array($this, 'admin_page_content')
        );
    }

    public function admin_page_content()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
    ?>
        <div class="wrap">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('cf7_rate_limiter_options');
                do_settings_sections('cf7_rate_limiter_options');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Maximum Submissions</th>
                        <td><input type="number" name="cf7_rate_limiter_options[max_submissions]" value="<?php echo esc_attr(isset($this->options['max_submissions']) ? $this->options['max_submissions'] : 3); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Time Limit (in seconds)</th>
                        <td><input type="number" name="cf7_rate_limiter_options[time_limit]" value="<?php echo esc_attr(isset($this->options['time_limit']) ? $this->options['time_limit'] : 3600); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
<?php
    }
}

// Initialize the plugin
new CF7_Rate_Limiter();

// Activation hook
register_activation_hook(__FILE__, 'cf7_rate_limiter_activate');
function cf7_rate_limiter_activate()
{
    // Set default options
    add_option('cf7_rate_limiter_options', array(
        'max_submissions' => 3,
        'time_limit' => 3600
    ));
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'cf7_rate_limiter_deactivate');
function cf7_rate_limiter_deactivate()
{
    // Remove options when plugin is deactivated
    delete_option('cf7_rate_limiter_options');
}
