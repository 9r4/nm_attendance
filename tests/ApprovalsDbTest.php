<?php
// 承認の DB を使うテスト：一覧の取得（承認範囲・LEFT JOIN・件数）、承認・却下の反映、トランザクション（失敗したら元に戻る）
// テスト用の DB を使う。環境変数 ATTENDANCE_TEST_DSN が無いときは、飛ばす（WSL では実行しなくてよい）
//   例：ATTENDANCE_TEST_DSN='mysql:host=localhost;dbname=attendance_test;charset=utf8mb4' ATTENDANCE_TEST_USER=root ATTENDANCE_TEST_PASSWORD='' composer test
// テストのたびに、テスト用の DB の表を作り直す（docs/db/schema.sql と docs/db/test_data.sql を使う）。本番の DB を指定しないこと

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/approvals.php';

final class ApprovalsDbTest extends TestCase {
    private PDO $pdo;

    // テストデータ（docs/db/test_data.sql）の管理者
    private const SALES_MANAGER = ['employee_id' => '2025002', 'role' => 'manager', 'approval_scope' => 'department', 'department_id' => null];
    private const ALL_MANAGER = ['employee_id' => '2025003', 'role' => 'manager', 'approval_scope' => 'all', 'department_id' => null];

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
        $this->pdo->exec('DROP TRIGGER IF EXISTS fail_request_update');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (['schema.sql', 'test_data.sql'] as $file) {
            $sql = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../docs/db/' . $file));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }

    private function departmentId(string $name): int {
        $stmt = $this->pdo->prepare('SELECT id FROM departments WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return (int) $stmt->fetchColumn();
    }

    private function manager(array $manager): array {
        $stmt = $this->pdo->prepare('SELECT department_id FROM employees WHERE employee_id = :id');
        $stmt->execute([':id' => $manager['employee_id']]);
        return ['department_id' => (int) $stmt->fetchColumn()] + $manager;
    }

    private function addRequest(string $employeeId, string $date, string $type, array $requested, array $original = [], string $createdAt = '2026-10-15 09:00:00'): int {
        $columns = ['employee_id' => $employeeId, 'target_date' => $date, 'request_type' => $type, 'created_at' => $createdAt];
        foreach ($requested as $field => $value) {
            $columns['requested_' . $field] = $value;
        }
        foreach ($original as $field => $value) {
            $columns['original_' . $field] = $value;
        }
        $names = array_keys($columns);
        $sql = 'INSERT INTO attendance_requests (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')';
        $this->pdo->prepare($sql)->execute(array_combine(array_map(fn ($n) => ':' . $n, $names), array_values($columns)));
        return (int) $this->pdo->lastInsertId();
    }

    private function attendance(string $employeeId, string $date): ?array {
        $stmt = $this->pdo->prepare('SELECT check_in_at, check_out_at, break_start_at, break_end_at, overtime_end_at FROM attendances WHERE employee_id = :e AND work_date = :d');
        $stmt->execute([':e' => $employeeId, ':d' => $date]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function request(int $id): array {
        return $this->pdo->query('SELECT status, reviewer_id, review_comment, reviewed_at FROM attendance_requests WHERE id = ' . $id)->fetch();
    }

    public function testPendingListScope(): void {
        $this->pdo->exec("INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at) VALUES ('2025001', '2026-10-14', '2026-10-14 09:00:00', '2026-10-14 19:45:00')");
        $sales2 = $this->addRequest('2025001', '2026-10-14', 'overtime', ['overtime_end_at' => '2026-10-14 19:30:00'], [], '2026-10-15 10:00:00');
        $sales1 = $this->addRequest('2025001', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00'], [], '2026-10-15 09:00:00');
        $own = $this->addRequest('2025002', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00'], [], '2026-10-15 11:00:00');
        $dev = $this->addRequest('2025003', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00']);
        $done = $this->addRequest('2025001', '2026-10-12', 'correction', ['check_in_at' => '2026-10-12 09:00:00']);
        $this->pdo->exec("UPDATE attendance_requests SET status = 'approved', reviewer_id = '2025003', reviewed_at = NOW() WHERE id = $done");

        // 部署（営業部）：営業部の申請だけ、自分の申請は除く、古い順
        $rows = fetchPendingRequests($this->pdo, '2025002', $this->departmentId('営業部'));
        $this->assertSame([$sales1, $sales2], array_map(fn ($r) => (int) $r['id'], $rows));
        $this->assertSame(['山田 太郎', '営業部'], [$rows[0]['name'], $rows[0]['department_name']]);
        // LEFT JOIN：勤怠が無い日は NULL、ある日は今の退勤時刻
        $this->assertNull($rows[0]['current_check_out_at']);
        $this->assertSame('2026-10-14 19:45:00', $rows[1]['current_check_out_at']);
        foreach (['requested_overtime_end_at', 'original_check_in_at', 'reason', 'created_at', 'target_date', 'request_type'] as $column) {
            $this->assertArrayHasKey($column, $rows[1]);
        }

        // 全社（2025003）：自分の申請以外の申請中すべて
        $all = fetchPendingRequests($this->pdo, '2025003', null);
        $this->assertSame([$sales1, $sales2, $own], array_map(fn ($r) => (int) $r['id'], $all));
        $this->assertNotContains($dev, array_map(fn ($r) => (int) $r['id'], $all));
    }

    public function testProcessedList(): void {
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $ids[$i] = $this->addRequest('2025001', '2026-10-0' . $i, 'correction', ['check_in_at' => '2026-10-0' . $i . ' 09:00:00']);
            $this->pdo->exec("UPDATE attendance_requests SET status = 'rejected', reviewer_id = '2025002', review_comment = 'NG', reviewed_at = '2026-10-15 10:0$i:00' WHERE id = {$ids[$i]}");
        }
        $this->addRequest('2025001', '2026-10-09', 'correction', ['check_in_at' => '2026-10-09 09:00:00']); // 申請中は出ない
        $dev = $this->addRequest('2025003', '2026-10-01', 'correction', ['check_in_at' => '2026-10-01 09:00:00']);
        $this->pdo->exec("UPDATE attendance_requests SET status = 'approved', reviewer_id = '2025002', reviewed_at = '2026-10-15 11:00:00' WHERE id = $dev");

        $rows = fetchProcessedRequests($this->pdo, '2025002', $this->departmentId('営業部'), 3);
        $this->assertSame([$ids[4], $ids[3], $ids[2]], array_map(fn ($r) => (int) $r['id'], $rows)); // 新しい順・件数
        $this->assertSame(['rejected', 'NG', '佐藤 花子', '山田 太郎', '営業部'], [$rows[0]['status'], $rows[0]['review_comment'], $rows[0]['reviewer_name'], $rows[0]['name'], $rows[0]['department_name']]);
        // 画面に出す列がそろっていること（申請日時・変更前と申請値・理由）
        foreach (['reviewed_at', 'created_at', 'target_date', 'request_type', 'reason', 'requested_check_in_at', 'original_check_in_at', 'requested_overtime_end_at', 'original_overtime_end_at'] as $column) {
            $this->assertArrayHasKey($column, $rows[0], $column);
        }
        $all = fetchProcessedRequests($this->pdo, '2025001', null, 50);
        $this->assertSame($dev, (int) $all[0]['id']);
        $this->assertCount(1, $all); // 2025001 自身の申請は除く
    }

    public function testApproveCorrectionCreatesAndReplaces(): void {
        $manager = $this->manager(self::SALES_MANAGER);
        // 行が無い日（新規登録）：作る
        $new = $this->addRequest('2025001', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00', 'check_out_at' => '2026-10-13 18:00:00']);
        $this->assertSame(['ok' => true, 'message' => '山田 太郎 さんの申請を承認し、勤怠に反映しました。'], reviewRequest($this->pdo, $manager, $new, 'approve', ''));
        $this->assertSame(['check_in_at' => '2026-10-13 09:00:00', 'check_out_at' => '2026-10-13 18:00:00', 'break_start_at' => null, 'break_end_at' => null, 'overtime_end_at' => null], $this->attendance('2025001', '2026-10-13'));
        $request = $this->request($new);
        $this->assertSame(['approved', '2025002', null], [$request['status'], $request['reviewer_id'], $request['review_comment']]);
        $this->assertNotNull($request['reviewed_at']);

        // 行がある日（修正）：4つの時刻をすべて置き換える（休憩を消す申請も）。残業終了時刻は変えない
        $this->pdo->exec("INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at, overtime_end_at) VALUES ('2025001', '2026-10-14', '2026-10-14 09:00:00', '2026-10-14 17:45:00', '2026-10-14 15:00:00', '2026-10-14 15:10:00', '2026-10-14 17:30:00')");
        $fix = $this->addRequest('2025001', '2026-10-14', 'correction', ['check_in_at' => '2026-10-14 09:00:00', 'check_out_at' => '2026-10-14 18:30:00']);
        reviewRequest($this->pdo, $manager, $fix, 'approve', '');
        $this->assertSame(['check_in_at' => '2026-10-14 09:00:00', 'check_out_at' => '2026-10-14 18:30:00', 'break_start_at' => null, 'break_end_at' => null, 'overtime_end_at' => '2026-10-14 17:30:00'], $this->attendance('2025001', '2026-10-14'));
        $this->assertSame('1', (string) $this->pdo->query("SELECT COUNT(*) FROM attendances WHERE employee_id = '2025001' AND work_date = '2026-10-14'")->fetchColumn());

        // 承認されたので、同じ日に新しい申請を出せる（BR-002 の一意制約は申請中だけ）
        $this->addRequest('2025001', '2026-10-14', 'correction', ['check_in_at' => '2026-10-14 08:00:00']);
        $this->assertTrue(true);
    }

    public function testApproveOvertimeAndReject(): void {
        $manager = $this->manager(self::ALL_MANAGER);
        $this->pdo->exec("INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at) VALUES ('2025001', '2026-10-14', '2026-10-14 09:00:00', '2026-10-14 19:45:00')");
        $overtime = $this->addRequest('2025001', '2026-10-14', 'overtime', ['overtime_end_at' => '2026-10-14 19:30:00']);
        reviewRequest($this->pdo, $manager, $overtime, 'approve', '');
        $this->assertSame(['check_in_at' => '2026-10-14 09:00:00', 'check_out_at' => '2026-10-14 19:45:00', 'break_start_at' => null, 'break_end_at' => null, 'overtime_end_at' => '2026-10-14 19:30:00'], $this->attendance('2025001', '2026-10-14'));

        // 却下：勤怠は変わらない。コメントあり・なし（空は NULL）
        $before = $this->attendance('2025001', '2026-10-14');
        $reject1 = $this->addRequest('2025001', '2026-10-14', 'correction', ['check_in_at' => '2026-10-14 07:00:00']);
        $this->assertSame(['ok' => true, 'message' => '山田 太郎 さんの申請を却下しました。'], reviewRequest($this->pdo, $manager, $reject1, 'reject', '  根拠が不明  '));
        $this->assertSame(['rejected', '2025003', '根拠が不明'], array_slice(array_values($this->request($reject1)), 0, 3));
        $this->assertSame($before, $this->attendance('2025001', '2026-10-14'));
        $reject2 = $this->addRequest('2025001', '2026-10-14', 'correction', ['check_in_at' => '2026-10-14 07:00:00']);
        reviewRequest($this->pdo, $manager, $reject2, 'reject', '');
        $this->assertNull($this->request($reject2)['review_comment']);
        $this->assertSame(['ok' => false, 'errors' => ['comment' => 'コメントは 500 文字までにしてください。']], reviewRequest($this->pdo, $manager, $this->addRequest('2025001', '2026-10-15', 'correction', []), 'reject', str_repeat('あ', 501)));
    }

    public function testRefusals(): void {
        $sales = $this->manager(self::SALES_MANAGER);
        $processed = $this->addRequest('2025001', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00']);
        reviewRequest($this->pdo, $sales, $processed, 'approve', '');
        $own = $this->addRequest('2025002', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00']);
        $dev = $this->addRequest('2025003', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00']);

        $this->assertStringContainsString('すでに処理されています', reviewRequest($this->pdo, $sales, $processed, 'reject', '')['errors']['form']);
        $this->assertStringContainsString('見つかりません', reviewRequest($this->pdo, $sales, 999999, 'approve', '')['errors']['form']);
        $this->assertSame('この申請を処理する権限がありません。', reviewRequest($this->pdo, $sales, $own, 'approve', '')['errors']['form']);
        $this->assertSame('この申請を処理する権限がありません。', reviewRequest($this->pdo, $sales, $dev, 'approve', '')['errors']['form']);
        $this->assertSame(['pending', 'pending'], [$this->request($own)['status'], $this->request($dev)['status']]);
        $this->assertNull($this->attendance('2025003', '2026-10-13'));
        $this->assertFalse($this->pdo->inTransaction());
    }

    // 途中で失敗したら、勤怠への反映も元に戻る（トランザクション）
    public function testRollbackWhenStatusUpdateFails(): void {
        $manager = $this->manager(self::SALES_MANAGER);
        $id = $this->addRequest('2025001', '2026-10-13', 'correction', ['check_in_at' => '2026-10-13 09:00:00']);
        // 申請の更新を、必ず失敗させる（テスト用のトリガー）
        $this->pdo->exec("CREATE TRIGGER fail_request_update BEFORE UPDATE ON attendance_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
        try {
            reviewRequest($this->pdo, $manager, $id, 'approve', '');
            $this->fail('例外になるはず');
        } catch (PDOException $e) {
            $this->assertStringContainsString('test failure', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertNull($this->attendance('2025001', '2026-10-13')); // 勤怠に反映していない
        $this->assertSame('pending', $this->request($id)['status']);
    }
}
