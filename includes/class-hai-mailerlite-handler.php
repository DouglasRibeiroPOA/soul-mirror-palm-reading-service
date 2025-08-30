<?php

class HAI_MailerLite_Handler
{
    private $api_key;
    private $group_id;

    public function __construct()
    {
        $this->api_key = get_option('hai_mailerlite_api_key');
        $this->group_id = get_option('hai_mailerlite_group_id');
    }

    public static function sync_user($name, $email, $uuid)
    {
        $api_key  = get_option('hai_mailerlite_api_key');
        $group_id = get_option('hai_mailerlite_group_id');

        if (!$api_key || !$group_id) {
            error_log("[MailerLite] ❌ Missing API key or group ID");
            return ['success' => false, 'step' => 'config_check', 'message' => 'Missing MailerLite configuration'];
        }

        error_log("🔄 [MailerLite] Starting sync for: $email");

        $subscriber = self::get_subscriber($email);
        error_log("📨 [MailerLite] Subscriber fetched: " . print_r($subscriber, true));

        // ✅ STEP 0: Subscriber doesn't exist → Create new
        if (isset($subscriber['status_code']) && $subscriber['status_code'] === 404) {
            error_log("🆕 [MailerLite] Subscriber not found (404). Creating...");
            $result = self::create_subscriber($email, $name, $uuid, $group_id);
            $result['step'] = 'created_new';
            return $result;
        }

        // ✅ STEP 1: Analyze existing subscriber
        $status     = $subscriber['data']['status'] ?? 'unknown';
        $groups     = array_column($subscriber['data']['groups'] ?? [], 'id');
        $company    = $subscriber['data']['fields']['company'] ?? $uuid;
        $is_active  = ($status === 'active');
        $in_group   = in_array($group_id, $groups);

        error_log("📊 [MailerLite] Status: $status | In Group? " . ($in_group ? 'Yes' : 'No') . " | Groups: " . implode(',', $groups));

        // ✅ STEP 2: Reactivation Flow (3-step process)
        if (!$is_active) {
            error_log("🟡 [MailerLite] Reactivating inactive subscriber");

            // Step 2.1: Remove from all groups
            $step1 = self::update_subscriber($email, 'unsubscribed', [], $company);
            error_log("↩️ [MailerLite] Step 1: Unsubscribed from all groups: " . print_r($step1, true));

            usleep(500000); // wait 0.5s

            // Step 2.2: Reactivate the subscriber (no groups yet)
            $step2 = self::update_subscriber($email, 'active', [], $company);
            error_log("🟢 [MailerLite] Step 2: Reactivated subscriber: " . print_r($step2, true));

            usleep(500000); // wait another 0.5s
        }

        // ✅ STEP 3: Final group assignment
        error_log("🧹 [MailerLite] Assigning subscriber to the current group only");
        $response = self::update_subscriber($email, 'active', [$group_id], $company);
        error_log("📦 [MailerLite] Final group assign result: " . print_r($response, true));

        // ✅ Optional Final Check
        $final_subscriber = self::get_subscriber($email);
        error_log("🔍 [MailerLite] Final status check after sync: " . print_r($final_subscriber, true));

        if ($response['success']) {
            return [
                'success' => true,
                'step'    => 'final_update',
                'status'  => 'synced',
                'company' => $company,
            ];
        } else {
            return [
                'success' => false,
                'step'    => 'final_update_failed',
                'message' => $response['message'] ?? 'Unknown error',
                'errors'  => $response['errors'] ?? [],
            ];
        }
    }



    public static function get_subscriber($email)
    {
        $endpoint = 'subscribers/' . urlencode($email);
        $response = self::make_api_request('GET', $endpoint);

        error_log("📨 [MailerLite] Get subscriber response: " . print_r($response, true));

        // If we already added status_code in make_api_request, check here
        if (isset($response['status_code']) && $response['status_code'] === 404) {
            return [
                'success' => false,
                'status_code' => 404,
                'message' => 'Subscriber not found.'
            ];
        }

        // Still keep check for unsubscribed-and-blocked case
        if (
            isset($response['errors']['email'][0]) &&
            $response['errors']['email'][0] === 'This subscriber is unsubscribed and cannot be imported'
        ) {
            return [
                'success' => false,
                'status_code' => $response['status_code'] ?? 422,
                'message' => 'This subscriber is unsubscribed and cannot be imported',
                'errors' => $response['errors'],
                'subscriber' => $response['subscriber'] ?? null,
            ];
        }

        // If successful (status 200), just return full response
        return $response;
    }


    public static function create_subscriber($email, $name, $uuid, $group_id)
    {
        $endpoint = 'subscribers';

        $payload = [
            'email' => $email,
            'fields' => [
                'name' => $name,
                'company' => $uuid,
            ],
            'groups' => [$group_id],
        ];

        error_log("📦 [MailerLite] Creating new subscriber with payload:");
        error_log(print_r($payload, true));

        $response = self::make_api_request('POST', $endpoint, $payload);

        error_log("📬 [MailerLite] Create subscriber response: " . print_r($response, true));

        if (isset($response['success']) && $response['success'] === false) {
            // Subscriber está desinscrito permanentemente
            if (
                isset($response['errors']['email'][0]) &&
                $response['errors']['email'][0] === 'This subscriber is unsubscribed and cannot be imported'
            ) {
                return [
                    'success' => false,
                    'message' => $response['message'] ?? 'Cannot re-import unsubscribed subscriber.',
                    'errors' => $response['errors'],
                    'subscriber' => $response['subscriber']
                ];
            }

            return ['success' => false, 'message' => $response['message'] ?? 'Failed to create subscriber.'];
        }

        return ['success' => true, 'data' => $response['data'] ?? []];
    }


    public static function update_subscriber($email, $status, $groups = [], $company = '')
    {
        $endpoint = 'subscribers/' . urlencode($email);

        $data = [
            'status' => $status,
            'groups' => $groups,
        ];

        if (!empty($company)) {
            $data['fields'] = [
                'company' => $company,
            ];
        }

        error_log("🛠️ [MailerLite] Updating subscriber ($email) to status '$status' with groups:");
        error_log(print_r($groups, true));

        $response = self::make_api_request('PUT', $endpoint, $data);

        error_log("📬 [MailerLite] Update subscriber response: " . print_r($response, true));

        if (isset($response['data'])) {
            return ['success' => true, 'data' => $response['data']];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Failed to update subscriber.',
            'errors' => $response['errors'] ?? [],
        ];
    }


    private static function make_api_request($method, $endpoint, $body = null)
    {
        $api_key = get_option('hai_mailerlite_api_key');
        $url = 'https://connect.mailerlite.com/api/' . $endpoint;

        $args = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'timeout' => 20,
            'method'  => $method,
        ];

        if ($body !== null) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log('[MailerLite] ⚠️ WP Error: ' . $response->get_error_message());
            return [
                'success' => false,
                'status_code' => 500,
                'message' => $response->get_error_message()
            ];
        }

        $status_code   = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $parsed_body   = json_decode($response_body, true);

        // Always include the status code in the returned array
        if (is_array($parsed_body)) {
            $parsed_body['status_code'] = $status_code;
        }

        if ($status_code >= 200 && $status_code < 300) {
            return $parsed_body;
        }

        error_log("[MailerLite] ❌ Request failed. Code: $status_code");
        error_log("[MailerLite] ❌ Body: $response_body");

        return $parsed_body ?: [
            'success' => false,
            'status_code' => $status_code,
            'message' => 'Unknown error'
        ];
    }
}
