<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * English strings for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI Grading';
$string['pagetitle'] = 'Automatic AI Evaluation';
$string['pagesubtitle'] = 'Select a VPL activity, configure the evaluation criteria and test before applying.';
$string['coursecontext'] = 'Course context';
$string['simulationmode'] = 'Simulation mode';
$string['activitysection'] = 'VPL activity';
$string['activitysectionhelp'] = 'Select a mock VPL activity. The rest of the interface updates as if it were connected to Moodle data.';
$string['criteriasection'] = 'Evaluation criteria';
$string['criteriasectionhelp'] = 'Define the rubric structure that will guide manual references and simulated AI grading.';
$string['promptsection'] = 'Base prompt and AI guidelines';
$string['promptsectionhelp'] = 'Write broad grading instructions. The preview shows how this setup would feed the external AI service later.';
$string['manualsection'] = 'Manual reference grading';
$string['manualsectionhelp'] = 'Load a mock student case, grade it by criterion and keep references in the temporary page history.';
$string['aitestsection'] = 'AI evaluation test';
$string['aitestsectionhelp'] = 'Run simulated AI grading with coherent mock responses. Keep useful tests or discard them.';
$string['finalsection'] = 'Final action';
$string['finalsectionhelp'] = 'This phase only prepares the setup screen. Applying grading shows a placeholder.';
$string['applybutton'] = 'Apply evaluation to students';
$string['ai_grading:view'] = 'View AI Grading';
$string['ai_grading:manage'] = 'Configure AI Grading';
$string['privacy:metadata'] = 'AI Grading stores evaluation configurations, student IDs, VPL submission IDs, teacher observations and AI test results.';
$string['privacy:metadata:local_ai_grading_config'] = 'Evaluation configurations created by teachers for VPL activities.';
$string['privacy:metadata:local_ai_grading_manual'] = 'Manual reference grades linked to students and VPL submissions.';
$string['privacy:metadata:local_ai_grading_mancrit'] = 'Criterion details for manual reference grades.';
$string['privacy:metadata:local_ai_grading_aitest'] = 'AI evaluation tests run by teachers.';
$string['privacy:metadata:local_ai_grading_aitcrit'] = 'Criterion details for AI tests.';
$string['privacy:metadata:local_ai_grading_result'] = 'Real AI grading results for later phases.';
$string['privacy:metadata:local_ai_grading_rescrit'] = 'Criterion details for real AI grading results.';
$string['privacy:metadata:courseid'] = 'Course ID.';
$string['privacy:metadata:vplid'] = 'VPL activity ID.';
$string['privacy:metadata:teacherid'] = 'Teacher ID that created or updated the configuration.';
$string['privacy:metadata:studentid'] = 'Evaluated student ID.';
$string['privacy:metadata:submissionid'] = 'VPL submission ID.';
$string['privacy:metadata:observations'] = 'Observations or feedback stored by teachers or by the AI service.';
$string['privacy:metadata:grades'] = 'Grades calculated or stored during evaluation.';

$string['activitylabel'] = 'Activity';
$string['activityhelp'] = 'Mock VPL options for this first phase';
$string['activitypreview'] = 'Activity preview';
$string['addcriterion'] = 'Add criterion';
$string['addlevel'] = 'Add level';
$string['aicase'] = 'Test case';
$string['aidiscard'] = 'Discard result';
$string['aifeedback'] = 'General feedback';
$string['aikeep'] = 'Keep result';
$string['airun'] = 'Run AI test';
$string['aiscore'] = 'AI score';
$string['aitotal'] = 'Total grade';
$string['applyplaceholder'] = 'Placeholder: the next phase will open result management and apply the configured AI evaluation.';
$string['active'] = 'Active';
$string['criteriatotal'] = 'Total weight';
$string['criteriondescription'] = 'Criterion description';
$string['criterionname'] = 'Criterion name';
$string['delete'] = 'Delete';
$string['description'] = 'Description';
$string['discard'] = 'Discard';
$string['editinline'] = 'Edit directly in this block';
$string['history'] = 'History';
$string['keepasactive'] = 'Set active';
$string['levelname'] = 'Level name';
$string['loadcase'] = 'Load case';
$string['manualnew'] = 'Work on a new reference';
$string['manualobservations'] = 'General observations';
$string['manualsave'] = 'Save manual reference';
$string['manualscore'] = 'Manual score';
$string['manualselectmode'] = 'Selection mode';
$string['manualspecificstudent'] = 'Specific student';
$string['manualrandomstudent'] = 'Random student';
$string['minpercent'] = 'Minimum percentage';
$string['movedown'] = 'Move down';
$string['moveup'] = 'Move up';
$string['newreference'] = 'New reference';
$string['noactivecase'] = 'No active case loaded yet.';
$string['nohistory'] = 'No saved items yet.';
$string['noairesult'] = 'No AI result generated yet.';
$string['pendingresult'] = 'Unsaved simulated result';
$string['percent'] = 'Percent';
$string['promptpreview'] = 'Evaluation payload preview';
$string['savednotice'] = 'Saved in temporary page history.';
$string['simulated'] = 'Simulated';
$string['student'] = 'Student';
$string['testkept'] = 'AI test kept in history.';
$string['weight'] = 'Weight';

$string['settings:mode'] = 'Operating mode';
$string['settings:mode_desc'] = 'Mock uses simulated responses. External sends the AI test to the webhook configured in Moodle.';
$string['settings:mode_mock'] = 'mock';
$string['settings:mode_external'] = 'external';
$string['settings:service_url'] = 'External service base URL';
$string['settings:service_url_desc'] = 'n8n webhook or external backend URL that will receive the grading JSON.';
$string['settings:service_token'] = 'API key or token';
$string['settings:service_token_desc'] = 'Sensitive token used to authenticate requests to the external service. It is never exposed to the browser.';
$string['settings:timeout'] = 'Request timeout';
$string['settings:timeout_desc'] = 'Maximum time, in seconds, to wait for the external service response.';

$string['aitestdeleted'] = 'AI test deleted.';
$string['aitestsaved'] = 'AI test saved.';
$string['configsaved'] = 'Configuration saved.';
$string['configsavedweightwarning'] = 'Configuration saved. Warning: weights add up to {$a}%, not 100%.';
$string['criterionnamerequired'] = 'Each criterion needs a name.';
$string['criterionwithoutlevels'] = 'Each criterion needs at least one level.';
$string['externalfallbackmissingconfig'] = 'External mode is enabled, but URL or token is missing. A safe mock test was generated instead.';
$string['externalinvalidjson'] = 'The external service did not return valid JSON.';
$string['externalinvalidlevel'] = 'The external service returned a criterion or level that does not belong to this rubric.';
$string['externalinvalidresponse'] = 'The external service returned an incomplete response.';
$string['externalmissingcriterion'] = 'The external service did not return detail for every criterion.';
$string['externalrequestfailed'] = 'External request failed (HTTP {$a->httpcode}). {$a->detail}';
$string['externalresultnotice'] = 'AI test received from the external service and saved.';
$string['invalidcriteria'] = 'The criteria structure is not valid.';
$string['invalidcourse'] = 'The configuration does not belong to the current course.';
$string['invalidjson'] = 'The backend request does not contain valid JSON.';
$string['invalidlevelselection'] = 'The selected level does not belong to the corresponding criterion.';
$string['invalidnumber'] = 'One of the numeric values is not valid.';
$string['invalidpayload'] = 'The external service payload could not be built.';
$string['invalidstudentselection'] = 'Only course students can be selected for this test.';
$string['levelnamerequired'] = 'Each level needs a name.';
$string['levelsrequired'] = 'Each criterion needs at least one level.';
$string['manualdeleted'] = 'Manual grade deleted.';
$string['manualincomplete'] = 'Select one level for every criterion.';
$string['manualsaved'] = 'Manual grade saved.';
$string['mockcriteriadetail'] = '{$a->criterion}: level "{$a->level}" ({$a->percentage}%). Simulated response to validate the flow before connecting the external service.';
$string['mockgeneralfeedback'] = 'Simulated response: the submission was evaluated using the levels configured in the rubric.';
$string['mockresultnotice'] = 'Simulated AI test saved.';
$string['nocriteria'] = 'Define at least one evaluation criterion.';
$string['unexpectederror'] = 'An unexpected error occurred while processing the request.';
$string['unknownaction'] = 'Unknown AJAX action.';
