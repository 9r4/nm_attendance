<?php
// 出退勤・休憩（メイン画面）の判断と処理
// 押せるかどうかの判断は、すべてここで行う（画面のボタンの状態は、表示のためだけ）

include_once(__DIR__ . '/queries/attendances.php');

// ボタン（操作）の名前と、画面に出す名前
const ATTENDANCE_ACTION_LABELS = [
    'check_in' => '出勤',
    'check_out' => '退勤',
    'break_start' => '休憩開始',
    'break_end' => '休憩終了',
];

// 状態と、状態バッジに出す名前
const ATTENDANCE_STATUS_LABELS = [
    'not_started' => '未出勤',
    'working' => '勤務中',
    'on_break' => '休憩中',
    'finished' => '退勤済み',
];

// 今日の修正・新規登録が申請中のときの案内（D-063）
const PUNCH_LOCKED_MESSAGE = '本日の修正申請が申請中のため、打刻できません。打刻する場合は、記録表示で申請を取り消してください。';

// 今日の残業申請が申請中のときの、簡単残業申請のボタンの下の案内（D-069）
const OVERTIME_PENDING_MESSAGE = '本日分の残業申請は申請中です。';

// 押したときの扱い
const ACTION_RECORD = 'record';            // そのまま記録する
const ACTION_OVERWRITE = 'overwrite';      // 上書き確認のあとで記録する
const ACTION_UNAVAILABLE = 'unavailable';  // 押せない

// 勤務日（押した日。0時で切り替え）。例：'2026-10-15'
function workDateOf(int $timestamp): string {
    return date('Y-m-d', $timestamp);
}

// 記録する時刻（分単位。秒は切り捨て）。例：09:01:45 → '2026-10-15 09:01:00'
function minuteTimestamp(int $timestamp): string {
    return date('Y-m-d H:i:00', $timestamp);
}

// 'Y-m-d H:i:s' の日時を 'H:i' にする（NULL は '--:--'）
function formatClock(?string $datetime): string {
    return $datetime === null ? '--:--' : substr($datetime, 11, 5);
}

// 休憩中か（休憩開始があり、休憩終了と退勤がまだ）
function isOnBreak(?array $attendance): bool {
    return $attendance !== null
        && $attendance['break_start_at'] !== null
        && $attendance['break_end_at'] === null
        && $attendance['check_out_at'] === null;
}

// 状態（not_started／working／on_break／finished）
function attendanceStatus(?array $attendance): string {
    if ($attendance !== null && $attendance['check_out_at'] !== null) {
        return 'finished';
    }
    if ($attendance === null || $attendance['check_in_at'] === null) {
        return 'not_started';
    }
    return isOnBreak($attendance) ? 'on_break' : 'working';
}

// ボタンを押したときの扱い（ACTION_RECORD／ACTION_OVERWRITE／ACTION_UNAVAILABLE）
function actionMode(?array $attendance, string $action): string {
    $checkIn = $attendance['check_in_at'] ?? null;
    $checkOut = $attendance['check_out_at'] ?? null;
    $breakStart = $attendance['break_start_at'] ?? null;

    switch ($action) {
        case 'check_in':
            // 休憩・退勤のあとに出勤を変えると、時刻の前後が逆になるため押せない（D-059）
            if ($checkOut !== null || $breakStart !== null) {
                return ACTION_UNAVAILABLE;
            }
            return $checkIn === null ? ACTION_RECORD : ACTION_OVERWRITE;
        case 'check_out':
            if ($checkIn === null || isOnBreak($attendance)) {
                return ACTION_UNAVAILABLE;
            }
            return $checkOut === null ? ACTION_RECORD : ACTION_OVERWRITE;
        case 'break_start':
            // 休憩は1日1回（D-007・D-042）
            return ($checkIn !== null && $checkOut === null && $breakStart === null) ? ACTION_RECORD : ACTION_UNAVAILABLE;
        case 'break_end':
            return isOnBreak($attendance) ? ACTION_RECORD : ACTION_UNAVAILABLE;
    }
    throw new InvalidArgumentException('知らない操作です: ' . $action);
}

// 簡単残業申請を押せるか：その日の退勤が記録済みで、標準終了時刻（'18:00:00'）より後（FR-008、D-062）
function overtimeAvailable(?array $attendance, string $standardEnd): bool {
    $checkOut = $attendance['check_out_at'] ?? null;
    return $checkOut !== null && substr($checkOut, 11) > $standardEnd;
}

// 画面に出す値（状態・本日の記録・押せるボタン）。メイン画面の表示と、API の応答の両方で使う
// $standardEnd（標準終了時刻 '18:00:00'）を渡すと、簡単残業申請のボタンを押せるか（overtime）も入れる
// $locked：今日の修正・新規登録が申請中で、打刻を止めているか（D-063）。true なら、出勤・退勤・休憩のボタンをすべて押せなくする
// $overtimePending：今日の残業申請が申請中か（D-069）。true なら、簡単残業申請のボタンを押せなくする（申請中の重複は DB でも断る）
function attendanceView(?array $attendance, ?string $standardEnd = null, bool $locked = false, bool $overtimePending = false): array {
    $status = attendanceStatus($attendance);
    $breakStart = $attendance['break_start_at'] ?? null;
    $breakEnd = $attendance['break_end_at'] ?? null;

    $breakLabel = '--:--';
    if ($breakStart !== null) {
        $endLabel = $breakEnd !== null ? formatClock($breakEnd) : (isOnBreak($attendance) ? '休憩中' : '');
        $breakLabel = formatClock($breakStart) . '–' . $endLabel;
    }

    $buttons = [];
    foreach (array_keys(ATTENDANCE_ACTION_LABELS) as $action) {
        $buttons[$action] = !$locked && actionMode($attendance, $action) !== ACTION_UNAVAILABLE;
    }

    if ($standardEnd !== null) {
        $buttons['overtime'] = !$overtimePending && overtimeAvailable($attendance, $standardEnd);
    }

    return [
        'status' => $status,
        'statusLabel' => ATTENDANCE_STATUS_LABELS[$status],
        'checkIn' => formatClock($attendance['check_in_at'] ?? null),
        'checkOut' => formatClock($attendance['check_out_at'] ?? null),
        'break' => $breakLabel,
        'buttons' => $buttons,
        'locked' => $locked,
        'overtimePending' => $overtimePending,
    ];
}

// ボタンを押したときの処理。$overwrite は、上書き確認で「上書きする」を押したとき true
// $standardEnd：標準終了時刻（画面に返す、簡単残業申請のボタンの判断に使う）
// $locked：今日の修正・新規登録が申請中か（D-063）。申請中は打刻しない（承認で申請の値に上書きされ、押した打刻が消えるため）
// $overtimePending：今日の残業申請が申請中か（D-069。返す表示の、簡単残業申請のボタンに使う）
// 戻り値：['result' => recorded／overwritten／confirm／unavailable／conflict, 'message' => 通知の文, 'confirm' => 確認の文（confirm のとき）, 'view' => attendanceView()]
function performAttendanceAction(PDO $pdo, string $employeeId, string $action, bool $overwrite, int $now, ?string $standardEnd = null, bool $locked = false, bool $overtimePending = false): array {
    if (!isset(ATTENDANCE_ACTION_LABELS[$action])) {
        throw new InvalidArgumentException('知らない操作です: ' . $action);
    }
    $label = ATTENDANCE_ACTION_LABELS[$action];
    $workDate = workDateOf($now);
    $time = minuteTimestamp($now);

    $attendance = fetchAttendance($pdo, $employeeId, $workDate);
    if ($locked) {
        return [
            'result' => 'unavailable',
            'message' => PUNCH_LOCKED_MESSAGE,
            'view' => attendanceView($attendance, $standardEnd, true, $overtimePending),
        ];
    }
    $mode = actionMode($attendance, $action);

    if ($mode === ACTION_UNAVAILABLE) {
        return [
            'result' => 'unavailable',
            'message' => '今は「' . $label . '」を押せません。表示を最新の状態にしました。',
            'view' => attendanceView($attendance, $standardEnd, false, $overtimePending),
        ];
    }

    if ($mode === ACTION_OVERWRITE && !$overwrite) {
        $current = $action === 'check_in' ? $attendance['check_in_at'] : $attendance['check_out_at'];
        return [
            'result' => 'confirm',
            'confirm' => [
                'title' => $label . 'を上書きしますか？',
                'body' => '本日の' . $label . 'は、すでに ' . formatClock($current) . ' で記録されています。今の時刻で上書きしますか？',
            ],
            'view' => attendanceView($attendance, $standardEnd, false, $overtimePending),
        ];
    }

    switch ($action) {
        case 'check_in':
            if ($attendance === null) {
                try {
                    insertCheckIn($pdo, $employeeId, $workDate, $time);
                } catch (PDOException $e) {
                    // 別の画面で、同時に出勤が記録された（一意制約の違反）
                    if (($e->errorInfo[1] ?? null) !== 1062) {
                        throw $e;
                    }
                    return [
                        'result' => 'conflict',
                        'message' => '別の画面で、すでに出勤が記録されています。表示を最新の状態にしました。',
                        'view' => attendanceView(fetchAttendance($pdo, $employeeId, $workDate), $standardEnd, false, $overtimePending),
                    ];
                }
            } else {
                updateCheckIn($pdo, $employeeId, $workDate, $time);
            }
            break;
        case 'check_out':
            updateCheckOut($pdo, $employeeId, $workDate, $time);
            break;
        case 'break_start':
            updateBreakStart($pdo, $employeeId, $workDate, $time);
            break;
        case 'break_end':
            updateBreakEnd($pdo, $employeeId, $workDate, $time);
            break;
    }

    $clock = formatClock($time);
    if ($mode === ACTION_OVERWRITE) {
        $result = 'overwritten';
        $message = $label . 'を ' . $clock . ' に上書きしました';
    } else {
        $result = 'recorded';
        $messages = [
            'check_in' => '出勤を記録しました',
            'check_out' => '退勤を記録しました',
            'break_start' => '休憩を開始しました',
            'break_end' => '休憩を終了しました',
        ];
        $message = $messages[$action] . '（' . $clock . '）';
    }

    return [
        'result' => $result,
        'message' => $message,
        'view' => attendanceView(fetchAttendance($pdo, $employeeId, $workDate), $standardEnd, false, $overtimePending),
    ];
}
