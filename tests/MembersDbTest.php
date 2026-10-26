<?php
// 社員の勤怠の DB を使うテスト：一覧の取得（fetchMemberAttendances()：対象・LEFT JOIN の ON・並び順）と、在籍状態（fetchEmployeeProfile()）
// テスト用の DB を使う。環境変数 ATTENDANCE_TEST_DSN が無いときは、飛ばす（WSL では実行しなくてよい）
//   例：ATTENDANCE_TEST_DSN='mysql:host=localhost;dbname=attendance_test;charset=utf8mb4' ATTENDANCE_TEST_USER=root ATTENDANCE_TEST_PASSWORD='' composer test
// テストのたびに、テスト用の DB の表を作り直す（docs/db/schema.sql と docs/db/test_data.sql を使う）。本番の DB を指定しないこと

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/members.php';

final class MembersDbTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $dsn = getenv('ATTENDANCE_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('ATTENDANCE_TEST_DSN が無いため、DB のテストは飛ばす');
        }
        $this->pdo = new PDO($dsn, getenv('ATTENDANCE_TEST_USER') ?: 'root', getenv('ATTENDANCE_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+09:00'");
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $this->pdo->exec('DROP TABLE `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (['schema.sql', 'test_data.sql'] as $file) {
            $sql = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../docs/db/' . $file));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $this->pdo->exec($statement);
            }
        }
        // 退職した社員（営業部）と、営業部の2人目の社員（社員コードの順を確かめる）
        $this->pdo->exec("INSERT INTO employees (employee_id, name, email, department_id, joined_on, left_on, employment_status) VALUES
            ('2025005', '退職 太郎', 'retired@example.com', 1, '2025-04-01', '2026-09-30', 'retired'),
            ('2025006', '伊藤 三郎', 'ito@example.com', 1, '2025-04-01', NULL, 'active')");
        $this->pdo->exec("INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at) VALUES
            ('2025001', '2026-10-26', '2026-10-26 09:01:00', NULL, '2026-10-26 15:00:00', NULL),
            ('2025001', '2026-10-25', '2026-10-25 08:00:00', '2026-10-25 17:00:00', NULL, NULL),
            ('2025006', '2026-10-25', '2026-10-25 08:00:00', '2026-10-25 17:00:00', NULL, NULL),
            ('2025004', '2026-10-26', '2026-10-26 10:00:00', '2026-10-26 19:00:00', NULL, NULL),
            ('2025005', '2026-10-26', '2026-10-26 09:00:00', NULL, NULL, NULL)");
    }

    private function ids(array $rows): array {
        return array_column($rows, 'employee_id');
    }

    public function testDepartmentScope(): void {
        // 営業部の管理者（2025002）：営業部の在籍中の社員だけ。自分と退職（2025005）は出ない
        $rows = fetchMemberAttendances($this->pdo, '2025002', 1, '2026-10-26');
        $this->assertSame(['2025001', '2025006'], $this->ids($rows));

        // 画面で使う列がそろっていること
        $this->assertSame(['employee_id', 'name', 'department_name', 'check_in_at', 'check_out_at', 'break_start_at', 'break_end_at'], array_keys($rows[0]));
        $this->assertSame(['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部',
            'check_in_at' => '2026-10-26 09:01:00', 'check_out_at' => null, 'break_start_at' => '2026-10-26 15:00:00', 'break_end_at' => null], $rows[0]);

        // 打刻していない社員（2025006。ほかの日の勤怠はある）も出て、勤怠の列は null（ほかの日の勤怠が混ざらない）
        $this->assertSame([null, null, null, null], [$rows[1]['check_in_at'], $rows[1]['check_out_at'], $rows[1]['break_start_at'], $rows[1]['break_end_at']]);
    }

    public function testAllScopeAndOrder(): void {
        // 全社の管理者（2025003・開発部）：自分以外の在籍中の全員を、部署の作成順 → 社員コードの順に
        $rows = fetchMemberAttendances($this->pdo, '2025003', null, '2026-10-26');
        $this->assertSame(['2025001', '2025002', '2025006', '2025004'], $this->ids($rows));
        $this->assertSame(['営業部', '営業部', '営業部', '開発部'], array_column($rows, 'department_name'));
        $this->assertSame('2026-10-26 19:00:00', $rows[3]['check_out_at']); // ログイン不可でも在籍中なら出る

        // 前の日を指定すると、その日の勤怠になる
        $rows = fetchMemberAttendances($this->pdo, '2025003', null, '2026-10-25');
        $this->assertSame(['2026-10-25 08:00:00', null, '2026-10-25 08:00:00', null], array_column($rows, 'check_in_at'));
    }

    public function testNoMembers(): void {
        // 開発部だけの範囲で、開発部の社員を全員退職にすると、対象がいない（自分は出ない）
        $this->pdo->exec("UPDATE employees SET employment_status = 'retired', left_on = '2026-10-01' WHERE employee_id = '2025004'");
        $this->assertSame([], fetchMemberAttendances($this->pdo, '2025003', 2, '2026-10-26'));
    }

    public function testProfileHasEmploymentStatus(): void {
        $this->assertSame('active', fetchEmployeeProfile($this->pdo, '2025001')['employment_status']);
        $this->assertSame('retired', fetchEmployeeProfile($this->pdo, '2025005')['employment_status']);

        // 一覧の対象と、詳細を見てよい社員が同じであること
        $manager = ['employee_id' => '2025002', 'role' => ROLE_MANAGER, 'approval_scope' => 'department', 'department_id' => 1];
        $viewable = [];
        foreach (['2025001', '2025002', '2025003', '2025004', '2025005', '2025006'] as $id) {
            if (canViewMember($manager, fetchEmployeeProfile($this->pdo, $id))) {
                $viewable[] = $id;
            }
        }
        $this->assertSame($this->ids(fetchMemberAttendances($this->pdo, '2025002', 1, '2026-10-26')), $viewable);
    }
}
