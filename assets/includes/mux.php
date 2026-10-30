<?php
/**
 * Mux Video integration for PlayTube.
 *
 * Design notes:
 *  - Credentials live ONLY in the environment (Base44 secrets reach the container through
 *    /run/base44/app.env as MUX_TOKEN_ID / MUX_TOKEN_SECRET). They are never written to the
 *    database, never returned by an endpoint and never logged.
 *  - Uploads are direct browser -> Mux (presigned PUT). PHP never proxies video bytes.
 *  - Processing status is read with server-side polling; there are no webhooks.
 *  - No storage quota, video count or per-user limit is enforced here on purpose: the Mux
 *    plan is the only capacity boundary. Nothing in this file deletes assets automatically.
 */

define('PT_MUX_API_HOST', rtrim(getenv('MUX_API_HOST') ?: 'https://api.mux.com', '/'));
define('PT_MUX_PLAYBACK_HOST', 'https://stream.mux.com');
define('PT_MUX_IMAGE_HOST', 'https://image.mux.com');
define('PT_MUX_POLL_INTERVAL', 4);      // seconds between provider polls for one row
define('PT_MUX_UPLOAD_TIMEOUT', 7200);  // Mux direct-upload URL lifetime

/** Accepted containers for the direct-upload pipeline. */
function PT_MuxAllowedTypes() {
    return array(
        'mp4'  => array('video/mp4', 'application/octet-stream'),
        'm4v'  => array('video/x-m4v', 'video/mp4', 'application/octet-stream'),
        'mov'  => array('video/quicktime', 'application/octet-stream'),
        'webm' => array('video/webm', 'application/octet-stream'),
    );
}

/**
 * Transient, in-memory credentials used only to validate what an administrator just typed
 * on the setup screen. They are never persisted, never logged and cleared immediately after
 * the check; the real values must live in Base44 → Secrets.
 */
function PT_MuxSetCredentialOverride($id, $secret) {
    $GLOBALS['pt_mux_credential_override'] = array(
        'id'     => trim((string) $id),
        'secret' => trim((string) $secret),
    );
}

function PT_MuxClearCredentialOverride() {
    unset($GLOBALS['pt_mux_credential_override']);
}

function PT_MuxCredentials() {
    if (!empty($GLOBALS['pt_mux_credential_override'])) {
        return $GLOBALS['pt_mux_credential_override'];
    }
    $id     = getenv('MUX_TOKEN_ID');
    $secret = getenv('MUX_TOKEN_SECRET');
    return array(
        'id'     => is_string($id) ? trim($id) : '',
        'secret' => is_string($secret) ? trim($secret) : '',
    );
}

function PT_MuxIsConfigured() {
    $creds = PT_MuxCredentials();
    return ($creds['id'] !== '' && $creds['secret'] !== '');
}

/** Never exposes more than the first/last characters of a token. */
function PT_MuxMask($value) {
    $value = (string) $value;
    $len   = strlen($value);
    if ($len === 0) {
        return '';
    }
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($value, -4);
}

/**
 * Uniform error shape so callers never have to touch undefined keys.
 * code: not_configured | auth | rate_limit | unavailable | rejected | invalid_response
 */
function PT_MuxError($code, $message, $status = 0) {
    return array(
        'ok'      => false,
        'code'    => $code,
        'message' => $message,
        'status'  => (int) $status,
        'data'    => array(),
    );
}

function PT_MuxUserMessage($res, $fallback = '') {
    if (!empty($res['message'])) {
        return $res['message'];
    }
    return $fallback !== '' ? $fallback : 'Mux request failed.';
}

/** Pulls the first human readable message out of a Mux error payload. */
function PT_MuxProviderMessage($body, $fallback) {
    if (!empty($body['error']['messages']) && is_array($body['error']['messages'])) {
        $first = reset($body['error']['messages']);
        if (is_string($first) && $first !== '') {
            return $first;
        }
    }
    if (!empty($body['error']['type']) && is_string($body['error']['type'])) {
        return $body['error']['type'];
    }
    if (!empty($body['message']) && is_string($body['message'])) {
        return $body['message'];
    }
    return $fallback;
}

/**
 * Authenticated call against the Mux API.
 * Returns ['ok'=>bool, 'data'=>array, 'code'=>string, 'message'=>string, 'status'=>int].
 */
function PT_MuxRequest($method, $path, $payload = null, $timeout = 20) {
    if (!PT_MuxIsConfigured()) {
        return PT_MuxError('not_configured', 'Mux is not connected yet. Add MUX_TOKEN_ID and MUX_TOKEN_SECRET in Base44 → Secrets.');
    }
    $creds = PT_MuxCredentials();
    $url   = PT_MUX_API_HOST . $path;

    $ch = curl_init($url);
    $headers = array('Accept: application/json');
    $opts = array(
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERPWD        => $creds['id'] . ':' . $creds['secret'],
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    );
    if ($payload !== null) {
        $headers[]                = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw     = curl_exec($ch);
    $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $curlErr !== '') {
        return PT_MuxError('unavailable', 'Could not reach Mux (' . $curlErr . '). Please try again.', $http);
    }

    $body = json_decode($raw, true);
    if ($http >= 400) {
        if ($http === 401 || $http === 403) {
            return PT_MuxError('auth', 'Mux rejected the API credentials. Check MUX_TOKEN_ID and MUX_TOKEN_SECRET.', $http);
        }
        if ($http === 429) {
            return PT_MuxError('rate_limit', 'Mux is rate limiting requests. Please retry in a moment.', $http);
        }
        if ($http >= 500) {
            return PT_MuxError('unavailable', 'Mux is temporarily unavailable (HTTP ' . $http . '). Please retry shortly.', $http);
        }
        return PT_MuxError('rejected', PT_MuxProviderMessage(is_array($body) ? $body : array(), 'Mux rejected the request (HTTP ' . $http . ').'), $http);
    }

    if (!is_array($body) || !isset($body['data'])) {
        return PT_MuxError('invalid_response', 'Unexpected response from Mux. Please try again.', $http);
    }

    return array('ok' => true, 'code' => '', 'message' => '', 'status' => $http, 'data' => $body['data']);
}

/** Cheap credential check used by the admin setup screen. */
function PT_MuxTest() {
    if (!PT_MuxIsConfigured()) {
        return array('configured' => false, 'connected' => false, 'message' => 'MUX_TOKEN_ID and MUX_TOKEN_SECRET are not set in Base44 → Secrets.');
    }
    $res = PT_MuxRequest('GET', '/video/v1/assets?limit=1');
    if (!empty($res['ok'])) {
        return array('configured' => true, 'connected' => true, 'message' => 'Connected to Mux.');
    }
    return array('configured' => true, 'connected' => false, 'message' => PT_MuxUserMessage($res, 'Could not authenticate with Mux.'));
}

/** Creates a presigned direct-upload URL for the browser. */
function PT_MuxCreateUpload($user_id, $client_key, $mime_type, $original_name) {
    $passthrough = json_encode(array(
        'user_id'    => (int) $user_id,
        'client_key' => (string) $client_key,
    ));

    $payload = array(
        'cors_origin'        => '*',
        'timeout'            => PT_MUX_UPLOAD_TIMEOUT,
        'passthrough'        => $passthrough,
        'new_asset_settings' => array(
            'playback_policy' => array('public'),
            'passthrough'     => $passthrough,
        ),
    );

    $res = PT_MuxRequest('POST', '/video/v1/uploads', $payload, 25);
    if (empty($res['ok'])) {
        return $res;
    }
    if (empty($res['data']['id']) || empty($res['data']['url'])) {
        return PT_MuxError('invalid_response', 'Mux did not return an upload URL. Please try again.');
    }
    return $res;
}

function PT_MuxGetUpload($upload_id) {
    return PT_MuxRequest('GET', '/video/v1/uploads/' . rawurlencode($upload_id));
}

function PT_MuxGetAsset($asset_id) {
    return PT_MuxRequest('GET', '/video/v1/assets/' . rawurlencode($asset_id));
}

/** Only ever called for an explicit user/admin delete — never for capacity management. */
function PT_MuxDeleteAsset($asset_id) {
    if (empty($asset_id)) {
        return false;
    }
    $res = PT_MuxRequest('DELETE', '/video/v1/assets/' . rawurlencode($asset_id));
    return !empty($res['ok']) || (isset($res['status']) && (int) $res['status'] === 404);
}

function PT_MuxPlaybackUrl($playback_id) {
    if (empty($playback_id)) {
        return '';
    }
    return PT_MUX_PLAYBACK_HOST . '/' . rawurlencode($playback_id) . '.m3u8';
}

function PT_MuxThumbnailUrl($playback_id, $time = 1) {
    if (empty($playback_id)) {
        return '';
    }
    return PT_MUX_IMAGE_HOST . '/' . rawurlencode($playback_id) . '/thumbnail.jpg?time=' . (int) $time . '&width=640';
}

/** First public playback id of an asset (Mux returns the array in creation order). */
function PT_MuxPublicPlaybackId($asset) {
    if (empty($asset['playback_ids']) || !is_array($asset['playback_ids'])) {
        return '';
    }
    foreach ($asset['playback_ids'] as $pb) {
        if (!empty($pb['id']) && (!isset($pb['policy']) || $pb['policy'] === 'public')) {
            return $pb['id'];
        }
    }
    return '';
}

/** 95.4 -> "1:35" / 3725 -> "1:02:05" (matches the app's duration column format). */
function PT_MuxDurationText($seconds) {
    $seconds = (int) round((float) $seconds);
    if ($seconds <= 0) {
        return '0:00';
    }
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return $h . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
    }
    return $m . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
}

/* -------------------------------------------------------------------------
 * Database helpers (table: mux_videos — additive, existing rows untouched)
 * ---------------------------------------------------------------------- */

function PT_MuxRowBy($column, $value) {
    global $db;
    if (!in_array($column, array('id', 'client_key', 'upload_id', 'asset_id', 'video_id'), true) || empty($value)) {
        return null;
    }
    return $db->where($column, $value)->getOne(T_MUX_VIDEOS);
}

function PT_MuxRowForUser($user_id, $client_key) {
    global $db;
    if (empty($user_id) || empty($client_key)) {
        return null;
    }
    return $db->where('user_id', $user_id)->where('client_key', $client_key)->getOne(T_MUX_VIDEOS);
}

/**
 * Creates or refreshes the tracking row for one browser upload attempt.
 * (user_id, client_key) is unique, so retrying a file never creates a second record.
 */
function PT_MuxRegisterUpload($user_id, $client_key, $upload_id, $size, $mime, $original_name) {
    global $db;
    $now  = time();
    $row  = PT_MuxRowForUser($user_id, $client_key);
    $data = array(
        'user_id'       => (int) $user_id,
        'client_key'    => $client_key,
        'upload_id'     => $upload_id,
        'status'        => 'uploading',
        'file_size'     => (int) $size,
        'mime_type'     => substr((string) $mime, 0, 64),
        'original_name' => substr((string) $original_name, 0, 255),
        'error_code'    => '',
        'error_message' => '',
        'updated_at'    => $now,
    );
    if (!empty($row)) {
        $db->where('id', $row->id)->update(T_MUX_VIDEOS, $data);
        return PT_MuxRowBy('id', $row->id);
    }
    $data['created_at'] = $now;
    $id = $db->insert(T_MUX_VIDEOS, $data);
    return $id ? PT_MuxRowBy('id', $id) : null;
}

/**
 * Polls Mux for one row and persists the outcome. Throttled, terminal-state aware,
 * and safe to call from every status request without hammering the API.
 */
function PT_MuxSyncRow($row) {
    global $db;
    if (empty($row)) {
        return null;
    }
    if (in_array($row->status, array('ready', 'failed'), true)) {
        return $row;
    }
    if (empty($row->upload_id) || !PT_MuxIsConfigured()) {
        return $row;
    }
    if ((int) $row->updated_at > (time() - PT_MUX_POLL_INTERVAL)) {
        return $row; // throttle: polled very recently
    }

    $status      = $row->status;
    $asset_id    = $row->asset_id;
    $playback_id = $row->playback_id;
    $duration    = $row->duration;
    $thumb       = $row->thumbnail_url;
    $error_code  = '';
    $error_msg   = '';

    if (empty($asset_id)) {
        $res = PT_MuxGetUpload($row->upload_id);
        if (empty($res['ok'])) {
            if ($res['code'] === 'rate_limit' || $res['code'] === 'unavailable') {
                return $row; // transient: keep the current state
            }
            $status     = 'failed';
            $error_code = $res['code'];
            $error_msg  = PT_MuxUserMessage($res);
        } else {
            $upload = $res['data'];
            if (!empty($upload['asset_id'])) {
                $asset_id = $upload['asset_id'];
                $status   = 'processing';
            } elseif (isset($upload['status']) && $upload['status'] === 'waiting') {
                $status = 'uploading';
            } elseif (isset($upload['status']) && in_array($upload['status'], array('errored', 'cancelled', 'timed_out'), true)) {
                $status     = 'failed';
                $error_code = 'upload_' . $upload['status'];
                $error_msg  = PT_MuxProviderMessage($upload, 'The upload did not reach Mux. Please retry the upload.');
            }
        }
    }

    if (!empty($asset_id) && $status !== 'failed') {
        $res = PT_MuxGetAsset($asset_id);
        if (empty($res['ok'])) {
            if ($res['code'] === 'rate_limit' || $res['code'] === 'unavailable') {
                return $row;
            }
            $status     = 'failed';
            $error_code = $res['code'];
            $error_msg  = PT_MuxUserMessage($res);
        } else {
            $asset        = $res['data'];
            $asset_status = isset($asset['status']) ? $asset['status'] : '';
            if ($asset_status === 'ready') {
                $playback_id = PT_MuxPublicPlaybackId($asset);
                if ($playback_id === '') {
                    $status     = 'failed';
                    $error_code = 'no_playback_id';
                    $error_msg  = 'Mux finished processing but returned no public playback ID.';
                } else {
                    $status   = 'ready';
                    $duration = isset($asset['duration']) ? $asset['duration'] : $duration;
                    $thumb    = PT_MuxThumbnailUrl($playback_id, 1);
                }
            } elseif ($asset_status === 'errored') {
                $status     = 'failed';
                $error_code = 'asset_errored';
                $error_msg  = PT_MuxProviderMessage($asset, 'Mux could not process this video. The file may be corrupt or use an unsupported codec.');
            } else {
                $status = 'processing';
            }
        }
    }

    $db->where('id', $row->id)->update(T_MUX_VIDEOS, array(
        'asset_id'      => $asset_id ? $asset_id : '',
        'playback_id'   => $playback_id ? $playback_id : '',
        'status'        => $status,
        'duration'      => $duration !== null ? $duration : '',
        'thumbnail_url' => $thumb ? $thumb : '',
        'error_code'    => $error_code,
        'error_message' => $error_msg,
        'updated_at'    => time(),
    ));
    $row = PT_MuxRowBy('id', $row->id);

    PT_MuxSyncVideoRecord($row);
    return $row;
}

/**
 * Mirrors Mux state onto the app's own videos row so the existing player, listings and
 * permissions keep working untouched: video_location becomes the HLS URL and
 * converted=1 only once Mux reports the asset ready.
 */
function PT_MuxSyncVideoRecord($row) {
    global $db;
    if (empty($row) || empty($row->video_id)) {
        return;
    }
    $video = $db->where('video_id', $row->video_id)->getOne(T_VIDEOS);
    if (empty($video)) {
        return;
    }
    $update = array();
    if ($row->status === 'ready' && !empty($row->playback_id)) {
        $update['video_location'] = PT_MuxPlaybackUrl($row->playback_id);
        $update['converted']      = 1;
        $update['duration']       = PT_MuxDurationText($row->duration);
        if (!empty($row->thumbnail_url) && (empty($video->thumbnail) || strpos($video->thumbnail, 'upload/photos/thumbnail.jpg') !== false)) {
            $update['thumbnail'] = $row->thumbnail_url;
        }
        if (!empty($row->file_size)) {
            $update['size'] = (int) $row->file_size;
        }
    } elseif ($row->status === 'failed') {
        // Stays out of the playable set (converted != 1) so a failed upload never looks playable.
        $update['converted'] = 0;
    }
    if (!empty($update)) {
        $db->where('video_id', $row->video_id)->update(T_VIDEOS, $update);
    }
}

/** JSON shape consumed by both the upload page and the admin screen. */
function PT_MuxStatusPayload($row) {
    if (empty($row)) {
        return array('status' => 404, 'state' => 'unknown', 'message' => 'No Mux upload found for this request.');
    }
    $state   = (string) $row->status;
    $message = 'Preparing the upload.';
    if ($state === 'uploading') {
        $message = 'Waiting for the file to finish uploading to Mux.';
    } elseif ($state === 'processing') {
        $message = 'Mux is processing the video. This usually takes a few seconds.';
    } elseif ($state === 'ready') {
        $message = 'Ready to play.';
    } elseif ($state === 'failed') {
        $message = $row->error_message !== '' ? $row->error_message : 'Mux could not process this upload.';
    }
    return array(
        'status'        => 200,
        'state'         => $state,
        'final'         => in_array($state, array('ready', 'failed'), true),
        'upload_id'     => (string) $row->upload_id,
        'asset_id'      => (string) $row->asset_id,
        'playback_id'   => (string) $row->playback_id,
        'playback_url'  => $row->playback_id ? PT_MuxPlaybackUrl($row->playback_id) : '',
        'duration'      => PT_MuxDurationText($row->duration),
        'thumbnail_url' => (string) $row->thumbnail_url,
        'video_id'      => (string) $row->video_id,
        'message'       => $message,
        'error_code'    => (string) $row->error_code,
    );
}

/* -------------------------------------------------------------------------
 * Non-secret connection state (table: mux_state) — lets the admin screen show
 * "connected / needs configuration" without ever reading a credential back.
 * ---------------------------------------------------------------------- */

function PT_MuxStateSet($key, $value) {
    global $db;
    $existing = $db->where('name', $key)->getOne(T_MUX_STATE);
    if (!empty($existing)) {
        $db->where('name', $key)->update(T_MUX_STATE, array('value' => (string) $value));
    } else {
        $db->insert(T_MUX_STATE, array('name' => $key, 'value' => (string) $value));
    }
}

function PT_MuxStateGet($key, $default = '') {
    global $db;
    $row = $db->where('name', $key)->getOne(T_MUX_STATE);
    return !empty($row) ? $row->value : $default;
}

/** Full picture for the admin setup screen. Contains no secret material. */
function PT_MuxConnectionStatus($verify = false) {
    $creds  = PT_MuxCredentials();
    $status = array(
        'configured'    => PT_MuxIsConfigured(),
        'connected'     => PT_MuxStateGet('connected', '0') === '1',
        'message'       => PT_MuxStateGet('message', ''),
        'checked_at'    => (int) PT_MuxStateGet('checked_at', '0'),
        'token_id_hint' => PT_MuxMask($creds['id']),
        'secret_set'    => $creds['secret'] !== '',
    );
    if (!$status['configured']) {
        $status['connected'] = false;
        $status['message']   = 'Add MUX_TOKEN_ID and MUX_TOKEN_SECRET in Base44 → Secrets, then re-check.';
        return $status;
    }
    if ($verify) {
        $test = PT_MuxTest();
        PT_MuxStateSet('connected', $test['connected'] ? '1' : '0');
        PT_MuxStateSet('message', $test['message']);
        PT_MuxStateSet('checked_at', time());
        $status['connected']  = $test['connected'];
        $status['message']    = $test['message'];
        $status['checked_at'] = time();
    }
    return $status;
}
