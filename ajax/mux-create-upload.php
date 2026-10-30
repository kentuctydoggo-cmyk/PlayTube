<?php
header('Content-Type: application/json');
/**
 * Creates (or refreshes) a Mux direct-upload URL for one file the browser is about to send.
 * Video bytes never touch this server.
 */
if (IS_LOGGED == false) {
    echo json_encode(array('status' => 401, 'message' => 'Please sign in to upload.'));
    exit();
}

if (!PT_MuxIsConfigured()) {
    echo json_encode(array('status' => 503, 'message' => 'Mux is not connected yet. An administrator must add MUX_TOKEN_ID and MUX_TOKEN_SECRET in Base44 → Secrets.'));
    exit();
}

$original_name = isset($_POST['name']) ? PT_Secure($_POST['name']) : '';
$mime_type     = isset($_POST['type']) ? strtolower(trim((string) $_POST['type'])) : '';
$file_size     = isset($_POST['size']) ? (int) $_POST['size'] : 0;
$client_key    = isset($_POST['client_key']) ? (string) $_POST['client_key'] : '';

if ($client_key === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $client_key)) {
    $client_key = bin2hex(random_bytes(16));
}

if ($original_name === '') {
    echo json_encode(array('status' => 400, 'message' => 'The file has no name. Please pick the video again.'));
    exit();
}

$extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
$allowed   = PT_MuxAllowedTypes();
if (!isset($allowed[$extension])) {
    echo json_encode(array('status' => 415, 'message' => 'Unsupported file type ".' . $extension . '". Mux accepts MP4, MOV, M4V and WebM.'));
    exit();
}

// The browser sometimes reports an empty or generic type for valid videos; fall back to
// the container-derived type instead of rejecting the upload.
if ($mime_type === '' || $mime_type === 'application/octet-stream') {
    $map = array(
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/x-m4v',
        'mov'  => 'video/quicktime',
        'webm' => 'video/webm',
    );
    $mime_type = $map[$extension];
}
if (!in_array($mime_type, $allowed[$extension], true)) {
    echo json_encode(array('status' => 415, 'message' => 'That file does not look like a ' . strtoupper($extension) . ' video (reported type: ' . $mime_type . ').'));
    exit();
}

if ($file_size <= 0) {
    echo json_encode(array('status' => 400, 'message' => 'The selected file is empty. Please choose a different video.'));
    exit();
}

// Only an optional, admin-configurable per-file guard exists (0 = whatever Mux accepts).
$admin_cap = isset($pt->config->mux_max_upload) ? (int) $pt->config->mux_max_upload : 0;
if ($admin_cap > 0 && $file_size > $admin_cap) {
    echo json_encode(array('status' => 413, 'message' => 'This file is larger than the configured limit of ' . pt_size_format($admin_cap) . '.'));
    exit();
}

$existing = PT_MuxRowForUser($pt->user->id, $client_key);
if (!empty($existing) && $existing->status === 'ready' && $existing->playback_id !== '') {
    echo json_encode(array('status' => 409, 'message' => 'This video has already been uploaded. Open it from your channel instead of uploading it twice.', 'client_key' => $client_key, 'state' => 'ready'));
    exit();
}

$res = PT_MuxCreateUpload($pt->user->id, $client_key, $mime_type, $original_name);
if (empty($res['ok'])) {
    $http = 400;
    if ($res['code'] === 'rate_limit') {
        $http = 429;
    } elseif ($res['code'] === 'unavailable') {
        $http = 503;
    } elseif ($res['code'] === 'auth' || $res['code'] === 'not_configured') {
        $http = 503;
    }
    echo json_encode(array('status' => $http, 'message' => PT_MuxUserMessage($res, 'Mux could not create the upload.')));
    exit();
}

$row = PT_MuxRegisterUpload($pt->user->id, $client_key, $res['data']['id'], $file_size, $mime_type, $original_name);
if (empty($row)) {
    echo json_encode(array('status' => 500, 'message' => 'Could not save the upload record. Please try again.'));
    exit();
}

echo json_encode(array(
    'status'     => 200,
    'upload_id'  => $res['data']['id'],
    'upload_url' => $res['data']['url'],
    'client_key' => $client_key,
    'mime_type'  => $mime_type,
    'state'      => (string) $row->status,
));
exit();
