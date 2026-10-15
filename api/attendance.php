<?php
// 出退勤・休憩のボタンを押したときの受け口（FR-003、BR-001）。メイン画面の JavaScript から呼ぶ
// 受け付けるのは POST（csrf_token、action、上書き確認のあとは overwrite=1）。結果は JSON で返す
include_once(__DIR__ . '/../src/functions.php');
include_once(__DIR__ . '/../src/dbconnect.php');
include_once(__DIR__ . '/../src/auth.php');
include_once(__DIR__ . '/../src/queries/work_settings.php');
include_once(__DIR__ . '/../src/attendance.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['message' => 'この操作は、メイン画面のボタンから行ってください。']);
}

// ログインしていない（自動ログアウトを含む）場合は、画面側でログイン画面へ移す
$currentUser = currentUser($pdo);
if ($currentUser === null) {
    jsonResponse(401, ['message' => 'ログインしてください。', 'redirect' => appUrl('login.php')]);
}

requireValidCsrfToken();

// システム管理者は社員ではないため、出退勤の記録はない
if ($currentUser['role'] === ROLE_SYSTEM_ADMIN) {
    jsonResponse(403, ['message' => 'システム管理者には、出退勤の記録はありません。']);
}

$action = $_POST['action'] ?? '';
if (!is_string($action) || !isset(ATTENDANCE_ACTION_LABELS[$action])) {
    jsonResponse(400, ['message' => '不正なリクエストです。画面を開き直して、もう一度お試しください。']);
}
$overwrite = ($_POST['overwrite'] ?? '') === '1';

jsonResponse(200, performAttendanceAction($pdo, $currentUser['employee_id'], $action, $overwrite, time(), fetchWorkSettings($pdo)['standard_end']));
