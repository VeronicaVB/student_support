<?php
// DEVELOPMENT ONLY - DO NOT DEPLOY TO PRODUCTION

require_once(__DIR__ . '/../../../config.php');

require_login();

if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'admin');
}

use local_student_support\agent\prompts\system_prompt;

$context = [
    'educational_level'     => 'Secondary education (Year 9)',
    'pedagogical_approach'  => 'Scaffolded learning',
    'curriculum_notes'      => 'Algebra basics: linear equations and variables'
];

$prompt = system_prompt::with_context($context);

header('Content-Type: text/plain; charset=utf-8');

echo "===== SYSTEM PROMPT TEST =====\n\n";
echo $prompt;
echo "\n\n===== END =====\n";
