<?php
// 承認・却下の受け口（FR-006）。承認画面の JavaScript から呼ぶ
// 受け付けるのは POST（csrf_token、request_id、decision＝approve／reject、却下のときは comment）。結果は JSON
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/approvals.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['message' => '承認・却下は、承認画面のボタンから行ってください。']);
}

$currentUser = currentUser($pdo);
if ($currentUser === null) {
    jsonResponse(401, ['message' => 'ログインしてください。', 'redirect' => appUrl('login.php')]);
}

requireValidCsrfToken();

// 承認できるのは管理者だけ（D-024）
if ($currentUser['role'] !== ROLE_MANAGER) {
    jsonResponse(403, ['message' => '承認・却下は、管理者だけができます。']);
}

$requestId = inputString($_POST, 'request_id');
$decision = inputString($_POST, 'decision');
if (!preg_match('/\A[1-9]\d{0,18}\z/', $requestId) || !in_array($decision, ['approve', 'reject'], true)) {
    jsonResponse(400, ['message' => '不正なリクエストです。画面を開き直して、もう一度お試しください。']);
}

$result = reviewRequest($pdo, $currentUser, (int) $requestId, $decision, inputString($_POST, 'comment'));
if ($result['ok']) {
    // 承認画面は、読み込み直して一覧と通知を出す
    setFlash($result['message']);
}
jsonResponse(200, $result);
