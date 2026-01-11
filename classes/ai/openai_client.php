<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_student_support\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * OpenAI API client for the Student Support Agent.
 *
 * This client is responsible ONLY for:
 * - Sending requests to OpenAI Chat Completions API
 * - Receiving responses (text or tool calls)
 * - Returning structured data to the agent
 *
 * This client NEVER:
 * - Executes tools or actions
 * - Makes decisions about agent behavior
 * - Performs any side effects
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_client {

    /** @var string Response type: text message. */
    public const RESPONSE_TEXT = 'text';

    /** @var string Response type: tool/function call. */
    public const RESPONSE_TOOL_CALL = 'tool_call';

    /** @var string Response type: error. */
    public const RESPONSE_ERROR = 'error';

    /** @var string API endpoint. */
    private string $endpoint;

    /** @var string API key. */
    private string $apikey;

    /** @var string Model name. */
    private string $model;

    /** @var float Temperature setting. */
    private float $temperature;

    /** @var int Maximum tokens. */
    private int $maxtokens;

    /** @var int Request timeout in seconds. */
    private int $timeout;

    /**
     * Constructor.
     *
     * @param string|null $endpoint API endpoint (null = use config).
     * @param string|null $apikey API key (null = use config).
     * @param string|null $model Model name (null = use config).
     * @param float|null $temperature Temperature (null = use config).
     * @param int|null $maxtokens Max tokens (null = use config).
     */
    public function __construct(
        ?string $endpoint = null,
        ?string $apikey = null,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxtokens = null
    ) {
        $this->endpoint = $endpoint ?? get_config('local_student_support', 'apiendpoint')
            ?: 'https://api.openai.com/v1/chat/completions';
        $this->apikey = $apikey ?? get_config('local_student_support', 'apikey') ?: '';
        $this->model = $model ?? get_config('local_student_support', 'model') ?: 'gpt-4';
        $this->temperature = $temperature ?? (float) (get_config('local_student_support', 'temperature') ?: 0.7);
        $this->maxtokens = $maxtokens ?? (int) (get_config('local_student_support', 'maxtokens') ?: 1024);
        $this->timeout = 60;
    }

    /**
     * Check if the client is configured.
     *
     * @return bool True if API key is set.
     */
    public function is_configured(): bool {
        return !empty($this->apikey) && !empty($this->endpoint);
    }

    /**
     * Send a request to the OpenAI API.
     *
     * @param string $systemprompt The system prompt.
     * @param array $messages Conversation messages (role/content pairs).
     * @param array $tools Optional array of tool definitions.
     * @return array Response with 'type', 'content', and 'metadata'.
     */
    public function ask(string $systemprompt, array $messages, array $tools = []): array {
        if (!$this->is_configured()) {
            return $this->error_response('API not configured. Missing API key or endpoint.');
        }

        // Build the messages array with system prompt first.
        $apimessages = [];
        $apimessages[] = [
            'role' => 'system',
            'content' => $systemprompt,
        ];

        // Add conversation messages.
        foreach ($messages as $msg) {
            $apimessages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Build request body.
        $requestbody = [
            'model' => $this->model,
            'messages' => $apimessages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxtokens,
        ];

        // Add tools if provided.
        if (!empty($tools)) {
            $requestbody['tools'] = $tools;
            $requestbody['tool_choice'] = 'required';
        }


        // Make the API request.
        return $this->make_request($requestbody);
    }

    /**
     * Make the actual HTTP request to OpenAI.
     *
     * @param array $body Request body.
     * @return array Parsed response.
     */
    private function make_request(array $body): array {
    $headers = [
        'Authorization: Bearer ' . $this->apikey,
        'Content-Type: application/json'
    ];

    $ch = curl_init();

    $starttime = microtime(true);

    curl_setopt_array($ch, [
        CURLOPT_URL => $this->endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $this->timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,

        // IMPORTANT: avoid proxy issues with Azure VNet
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    $duration = round((microtime(true) - $starttime) * 1000);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return $this->error_response(
            'Connection error: ' . $error,
            ['http_code' => $httpcode, 'duration_ms' => $duration]
        );
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->error_response(
            'Invalid JSON response from API',
            [
                'http_code' => $httpcode,
                'duration_ms' => $duration,
                'raw' => substr($response, 0, 500),
            ]
        );
    }

    // Azure / OpenAI API-level error
    if (isset($data['error'])) {
        return $this->error_response(
            $data['error']['message'] ?? 'Unknown API error',
            [
                'http_code' => $httpcode,
                'duration_ms' => $duration,
                'error_type' => $data['error']['type'] ?? 'unknown',
            ]
        );
    }

    // Expected Chat Completions structure
    if (!isset($data['choices'][0]['message'])) {
        return $this->error_response(
            'Unexpected API response structure',
            [
                'http_code' => $httpcode,
                'duration_ms' => $duration,
            ]
        );
    }

    return $this->parse_response($data, $duration);
}


    /**
     * Parse the OpenAI API response.
     *
     * @param array $data Decoded JSON response.
     * @param int $duration Request duration in ms.
     * @return array Structured response.
     */
    private function parse_response(array $data, int $duration): array {
        $message = $data['choices'][0]['message'];
        $finishreason = $data['choices'][0]['finish_reason'] ?? 'unknown';

        // Build metadata.
        $metadata = [
            'model' => $data['model'] ?? $this->model,
            'finish_reason' => $finishreason,
            'duration_ms' => $duration,
            'usage' => $data['usage'] ?? null,
        ];

        // Check for tool calls.
        if (isset($message['tool_calls']) && !empty($message['tool_calls'])) {
            return $this->parse_tool_calls($message['tool_calls'], $metadata);
        }

        // Regular text response.
        return [
            'type' => self::RESPONSE_TEXT,
            'content' => $message['content'] ?? '',
            'metadata' => $metadata,
        ];
    }

    /**
     * Parse tool calls from the response.
     *
     * @param array $toolcalls Array of tool calls from API.
     * @param array $metadata Response metadata.
     * @return array Structured tool call response.
     */
    private function parse_tool_calls(array $toolcalls, array $metadata): array {
        // We handle the first tool call (agent processes one action at a time).
        $toolcall = $toolcalls[0];

        if ($toolcall['type'] !== 'function') {
            return $this->error_response('Unsupported tool call type: ' . $toolcall['type'], $metadata);
        }

        $function = $toolcall['function'];
        $arguments = [];

        // Parse function arguments.
        if (!empty($function['arguments'])) {
            $arguments = json_decode($function['arguments'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $arguments = [];
            }
        }

        return [
            'type' => self::RESPONSE_TOOL_CALL,
            'content' => null,
            'tool_call' => [
                'id' => $toolcall['id'] ?? null,
                'name' => $function['name'],
                'arguments' => $arguments,
            ],
            'metadata' => $metadata,
        ];
    }

    /**
     * Create an error response.
     *
     * @param string $message Error message.
     * @param array $metadata Additional metadata.
     * @return array Error response structure.
     */
    private function error_response(string $message, array $metadata = []): array {
        debugging("OpenAI Client Error: {$message}", DEBUG_DEVELOPER);

        return [
            'type' => self::RESPONSE_ERROR,
            'content' => null,
            'error' => $message,
            'metadata' => $metadata,
        ];
    }

    /**
     * Get the current model name.
     *
     * @return string Model name.
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * Get the current temperature.
     *
     * @return float Temperature.
     */
    public function get_temperature(): float {
        return $this->temperature;
    }

    /**
     * Get the current max tokens.
     *
     * @return int Max tokens.
     */
    public function get_max_tokens(): int {
        return $this->maxtokens;
    }
}
