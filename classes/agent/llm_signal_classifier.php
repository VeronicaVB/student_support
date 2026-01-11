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

namespace local_student_support\agent;

use local_student_support\ai\openai_client;

defined('MOODLE_INTERNAL') || die();

/**
 * LLM-based signal classifier for ambiguous student messages.
 *
 * This classifier is used when regex patterns cannot reliably determine
 * the student's intent. It asks the LLM to classify the message in context.
 *
 * DESIGN PRINCIPLES:
 * - Fast: Single, focused prompt with constrained output
 * - Contextual: Uses conversation history for accurate classification
 * - Structured: Returns JSON for reliable parsing
 * - Fallback-safe: Returns sensible defaults on error
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class llm_signal_classifier {

    /** @var openai_client LLM client. */
    private openai_client $client;

    /** @var bool Whether the classifier is available. */
    private bool $available;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->client = new openai_client();
        $this->available = $this->client->is_configured();
    }

    /**
     * Check if the classifier is available.
     *
     * @return bool True if LLM is configured and available.
     */
    public function is_available(): bool {
        return $this->available;
    }

    /**
     * Classify ambiguous signals in a student message.
     *
     * @param string $message The student's message.
     * @param array $context Conversation context including history and topic.
     * @return array Classified signals with confidence.
     */
    public function classify(string $message, array $context): array {
        if (!$this->available) {
            return $this->get_default_classification();
        }

        try {
            $prompt = $this->build_classification_prompt($message, $context);
            $response = $this->client->ask($prompt['system'], $prompt['messages'], []);

            return $this->parse_classification_response($response);
        } catch (\Exception $e) {
            debugging("LLM signal classification failed: " . $e->getMessage(), DEBUG_DEVELOPER);
            return $this->get_default_classification();
        }
    }

    /**
     * Build the classification prompt.
     *
     * @param string $message Student message.
     * @param array $context Conversation context.
     * @return array System prompt and messages.
     */
    private function build_classification_prompt(string $message, array $context): array {
        $currenttopic = $context['memory_summary']['current_topic'] ?? 'unknown';
        $lastaction = $context['last_action'] ?? 'none';

        // Get last 2 exchanges for context.
        $recentexchanges = $this->get_recent_exchanges($context, 2);

        $systemprompt = <<<SYSTEM
You are a signal classifier for an educational tutoring system. Classify the student's message.

RESPOND ONLY WITH JSON in this exact format:
{
  "primary_signal": "signal_name",
  "confidence": 0.0-1.0,
  "reasoning": "brief explanation"
}

VALID SIGNALS (choose ONE as primary):
- "confusion": Student doesn't understand, needs different explanation
- "uncertainty": Student is unsure but not completely lost
- "confirms_understanding": Student indicates they understand
- "ready_to_practice": Student wants to try/practice/attempt something
- "wants_example": Student wants to see an example
- "needs_clarification": Student wants previous explanation clarified OR is correcting/pushing back on tutor's response
- "attempting": Student is trying to answer or engage with the material
- "new_question": Student is asking about a NEW topic (not continuing current discussion)
- "neutral": No clear signal, general response

CRITICAL RULES:
1. Short responses like "yes", "everything", "ok" are CONTINUATIONS of the current topic, NOT new topics
2. "everything" after "what confuses you?" = "confusion" about current topic
3. "yes" after "ready to try?" = "ready_to_practice"
4. PUSHBACK DETECTION (VERY IMPORTANT):
   - "you just came up with X" = "needs_clarification" (student pointing out tutor's idea)
   - "you're the one explaining X" = "needs_clarification" (pushback)
   - "that's YOUR idea/example/analogy" = "needs_clarification" (correction)
   - "that's not what I asked/said/meant" = "needs_clarification" (correction)
   - "why are you asking/saying that" = "needs_clarification" (pushback)
   - These are NOT confusion - student understands but is correcting the tutor
5. If student complains or says the tutor misunderstood = "needs_clarification"
6. Consider conversation context when classifying
7. "what do you mean" + reference to tutor's words = "needs_clarification", NOT "confusion"
SYSTEM;

        $userprompt = <<<USER
CURRENT TOPIC: {$currenttopic}
LAST TUTOR ACTION: {$lastaction}

RECENT CONVERSATION:
{$recentexchanges}

STUDENT'S NEW MESSAGE: "{$message}"

Classify this message. JSON only:
USER;

        return [
            'system' => $systemprompt,
            'messages' => [
                ['role' => 'user', 'content' => $userprompt],
            ],
        ];
    }

    /**
     * Get recent conversation exchanges formatted.
     *
     * @param array $context Conversation context.
     * @param int $count Number of exchanges to include.
     * @return string Formatted exchanges.
     */
    private function get_recent_exchanges(array $context, int $count): string {
        $history = $context['conversation_history'] ?? [];

        if (empty($history)) {
            return "(No previous conversation)";
        }

        // Get last N*2 messages (each exchange = 1 student + 1 tutor).
        $recent = array_slice($history, -($count * 2));

        $lines = [];
        foreach ($recent as $msg) {
            $role = ($msg['role'] === 'user') ? 'Student' : 'Tutor';
            $content = trim($msg['content'] ?? '');
            // Truncate long messages.
            if (mb_strlen($content) > 150) {
                $content = mb_substr($content, 0, 150) . '...';
            }
            $lines[] = "{$role}: {$content}";
        }

        return implode("\n", $lines);
    }

    /**
     * Parse the LLM classification response.
     *
     * @param array $response LLM response.
     * @return array Parsed classification.
     */
    private function parse_classification_response(array $response): array {
        $content = $response['content'] ?? '';

        // Try to extract JSON from response.
        $json = $this->extract_json($content);

        if ($json === null) {
            debugging("Failed to parse LLM classification response: {$content}", DEBUG_DEVELOPER);
            return $this->get_default_classification();
        }

        // Validate and normalize.
        $validSignals = [
            'confusion', 'uncertainty', 'confirms_understanding', 'ready_to_practice',
            'wants_example', 'needs_clarification', 'attempting', 'new_question', 'neutral',
        ];

        $primarysignal = $json['primary_signal'] ?? 'neutral';
        if (!in_array($primarysignal, $validSignals)) {
            $primarysignal = 'neutral';
        }

        $confidence = (float) ($json['confidence'] ?? 0.5);
        $confidence = max(0.0, min(1.0, $confidence));

        return [
            'primary_signal' => $primarysignal,
            'confidence' => $confidence,
            'reasoning' => $json['reasoning'] ?? '',
            'source' => 'llm',
        ];
    }

    /**
     * Extract JSON from response text.
     *
     * @param string $text Response text.
     * @return array|null Parsed JSON or null.
     */
    private function extract_json(string $text): ?array {
        // Try direct parse first.
        $decoded = json_decode($text, true);
        if ($decoded !== null) {
            return $decoded;
        }

        // Try to find JSON in the text.
        if (preg_match('/\{[^{}]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Get default classification when LLM is unavailable or fails.
     *
     * @return array Default classification.
     */
    private function get_default_classification(): array {
        return [
            'primary_signal' => 'neutral',
            'confidence' => 0.3,
            'reasoning' => 'LLM classification unavailable',
            'source' => 'default',
        ];
    }

    /**
     * Translate LLM signal name to signal_detector constant.
     *
     * @param string $llmsignal Signal name from LLM.
     * @return string Signal detector constant.
     */
    public static function translate_to_detector_signal(string $llmsignal): string {
        $mapping = [
            'confusion' => signal_detector::SIGNAL_CONFUSION,
            'uncertainty' => signal_detector::SIGNAL_UNCERTAINTY,
            'confirms_understanding' => signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING,
            'ready_to_practice' => signal_detector::SIGNAL_READY_TO_PRACTICE,
            'wants_example' => signal_detector::SIGNAL_WANTS_EXAMPLE,
            'needs_clarification' => signal_detector::SIGNAL_NEEDS_CLARIFICATION,
            'attempting' => signal_detector::SIGNAL_ATTEMPTING,
            'new_question' => signal_detector::SIGNAL_NEW_QUESTION,
            'neutral' => '', // No signal.
        ];

        return $mapping[$llmsignal] ?? '';
    }
}
