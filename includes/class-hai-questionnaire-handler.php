<?php
defined('ABSPATH') || exit;

class HAI_Questionnaire_Handler
{
    /**
     * Register AJAX endpoints.
     */
    public static function init()
    {
        // MailerLite sync (called from page 1 → page 2 transition)
        add_action('wp_ajax_hai_handle_subscriber',        [self::class, 'handle_subscriber']);
        add_action('wp_ajax_nopriv_hai_handle_subscriber', [self::class, 'handle_subscriber']);


        // Single “generate report” endpoint (called on survey completion)
        add_action('wp_ajax_hai_generate_report',        [self::class, 'handle_generate_report']);
        add_action('wp_ajax_nopriv_hai_generate_report', [self::class, 'handle_generate_report']);
    }

    /**
     * AJAX handler: lookup or create subscriber, then return one of:
     *  - proceed       (new user → free trial)
     *  - show_packages (used trial only → redirect to offerings)
     *  - login         (has account_id → redirect to login with account_id)
     */
    public static function handle_subscriber()
    {
        $body  = json_decode(file_get_contents('php://input'), true);
        $name  = sanitize_text_field($body['name']  ?? '');
        $email = sanitize_email($body['email'] ?? '');
        $uuid  = sanitize_text_field($body['uuid']  ?? '');

        if (empty($name) || empty($email)) {
            wp_send_json_error(
                ['message' => 'Name and email are required.', 'status' => 'validation'],
                400
            );
        }

        // Look up any prior submission
        $user = HAI_Persistence_Handler::find_user_by_email($email);

        // Helper closures to set/clear the UX cookies
        $set_block = function (string $em) {
            $norm = strtolower(trim($em));
            // Persist until backend says otherwise. SameSite=Lax, Secure on HTTPS.
            setcookie('hai_force_intro', '1',  time() + 31536000, '/', '', is_ssl(), true);
            setcookie('hai_block_email', rawurlencode($norm), time() + 31536000, '/', '', is_ssl(), true);
        };
        $clear_block = function () {
            // Expire both immediately
            setcookie('hai_force_intro', '', time() - 3600, '/', '', is_ssl(), true);
            setcookie('hai_block_email', '', time() - 3600, '/', '', is_ssl(), true);
        };

        // 1) No record → free trial path: create/sync subscriber, then proceed
        if (! $user) {
            $sync = HAI_MailerLite_Handler::sync_user($name, $email, $uuid);
            $clear_block(); // allow this session to flow
            wp_send_json_success([
                'status' => 'proceed',
                'sync'   => $sync,
            ]);
        }

        // 2) Record exists but no account/profile → show offerings
        if (empty($user->account_id) && empty($user->profile_id)) {
            $set_block($email);
            wp_send_json_success([
                'status'       => 'show_packages',
                'redirect_url' => home_url('/offerings/?show_access_denied=true'),
            ]);
        }

        // 3) Already has account → send to login page with account_id
        $login_url = add_query_arg('account_id', urlencode($user->account_id), home_url('/login/'));

        $set_block($email);
        wp_send_json_success([
            'status'       => 'login',
            'redirect_url' => $login_url,
        ]);
    }




    public static function handle_generate_report()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        // 1) Validate required fields + module
        if (
            empty($data['uuid'])    ||
            empty($data['name'])    ||
            empty($data['email'])   ||
            empty($data['answers']) ||
            empty($data['module'])
        ) {
            wp_send_json_error(['error' => 'Missing required fields.'], 400);
            return;
        }

        // Normalize inputs
        $uuid            = sanitize_text_field($data['uuid']);
        $name            = sanitize_text_field($data['name']);
        $email_raw       = sanitize_email($data['email']);
        $email_norm      = strtolower(trim($email_raw));
        $requestedModule = sanitize_text_field($data['module']);                 // e.g. "palm-reading"
        $requestedTopic  = sanitize_text_field($data['topic'] ?? 'intro');       // client hint; server will enforce
        $token           = $_COOKIE['sm_jwt'] ?? null;
        $hasToken        = !empty($token);

        // 2) Check if this email already has any completed report (openai_html not empty)
        //    for the same module (so free intro is allowed only once per email+module)
        $already_had_intro = false;
        try {
            if (!empty($email_norm)) {
                $already_had_intro = (bool) HAI_Persistence_Handler::has_completed_report_for_email(
                    $email_norm,
                    $requestedModule ?: 'palm-reading'
                );
            }
        } catch (\Throwable $e) {
            // Fail-safe: do not grant free intro if we cannot verify
            error_log('[HAI] has_completed_report_for_email error: ' . $e->getMessage());
            $already_had_intro = true;
        }

        // 3) Decide topic + enforce auth/credits
        // Guests can ONLY run 'intro' ONCE per email; otherwise they must log in to use credits.
        // Logged-in users must spend a credit for ANY topic (even if 'intro' is requested).
        $effectiveTopic = 'intro';

        if (!$hasToken) {
            // --- Guest path ---
            if ($already_had_intro) {
                // Existing email trying to run again as guest → must log in to use credits
                wp_send_json_success([
                    'redirect' => home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI'])),
                ]);
                return;
            }
            // New email → allow one free intro (no credit charge)
            $effectiveTopic = 'intro';
        } else {
            // --- Logged-in path ---
            $sm_user = SM_Account::get_sm_user_from_jwt($token);
            if (!$sm_user) {
                // Invalid/expired token → login
                wp_send_json_success([
                    'redirect' => home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI'])),
                ]);
                return;
            }

            // Normalize/whitelist topic; treat 'intro' as 'general' for paid runs
            $allowed   = ['general', 'love', 'wealth', 'energy', 'intro'];
            $candidate = in_array($requestedTopic, $allowed, true) ? $requestedTopic : 'general';
            $effectiveTopic = ($candidate === 'intro') ? 'general' : $candidate;

            // Deduct 1 credit from account service
            $creditResult = SM_Credit_Controller::rest_use_credit_for_user($sm_user->id, $effectiveTopic, 1);
            if (empty($creditResult['success'])) {
                // No credits → send to Offerings
                wp_send_json_success([
                    'error'        => 'Not enough credits.',
                    'purchase_url' => home_url('/offerings/'),
                ]);
                return;
            }
        }

        // 4) Persist the raw answers (unchanged)
        self::save_response($data);

        // 5) Generate the mystical report
        $openai = HAI_OpenAI::generate_mystical_report($data, $effectiveTopic);
        if (empty($openai['success'])) {
            wp_send_json_error([
                'error' => $openai['error'] ?? 'OpenAI error'
            ], 500);
            return;
        }

        // 6) Persist HTML & context ID (unchanged)
        self::save_html_and_context(
            $uuid,
            $openai['html'],
            $openai['context_id'] ?? null
        );

        // 7) (Removed) No need to "mark" the intro; saving HTML is the mark

        // 8) Return the HTML to front-end
        wp_send_json_success([
            'html'       => $openai['html'],
            'context_id' => $openai['context_id'] ?? null,
        ]);
    }




    /**
     * Internal: save the user's answers to the DB.
     */
    private static function save_response(array $data): void
    {
        // Assumes HAI_Persistence_Handler::save_response_data() returns true on success.
        $ok = HAI_Persistence_Handler::save_response_data($data);
        if ($ok !== true) {
            error_log("HAI_Questionnaire_Handler::save_response failed: " . print_r($ok, true));
        }
    }

    /**
     * Internal: save the generated HTML + OpenAI context ID to the DB.
     */
    private static function save_html_and_context(string $uuid, string $html, ?string $context_id): void
    {
        $ok = HAI_Persistence_Handler::save_html_and_context($uuid, $html, $context_id);
        if ($ok !== true) {
            error_log("HAI_Questionnaire_Handler::save_html_and_context failed for UUID {$uuid}");
        }
    }
}
