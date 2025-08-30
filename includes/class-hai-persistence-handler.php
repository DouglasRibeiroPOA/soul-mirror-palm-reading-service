<?php
defined('ABSPATH') || exit;

class HAI_Persistence_Handler
{
    /**
     * Insert the user’s raw answers into wp_holistic_palm_reading.
     */
    public static function save_response_data($data)
    {
        global $wpdb;

        // Fix: empty() only takes one argument
        if (empty($data['uuid']) || empty($data['answers'])) {
            return 'Invalid submission';
        }

        $table = $wpdb->prefix . 'holistic_palm_reading';

        $uuid       = sanitize_text_field($data['uuid']);
        $module     = sanitize_text_field($data['module'] ?? 'palm-reading');
        $name       = sanitize_text_field($data['name']   ?? '');
        $email      = sanitize_email($data['email']  ?? '');
        $gender     = sanitize_text_field($data['gender'] ?? '');
        $account_id = isset($data['account_id']) ? sanitize_text_field($data['account_id']) : null;
        $profile_id = isset($data['profile_id']) ? sanitize_text_field($data['profile_id']) : null;
        $answers    = wp_json_encode($data['answers'], JSON_UNESCAPED_UNICODE);

        $inserted = $wpdb->insert(
            $table,
            [
                'uuid'         => $uuid,
                'module'       => $module,
                'name'         => $name,
                'email'        => $email,
                'gender'       => $gender,
                'account_id'   => $account_id,
                'profile_id'   => $profile_id,
                'answers_json' => $answers,
                'submitted_at' => current_time('mysql'),
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s'
            ]
        );

        return $inserted !== false ? true : 'Database insert failed';
    }

    /**
     * Update the same table with HTML + context ID.
     */
    public static function save_html_and_context($uuid, $html, $context_id = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'holistic_palm_reading';

        $updated = $wpdb->update(
            $table,
            [
                'openai_html'       => wp_kses_post($html),                 // ← keep HTML
                'openai_context_id' => sanitize_text_field($context_id),
            ],
            [
                'uuid' => sanitize_text_field($uuid)
            ],
            [
                '%s',
                '%s'
            ],
            [
                '%s'
            ]
        );

        return $updated !== false;
    }


    public static function find_user_by_email(string $email)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'holistic_palm_reading';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE email = %s LIMIT 1",
                $email
            )
        );
    }

    /**
     * Return TRUE if this email already has any completed report
     * (i.e., openai_html is non-empty) for the given module.
     */
    public static function has_completed_report_for_email(string $email, string $module = 'palm-reading'): bool
    {
        global $wpdb;

        // Normalize for comparison
        $email_norm = strtolower(trim(sanitize_email($email)));
        if ($email_norm === '') return false;

        $table = $wpdb->prefix . 'holistic_palm_reading';

        // Any row with non-empty openai_html counts as "completed"
        $sql = "
        SELECT 1
        FROM {$table}
        WHERE module = %s
          AND LOWER(email) = LOWER(%s)
          AND openai_html IS NOT NULL
          AND openai_html <> ''
        LIMIT 1
    ";

        $exists = $wpdb->get_var($wpdb->prepare($sql, $module, $email_norm));
        return (bool) $exists;
    }

    /**
     * Convenience helper matching your “free intro” rule:
     * - New email (no completed reports) => eligible for 1 free intro.
     * - Otherwise => not eligible.
     */
    public static function is_intro_eligible_for_email(string $email, string $module = 'palm-reading'): bool
    {
        return ! self::has_completed_report_for_email($email, $module);
    }
}
