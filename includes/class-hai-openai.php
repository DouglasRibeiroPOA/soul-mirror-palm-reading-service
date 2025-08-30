<?php

class HAI_OpenAI

{

    public static function generate_mystical_report(array $user_data, string $topic = 'intro'): array
    {
        $api_key = get_option('openai_api_key');
        if (empty($api_key)) {
            return ['success' => false, 'error' => 'Missing API key'];
        }

        // Sanitize inputs
        $name         = sanitize_text_field($user_data['name'] ?? 'Seeker');
        $gender       = sanitize_text_field($user_data['gender'] ?? 'unspecified');
        $answers      = $user_data['answers'] ?? [];
        $image_base64 = $user_data['palm_image'] ?? null;

        // Clean up base64 image prefix
        if ($image_base64 && strpos($image_base64, 'data:image') !== false) {
            $image_base64 = preg_replace(
                '/^(data:image\/[a-z]+;base64,)+/',
                'data:image/jpeg;base64,',
                $image_base64
            );
        }

        // Build the insights bullet list
        $insights = '';
        foreach ($answers as $key => $value) {
            $label     = ucwords(str_replace('_', ' ', $key));
            $insights .= "- {$label}: \"{$value}\"\n";
        }

        // Fetch the full prompt text for this topic
        $prompt_text = self::get_prompt_text($topic, $name, $gender, $insights);

        // Build the OpenAI messages array
        $base_message = [
            [
                'role'    => 'system',
                'content' => 'You are a mystical palm reader… warm, curious, full of wonder.'
            ],
            [
                'role'    => 'user',
                'content' => array_values(array_filter([
                    ['type' => 'text', 'text' => $prompt_text],
                    $image_base64 ? [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url'    => $image_base64,
                            'detail' => 'high',
                        ],
                    ] : null,
                ])),
            ],
        ];

        // Call OpenAI
        $resp       = self::call_openai($api_key, $base_message);
        $html       = $resp['content']    ?? '';
        $context_id = $resp['context_id'] ?? null;

        // Clean fences
        $clean = trim(preg_replace('/```html|```/', '', $html));

        // Fallback retry without image if needed
        if (
            !$clean ||
            stripos($clean, 'can’t help') !== false ||
            stripos($clean, 'sorry') !== false
        ) {
            $fallback = $base_message;
            $fallback[1]['content'] = [['type' => 'text', 'text' => self::get_prompt_text('general', $name, $gender, $insights)]];
            $resp     = self::call_openai($api_key, $fallback);
            $clean    = trim(preg_replace('/```html|```/', '', $resp['content'] ?? ''));
        }

        if (!$clean) {
            return ['success' => false, 'error' => 'No response from OpenAI.'];
        }
        // ✨ ADD THIS BLOCK
        if ($topic === 'intro') {
            $clean .= self::render_intro_cta_html();
        }

        // Append consistent buttons
        $clean .= self::render_report_buttons_html($context_id);

        return [
            'success'    => true,
            'html'       => $clean,
            'context_id' => $context_id,
        ];
    }

    /**
     * Return the full OpenAI prompt for each topic.
     */
    private static function get_prompt_text(string $topic, string $name, string $gender, string $insights): string
    {
        // Base template with placeholders
        $templates = [
            'intro' => <<<EOT
                            You are a mystical palm reader. Using only <h4>, <p>, <ul>, and <li> tags, craft a concise, intriguing report for {name} ({gender}) based on:

                            {insights}

                            <h4>Overview</h4>
                            <p>In 2–3 brief sentences, offer a poetic welcome that sets a calming, mystical tone.</p>

                            <h4>Insights</h4>
                            <ul>
                            <li><strong>Hand Shape</strong>: 2–3 sentences on energy and emotion.</li>
                            <li><strong>Fingers</strong>: 2–3 sentences on creativity and balance.</li>
                            <li><strong>Main Lines</strong>: 4–5 sentences providing a deeper dive into the heart line’s emotional rhythm, the head line’s clarity and focus, and the life line’s vitality—exploring how their intersections reveal pivotal life themes.</li>
                            <li><strong>Mounts & Markings</strong>: 2–3 sentences on unique signs and hidden messages.</li>
                            <li><strong>Hidden Channels</strong>: 2–3 sentences teasing a subtle minor line or symbol that hints at untapped potential, igniting your curiosity to learn more.</li>
                            </ul>

                            <h4>Energy Path</h4>
                            <p>In 2–3 sentences, close with an uplifting message about growth and healing.</p>

                            add this paragraph at the end always please:

                            <p class="intro-message">✨ Want to know more? Unveil hidden fortunes & aura secrets—unlock 5 insights & continue your journey.</p>
                            EOT,
            'general' => <<<EOT
                            You are a mystical palm reader. Using only <h4>, <p>, <ul>, and <li> tags, craft a concise, intriguing general report for {name} ({gender}) based on:

                            {insights}

                            <h4>Overview</h4>
                            <p>In 2–3 brief sentences, offer a poetic welcome that sets a calming, mystical tone and prepares the reader to journey through their hand’s secrets.</p>

                            <h4>Insights</h4>
                            <ul>
                            <li><strong>Hand Shape</strong>: 2–3 sentences on the overall energy and emotional undercurrents revealed by the form of the palm.</li>

                            <li><strong>Fingers</strong>: 2–3 sentences on the balance of creativity and practicality as indicated by finger length and spacing.</li>

                            <li><strong>Main Lines</strong>:
                                <ul>
                                <li><strong>Heart Line</strong>: 2–3 sentences on the emotional rhythm—what the curve, depth, and breaks say about love, empathy, and relationships.</li>
                                <li><strong>Head Line</strong>: 2–3 sentences on thought patterns—how line clarity, length, and forks reveal intellect, focus, and decision-making style.</li>
                                <li><strong>Life Line</strong>: 2–3 sentences on vitality—what the arc, strength, and secondary branches suggest about health, resilience, and major life changes.</li>
                                </ul>
                            </li>

                            <li><strong>Mounts & Markings</strong>: 2–3 sentences on subtle dots, crosses, and mounds that whisper hidden talents or cautionary signs.</li>

                            <li><strong>Hidden Channels</strong>: 2–3 sentences teasing a minor line or rare marking that hints at untapped potential and invites further exploration.</li>
                            </ul>

                            <h4>Energy Path</h4>
                            <p>In 2–3 sentences, close with an uplifting message about growth and healing, and end with a brief invitation: “Get more insights.”</p>
                            EOT,

            'love' => <<<EOT
                            You are a mystical palm reader. Using only <h4>, <p>, <ul>, and <li> tags, craft a concise, enchanting love report for {name} ({gender}) based on:

                            {insights}

                            <h4>Overview</h4>
                            <p>In 2–3 brief sentences, offer a romantic welcome that sets a tender, enchanting tone and invites the reader to explore their heart’s deepest truths.</p>

                            <h4>Insights</h4>
                            <ul>
                            <li><strong>Heart Line</strong>:
                                <ul>
                                <li><strong>Depth & Curve</strong>: 2–3 sentences on the emotional flow—what the shape and depth reveal about love capacity and empathy.</li>
                                <li><strong>Breaks & Forks</strong>: 2–3 sentences on past wounds, future healings, and how flexibility in love shapes relationships.</li>
                                </ul>
                            </li>

                            <li><strong>Mount of Venus</strong>: 2–3 sentences on passion and warmth—how the fullness of this mount speaks to affection, romance, and vitality.</li>

                            <li><strong>Relationship Lines</strong>: 2–3 sentences on the minor lines beneath the pinky—insights into partnership potential, commitments, and soulmate connections.</li>

                            <li><strong>Finger Dynamics</strong>: 2–3 sentences on pinky and ring finger proportions, revealing communication style, intimacy needs, and creative expression in love.</li>

                            <li><strong>Markings of Love</strong>: 2–3 sentences on heart symbols, stars, or crosses—rare signs that hint at destined encounters or cautionary tales.</li>
                            </ul>

                            <h4>Love Path</h4>
                            <p>In 2–3 sentences, close with an uplifting message of affection and growth, and end with a brief invitation: “Discover deeper love insights.”</p>
                            EOT,


            'wealth' => <<<EOT
                            You are a mystical palm reader. Using only <h4>, <p>, <ul>, and <li> tags, craft a concise, empowering wealth report for {name} ({gender}) based on:

                            {insights}

                            <h4>Overview</h4>
                            <p>In 2–3 brief sentences, offer a grounding welcome that sets an abundant, optimistic tone and prepares the reader to explore their prosperity potential.</p>

                            <h4>Insights</h4>
                            <ul>
                            <li><strong>Fate Line</strong>:
                                <ul>
                                <li><strong>Depth & Presence</strong>: 2–3 sentences on the strength and clarity of this line—how it reveals career paths and life purpose.</li>
                                <li><strong>Breaks & Branches</strong>: 2–3 sentences on shifts in fortune, pivotal opportunities, and the timing of success.</li>
                                </ul>
                            </li>

                            <li><strong>Mount of Jupiter</strong>: 2–3 sentences on ambition and leadership—how its prominence speaks to confidence, status, and achievement.</li>

                            <li><strong>Money Lines</strong>: 2–3 sentences on the small, parallel lines at the base of the ring finger—signs of financial luck, investments, and windfalls.</li>

                            <li><strong>Finger Proportions</strong>: 2–3 sentences on thumb and index finger balance—insights into willpower, persuasion skills, and negotiation prowess.</li>

                            <li><strong>Markings of Prosperity</strong>: 2–3 sentences on stars, crosses, or triangles—rare symbols that hint at unexpected gains or cautionary lessons.</li>

                            <li><strong>Hidden Wealth Channels</strong>: 2–3 sentences teasing a subtle line or mount that suggests untapped avenues of abundance and invites deeper discovery.</li>
                            </ul>

                            <h4>Prosperity Path</h4>
                            <p>In 2–3 sentences, close with an uplifting message about cultivating abundance and smart choices, and end with a brief invitation: “Unlock more wealth secrets.”</p>
                            EOT,


            'energy' => <<<EOT
                            You are a mystical palm reader. Using only <h4>, <p>, <ul>, and <li> tags, craft a concise, invigorating energy report for {name} ({gender}) based on:

                            {insights}

                            <h4>Overview</h4>
                            <p>In 2–3 brief sentences, offer a vibrant welcome that sets a revitalizing tone and invites the reader to sense the flow of their inner vitality.</p>

                            <h4>Insights</h4>
                            <ul>
                            <li><strong>Energy Lines</strong>:
                                <ul>
                                <li><strong>Sun Line</strong>: 2–3 sentences on the brightness and clarity of this line—how it reflects creative spark and personal power.</li>
                                <li><strong>Health Line</strong>: 2–3 sentences on breaks, depth, and continuity—what they suggest about physical stamina and well-being.</li>
                                </ul>
                            </li>

                            <li><strong>Mount of Mercury</strong>: 2–3 sentences on communication energy and adaptability—how its prominence reveals vitality in thought and speech.</li>

                            <li><strong>Finger Warmth</strong>: 2–3 sentences on subtle temperature and texture—what warmth or coolness in the fingertips hints at your emotional drive and calm.</li>

                            <li><strong>Minor Vital Lines</strong>: 2–3 sentences on faint lines like the intuition or travel line—teasing hints of dynamic energy shifts and balance.</li>

                            <li><strong>Markings of Vigor</strong>: 2–3 sentences on dots, stars, or crosses—rare signs that indicate bursts of energy or gentle reminders to rest.</li>
                            </ul>

                            <h4>Energy Path</h4>
                            <p>In 2–3 sentences, close with an uplifting message about harnessing and balancing your vital forces, and end with a brief invitation: “Discover deeper energy insights.”</p>
                            EOT,

        ];

        $body = $templates[$topic] ?? $templates['general'];

        // Inject variables
        return str_replace(
            ['{name}', '{gender}', '{insights}'],
            [$name, $gender, $insights],
            $body
        );
    }

    /**
     * Render the three bottom buttons.
     */
    private static function render_report_buttons_html(?string $context_id): string
    {
        $ctx = esc_attr($context_id ?? '');
        return <<<HTML
                <div class="report-buttons" data-context-id="{$ctx}">
                <button class="btn-report btn-topic-love">Discover Your Love Story</button>
                <button class="btn-report btn-topic-wealth">Reveal Your Prosperity Path</button>
                <button class="btn-report btn-topic-energy">Awaken Your Inner Energy</button>
                </div>
                HTML;
    }

    /**
     * CTA shown only on the 'intro' report, appended server-side (not sent to OpenAI).
     * You can edit text here freely; front-end CSS can target .intro-message.
     */
    private static function render_intro_cta_html(): string
    {
        // Allow overriding via a WP filter if you want to control this elsewhere.
        $html = <<<HTML
        <p class="intro-message">
            <strong>✨ Want to know more? Unveil hidden fortunes &amp; aura secrets — unlock 5 insights &amp; continue your journey.</strong>
        </p>
    HTML;

        return apply_filters('hai_intro_cta_html', $html);
    }


    private static function call_openai($api_key, $messages)
    {
        $payload = [
            'model'       => 'gpt-4o',
            'messages'    => $messages,
            'temperature' => 0.8,
            'max_tokens'  => 2000,
        ];

        error_log("📡 Sending to OpenAI:\n" . json_encode($payload, JSON_PRETTY_PRINT));

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($payload),
            'timeout' => 60,
        ]);

        // Log the raw response array
        error_log("[OpenAI] 📬 Raw response: " . print_r($response, true));

        if (is_wp_error($response)) {
            error_log("❌ WP Error: " . $response->get_error_message());
            return ['content' => null, 'context_id' => null];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log("[OpenAI] 📬 Decoded body: " . print_r($body, true));

        // Extract context ID (conversation ID)
        $context_id = $body['id'] ?? null;
        error_log("[OpenAI] 🧠 Context ID: $context_id");

        return [
            'content'    => $body['choices'][0]['message']['content'] ?? null,
            'context_id' => $context_id,
        ];
    }
}
