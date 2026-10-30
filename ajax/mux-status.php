<?php
header('Content-Type: application/json');
/**
 * Processing status for one Mux upload, polled by the browser (no webhooks).
 * Accepts either the pre-publish `client_key` or the published `video_id`.
 * Every response carries a human readable `message` — never an undefined field.
 */
if (IS_LOGGED == false) {
    echo json_encode(array('status' => 401, 'message' => 'Please sign in to check the upload.'));
    exit();
}

$client_key = isset($_REQUEST['client_key']) ? (string) $_REQUEST['client_key'] : '';
$video_id   = isset($_REQUEST['video_id']) ? PT_Secure($_REQUEST['video_id']) : '';

if ($video_id !== '') {
    $row = PT_MuxRowBy('video_id', $video_id);
} elseif ($client_key !== '') {
    $row = PT_MuxRowForUser($pt->user->id, $client_key);
} else {
    echo json_encode(array('status' => 400, 'message' => 'Missing upload reference.'));
    exit();
}

if (empty($row)) {
    echo json_encode(array('status' => 404, 'message' => 'No Mux upload found for this request.'));
    exit();
}

if ((int) $row->user_id !== (int) $pt->user->id && !PT_IsAdmin()) {
    echo json_encode(array('status' => 403, 'message' => 'You cannot view this upload.'));
    exit();
}

if (!PT_MuxIsConfigured()) {
    echo json_encode(array('status' => 503, 'message' => 'Mux is not connected yet.'));
    exit();
}

$row     = PT_MuxSyncRow($row);
$payload = PT_MuxStatusPayload($row);
$payload['poll_after_ms'] = in_array($row->status, array('ready', 'failed'), true) ? 0 : 3000;
echo json_encode($payload);
exit();
