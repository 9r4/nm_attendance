<?php
// 承認（src/approvals.php）のテスト：承認範囲の判断、一覧の表示の組み立て。DB は使わない

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/approvals.php';

final class ApprovalsTest extends TestCase {
    private static function user(string $id, string $role, string $scope, ?int $departmentId): array {
        return ['employee_id' => $id, 'role' => $role, 'approval_scope' => $scope, 'department_id' => $departmentId];
    }

    public function testCanReview(): void {
        $salesManager = self::user('2025002', ROLE_MANAGER, 'department', 1);
        $allManager = self::user('2025003', ROLE_MANAGER, 'all', 2);
        $salesRequest = ['employee_id' => '2025001', 'department_id' => 1];
        $devRequest = ['employee_id' => '2025009', 'department_id' => '2'];

        $this->assertTrue(canReview($salesManager, $salesRequest));            // 部署：同じ部署
        $this->assertFalse(canReview($salesManager, $devRequest));             // 部署：ほかの部署
        $this->assertTrue(canReview($allManager, $salesRequest));              // 全社
        $this->assertTrue(canReview($allManager, $devRequest));                // 全社（部署 ID が文字列で来ても）
        $this->assertFalse(canReview($salesManager, ['employee_id' => '2025002', 'department_id' => 1])); // 自分の申請
        $this->assertFalse(canReview($allManager, ['employee_id' => '2025003', 'department_id' => 2]));
        $this->assertFalse(canReview(self::user('2025001', ROLE_EMPLOYEE, 'all', 1), $devRequest));       // 社員
        $this->assertFalse(canReview(self::user('sysadmin', ROLE_SYSTEM_ADMIN, 'all', null), $devRequest)); // システム管理者
    }

    public function testApprovalDepartmentId(): void {
        $this->assertSame(1, approvalDepartmentId(self::user('2025002', ROLE_MANAGER, 'department', 1)));
        $this->assertNull(approvalDepartmentId(self::user('2025003', ROLE_MANAGER, 'all', 2)));
        $this->expectException(RuntimeException::class);
        approvalDepartmentId(self::user('2025002', ROLE_MANAGER, 'department', null));
    }

    private static function row(array $override): array {
        $row = [
            'id' => '7', 'employee_id' => '2025001', 'target_date' => '2026-10-14', 'request_type' => 'correction',
            'reason' => null, 'created_at' => '2026-10-15 09:05:00', 'name' => '山田 太郎', 'department_name' => '営業部',
        ];
        foreach (['check_in_at', 'check_out_at', 'break_start_at', 'break_end_at', 'overtime_end_at'] as $field) {
            $row['requested_' . $field] = null;
            $row['original_' . $field] = null;
        }
        return array_merge($row, $override);
    }

    public function testItemViewForPending(): void {
        $view = approvalItemView(self::row([
            'requested_check_in_at' => '2026-10-14 09:00:00', 'requested_check_out_at' => '2026-10-14 18:30:00',
            'original_check_in_at' => '2026-10-14 09:00:00', 'original_check_out_at' => '2026-10-14 17:45:00',
            'reason' => '打刻忘れ', 'current_check_out_at' => '2026-10-14 17:45:00',
        ]), '18:00:00');
        $this->assertSame(7, $view['id']);
        $this->assertSame(['山田 太郎', '営業部', '修正', '10/14（水）', '打刻忘れ', '10/15 09:05', ''], [$view['name'], $view['department'], $view['type'], $view['targetDate'], $view['reason'], $view['createdAt'], $view['reference']]);
        $this->assertSame([['label' => '退勤', 'before' => '17:45', 'after' => '18:30']], $view['changes']);
        $this->assertArrayNotHasKey('status', $view);

        $overtime = approvalItemView(self::row(['request_type' => 'overtime', 'requested_overtime_end_at' => '2026-10-14 19:30:00', 'current_check_out_at' => '2026-10-14 19:45:00']), '18:00:00');
        $this->assertSame('残業', $overtime['type']);
        $this->assertSame([['label' => '残業終了', 'before' => 'なし', 'after' => '19:30']], $overtime['changes']);
        $this->assertSame('標準終了 18:00・今の退勤 19:45', $overtime['reference']);

        $noAttendance = approvalItemView(self::row(['request_type' => 'overtime', 'requested_overtime_end_at' => '2026-10-14 19:30:00', 'current_check_out_at' => null]), '18:00:00');
        $this->assertSame('標準終了 18:00・今の退勤 --:--', $noAttendance['reference']);

        $new = approvalItemView(self::row(['requested_check_in_at' => '2026-10-14 09:00:00']), '18:00:00');
        $this->assertSame('新規登録', $new['type']);
    }

    public function testItemViewForProcessed(): void {
        $view = approvalItemView(self::row([
            'requested_check_in_at' => '2026-10-14 09:00:00', 'status' => 'rejected', 'review_comment' => '根拠が不明',
            'reviewed_at' => '2026-10-15 10:30:00', 'reviewer_name' => '佐藤 花子',
        ]), '18:00:00');
        $this->assertSame(['rejected', '却下', '佐藤 花子', '10/15 10:30', '根拠が不明'], [$view['status'], $view['statusLabel'], $view['reviewerName'], $view['reviewedAt'], $view['reviewComment']]);
        $approved = approvalItemView(self::row(['status' => 'approved', 'review_comment' => null, 'reviewed_at' => '2026-10-15 10:30:00', 'reviewer_name' => null]), '18:00:00');
        $this->assertSame(['承認済み', '', ''], [$approved['statusLabel'], $approved['reviewerName'], $approved['reviewComment']]);
    }
}
