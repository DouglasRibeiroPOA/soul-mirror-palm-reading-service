<?php
/*
    Plugin Name: Holistic AI Experiences
    Description: Modular plugin to deliver AI-powered holistic experiences like palm reading and aura insights.
    Version: 2.0
    Author: Simone Moreira
    */

/**
 * Enqueue SurveyJS and plugin assets
 */
/**
 * Enqueue SurveyJS, plugin assets, and token-capture script only when
 * our [holistic_questionnaire] shortcode is present.
 */
defined( 'ABSPATH' ) || exit;

function hai_enqueue_scripts() {
    // only on a single post/page where our shortcode appears
    if ( ! is_singular() ) {
        return;
    }
    global $post;
    if ( empty( $post->post_content ) || ! has_shortcode( $post->post_content, 'holistic_questionnaire' ) ) {
        return;
    }

    $plugin_url = plugin_dir_url( __FILE__ );
    $plugin_dir = plugin_dir_path( __FILE__ );

    // SurveyJS core
    wp_enqueue_style(  'hai-survey-default',  $plugin_url . 'imports/default.css', [], filemtime( $plugin_dir . 'imports/default.css' ) );
    wp_enqueue_style(  'hai-survey-theme',    $plugin_url . 'imports/index.css',   [], filemtime( $plugin_dir . 'imports/index.css' ) );
    wp_enqueue_script( 'hai-survey-core',     $plugin_url . 'imports/survey.core.min.js',  [], filemtime( $plugin_dir . 'imports/survey.core.min.js' ),  true );
    wp_enqueue_script( 'hai-survey-i18n',     $plugin_url . 'imports/survey.i18n.min.js', ['hai-survey-core'], filemtime( $plugin_dir . 'imports/survey.i18n.min.js' ),  true );
    wp_enqueue_script( 'hai-survey-ui',       $plugin_url . 'imports/survey-js-ui.js',    ['hai-survey-core'], filemtime( $plugin_dir . 'imports/survey-js-ui.js' ),    true );
    wp_enqueue_script( 'hai-survey-theme-js', $plugin_url . 'imports/theme.index.js',    ['hai-survey-core'], filemtime( $plugin_dir . 'imports/theme.index.js' ),    true );

    // Your main plugin styles & script
    wp_enqueue_style(  'hai-style',           $plugin_url . 'assets/css/style.css',      [], filemtime( $plugin_dir . 'assets/css/style.css' ) );
    wp_enqueue_script( 'hai-script',          $plugin_url . 'assets/js/script.js',     ['hai-survey-core'], filemtime( $plugin_dir . 'assets/js/script.js' ), true );

    // Token capture
    wp_enqueue_script( 'sm-token-capture',    $plugin_url . 'assets/js/sm-token-capture.js', [], filemtime( $plugin_dir . 'assets/js/sm-token-capture.js' ), true );

    // expose AJAX URL if your script needs it
    wp_localize_script( 'hai-script', 'HaiSurvey', [
      'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    ] );
}
add_action( 'wp_enqueue_scripts', 'hai_enqueue_scripts' );




/**
 * Shortcode to render the experience
 * Usage: [holistic_questionnaire module="palm-reading"]
 */
function hai_render_questionnaire_shortcode($atts)
{
    $atts = shortcode_atts(['module' => 'palm-reading'], $atts);

    ob_start();
    hai_load_template('templates/holistic-questionnaire.php', ['module' => $atts['module']]);
    return ob_get_clean();
}
add_shortcode('holistic_questionnaire', 'hai_render_questionnaire_shortcode');


/**
 * Create DB table on plugin activation
 */
function holistic_palm_reading()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'holistic_palm_reading';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        uuid varchar(64) NOT NULL,
        module varchar(100) NOT NULL,
        name text,
        email text,
        gender varchar(50),
        answers_json longtext,
        openai_html longtext,
        account_id varchar(64),
        profile_id varchar(64),
        openai_context_id varchar(100) DEFAULT NULL,
        submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";


    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'holistic_palm_reading');


/**
 * Load Settings Page for OpenAI API
 */
add_action('admin_menu', 'hai_add_settings_page');
function hai_add_settings_page()
{
    add_options_page(
        'Holistic AI Settings',
        'Holistic AI',
        'manage_options',
        'holistic-ai-settings',
        'hai_render_settings_page'
    );
}

add_action('admin_init', 'hai_register_settings');
function hai_register_settings()
{
    register_setting('hai_settings_group', 'openai_api_key');
    register_setting('hai_settings_group', 'hai_mailerlite_api_key');
    register_setting('hai_settings_group', 'hai_mailerlite_group_id');
}

function hai_render_settings_page()
{
?>
    <div class="wrap">
        <h1>Holistic AI Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('hai_settings_group'); ?>
            <?php do_settings_sections('hai_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">OpenAI API Key</th>
                    <td>
                        <input type="text" name="openai_api_key" value="<?php echo esc_attr(get_option('openai_api_key')); ?>" size="50" />
                        <p class="description">Enter your OpenAI API key. Used to generate mystical reports.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">MailerLite API Key</th>
                    <td>
                        <input type="text" name="hai_mailerlite_api_key" value="<?php echo esc_attr(get_option('hai_mailerlite_api_key')); ?>" size="50" />
                        <p class="description">Enter your MailerLite API Key to sync user data.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">MailerLite Group ID</th>
                    <td>
                        <input type="text" name="hai_mailerlite_group_id" value="<?php echo esc_attr(get_option('hai_mailerlite_group_id')); ?>" size="50" />
                        <p class="description">The group ID to which new users should be added.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php
}



/**
 * Template loader for shortcode
 */
if (!function_exists('hai_load_template')) {
    function hai_load_template($file, $vars = [])
    {
        $path = plugin_dir_path(__FILE__) . $file;
        if (!file_exists($path)) {
            error_log("Template not found: $path");
            return;
        }

        extract($vars);
        include $path;
    }
}

/**
 * Load supporting classes
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-hai-openai.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-hai-questionnaire-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-hai-persistence-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-hai-mailerlite-handler.php';


// Initialize AJAX and handlers
HAI_Questionnaire_Handler::init();
