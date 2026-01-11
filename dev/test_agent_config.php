<?php
// DEVELOPMENT ONLY - DO NOT DEPLOY TO PRODUCTION

require_once(__DIR__ . '/../../../config.php');

require_login();

if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'admin');
}


use local_student_support\agent\agent_config;

$agent = new agent_config(2, 79);

$context = $agent->build_agent_context();


header('Content-Type: text/plain; charset=utf-8');

echo "===== AGENT CONFIG TEST =====\n\n";
print_r($context);

echo "\n\n===== GET AGENT CONSTRAINTS  =====\n\n";
print_r($agent->get_agent_constraints());

echo "\n\n===== GET AGENT GOALS  =====\n\n";
print_r($agent->get_agent_goals());




echo "\n\n===== END =====\n";