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

/**
 * Language strings for local_student_support.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'Student Support Agent';
$string['plugindescription'] = 'AI-powered student support agent for pedagogical assistance';

// Capabilities.
$string['student_support:use'] = 'Use the Student Support Agent';
$string['student_support:viewreports'] = 'View Student Support Agent reports';
$string['student_support:configure'] = 'Configure Student Support Agent settings';
$string['student_support:configurecourse'] = 'Configure Student Support Agent course settings';

// Settings - General.
$string['settings:general'] = 'General settings';
$string['settings:general_desc'] = 'General configuration for the Student Support Agent.';
$string['settings:enabled'] = 'Enable plugin';
$string['settings:enabled_desc'] = 'Enable or disable the Student Support Agent across the site.';

// Settings - AI Provider.
$string['settings:provider'] = 'AI Provider settings';
$string['settings:provider_desc'] = 'Configure the AI provider connection settings.';
$string['settings:apiendpoint'] = 'API endpoint';
$string['settings:apiendpoint_desc'] = 'The API endpoint URL for the AI provider.';
$string['settings:apikey'] = 'API key';
$string['settings:apikey_desc'] = 'The API key for authenticating with the AI provider.';
$string['settings:model'] = 'Model';
$string['settings:model_desc'] = 'The AI model to use for generating responses.';

// Settings - Curriculum.
$string['settings:curriculum'] = 'Curriculum settings';
$string['settings:curriculum_desc'] = 'Default curriculum configuration for the site. Can be overridden at course level.';
$string['settings:curriculumname'] = 'Curriculum name';
$string['settings:curriculumname_desc'] = 'Name of the curriculum framework (e.g., "Australian Curriculum", "Common Core").';
$string['settings:curriculumyear'] = 'Curriculum year/version';
$string['settings:curriculumyear_desc'] = 'The year or version of the curriculum being used.';
$string['settings:defaultgradelevel'] = 'Default grade level';
$string['settings:defaultgradelevel_desc'] = 'Default educational grade level for students (e.g., "Year 7", "Grade 8").';
$string['settings:subjectareas'] = 'Subject areas';
$string['settings:subjectareas_desc'] = 'Comma-separated list of subject areas covered by this curriculum.';

// Settings - Behaviour.
$string['settings:behaviour'] = 'Agent behaviour';
$string['settings:behaviour_desc'] = 'Configure how the agent interacts with students.';
$string['settings:maxattempts'] = 'Maximum guidance attempts';
$string['settings:maxattempts_desc'] = 'Maximum number of guidance attempts before suggesting teacher intervention.';
$string['settings:responselanguage'] = 'Response language';
$string['settings:responselanguage_desc'] = 'The language used for agent responses.';
$string['settings:pedagogicalapproach'] = 'Pedagogical approach';
$string['settings:pedagogicalapproach_desc'] = 'The primary pedagogical approach the agent should use when guiding students. Each approach emphasizes different teaching strategies:

**Socratic questioning**: Uses guided questions to help students discover answers themselves. The agent asks probing questions that encourage critical thinking and self-reflection, rather than providing direct explanations. Best for developing analytical skills and deeper understanding.

**Scaffolded learning**: Provides structured, step-by-step support that gradually builds understanding. Starts with foundational concepts and progressively introduces more complex ideas. Includes explanations followed by examples. Best for introducing new topics or working with students who need more structured guidance.

**Exploratory guidance**: Encourages learning through examples and experimentation. Presents analogies, real-world applications, and partial examples that students can explore. Best for hands-on learners and for topics that benefit from practical illustration.';
$string['settings:approach_socratic'] = 'Socratic questioning';
$string['settings:approach_socratic_help'] = 'Guides students through questions to help them discover answers independently. Prioritizes critical thinking over direct explanation.';
$string['settings:approach_scaffolded'] = 'Scaffolded learning';
$string['settings:approach_scaffolded_help'] = 'Provides step-by-step explanations building from simple to complex. Offers structured support with clear progression.';
$string['settings:approach_exploratory'] = 'Exploratory guidance';
$string['settings:approach_exploratory_help'] = 'Uses examples, analogies, and real-world connections to illustrate concepts. Encourages hands-on discovery.';

// Settings - Privacy.
$string['settings:privacy'] = 'Privacy settings';
$string['settings:privacy_desc'] = 'Configure privacy and data handling options.';
$string['settings:logconversations'] = 'Log conversations';
$string['settings:logconversations_desc'] = 'Store conversation history for analysis and improvement.';
$string['settings:retentionperiod'] = 'Data retention period';
$string['settings:retentionperiod_desc'] = 'Number of days to retain conversation data (0 = indefinite).';
$string['settings:anonymizedata'] = 'Anonymize exported data';
$string['settings:anonymizedata_desc'] = 'Remove identifying information when exporting conversation data.';

// Agent configuration.
$string['config:systemprompt'] = 'System prompt';
$string['config:systemprompt_desc'] = 'The system prompt that defines agent behaviour. Use with caution.';
$string['config:temperature'] = 'Response temperature';
$string['config:temperature_desc'] = 'Controls randomness in responses (0.0-1.0). Lower = more focused.';
$string['config:maxtokens'] = 'Maximum response tokens';
$string['config:maxtokens_desc'] = 'Maximum number of tokens in agent responses.';

// Conversation state.
$string['state:newconversation'] = 'New conversation';
$string['state:understanding'] = 'Understanding the question';
$string['state:guiding'] = 'Providing guidance';
$string['state:checkingcomprehension'] = 'Checking comprehension';
$string['state:escalating'] = 'Suggesting teacher assistance';
$string['state:completed'] = 'Conversation completed';

// Error messages.
$string['error:notconfigured'] = 'The Student Support Agent is not properly configured. Please contact your administrator.';
$string['error:apierror'] = 'An error occurred while communicating with the AI service.';
$string['error:ratelimited'] = 'Too many requests. Please wait a moment before trying again.';
$string['error:nocapability'] = 'You do not have permission to use the Student Support Agent.';
$string['error:coursenotconfigured'] = 'The Student Support Agent is not enabled for this course.';
$string['error:invalidstate'] = 'Invalid conversation state. Please start a new conversation.';

// Messages.
$string['message:welcome'] = 'Hello! I\'m here to help you understand your learning materials. What would you like to explore today?';
$string['message:escalate'] = 'It seems like you might benefit from speaking with your teacher about this. Would you like me to help you formulate your question for them?';
$string['message:checkunderstanding'] = 'Before we continue, can you explain in your own words what you\'ve understood so far?';
$string['message:encouragement'] = 'You\'re making good progress! Let\'s continue exploring this concept.';
$string['message:refusedirectanswer'] = 'I understand you\'d like the answer, but my role is to help you discover it yourself. Let\'s work through this step by step.';

// Course settings.
$string['course:settings'] = 'Student Support Agent settings';
$string['course:enabled'] = 'Enable in this course';
$string['course:enabled_desc'] = 'Enable the Student Support Agent for students in this course.';
$string['course:overridecurriculum'] = 'Override curriculum settings';
$string['course:overridecurriculum_desc'] = 'Use course-specific curriculum settings instead of site defaults.';
$string['course:gradelevel'] = 'Grade level';
$string['course:gradelevel_desc'] = 'The educational grade level for students in this course.';
$string['course:subjectarea'] = 'Subject area';
$string['course:subjectarea_desc'] = 'The subject area for this course.';

// Privacy.
$string['privacy:metadata:conversations'] = 'Stores conversation history between students and the support agent.';
$string['privacy:metadata:conversations:userid'] = 'The ID of the user who participated in the conversation.';
$string['privacy:metadata:conversations:message'] = 'The content of the message.';
$string['privacy:metadata:conversations:timecreated'] = 'The time when the message was created.';
$string['privacy:metadata:state'] = 'Stores the current state of student conversations.';
$string['privacy:metadata:state:userid'] = 'The ID of the user.';
$string['privacy:metadata:state:statedata'] = 'The serialized state data.';

// Cache.
$string['cachedef_conversationstate'] = 'Caches the current conversation state for active sessions.';
$string['cachedef_agentconfig'] = 'Caches agent configuration to reduce database queries.';
