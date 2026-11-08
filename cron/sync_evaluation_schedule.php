<?php
// cron/sync_evaluation_schedule.php
// Optional background runner. Execute this every minute with Windows Task
// Scheduler, Linux cron, or another scheduler if you want the database
// is_active flag to flip even when nobody is currently viewing the site.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../shared/system_settings_service.php';

$result = ss_sync_from_database($mysqli);
if (!$result['ok']) {
    fwrite(STDERR, "Schedule sync failed.\n");
    exit(1);
}

echo 'Evaluation schedule synced: ' . ($result['state'] ?? 'manual') . PHP_EOL;
