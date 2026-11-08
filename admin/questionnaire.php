<?php
/**
 * Questionnaire entry point.
 *
 * The questionnaire is now target-centric:
 *   Faculty | Staff | Dean / Principal | Executive Assistant (EA)
 *
 * Legacy eval_type/view URLs are translated to the new target scope so
 * existing bookmarks and older dashboard links remain usable.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$scope = $_GET['scope'] ?? $_POST['scope'] ?? '';
$evalType = $_GET['eval_type'] ?? $_POST['eval_type'] ?? '';
$target = $_GET['target'] ?? $_POST['target'] ?? '';

if (!in_array($scope, ['faculty','staff','school_head','ea'], true)) {
    // No explicit scope/legacy target means show the centralized questionnaire landing page.
    if ($evalType === 'staff') {
        $scope = ($target === 'EA') ? 'ea' : 'school_head';
    } elseif ($evalType === 'ea') {
        $scope = in_array($target, ['Dean','Principal'], true) ? 'school_head' : 'staff';
    } elseif ($evalType === 'school_head') {
        $scope = in_array($target, ['Dean','Principal','School','School Head'], true) ? 'school_head'
            : ($target === 'EA' ? 'ea' : 'faculty');
    } elseif ($target !== '') {
        $scope = in_array($target, ['Staff'], true) ? 'staff'
            : (in_array($target, ['Dean','Principal','School','School Head'], true) ? 'school_head' : 'faculty');
    } else {
        $scope = '';
    }
}

$_GET['scope'] = $scope;
require __DIR__ . '/questionnaire_general.php';
exit;
