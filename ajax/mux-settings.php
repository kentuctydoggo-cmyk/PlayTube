<?php
header('Content-Type: application/json');
/**
 * Mux connection status for the admin setup screen.
 *
 * The application cannot write Base44 secrets, so this endpoint never stores credentials.
 * It either reports the state of the environment (are MUX_TOKEN_ID / MUX_TOKEN_SECRET
 * present, do they authenticate?) or — when the administrator types fresh values — borrows
 * them for a single verification request and discards them immediately.
 */
if (IS_LOGGED == false || !PT_IsAdmin()) {
    echo json_encode(array('status' => 403, 'message' => 'Administrators only.'));
    exit();
}

$action = isset($_REQUEST['action']) ? PT_Secure($_REQUEST['action']) : 'status';

// Never echo these back; they are only used for the outbound request below.
$token_id     = isset($_POST['token_id']) ? trim((string) $_POST['token_id']) : '';
$token_secret = isset($_POST['token_secret']) ? trim((string) $_POST['token_secret']) : '';

if ($action === 'verify' && ($token_id !== '' || $token_secret !== '')) {
    if ($token_id === '' || $token_secret === '') {
        echo json_encode(array(
            'status'  => 400,
            'message' => 'Enter both the Token ID and the Secret Key.',
            'mux'     => PT_MuxConnectionStatus(false),
        ));
        exit();
    }

    PT_MuxSetCredentialOverride($token_id, $token_secret);
    $test = PT_MuxTest();
    PT_MuxClearCredentialOverride();

    $env = PT_MuxConnectionStatus(false);
    $message = $test['message'];
    if ($test['connected']) {
        $message = 'These credentials work. Store them in Base44 → Secrets as MUX_TOKEN_ID and MUX_TOKEN_SECRET so uploads can use them.';
    }
    echo json_encode(array(
        'status'       => 200,
        'valid'        => (bool) $test['connected'],
        'message'      => $message,
        'env'          => $env,
    ));
    exit();
}

if ($action === 'test') {
    $status = PT_MuxConnectionStatus(true);
    echo json_encode(array(
        'status'  => 200,
        'mux'     => $status,
        'message' => $status['message'],
    ));
    exit();
}

$status = PT_MuxConnectionStatus(false);
echo json_encode(array(
    'status'  => 200,
    'mux'     => $status,
    'message' => $status['message'],
));
exit();
