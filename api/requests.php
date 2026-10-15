<?php
// 申請の受け口（FR-005 修正・新規登録、FR-008 簡単残業申請）。記録表示・メイン画面の JavaScript から呼ぶ
// 受け付けるのは POST（csrf_token、type＝correction／overtime／cancel（取り消し）と、それぞれの入力）。結果は JSON
// 入力の誤りは ['ok' => false, 'errors' => [項目 => 文]]、受け付けたら ['ok' => true, 'message' => 文]
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/requests.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['message' => '申請は、画面の申請ウィンドウから行ってください。']);
}

$currentUser = currentUser($pdo);
if ($currentUser === null) {
    jsonResponse(401, ['message' => 'ログインしてください。', 'redirect' => appUrl('login.php')]);
}

requireValidCsrfToken();

// 申請できるのは社員・管理者（システム管理者は社員ではない）
if ($currentUser['role'] === ROLE_SYSTEM_ADMIN || $currentUser['joined_on'] === null) {
    jsonResponse(403, ['message' => 'システム管理者は、申請できません。']);
}

$type = $_POST['type'] ?? '';
if ($type === 'correction') {
    $result = submitCorrectionRequest($pdo, $currentUser, $_POST, time());
    if ($result['ok']) {
        // 記録表示は、画面を読み込み直してバッジを出し、この通知を表示する
        setFlash($result['message']);
    }
} elseif ($type === 'cancel') {
    // 申請の取り消し（D-063）。記録表示は、画面を読み込み直してこの通知を表示する
    $result = submitCancelRequest($pdo, $currentUser, $_POST);
    if ($result['ok']) {
        setFlash($result['message']);
    }
} elseif ($type === 'overtime') {
    $result = submitOvertimeRequest($pdo, $currentUser, $_POST, time(), fetchWorkSettings($pdo)['standard_end']);
} else {
    jsonResponse(400, ['message' => '不正なリクエストです。画面を開き直して、もう一度お試しください。']);
}

jsonResponse(200, $result);
