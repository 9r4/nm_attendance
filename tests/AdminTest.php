<?php
// システム管理者（src/admin.php）のテスト：社員コードの作成、入力の確認、一覧の表示の組み立て。DB は使わない

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/admin.php';

final class AdminTest extends TestCase {
    public function testFormatEmployeeId(): void {
        $this->assertSame('2026001', formatEmployeeId(2026, 1));
        $this->assertSame('2026999', formatEmployeeId(2026, 999));
        $this->assertSame('2026A01', formatEmployeeId(2026, 1000));
        $this->assertSame('2026A99', formatEmployeeId(2026, 1098));
        $this->assertSame('2026B01', formatEmployeeId(2026, 1099));
        $this->assertSame('2026Z99', formatEmployeeId(2026, 3573));
        $this->assertSame(3573, EMPLOYEE_NUMBER_MAX);
        // 作った社員コードは、どれも社員コードの形（DB設計書）に合う
        foreach ([1, 999, 1000, 1098, 1099, 2000, 3573] as $n) {
            $this->assertMatchesRegularExpression(EMPLOYEE_ID_PATTERN, formatEmployeeId(2026, $n));
        }
        // 番号が重ならない（1〜3573 がすべて違う社員コードになる）
        $ids = array_map(fn ($n) => formatEmployeeId(2026, $n), range(1, EMPLOYEE_NUMBER_MAX));
        $this->assertCount(EMPLOYEE_NUMBER_MAX, array_unique($ids));
    }

    public function testFormatEmployeeIdOutOfRange(): void {
        $this->expectException(InvalidArgumentException::class);
        formatEmployeeId(2026, 3574);
    }

    public function testValidatePassword(): void {
        $this->assertSame([], validatePassword('Abcd1234', 'Abcd1234'));
        $this->assertSame([], validatePassword('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~', '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~')); // 記号だけ（32 文字）
        $this->assertSame([], validatePassword(str_repeat('a', 8), str_repeat('a', 8)));   // 8 文字
        $this->assertSame([], validatePassword(str_repeat('a', 64), str_repeat('a', 64))); // 64 文字
        $this->assertSame(['password' => 'パスワードを入力してください。'], validatePassword('', ''));
        $this->assertSame(['password' => ADMIN_PASSWORD_RULE], validatePassword('Abc1234', 'Abc1234'));            // 7 文字
        $this->assertSame(['password' => ADMIN_PASSWORD_RULE], validatePassword(str_repeat('a', 65), str_repeat('a', 65)));
        $this->assertSame(['password' => ADMIN_PASSWORD_RULE], validatePassword('Abcd 1234', 'Abcd 1234'));        // 空白
        $this->assertSame(['password' => ADMIN_PASSWORD_RULE], validatePassword('パスワード1234', 'パスワード1234')); // 全角
        $this->assertSame(['password' => ADMIN_PASSWORD_RULE], validatePassword("Abcd1234\n", "Abcd1234\n"));      // 改行
        $this->assertSame(['password_confirm' => '確認用のパスワードが一致しません。'], validatePassword('Abcd1234', 'Abcd1235'));
    }

    private const DEPARTMENTS = [
        ['id' => 1, 'name' => '営業部', 'is_active' => 1, 'active_count' => 2],
        ['id' => 2, 'name' => '開発部', 'is_active' => '1', 'active_count' => 1],
        ['id' => 3, 'name' => '旧部署', 'is_active' => 0, 'active_count' => 0],
    ];

    private static function input(array $override = []): array {
        return $override + [
            'name' => '  山田 花子 ', 'email' => 'hanako@example.com', 'department_id' => '2', 'joined_on' => '2026-10-15',
            'role' => 'employee', 'approval_scope' => 'all', 'password' => 'Abcd1234', 'password_confirm' => 'Abcd1234',
        ];
    }

    public function testValidateAccountOk(): void {
        $result = validateAccount(self::input(), self::DEPARTMENTS, '2026-10-15');
        $this->assertSame([], $result['errors']);
        $this->assertSame('山田 花子', $result['values']['name']);             // 前後の空白を取る
        $this->assertSame('department', $result['values']['approval_scope']); // 社員は、承認範囲を既定値にする
        $manager = validateAccount(self::input(['role' => 'manager', 'approval_scope' => 'all']), self::DEPARTMENTS, '2026-10-15');
        $this->assertSame([], $manager['errors']);
        $this->assertSame('all', $manager['values']['approval_scope']);
        // 入社日の範囲の端
        $this->assertSame([], validateAccount(self::input(['joined_on' => '2000-01-01']), self::DEPARTMENTS, '2026-10-15')['errors']);
        $this->assertSame([], validateAccount(self::input(['joined_on' => '2027-10-15']), self::DEPARTMENTS, '2026-10-15')['errors']);
        // 名前・メールの上限
        $this->assertSame([], validateAccount(self::input(['name' => str_repeat('あ', 100)]), self::DEPARTMENTS, '2026-10-15')['errors']);
    }

    public function testValidateAccountErrors(): void {
        $errors = fn (array $override) => validateAccount(self::input($override), self::DEPARTMENTS, '2026-10-15')['errors'];
        $this->assertSame(['name' => '氏名を入力してください。'], $errors(['name' => '   ']));
        $this->assertSame(['name' => '氏名は 100 文字までにしてください。'], $errors(['name' => str_repeat('あ', 101)]));
        $this->assertSame(['email' => 'メールアドレスを入力してください。'], $errors(['email' => '']));
        $this->assertSame(['email' => 'メールアドレスの形が正しくありません。'], $errors(['email' => 'hanako@']));
        $this->assertSame(['email' => 'メールアドレスの形が正しくありません。'], $errors(['email' => str_repeat('a', 243) . '@example.com'])); // 255 文字
        $this->assertSame(['department_id' => '部署を選んでください。'], $errors(['department_id' => '']));
        $this->assertSame(['department_id' => '選んだ部署は使えません。画面を開き直してください。'], $errors(['department_id' => '3']));  // 無効
        $this->assertSame(['department_id' => '選んだ部署は使えません。画面を開き直してください。'], $errors(['department_id' => '99'])); // 無い
        $this->assertSame(['department_id' => '選んだ部署は使えません。画面を開き直してください。'], $errors(['department_id' => '1 OR 1=1']));
        $this->assertSame(['joined_on' => '入社日を入力してください。'], $errors(['joined_on' => '']));
        $range = ['joined_on' => '入社日は、2000-01-01 から 2027-10-15 までの日を指定してください。'];
        $this->assertSame($range, $errors(['joined_on' => '1999-12-31']));
        $this->assertSame($range, $errors(['joined_on' => '2027-10-16']));
        $this->assertSame($range, $errors(['joined_on' => '2026-02-30']));
        $this->assertSame(['role' => '権限を選んでください。'], $errors(['role' => 'system_admin'])); // システム管理者は作れない
        $this->assertSame(['approval_scope' => '承認範囲を選んでください。'], $errors(['role' => 'manager', 'approval_scope' => '']));
        $this->assertSame(['password' => ADMIN_PASSWORD_RULE], $errors(['password' => 'short', 'password_confirm' => 'short']));
        // パスワードは前後の空白を取らない（空白を含むものは、形の誤り）
        $this->assertSame(['password' => ADMIN_PASSWORD_RULE], $errors(['password' => ' Abcd1234', 'password_confirm' => ' Abcd1234']));
        // 配列で送られても、文字列でなければ空欄として扱う
        $this->assertSame(['name' => '氏名を入力してください。'], $errors(['name' => ['x']]));
        // 複数の誤りは、画面の順に並ぶ（画面には、先頭の1つを出す）
        $this->assertSame(['name', 'email', 'password'], array_keys($errors(['name' => '', 'email' => 'x', 'password' => ''])));
        // 画面の並び（氏名 → 部署 → メールアドレス）の順。部署とメールアドレスの両方が誤っていたら、部署が先（D-069）
        $this->assertSame(['department_id', 'email'], array_keys($errors(['department_id' => '', 'email' => 'x'])));
        $this->assertSame(['name', 'department_id', 'email', 'joined_on', 'role', 'password'],
            array_keys($errors(['name' => '', 'department_id' => '', 'email' => '', 'joined_on' => '', 'role' => '', 'password' => ''])));
    }

    public function testLeftOnRange(): void {
        $this->assertSame(['min' => '2025-04-01', 'max' => '2026-10-15'], leftOnRange('2025-04-01', '2026-10-15'));
        $this->assertSame(['min' => '2026-11-01', 'max' => '2026-11-01'], leftOnRange('2026-11-01', '2026-10-15')); // 入社前
    }

    public function testAccountView(): void {
        $row = ['employee_id' => '2026001', 'name' => '山田 花子', 'email' => 'h@example.com', 'joined_on' => '2026-10-01', 'left_on' => null,
            'employment_status' => 'active', 'department_name' => '総務部', 'role' => 'employee', 'approval_scope' => 'department', 'is_active' => 1];
        $view = accountView($row);
        $this->assertSame(['社員', '', '在籍', '', true], [$view['role'], $view['scope'], $view['status'], $view['leftOn'], $view['active']]);
        $view = accountView(['role' => 'manager', 'approval_scope' => 'all'] + $row);
        $this->assertSame(['管理者', '全社員'], [$view['role'], $view['scope']]);
        $view = accountView(['role' => 'manager', 'approval_scope' => 'department'] + $row);
        $this->assertSame('所属部署のみ', $view['scope']);
        $view = accountView(['is_active' => '0', 'left_on' => '2026-10-31', 'employment_status' => 'retired'] + $row);
        $this->assertSame(['退職', '退社日 2026/10/31', false], [$view['status'], $view['leftOn'], $view['active']]);
        // ログイン不可で、在籍状態が active のまま（テストデータの 2025004）も「退職」
        $view = accountView(['is_active' => 0] + $row);
        $this->assertSame(['退職', '', false], [$view['status'], $view['leftOn'], $view['active']]);
    }

    public function testDepartments(): void {
        $this->assertSame([1, 2], array_column(activeDepartments(self::DEPARTMENTS), 'id'));
        $this->assertSame('開発部', findDepartment(self::DEPARTMENTS, 2)['name']);
        $this->assertNull(findDepartment(self::DEPARTMENTS, 9));
        $this->assertNull(validateDepartmentName('総務部'));
        $this->assertNull(validateDepartmentName(str_repeat('あ', 50)));
        $this->assertSame('部署名を入力してください。', validateDepartmentName(''));
        $this->assertSame('部署名は 50 文字までにしてください。', validateDepartmentName(str_repeat('あ', 51)));
    }

    public function testValidateWorkSettings(): void {
        $input = ['standard_start' => '09:00', 'standard_end' => '18:00', 'lunch_start' => '12:00', 'lunch_end' => '13:00'];
        $ok = validateWorkSettings($input);
        $this->assertSame([], $ok['errors']);
        $this->assertSame(['standard_start' => '09:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00', 'standard_end' => '18:00:00'], $ok['values']);
        // 昼休憩が始業・終業にちょうど接する
        $this->assertSame([], validateWorkSettings(['lunch_start' => '09:00', 'lunch_end' => '18:00'] + $input)['errors']);

        $errors = fn (array $override) => validateWorkSettings($override + $input)['errors'];
        $this->assertSame(['standard_start' => '標準始業時刻を入力してください。'], $errors(['standard_start' => '']));
        $this->assertSame(['lunch_end' => '昼休憩の終了の形が正しくありません。'], $errors(['lunch_end' => '24:00']));
        $this->assertSame(['lunch_end' => '昼休憩の終了の形が正しくありません。'], $errors(['lunch_end' => '13:00:00']));
        $this->assertSame(['standard_end' => '標準終業時刻は、標準始業時刻より後にしてください。'], $errors(['standard_end' => '09:00']));
        $this->assertSame(['lunch_end' => '昼休憩の終了は、開始より後にしてください。'], $errors(['lunch_end' => '12:00']));
        $this->assertSame(['lunch_start' => '昼休憩は、標準始業時刻から標準終業時刻までの間にしてください。'], $errors(['lunch_start' => '08:30']));
        $this->assertSame(['lunch_start' => '昼休憩は、標準始業時刻から標準終業時刻までの間にしてください。'], $errors(['lunch_end' => '18:30', 'lunch_start' => '17:30']));
    }
}
