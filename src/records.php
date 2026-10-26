<?php
// 記録表示（月カレンダー）の組み立てと、労働時間の計算（FR-004、D-049、D-061）

include_once(__DIR__ . '/holidays.php');
include_once(__DIR__ . '/attendance.php');
include_once(__DIR__ . '/requests.php');

// 表示する月を決める
// $requested：URL の month（例：'2026-09'）。$today：今日（'2026-10-15'）。$joinedOn：入社日（'2025-04-01'）
// 前の月は入社した月まで、次の月は今月まで（D-061）。指定なし・正しくない形・範囲外は、今月
// 戻り値：['month' => '2026-09', 'label' => '2026年9月', 'first' => '2026-09-01', 'last' => '2026-09-30', 'prev' => '2026-08' または null, 'next' => '2026-10' または null]
function resolveMonth(?string $requested, string $today, string $joinedOn): array {
    $current = substr($today, 0, 7);
    // 入社日が未来の場合や、祝日を計算できない年より前の場合も、表示できる範囲に収める
    $earliest = max(min(substr($joinedOn, 0, 7), $current), sprintf('%04d-01', HOLIDAYS_MIN_YEAR));

    $month = $current;
    if ($requested !== null && preg_match('/\A(\d{4})-(\d{2})\z/', $requested, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12
        && $requested >= $earliest && $requested <= $current) {
        $month = $requested;
    }

    $first = $month . '-01';
    $prev = date('Y-m', strtotime($first . ' -1 month'));
    $next = date('Y-m', strtotime($first . ' +1 month'));
    return [
        'month' => $month,
        'label' => (int) substr($month, 0, 4) . '年' . (int) substr($month, 5, 2) . '月',
        'first' => $first,
        'last' => date('Y-m-t', strtotime($first)),
        'prev' => $prev >= $earliest ? $prev : null,
        'next' => $next <= $current ? $next : null,
    ];
}

// 1日の労働時間（分）＝ 退勤 − 出勤 − 昼休憩 − 休憩（D-049・D-061）
// 出勤か退勤が無い日は null。休憩は開始と終了の両方があるときだけ引く。昼休憩と休憩が重なる分は1回だけ引く
// $settings：勤務設定（lunch_start、lunch_end。'12:00:00' の形）
function dailyWorkMinutes(array $attendance, string $workDate, array $settings): ?int {
    if ($attendance['check_in_at'] === null || $attendance['check_out_at'] === null) {
        return null;
    }
    $start = strtotime($attendance['check_in_at']);
    $end = strtotime($attendance['check_out_at']);
    if ($end <= $start) {
        return 0;
    }

    // 引く時間帯（勤務の時間帯と重なる部分だけ）
    $rests = [[strtotime($workDate . ' ' . $settings['lunch_start']), strtotime($workDate . ' ' . $settings['lunch_end'])]];
    if ($attendance['break_start_at'] !== null && $attendance['break_end_at'] !== null) {
        $rests[] = [strtotime($attendance['break_start_at']), strtotime($attendance['break_end_at'])];
    }
    $clipped = [];
    foreach ($rests as [$restStart, $restEnd]) {
        $restStart = max($restStart, $start);
        $restEnd = min($restEnd, $end);
        if ($restStart < $restEnd) {
            $clipped[] = [$restStart, $restEnd];
        }
    }

    // 重なりをまとめてから合計する（二重に引かない）
    usort($clipped, fn ($a, $b) => $a[0] <=> $b[0]);
    $restSeconds = 0;
    $coveredUntil = $start;
    foreach ($clipped as [$restStart, $restEnd]) {
        $restStart = max($restStart, $coveredUntil);
        if ($restStart < $restEnd) {
            $restSeconds += $restEnd - $restStart;
            $coveredUntil = $restEnd;
        }
    }

    return intdiv($end - $start - $restSeconds, 60);
}

// 分を「○時間○分」にする（例：9630 → '160時間30分'）
function formatMinutes(int $minutes): string {
    return intdiv($minutes, 60) . '時間' . sprintf('%02d', $minutes % 60) . '分';
}

// 月カレンダーを組み立てる
// $attendances：fetchMonthlyAttendances() の戻り値。$requests：fetchMonthlyRequests() の戻り値（古い順）
// $today：今日（'2026-10-15'）。$joinedOn：入社日（これより前の日は、申請できない）
// 戻り値：['weeks' => 週ごとに7つ（日〜土）のセル（月の外は null）, 'workDays' => 出勤日数, 'totalMinutes' => 総労働時間（分）]
function buildCalendar(array $month, array $attendances, array $settings, string $today, array $requests, string $joinedOn): array {
    $byDate = [];
    foreach ($attendances as $row) {
        $byDate[$row['work_date']] = $row;
    }
    $requestsByDate = [];
    foreach ($requests as $request) {
        $requestsByDate[$request['target_date']][] = $request;
    }
    $holidays = japaneseHolidays((int) substr($month['first'], 0, 4));
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

    $cells = array_fill(0, (int) date('w', strtotime($month['first'])), null);
    $workDays = 0;
    $totalMinutes = 0;
    for ($date = $month['first']; $date <= $month['last']; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
        $weekday = (int) date('w', strtotime($date));
        $row = $byDate[$date] ?? null;
        $minutes = $row !== null ? dailyWorkMinutes($row, $date, $settings) : null;
        if ($row !== null && $row['check_in_at'] !== null) {
            $workDays++;
        }
        $totalMinutes += $minutes ?? 0;

        $view = attendanceView($row);
        // 過去の日で休憩終了が無いときは「休憩中」ではなく、終了を空けて表示する
        $break = $view['break'];
        if ($date !== $today && $row !== null && $row['break_start_at'] !== null && $row['break_end_at'] === null) {
            $break = formatClock($row['break_start_at']) . '–';
        }
        $dayRequests = $requestsByDate[$date] ?? [];
        $badge = requestBadgeStatus($dayRequests);
        $pendingCorrection = hasPendingCorrection($dayRequests);
        // 申請ウィンドウに入れておく、今の値（'H:i'。無ければ空欄）
        $values = [];
        foreach (['check_in' => 'check_in_at', 'check_out' => 'check_out_at', 'break_start' => 'break_start_at', 'break_end' => 'break_end_at'] as $name => $field) {
            $values[$name] = ($row[$field] ?? null) === null ? '' : formatClock($row[$field]);
        }
        $cells[] = [
            'date' => $date,
            'day' => (int) substr($date, 8, 2),
            'label' => (int) substr($date, 5, 2) . '月' . (int) substr($date, 8, 2) . '日（' . $weekdays[$weekday] . '）',
            'weekday' => $weekday,
            'holiday' => $holidays[$date] ?? '',
            'isRestDay' => $weekday === 0 || $weekday === 6 || isset($holidays[$date]),
            'isToday' => $date === $today,
            'hasRecord' => $row !== null && ($row['check_in_at'] !== null || $row['check_out_at'] !== null),
            'checkIn' => $view['checkIn'],
            'checkOut' => $view['checkOut'],
            'break' => $break,
            'workTime' => $minutes === null ? '--' : formatMinutes($minutes),
            'values' => $values,
            'badge' => $badge,
            'badgeLabel' => $badge === null ? '' : REQUEST_STATUS_LABELS[$badge],
            'requests' => array_map('requestView', $dayRequests),
            'isFuture' => $date > $today,
            'pendingCorrection' => $pendingCorrection,
            'canRequest' => $date >= $joinedOn && $date <= $today && !$pendingCorrection,
        ];
    }
    while (count($cells) % 7 !== 0) {
        $cells[] = null;
    }

    return [
        'weeks' => array_chunk($cells, 7),
        'workDays' => $workDays,
        'totalMinutes' => $totalMinutes,
    ];
}

// 日付を押したときに表示する、その日の記録と申請（js/records.js が使う。画面に data-days で埋め込む）
// $calendar：buildCalendar() の戻り値。戻り値：日付（'2026-10-15'）ごとの値
// $readOnly：見るだけ（社員の勤怠の詳細。D-070）なら、申請・修正・取り消しのボタンと、申請中の案内を出さないようにする
function calendarDays(array $calendar, bool $readOnly = false): array {
    $days = [];
    foreach ($calendar['weeks'] as $week) {
        foreach ($week as $cell) {
            if ($cell === null) {
                continue;
            }
            $day = array_intersect_key($cell, array_flip([
                'label', 'holiday', 'hasRecord', 'checkIn', 'checkOut', 'break', 'workTime',
                'values', 'requests', 'isFuture', 'pendingCorrection', 'canRequest',
            ]));
            if ($readOnly) {
                $day['canRequest'] = false;
                $day['pendingCorrection'] = false;
                $day['requests'] = array_map(fn ($request) => array_replace($request, ['canCancel' => false]), $day['requests']);
            }
            $days[$cell['date']] = $day;
        }
    }
    return $days;
}
