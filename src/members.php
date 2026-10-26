<?php
// 社員の勤怠（D-070）：管理者が、対象社員の今日の勤怠の一覧と、1人ずつの月の記録を見る
// 対象社員は承認範囲と同じ（自分の部署／全社）。自分と退職した社員は対象にしない。見るだけ（申請・修正・取り消しはできない）

include_once(__DIR__ . '/approvals.php');
include_once(__DIR__ . '/records.php');
include_once(__DIR__ . '/queries/employees.php');

// 見られない社員（対象外・存在しない・自分・退職）のときに出す文。どの理由かは区別しない
const MEMBER_NOT_VIEWABLE_MESSAGE = 'この社員の勤怠は表示できません。';

// この管理者が、この社員の勤怠を見られるか（$profile は fetchEmployeeProfile() の行）
// 一覧（fetchMemberAttendances()）の対象と同じ条件：管理者、自分でない、在籍中、承認範囲（部署／全社）
function canViewMember(array $user, array $profile): bool {
    if ($user['role'] !== ROLE_MANAGER || $profile['employee_id'] === $user['employee_id']) {
        return false;
    }
    // 在籍状態を取得していない（列が無い）ときも、見せない
    if (($profile['employment_status'] ?? null) !== 'active') {
        return false;
    }
    return $user['approval_scope'] === 'all' || (int) $profile['department_id'] === $user['department_id'];
}

// 一覧の1行の表示（$row は fetchMemberAttendances() の行）
// $workDate：今日（'2026-10-26'）。$settings：勤務設定（労働時間の計算で、昼休憩を引くため）
// 状態・時刻はメイン画面と同じ作り方。労働時間は退勤済みの人だけ（ほかは '--'）
function memberRowView(array $row, string $workDate, array $settings): array {
    $attendance = [
        'check_in_at' => $row['check_in_at'],
        'check_out_at' => $row['check_out_at'],
        'break_start_at' => $row['break_start_at'],
        'break_end_at' => $row['break_end_at'],
    ];
    $view = attendanceView($attendance);
    $minutes = dailyWorkMinutes($attendance, $workDate, $settings);
    return [
        'employeeId' => $row['employee_id'],
        'name' => $row['name'],
        'department' => $row['department_name'],
        'status' => $view['status'],
        'statusLabel' => $view['statusLabel'],
        'checkIn' => $view['checkIn'],
        'checkOut' => $view['checkOut'],
        'break' => $view['break'],
        'workTime' => $minutes === null ? '--' : formatMinutes($minutes),
    ];
}
