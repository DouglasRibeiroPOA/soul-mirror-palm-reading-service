<?php
defined('ABSPATH') || exit;

// Load module from shortcode attribute
$module = isset($atts['module']) ? sanitize_text_field($atts['module']) : 'palm-reading';
$survey_json_url = plugin_dir_url(__DIR__) . "modules/{$module}/survey.json";
?>

<div id="holistic-experience"
     data-module="<?php echo esc_attr($module); ?>"
     data-survey-url="<?php echo esc_url($survey_json_url); ?>"
     style="max-width: 800px; margin: 0 auto;">

    <!-- Loading Spinner -->
    <div id="spinner" style="display:none; text-align: center; margin-top: 40px;">
        <div class="loading-circle"></div>
        <p>🔮 Connecting to the oracle...</p>
        <img src="https://i.gifer.com/ZZ5H.gif" alt="Loading..." width="80" />
    </div>

    <!-- SurveyJS container -->
    <div id="surveyContainer" style="margin-top: 30px;"></div>

    <!-- Thank You Page (default content, will be replaced by OpenAI HTML) -->
    <div id="thankYouMessage" style="display:none; text-align: center; margin-top: 40px;">
        <h3>Thank you for sharing 🧘‍♀️</h3>
        <p>Your personalized results will be available soon.</p>
    </div>
</div>