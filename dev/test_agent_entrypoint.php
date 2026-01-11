<?php
// DEVELOPMENT ONLY
// Agent entrypoint test. Do NOT deploy to production.

require_once(__DIR__ . '/../../../config.php');

require_login();

if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'admin');
}

use local_student_support\agent\student_support_agent;
use local_student_support\agent\agent_config;
use local_student_support\agent\agent_memory;

// Simulated student input.
$studentInput = 'I do not understand what this question is asking me to do.';

// Build agent configuration (later: from settings + course context).


// Instantiate agent.
$agent = new student_support_agent(2, 79);

// Initialize memory.
$memory = new agent_memory($agent->get_session_id(), 2, 79);

// Run a single agent step (no loop yet).
$response = $agent->process_message($studentInput);

// Output as plain text.
header('Content-Type: text/plain; charset=utf-8');

echo "===== STUDENT INPUT =====\n";
echo $studentInput . "\n\n";

echo "===== AGENT RESPONSE =====\n";
// echo $response . "\n";
print_r($response);

echo "\n===== END =====\n";
