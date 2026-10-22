<?php
// システム管理者の DB を使うテスト：採番（同時に作っても重複しない）、アカウント作成・無効化・有効に戻す・パスワード再設定、部署、勤務設定、トランザクション（失敗したら元に戻る）
// テスト用の DB を使う。環境変数 ATTENDANCE_TEST_DSN が無いときは、飛ばす（WSL では実行しなくてよい）
//   例：ATTENDANCE_TEST_DSN='mysql:host=localhost;dbname=attendance_test;charset=utf8mb4' ATTENDANCE_TEST_USER=root ATTENDANCE_TEST_PASSWORD='' composer test
// テストのたびに、テスト用の DB の表を作り直す（docs/db/schema.sql と docs/db/test_data.sql を使う）。本番の DB を指定しないこと

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/admin.php';

final class AdminDbTest extends TestCase {
    private PDO $pdo;

    // アカウントを作る日時（2026 年なので、社員コードは 2026001 から）
    private const NOW = '2026-10-15 10:00:00';

    private static function connect(): PDO {
        $pdo = new PDO(getenv('ATTENDANCE_TEST_DSN'), getenv('ATTENDANCE_TEST_USER') ?: 'root', getenv('ATTENDANCE_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+09:00'");
        return $pdo;
    }

    protected function setUp(): void {
        $dsn = getenv('ATTENDANCE_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('ATTENDANCE_TEST_DSN が無いため、DB のテストは飛ばす');
        }
        $this->pdo = self::connect();
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
        $this->pdo->exec("INSERT INTO login_accounts (login_id, employee_id, password_hash, role) VALUES ('sysadmin', NULL, 'x', 'system_admin')");
    }

    private function value(string $sql): mixed {
        return $this->pdo->query($sql)->fetchColumn();
    }

    private function departmentId(string $name): int {
        $stmt = $this->pdo->prepare('SELECT id FROM departments WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return (int) $stmt->fetchColumn();
    }

    private function input(array $override = []): array {
        return $override + [
            'name' => '山田 花子', 'email' => 'hanako@example.com', 'department_id' => (string) $this->departmentId('開発部'), 'joined_on' => '2026-10-15',
            'role' => 'employee', 'approval_scope' => 'department', 'password' => 'Abcd1234', 'password_confirm' => 'Abcd1234',
        ];
    }

    public function testNextEmployeeNumber(): void {
        $this->assertSame(1, nextEmployeeNumber($this->pdo, 2030));   // その年の最初の1人：行を作る
        $this->assertSame(2, nextEmployeeNumber($this->pdo, 2030));   // 続き：+1
        $this->assertSame(1, nextEmployeeNumber($this->pdo, 2031));   // 年が変わると 1 から
        $this->assertSame(3, nextEmployeeNumber($this->pdo, 2030));
        $this->assertSame('3', (string) $this->value('SELECT last_number FROM employee_id_sequences WHERE year = 2030'));
        // 元に戻すと、通番も戻る
        $this->pdo->beginTransaction();
        $this->assertSame(4, nextEmployeeNumber($this->pdo, 2030));
        $this->pdo->rollBack();
        $this->assertSame(4, nextEmployeeNumber($this->pdo, 2030));
    }

    // 1人目のトランザクションが終わるまで、2人目は同じ年の通番を進められない（待たされる）。終わったあとは、続きの番号になる
    public function testNextEmployeeNumberLocksUntilCommit(): void {
        $other = self::connect();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->pdo->beginTransaction();
        $this->assertSame(1, nextEmployeeNumber($this->pdo, 2032));
        $other->beginTransaction();
        try {
            nextEmployeeNumber($other, 2032);
            $this->fail('ロックで待たされるはず');
        } catch (PDOException $e) {
            $this->assertSame(1205, $e->errorInfo[1]); // Lock wait timeout exceeded
        }
        $other->rollBack();
        $this->pdo->commit();
        $other->beginTransaction();
        $this->assertSame(2, nextEmployeeNumber($other, 2032));
        $other->commit();
    }

    public function testCreateAccount(): void {
        $now = strtotime(self::NOW);
        $result = createAccount($this->pdo, $this->input(), $now);
        $this->assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame('2026001', $result['employeeId']);
        $this->assertSame('アカウント「2026001」（山田 花子）を作成しました。社員コード（ログイン ID）とパスワードを、本人に伝えてください。', $result['message']);
        $employee = $this->pdo->query("SELECT name, email, department_id, joined_on, left_on, employment_status FROM employees WHERE employee_id = '2026001'")->fetch();
        $this->assertSame(['山田 花子', 'hanako@example.com', $this->departmentId('開発部'), '2026-10-15', null, 'active'],
            [$employee['name'], $employee['email'], (int) $employee['department_id'], $employee['joined_on'], $employee['left_on'], $employee['employment_status']]);
        $login = $this->pdo->query("SELECT employee_id, password_hash, role, approval_scope, is_active FROM login_accounts WHERE login_id = '2026001'")->fetch();
        $this->assertSame(['2026001', 'employee', 'department', 1], [$login['employee_id'], $login['role'], $login['approval_scope'], (int) $login['is_active']]);
        $this->assertTrue(password_verify('Abcd1234', $login['password_hash']));  // 平文ではなく、ハッシュで保存
        $this->assertNotSame('Abcd1234', $login['password_hash']);

        // 2人目（管理者・全社員）は 2026002
        $result = createAccount($this->pdo, $this->input(['email' => 'b@example.com', 'role' => 'manager', 'approval_scope' => 'all']), $now);
        $this->assertSame('2026002', $result['employeeId']);
        $this->assertSame(['manager', 'all'], array_values($this->pdo->query("SELECT role, approval_scope FROM login_accounts WHERE login_id = '2026002'")->fetch()));

        // メールアドレスの重複：何も残らず、通番も進まない
        $result = createAccount($this->pdo, $this->input(['email' => 'yamada@example.com']), $now);
        $this->assertSame(['email' => 'このメールアドレスは、すでに使われています。'], $result['errors']);
        $this->assertSame('山田 花子', $result['values']['name']); // 入力した値を返す（画面に残す）
        $this->assertSame('2', (string) $this->value('SELECT last_number FROM employee_id_sequences WHERE year = 2026'));
        $this->assertSame('2', (string) $this->value("SELECT COUNT(*) FROM employees WHERE employee_id LIKE '2026%'"));
        $this->assertSame('2026003', createAccount($this->pdo, $this->input(['email' => 'c@example.com']), $now)['employeeId']);

        // 入力の誤りでは、通番を進めない
        $this->assertFalse(createAccount($this->pdo, $this->input(['email' => 'd@example.com', 'password_confirm' => 'x']), $now)['ok']);
        $this->assertSame('3', (string) $this->value('SELECT last_number FROM employee_id_sequences WHERE year = 2026'));
    }

    public function testCreateAccountRejectsInactiveDepartmentAndUpperLimit(): void {
        $now = strtotime(self::NOW);
        $this->pdo->exec("UPDATE departments SET is_active = 0 WHERE name = '開発部'");
        $result = createAccount($this->pdo, $this->input(), $now);
        $this->assertSame(['department_id' => '選んだ部署は使えません。画面を開き直してください。'], $result['errors']);
        // 年間の上限（3573 人）に達していると作れない（通番も戻る）
        $this->pdo->exec("INSERT INTO employee_id_sequences (year, last_number) VALUES (2026, 3573)");
        $result = createAccount($this->pdo, $this->input(['department_id' => (string) $this->departmentId('営業部')]), $now);
        $this->assertSame(['form' => '今年作れるアカウントの上限（3573 人）に達しました。'], $result['errors']);
        $this->assertSame('3573', (string) $this->value('SELECT last_number FROM employee_id_sequences WHERE year = 2026'));
    }

    public function testCreateAccountRollsBackWhenLoginInsertFails(): void {
        // ログイン情報の追加を、必ず失敗させる（テスト用のトリガー）
        $this->pdo->exec("CREATE TRIGGER fail_login_insert BEFORE INSERT ON login_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
        try {
            createAccount($this->pdo, $this->input(), strtotime(self::NOW));
            $this->fail('例外になるはず');
        } catch (PDOException $e) {
            $this->assertStringContainsString('test failure', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame('0', (string) $this->value("SELECT COUNT(*) FROM employees WHERE employee_id LIKE '2026%'")); // 社員も残らない
        $this->assertFalse($this->value('SELECT last_number FROM employee_id_sequences WHERE year = 2026'));       // 採番も戻る
    }

    public function testFetchAccounts(): void {
        createAccount($this->pdo, $this->input(['role' => 'manager', 'approval_scope' => 'all']), strtotime(self::NOW));
        $rows = fetchAccounts($this->pdo);
        $this->assertSame(['2025001', '2025002', '2025003', '2025004', '2026001'], array_column($rows, 'employee_id')); // 社員コード順。システム管理者は出ない
        $this->assertSame(['employee_id', 'name', 'email', 'joined_on', 'left_on', 'employment_status', 'department_name', 'role', 'approval_scope', 'is_active'], array_keys($rows[0]));
        $last = $rows[4];
        $this->assertSame(['山田 花子', 'hanako@example.com', '開発部', 'manager', 'all', 1], [$last['name'], $last['email'], $last['department_name'], $last['role'], $last['approval_scope'], (int) $last['is_active']]);
        $this->assertSame(0, (int) $rows[3]['is_active']); // テストデータの 2025004 はログイン不可
    }

    private function state(string $employeeId): array {
        return array_values($this->pdo->query("SELECT e.employment_status, e.left_on, l.is_active FROM employees e JOIN login_accounts l ON l.employee_id = e.employee_id WHERE e.employee_id = '$employeeId'")->fetch());
    }

    public function testDeactivateAndReactivate(): void {
        $result = deactivateAccount($this->pdo, '2025001', '2026-10-15', '2026-10-15');
        $this->assertSame(['ok' => true, 'message' => 'アカウント「2025001」（山田 太郎）を無効にしました。'], $result);
        $this->assertSame(['retired', '2026-10-15', 0], $this->state('2025001'));
        $this->assertSame('このアカウントは、すでに無効です。', deactivateAccount($this->pdo, '2025001', '2026-10-15', '2026-10-15')['error']);

        $result = reactivateAccount($this->pdo, '2025001');
        $this->assertSame(['ok' => true, 'message' => 'アカウント「2025001」（山田 太郎）を有効に戻しました。'], $result);
        $this->assertSame(['active', null, 1], $this->state('2025001'));
        $this->assertSame('このアカウントは、すでに有効です。', reactivateAccount($this->pdo, '2025001')['error']);

        // テストデータの 2025004（ログイン不可・在籍状態は active）も、有効に戻せる
        $this->assertTrue(reactivateAccount($this->pdo, '2025004')['ok']);
        $this->assertSame(['active', null, 1], $this->state('2025004'));

        // 退社日の範囲（入社日 2025-04-01 〜 今日）
        $this->assertSame('退社日は、2025-04-01 から 2026-10-15 までの日を指定してください。', deactivateAccount($this->pdo, '2025001', '2025-03-31', '2026-10-15')['error']);
        $this->assertSame('退社日は、2025-04-01 から 2026-10-15 までの日を指定してください。', deactivateAccount($this->pdo, '2025001', '2026-10-16', '2026-10-15')['error']);
        $this->assertSame('退社日は、2025-04-01 から 2026-10-15 までの日を指定してください。', deactivateAccount($this->pdo, '2025001', '', '2026-10-15')['error']);
        $this->assertTrue(deactivateAccount($this->pdo, '2025001', '2025-04-01', '2026-10-15')['ok']);

        // 対象にできないもの：システム管理者、無い社員コード、形の違う値
        foreach (['sysadmin', '2099001', "2025001' OR '1'='1", ''] as $id) {
            $this->assertSame(ACCOUNT_NOT_FOUND, deactivateAccount($this->pdo, $id, '2026-10-15', '2026-10-15')['error'], $id);
            $this->assertSame(ACCOUNT_NOT_FOUND, reactivateAccount($this->pdo, $id)['error'], $id);
            $this->assertSame(ACCOUNT_NOT_FOUND, resetPassword($this->pdo, $id, 'Abcd1234', 'Abcd1234')['error'], $id);
        }
        $this->assertSame('1', (string) $this->value("SELECT is_active FROM login_accounts WHERE login_id = 'sysadmin'"));
    }

    public function testDeactivateRollsBackWhenLoginUpdateFails(): void {
        $this->pdo->exec("CREATE TRIGGER fail_login_update BEFORE UPDATE ON login_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
        try {
            deactivateAccount($this->pdo, '2025001', '2026-10-15', '2026-10-15');
            $this->fail('例外になるはず');
        } catch (PDOException $e) {
            $this->assertStringContainsString('test failure', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(['active', null, 1], $this->state('2025001')); // 社員の在籍状態も変わらない
    }

    public function testResetPassword(): void {
        $result = resetPassword($this->pdo, '2025001', 'New-Pass#2026', 'New-Pass#2026');
        $this->assertSame(['ok' => true, 'message' => 'アカウント「2025001」（山田 太郎）のパスワードを再設定しました。新しいパスワードを、本人に伝えてください。'], $result);
        $hash = $this->value("SELECT password_hash FROM login_accounts WHERE login_id = '2025001'");
        $this->assertTrue(password_verify('New-Pass#2026', $hash));
        $this->assertFalse(password_verify('Test1234', $hash));
        // 誤り：変わらない
        $this->assertSame('確認用のパスワードが一致しません。', resetPassword($this->pdo, '2025001', 'Abcd1234', 'Abcd1235')['error']);
        $this->assertSame(ADMIN_PASSWORD_RULE, resetPassword($this->pdo, '2025001', 'short', 'short')['error']);
        $this->assertSame($hash, $this->value("SELECT password_hash FROM login_accounts WHERE login_id = '2025001'"));
    }

    public function testDepartments(): void {
        $this->assertSame(['ok' => true, 'message' => '部署「総務部」を追加しました。'], addDepartment($this->pdo, '総務部'));
        // 部署を退職者だけにする：2025003（開発部）を退職にすると、開発部は 2025004 の 1 人（在籍状態で数える）
        deactivateAccount($this->pdo, '2025003', '2026-10-15', '2026-10-15');
        $rows = fetchDepartments($this->pdo);
        $this->assertSame(['id', 'name', 'is_active', 'active_count'], array_keys($rows[0]));
        $this->assertSame([['営業部', 1, 2], ['開発部', 1, 1], ['総務部', 1, 0]], array_map(fn ($r) => [$r['name'], (int) $r['is_active'], (int) $r['active_count']], $rows));

        $this->assertSame(DEPARTMENT_DUPLICATE, addDepartment($this->pdo, '営業部')['error']);
        $this->assertSame('部署名を入力してください。', addDepartment($this->pdo, '')['error']);
        $id = (string) $this->departmentId('総務部');
        $this->assertSame(['ok' => true, 'message' => '部署「総務部」の名前を「総務課」に変えました。'], renameDepartment($this->pdo, $id, '総務課'));
        $this->assertSame(DEPARTMENT_DUPLICATE, renameDepartment($this->pdo, $id, '開発部')['error']);
        $this->assertTrue(renameDepartment($this->pdo, $id, '総務課')['ok']); // 同じ名前のまま（変えない）
        $this->assertSame(DEPARTMENT_NOT_FOUND, renameDepartment($this->pdo, '999', 'x')['error']);
        $this->assertSame(DEPARTMENT_NOT_FOUND, renameDepartment($this->pdo, '1 OR 1=1', 'x')['error']);

        // 在籍者がいても無効にできる。所属している社員は、そのまま
        $sales = (string) $this->departmentId('営業部');
        $this->assertSame(['ok' => true, 'message' => '部署「営業部」を無効にしました。'], setDepartmentActive($this->pdo, $sales, false));
        $this->assertSame('0', (string) $this->value("SELECT is_active FROM departments WHERE id = $sales"));
        $this->assertSame((string) $sales, (string) $this->value("SELECT department_id FROM employees WHERE employee_id = '2025001'"));
        $this->assertSame('この部署は、すでに無効です。', setDepartmentActive($this->pdo, $sales, false)['error']);
        $this->assertSame(['ok' => true, 'message' => '部署「営業部」を有効に戻しました。'], setDepartmentActive($this->pdo, $sales, true));
        $this->assertSame('この部署は、すでに有効です。', setDepartmentActive($this->pdo, $sales, true)['error']);
        $this->assertSame(DEPARTMENT_NOT_FOUND, setDepartmentActive($this->pdo, '999', true)['error']);
    }

    public function testWorkSettings(): void {
        $this->pdo->exec("UPDATE work_settings SET updated_at = '2026-01-01 00:00:00' WHERE id = 1");
        $result = saveWorkSettings($this->pdo, ['standard_start' => '08:30', 'standard_end' => '17:30', 'lunch_start' => '12:30', 'lunch_end' => '13:30']);
        $this->assertSame(['ok' => true, 'message' => '勤務設定を保存しました。'], $result);
        $settings = fetchWorkSettings($this->pdo);
        $this->assertArrayHasKey('updated_at', $settings, 'fetchWorkSettings() に updated_at を追加する（PR 7）');
        $this->assertSame(['08:30:00', '17:30:00', '12:30:00', '13:30:00'], [$settings['standard_start'], $settings['standard_end'], $settings['lunch_start'], $settings['lunch_end']]);
        $this->assertNotSame('2026-01-01 00:00:00', $settings['updated_at']); // 更新日時は、DB が今の日時にする
        // 誤り：変わらない
        $this->assertFalse(saveWorkSettings($this->pdo, ['standard_start' => '18:00', 'standard_end' => '09:00', 'lunch_start' => '12:00', 'lunch_end' => '13:00'])['ok']);
        $this->assertSame('17:30:00', fetchWorkSettings($this->pdo)['standard_end']);
        $this->assertSame('1', (string) $this->value('SELECT COUNT(*) FROM work_settings')); // 1行のまま
    }
}
