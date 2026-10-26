<?php
// 社員の勤怠（src/members.php）のテスト：見てよい社員かの判断、一覧の1行の表示、詳細の「見るだけ」の値。DB は使わない

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/members.php';

final class MembersTest extends TestCase {
    private const SETTINGS = ['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00'];

    private static function user(string $id, string $role, string $scope, ?int $departmentId): array {
        return ['employee_id' => $id, 'role' => $role, 'approval_scope' => $scope, 'department_id' => $departmentId];
    }

    private static function profile(string $id, $departmentId, string $status = 'active'): array {
        return ['employee_id' => $id, 'name' => '山田 太郎', 'department_name' => '営業部', 'joined_on' => '2025-04-01', 'department_id' => $departmentId, 'employment_status' => $status];
    }

    public function testCanViewMember(): void {
        $salesManager = self::user('2025002', ROLE_MANAGER, 'department', 1);
        $allManager = self::user('2025003', ROLE_MANAGER, 'all', 2);

        $this->assertTrue(canViewMember($salesManager, self::profile('2025001', 1)));          // 部署：同じ部署
        $this->assertTrue(canViewMember($salesManager, self::profile('2025001', '1')));        // 部署 ID が文字列で来ても
        $this->assertFalse(canViewMember($salesManager, self::profile('2025004', 2)));         // 部署：ほかの部署
        $this->assertTrue(canViewMember($allManager, self::profile('2025001', 1)));            // 全社
        $this->assertTrue(canViewMember($allManager, self::profile('2025002', 1)));            // 全社：ほかの管理者も見られる
        $this->assertFalse(canViewMember($salesManager, self::profile('2025002', 1)));         // 自分
        $this->assertFalse(canViewMember($allManager, self::profile('2025003', 2)));
        $this->assertFalse(canViewMember($allManager, self::profile('2025001', 1, 'retired'))); // 退職
        $this->assertFalse(canViewMember(self::user('2025001', ROLE_EMPLOYEE, 'all', 1), self::profile('2025004', 1)));       // 社員
        $this->assertFalse(canViewMember(self::user('sysadmin', ROLE_SYSTEM_ADMIN, 'all', null), self::profile('2025001', 1))); // システム管理者

        // 在籍状態の列が無い（取得していない）ときは、見せない
        $profile = self::profile('2025001', 1);
        unset($profile['employment_status']);
        $this->assertFalse(canViewMember($allManager, $profile));
    }

    private static function row(array $times): array {
        return $times + ['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部',
            'check_in_at' => null, 'check_out_at' => null, 'break_start_at' => null, 'break_end_at' => null];
    }

    public static function rowCases(): array {
        return [
            '未出勤' => [[], 'not_started', '未出勤', '--:--', '--:--', '--:--', '--'],
            '勤務中' => [['check_in_at' => '2026-10-26 09:01:00'], 'working', '勤務中', '09:01', '--:--', '--:--', '--'],
            '休憩中' => [['check_in_at' => '2026-10-26 09:01:00', 'break_start_at' => '2026-10-26 15:00:00'], 'on_break', '休憩中', '09:01', '--:--', '15:00–休憩中', '--'],
            '休憩のあと勤務中' => [['check_in_at' => '2026-10-26 09:01:00', 'break_start_at' => '2026-10-26 15:00:00', 'break_end_at' => '2026-10-26 15:15:00'], 'working', '勤務中', '09:01', '--:--', '15:00–15:15', '--'],
            // 9:00〜18:30 から昼休憩 1 時間と休憩 15 分を引く
            '退勤済み' => [['check_in_at' => '2026-10-26 09:00:00', 'check_out_at' => '2026-10-26 18:30:00', 'break_start_at' => '2026-10-26 15:00:00', 'break_end_at' => '2026-10-26 15:15:00'], 'finished', '退勤済み', '09:00', '18:30', '15:00–15:15', '8時間15分'],
        ];
    }

    #[DataProvider('rowCases')]
    public function testMemberRowView(array $times, string $status, string $label, string $in, string $out, string $break, string $work): void {
        $view = memberRowView(self::row($times), '2026-10-26', self::SETTINGS);
        $this->assertSame([
            'employeeId' => '2025001', 'name' => '山田 太郎', 'department' => '営業部',
            'status' => $status, 'statusLabel' => $label, 'checkIn' => $in, 'checkOut' => $out, 'break' => $break, 'workTime' => $work,
        ], $view);
    }

    public function testCalendarDaysReadOnly(): void {
        $month = resolveMonth('2026-10', '2026-10-26', '2025-04-01');
        $attendances = [['work_date' => '2026-10-14', 'check_in_at' => '2026-10-14 09:00:00', 'check_out_at' => '2026-10-14 18:00:00', 'break_start_at' => null, 'break_end_at' => null]];
        $requests = [[
            'id' => '3', 'employee_id' => '2025001', 'target_date' => '2026-10-14', 'request_type' => 'correction', 'status' => 'pending',
            'requested_check_in_at' => '2026-10-14 08:30:00', 'requested_check_out_at' => '2026-10-14 18:00:00', 'requested_break_start_at' => null, 'requested_break_end_at' => null, 'requested_overtime_end_at' => null,
            'original_check_in_at' => '2026-10-14 09:00:00', 'original_check_out_at' => '2026-10-14 18:00:00', 'original_break_start_at' => null, 'original_break_end_at' => null, 'original_overtime_end_at' => null,
            'reason' => '打刻忘れ', 'review_comment' => null, 'created_at' => '2026-10-15 09:00:00',
        ]];
        $calendar = buildCalendar($month, $attendances, self::SETTINGS, '2026-10-26', $requests, '2025-04-01');

        // 記録表示（自分の分）：申請できる日・申請中の案内・取り消しは、今までどおり
        $days = calendarDays($calendar);
        $this->assertCount(31, $days);
        $this->assertTrue($days['2026-10-14']['pendingCorrection']);
        $this->assertFalse($days['2026-10-14']['canRequest']);
        $this->assertTrue($days['2026-10-14']['requests'][0]['canCancel']);
        $this->assertTrue($days['2026-10-13']['canRequest']);
        $this->assertFalse($days['2026-10-27']['canRequest']); // 未来の日

        // 見るだけ（社員の勤怠の詳細）：申請・取り消し・申請中の案内は出さない。記録と申請の内容は同じ
        $readOnly = calendarDays($calendar, true);
        $this->assertSame(array_keys($days), array_keys($readOnly));
        foreach ($readOnly as $day) {
            $this->assertFalse($day['canRequest']);
            $this->assertFalse($day['pendingCorrection']);
            foreach ($day['requests'] as $request) {
                $this->assertFalse($request['canCancel']);
            }
        }
        $this->assertSame('09:00', $readOnly['2026-10-14']['checkIn']);
        $this->assertSame($days['2026-10-14']['requests'][0]['changes'], $readOnly['2026-10-14']['requests'][0]['changes']);
        $this->assertSame('打刻忘れ', $readOnly['2026-10-14']['requests'][0]['reason']);
    }
}
