<?php

declare(strict_types=1);

namespace Bruno;

use SensitiveParameter;
use vielhuber\aihelper\ai_google;
use vielhuber\aihelper\aihelper;
use vielhuber\stringhelper\__;

// aihelper declares its provider classes in the same file as the factory.
class_exists(aihelper::class);

final class GatewayGemini extends ai_google
{
    /**
     * Use the selected Gemini model through the application's authenticated gateway.
     */
    public function __construct(string $model, #[SensitiveParameter] string $key, private readonly string $instruction)
    {
        parent::__construct(model: $model, timeout: 50, api_key: $key, max_tries: 1);
    }

    /**
     * Skip model discovery because the application already supplies the exact model.
     *
     * @return array<never, never>
     */
    public function fetchModels(): array
    {
        return [];
    }

    /**
     * Send the gateway credential only as an authorization header over verified TLS.
     *
     * @param array<string, mixed>|null $args
     */
    protected function makeApiCall(?array $args = null): mixed
    {
        $args['systemInstruction'] = ['parts' => [['text' => $this->instruction]]];
        $args['generationConfig']['responseMimeType'] = 'application/json';
        $args['generationConfig']['maxOutputTokens'] = 8192;
        foreach ($args['contents'] as $messageIndex => $message) {
            foreach ($message['parts'] as $partIndex => $part) {
                if (is_array($part) && in_array($part['inline_data']['mime_type'] ?? '', ['audio/x-wav', 'audio/vnd.wave', 'audio/wave'], strict: true)) {
                    $args['contents'][$messageIndex]['parts'][$partIndex]['inline_data']['mime_type'] = 'audio/wav';
                }
            }
        }

        return __::curl(
            url: 'https://ai.rebuhleiv.xyz/v1beta/models/' . rawurlencode($this->model) . ':generateContent',
            data: $args,
            method: 'POST',
            headers: ['Authorization' => 'Bearer ' . $this->api_key],
            timeout: $this->timeout,
            follow_redirects: false,
            ssl_verify: true,
        );
    }
}
