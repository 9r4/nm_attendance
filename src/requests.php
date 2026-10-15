<?php
// 申請（修正・新規登録、簡単残業申請）の入力のルールと保存、申請内容の表示（FR-005、FR-008、BR-002、D-062）
// 入力のルールは、すべてサーバーで確認する（画面の確認は、使いやすさのためだけ）

include_once(__DIR__ . '/queries/attendance_requests.php');
include_once(__DIR__ . '/attendance.php');

// 修正・新規登録で申請する時刻の項目と、画面に出す名前（順番は、時刻の前後の順）
const REQUEST_TIME_FIELDS = [
    'check_in_at' => '出勤',
    'break_start_at' => '休憩開始',
    'break_end_at' => '休憩終了',
    'check_out_at' => '退勤',
];

const REQUEST_STATUS_LABELS = [
    'pending' => '申請中',
    'approved' => '承認済み',
    'rejected' => '却下',
];

const REQUEST_REASON_MAX_LENGTH = 500;

// BR-002（同じ申請の重複）の文
const REQUEST_DUPLICATE_CORRECTION = 'この日には、申請中の申請があります。新しく申請する場合は、申請中の申請を取り消してください（承認・却下されるまでは、新しい申請はできません）。';
const REQUEST_DUPLICATE_OVERTIME = '本日分の残業申請は、すでに申請中です。';

// 画面から送られた値を、文字列として取り出す（無い・文字列でない場合は ''）
function inputString(array $input, string $key): string {
    $value = $input[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

// 'H:i' の時刻を、対象日の日時（'Y-m-d H:i:00'）にする。空欄は null。形が正しくなければ false
function parseRequestTime(string $value, string $date): string|false|null {
    if ($value === '') {
        return null;
    }
    if (!preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $value)) {
        return false;
    }
    return $date . ' ' . $value . ':00';
}

// 'Y-m-d' の正しい日付か
function isValidDate(string $value): bool {
    return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// 勤怠の行から、申請する4つの時刻だけを取り出す（行が無ければ、すべて null）
function currentRequestTimes(?array $attendance): array {
    $times = [];
    foreach (array_keys(REQUEST_TIME_FIELDS) as $field) {
        $times[$field] = $attendance[$field] ?? null;
    }
    return $times;
}

// 修正・新規登録の入力を確認する（3-1 のルール）
// $input：target_date、check_in、check_out、break_start、break_end（'H:i' か空欄）、reason
// $current：対象日の今の勤怠（無ければ null）。$now：今の日時（'Y-m-d H:i:s'）
// 戻り値：['errors' => [項目 => 文], 'targetDate' => …, 'requested' => 4つの時刻, 'reason' => 理由か null]
function validateCorrection(array $input, ?array $current, string $now, string $joinedOn): array {
    $errors = [];
    $today = substr($now, 0, 10);
    $targetDate = inputString($input, 'target_date');
    if (!isValidDate($targetDate) || $targetDate < $joinedOn || $targetDate > $today) {
        $errors['target_date'] = '対象日は、入社日から今日までの日を指定してください。';
        return ['errors' => $errors, 'targetDate' => $targetDate, 'requested' => [], 'reason' => null];
    }

    // 時刻の形
    $inputNames = ['check_in_at' => 'check_in', 'break_start_at' => 'break_start', 'break_end_at' => 'break_end', 'check_out_at' => 'check_out'];
    $requested = [];
    foreach ($inputNames as $field => $name) {
        $time = parseRequestTime(inputString($input, $name), $targetDate);
        if ($time === false) {
            $errors[$name] = '時刻の形が正しくありません。';
            $time = null;
        }
        $requested[$field] = $time;
    }

    if (!isset($errors['check_in']) && $requested['check_in_at'] === null) {
        $errors['check_in'] = '出勤時刻を入力してください。';
    }
    if (!isset($errors['break_start']) && !isset($errors['break_end']) && ($requested['break_start_at'] === null) !== ($requested['break_end_at'] === null)) {
        $errors[$requested['break_start_at'] === null ? 'break_start' : 'break_end'] = '休憩は、開始と終了の両方を入力してください。';
    }

    // 順番：出勤 ＜ 休憩開始 ＜ 休憩終了 ＜ 退勤（入っているものどうし）
    if ($errors === []) {
        $previousField = null;
        foreach ($inputNames as $field => $name) {
            if ($requested[$field] === null) {
                continue;
            }
            if ($previousField !== null && $requested[$field] <= $requested[$previousField]) {
                $errors[$name] = REQUEST_TIME_FIELDS[$field] . '時刻は、' . REQUEST_TIME_FIELDS[$previousField] . '時刻より後にしてください。';
                break;
            }
            $previousField = $field;
        }
    }

    // 対象日が今日なら、まだ来ていない時刻は不可
    if ($errors === [] && $targetDate === $today) {
        $nowMinute = substr($now, 0, 16) . ':00';
        foreach ($inputNames as $field => $name) {
            if ($requested[$field] !== null && $requested[$field] > $nowMinute) {
                $errors[$name] = 'まだ来ていない時刻は申請できません。';
            }
        }
    }

    $reason = inputString($input, 'reason');
    if (mb_strlen($reason) > REQUEST_REASON_MAX_LENGTH) {
        $errors['reason'] = '理由は ' . REQUEST_REASON_MAX_LENGTH . ' 文字までにしてください。';
    }

    if ($errors === [] && $requested === currentRequestTimes($current)) {
        $errors['form'] = '今の記録から変更がありません。';
    }

    return ['errors' => $errors, 'targetDate' => $targetDate, 'requested' => $requested, 'reason' => $reason === '' ? null : $reason];
}

// 簡単残業申請の入力を確認する（3-2 のルール）
// $input：overtime_end（'H:i'）。$attendance：本日の勤怠。$today：今日
// 戻り値：['errors' => [項目 => 文], 'overtimeEndAt' => 日時]
function validateOvertime(array $input, ?array $attendance, string $today, string $standardEnd): array {
    if (!overtimeAvailable($attendance, $standardEnd)) {
        return ['errors' => ['form' => '退勤済みで、退勤が標準終了時刻より後の場合に申請できます。'], 'overtimeEndAt' => null];
    }
    $value = inputString($input, 'overtime_end');
    $end = parseRequestTime($value, $today);
    if ($end === null) {
        return ['errors' => ['overtime_end' => '残業の終了時刻を入力してください。'], 'overtimeEndAt' => null];
    }
    if ($end === false) {
        return ['errors' => ['overtime_end' => '時刻の形が正しくありません。'], 'overtimeEndAt' => null];
    }
    if ($end <= $today . ' ' . $standardEnd) {
        return ['errors' => ['overtime_end' => '標準終了時刻（' . substr($standardEnd, 0, 5) . '）より後の時刻を指定してください。'], 'overtimeEndAt' => null];
    }
    if ($end > $attendance['check_out_at']) {
        return ['errors' => ['overtime_end' => '退勤時刻（' . formatClock($attendance['check_out_at']) . '）を超える時刻は指定できません。'], 'overtimeEndAt' => null];
    }
    return ['errors' => [], 'overtimeEndAt' => $end];
}

// 一意制約の違反（BR-002：申請中の同じ申請がある）か
function isDuplicateError(PDOException $e): bool {
    return ($e->errorInfo[1] ?? null) === 1062;
}

// 修正・新規登録の申請を受け付ける。戻り値：['ok' => true, 'message' => …] か ['ok' => false, 'errors' => […]]
function submitCorrectionRequest(PDO $pdo, array $user, array $input, int $now): array {
    $targetDate = inputString($input, 'target_date');
    $current = isValidDate($targetDate) ? fetchAttendance($pdo, $user['employee_id'], $targetDate) : null;
    $checked = validateCorrection($input, $current, date('Y-m-d H:i:s', $now), $user['joined_on']);
    if ($checked['errors'] !== []) {
        return ['ok' => false, 'errors' => $checked['errors']];
    }
    try {
        insertCorrectionRequest($pdo, $user['employee_id'], $checked['targetDate'], $checked['requested'], currentRequestTimes($current), $checked['reason']);
    } catch (PDOException $e) {
        if (!isDuplicateError($e)) {
            throw $e;
        }
        return ['ok' => false, 'errors' => ['form' => REQUEST_DUPLICATE_CORRECTION]];
    }
    return ['ok' => true, 'message' => '申請を受け付けました。承認されると勤怠に反映されます。'];
}

// 簡単残業申請を受け付ける。戻り値は submitCorrectionRequest() と同じ形
function submitOvertimeRequest(PDO $pdo, array $user, array $input, int $now, string $standardEnd): array {
    $today = workDateOf($now);
    $attendance = fetchAttendance($pdo, $user['employee_id'], $today);
    $checked = validateOvertime($input, $attendance, $today, $standardEnd);
    if ($checked['errors'] !== []) {
        return ['ok' => false, 'errors' => $checked['errors']];
    }
    try {
        insertOvertimeRequest($pdo, $user['employee_id'], $today, $checked['overtimeEndAt'], $attendance['overtime_end_at'] ?? null);
    } catch (PDOException $e) {
        if (!isDuplicateError($e)) {
            throw $e;
        }
        return ['ok' => false, 'errors' => ['form' => REQUEST_DUPLICATE_OVERTIME]];
    }
    return ['ok' => true, 'message' => '残業申請を受け付けました（〜' . formatClock($checked['overtimeEndAt']) . '・申請中）'];
}

// 申請の種別の名前（残業／新規登録（申請した時点で記録が無かった）／修正）
function requestTypeLabel(array $request): string {
    if ($request['request_type'] === 'overtime') {
        return '残業';
    }
    foreach (array_keys(REQUEST_TIME_FIELDS) as $field) {
        if ($request['original_' . $field] !== null) {
            return '修正';
        }
    }
    return '新規登録';
}

// 変わった項目だけを「変更前 → 申請値」にする。戻り値：[['label' => '退勤', 'before' => '17:30', 'after' => '18:45'], …]
function requestChanges(array $request): array {
    $fields = $request['request_type'] === 'overtime' ? ['overtime_end_at' => '残業終了'] : REQUEST_TIME_FIELDS;
    $changes = [];
    foreach ($fields as $field => $label) {
        $before = $request['original_' . $field];
        $after = $request['requested_' . $field];
        if ($before !== $after) {
            $changes[] = [
                'label' => $label,
                'before' => $before === null ? 'なし' : formatClock($before),
                'after' => $after === null ? 'なし' : formatClock($after),
            ];
        }
    }
    return $changes;
}

// 画面に出す申請の内容
function requestView(array $request): array {
    return [
        'id' => (int) $request['id'],
        'type' => requestTypeLabel($request),
        'status' => $request['status'],
        'statusLabel' => REQUEST_STATUS_LABELS[$request['status']],
        'createdAt' => date('Y/n/j H:i', strtotime($request['created_at'])),
        'changes' => requestChanges($request),
        'reason' => $request['reason'] ?? '',
        'reviewComment' => $request['review_comment'] ?? '',
        'canCancel' => $request['status'] === 'pending',
    ];
}

// カレンダーに出すバッジの状態：申請中があれば申請中、無ければ最新の申請の状態。申請が無ければ null
// $requests：その日の申請（古い順）
function requestBadgeStatus(array $requests): ?string {
    if ($requests === []) {
        return null;
    }
    foreach ($requests as $request) {
        if ($request['status'] === 'pending') {
            return 'pending';
        }
    }
    return $requests[count($requests) - 1]['status'];
}

// その日に、申請中の修正・新規登録があるか
function hasPendingCorrection(array $requests): bool {
    foreach ($requests as $request) {
        if ($request['request_type'] === 'correction' && $request['status'] === 'pending') {
            return true;
        }
    }
    return false;
}

// その日の修正・新規登録が申請中か（申請中は、メイン画面の打刻を止める。D-063）
function isCorrectionPending(PDO $pdo, string $employeeId, string $date): bool {
    return hasPendingCorrection(fetchMonthlyRequests($pdo, $employeeId, $date, $date));
}

// 自分の申請中の申請を取り消す（削除する。D-063）。戻り値は submitCorrectionRequest() と同じ形
function submitCancelRequest(PDO $pdo, array $user, array $input): array {
    $requestId = inputString($input, 'request_id');
    if (!preg_match('/\A[1-9]\d{0,18}\z/', $requestId)) {
        return ['ok' => false, 'errors' => ['form' => '取り消す申請が正しくありません。画面を読み込み直してください。']];
    }
    if (!deletePendingRequest($pdo, (int) $requestId, $user['employee_id'])) {
        return ['ok' => false, 'errors' => ['form' => 'この申請は、すでに承認・却下されたか、取り消されています。画面を読み込み直してください。']];
    }
    return ['ok' => true, 'message' => '申請を取り消しました。'];
}

