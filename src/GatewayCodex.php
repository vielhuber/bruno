<?php

declare(strict_types=1);

namespace Bruno;

use SensitiveParameter;
use vielhuber\aihelper\ai_openai;
use vielhuber\stringhelper\__;

final class GatewayCodex extends ai_openai
{
    /**
     * Use the configured Codex model without forwarding user content to additional services.
     */
    public function __construct(string $model, #[SensitiveParameter] string $key, private readonly string $instruction)
    {
        parent::__construct(model: $model, timeout: 50, api_key: $key, max_tries: 1);
    }

    /**
     * Avoid model discovery because the gateway model is already configured.
     *
     * @return array<never, never>
     */
    public function fetchModels(): array
    {
        return [];
    }

    /**
     * Require JSON over the native Codex Responses protocol without storing responses.
     *
     * @param array<string, mixed>|null $args
     */
    protected function makeApiCall(?array $args = null): mixed
    {
        $args['instructions'] = $this->instruction;
        $args['reasoning'] = ['effort' => 'low'];
        $args['store'] = false;
        $args['max_output_tokens'] = 8192;
        $args['text']['format'] = ['type' => 'json_object'];

        return __::curl(
            url: 'https://ai.rebuhleiv.xyz/v1/responses',
            data: $args,
            method: 'POST',
            headers: ['Authorization' => 'Bearer ' . $this->api_key],
            timeout: $this->timeout,
            follow_redirects: false,
            ssl_verify: true,
        );
    }
}
