<?php
/** Present self-generated account notices from the recipient's perspective. */
function notification_message_for_view(array $notification, int $viewerId): string {
    $message = (string)($notification['message'] ?? '');
    if (($notification['type'] ?? '') !== 'designation_update') return $message;

    $extra = json_decode((string)($notification['extra_data'] ?? ''), true);
    if (!is_array($extra) || (int)($extra['user_id'] ?? 0) !== $viewerId) return $message;

    $old = trim((string)($extra['old_desig'] ?? ''));
    $new = trim((string)($extra['new_desig'] ?? ''));
    if ($old === '' || $new === '') return $message;

    return 'You updated your designation from "' . $old . '" to "' . $new . '".';
}
