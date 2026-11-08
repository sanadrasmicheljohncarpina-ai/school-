<?php
// dean_ea_notices.php
// Builds the "Executive Assistant set ..." notices for the Dean's bell from
// the live system settings. Shared by dean_dashboard.php and
// dean_notifications_api.php so the bell and the API never disagree.
//
// Each notice's key includes the values it announces, so when the EA changes
// the academic period or the evaluation schedule a NEW key appears and the
// bell shows it as a fresh unread notice. Unchanged settings keep the same
// key and are not re-announced.

function dean_ea_notices(array $settings): array
{
    $out = [];
    if ((int)($settings['period_id'] ?? 0) <= 0) {
        return $out;
    }

    $year = trim((string)($settings['academic_year'] ?? ''));
    $term = trim((string)($settings['academic_term'] ?? ''));
    if ($year !== '') {
        $period = $term !== '' ? $year . ' · ' . $term : $year;
        $out[] = [
            'key'        => 'ea_period:' . hash('sha256', $period),
            'text'       => 'The Executive Assistant set the academic period to ' . $period . '.',
            'type'       => 'ea_period',
            'created_at' => null,
        ];
    }

    $start = trim((string)($settings['eval_start_display'] ?? ''));
    $end   = trim((string)($settings['eval_end_display'] ?? ''));
    if ($start !== '' && $end !== '') {
        $range = $start . ' – ' . $end;
        $out[] = [
            'key'        => 'ea_schedule:' . hash('sha256', $range),
            'text'       => 'The Executive Assistant set the evaluation schedule: ' . $range . '.',
            'type'       => 'ea_schedule',
            'created_at' => null,
        ];
    }
    return $out;
}
