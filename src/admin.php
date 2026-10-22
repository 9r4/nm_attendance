<?php
// システム管理者（FR-007、D-013、D-015、D-024、D-066、D-067）：アカウント管理・部署・勤務設定の入力の確認と処理、一覧の表示の組み立て
// 入力のルールは、すべてサーバーで確認する（画面の確認は、使いやすさのためだけ）

include_once(__DIR__ . '/auth.php');
include_once(__DIR__ . '/requests.php');
include_once(__DIR__ . '/queries/employee_id_sequences.php');
include_once(__DIR__ . '/queries/departments.php');
include_once(__DIR__ . '/queries/employees.php');
include_once(__DIR__ . '/queries/login_accounts.php');
include_once(__DIR__ . '/queries/work_settings.php');

// 文字数の上限（DB の列の長さ）
const ADMIN_NAME_MAX_LENGTH = 100;
const ADMIN_EMAIL_MAX_LENGTH = 254;
const ADMIN_DEPARTMENT_NAME_MAX_LENGTH = 50;

// パスワード：半角の英数字・記号（空白を除く、ASCII の 0x21〜0x7E）で 8〜64 文字（D-066）
const ADMIN_PASSWORD_PATTERN = '/\A[\x21-\x7E]{8,64}\z/';
const ADMIN_PASSWORD_RULE = 'パスワードは、半角の英数字・記号で 8〜64 文字にしてください。';

// 入社日の範囲：2000-01-01 〜 今日の1年後（D-067）
const ADMIN_JOINED_ON_MIN = '2000-01-01';

// 1年に作れる社員コードの数（001〜999 と A01〜Z99。D-013）
const EMPLOYEE_NUMBER_MAX = 999 + 26 * 99;

// 社員コードの形（例：2026001、2026A01。DB設計書）
const EMPLOYEE_ID_PATTERN = '/\A[0-9]{4}([0-9]{3}|[A-Z][0-9]{2})\z/';

const EMPLOYMENT_STATUS_ACTIVE = 'active';
const EMPLOYMENT_STATUS_RETIRED = 'retired';

const ACCOUNT_ROLE_LABELS = [ROLE_EMPLOYEE => '社員', ROLE_MANAGER => '管理者'];
const APPROVAL_SCOPE_LABELS = ['department' => '所属部署のみ', 'all' => '全社員'];

// 勤務設定の項目と、画面に出す名前（順番は、時刻の前後の順）
const WORK_SETTING_FIELDS = [
    'standard_start' => '標準始業時刻',
    'lunch_start' => '昼休憩の開始',
    'lunch_end' => '昼休憩の終了',
    'standard_end' => '標準終業時刻',
];

// 画面から送られた値を、そのまま取り出す（パスワード用。前後の空白も取らない）
function inputRaw(array $input, string $key): string {
    $value = $input[$key] ?? '';
    return is_string($value) ? $value : '';
}

// 年と通番から社員コードを作る（D-013）。1〜999 は 'yyyy'＋3桁、1000〜3573 は 'yyyy'＋英字＋2桁（1000＝A01、1098＝A99、1099＝B01）
function formatEmployeeId(int $year, int $number): string {
    if ($number < 1 || $number > EMPLOYEE_NUMBER_MAX) {
        throw new InvalidArgumentException('通番が範囲外です: ' . $number);
    }
    if ($number <= 999) {
        return sprintf('%04d%03d', $year, $number);
    }
    $m = $number - 1000;
    return sprintf('%04d%s%02d', $year, chr(ord('A') + intdiv($m, 99)), $m % 99 + 1);
}

// パスワードと確認の入力を確認する。誤りがあれば [項目 => 文]
function validatePassword(string $password, string $confirm): array {
    if ($password === '') {
        return ['password' => 'パスワードを入力してください。'];
    }
    if (!preg_match(ADMIN_PASSWORD_PATTERN, $password)) {
        return ['password' => ADMIN_PASSWORD_RULE];
    }
    if ($confirm !== $password) {
        return ['password_confirm' => '確認用のパスワードが一致しません。'];
    }
    return [];
}

// 部署の一覧から、有効な部署だけを選ぶ
function activeDepartments(array $departments): array {
    return array_values(array_filter($departments, fn ($d) => (int) $d['is_active'] === 1));
}

// 部署の一覧から、ID で1つ探す。無ければ null
function findDepartment(array $departments, int $id): ?array {
    foreach ($departments as $department) {
        if ((int) $department['id'] === $id) {
            return $department;
        }
    }
    return null;
}

// アカウント作成の入力を確認する（2-1 のルール）
// $input：name、email、department_id、joined_on、role、approval_scope、password、password_confirm
// $departments：fetchDepartments() の戻り値。$today：今日（'2026-10-15'）
// 戻り値：['errors' => [項目 => 文]（画面の並びの順：氏名 → 部署 → メールアドレス → 入社日 → 権限 → 承認範囲 → パスワード。D-069）, 'values' => 確認した値]
function validateAccount(array $input, array $departments, string $today): array {
    $values = [
        'name' => inputString($input, 'name'),
        'email' => inputString($input, 'email'),
        'department_id' => inputString($input, 'department_id'),
        'joined_on' => inputString($input, 'joined_on'),
        'role' => inputString($input, 'role'),
        'approval_scope' => inputString($input, 'approval_scope'),
    ];
    $errors = [];

    if ($values['name'] === '') {
        $errors['name'] = '氏名を入力してください。';
    } elseif (mb_strlen($values['name']) > ADMIN_NAME_MAX_LENGTH) {
        $errors['name'] = '氏名は ' . ADMIN_NAME_MAX_LENGTH . ' 文字までにしてください。';
    }

    if ($values['department_id'] === '') {
        $errors['department_id'] = '部署を選んでください。';
    } else {
        $department = preg_match('/\A[1-9]\d{0,9}\z/', $values['department_id']) ? findDepartment($departments, (int) $values['department_id']) : null;
        if ($department === null || (int) $department['is_active'] !== 1) {
            $errors['department_id'] = '選んだ部署は使えません。画面を開き直してください。';
        }
    }

    if ($values['email'] === '') {
        $errors['email'] = 'メールアドレスを入力してください。';
    } elseif (strlen($values['email']) > ADMIN_EMAIL_MAX_LENGTH || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'メールアドレスの形が正しくありません。';
    }

    $maxJoinedOn = date('Y-m-d', strtotime($today . ' +1 year'));
    if ($values['joined_on'] === '') {
        $errors['joined_on'] = '入社日を入力してください。';
    } elseif (!isValidDate($values['joined_on']) || $values['joined_on'] < ADMIN_JOINED_ON_MIN || $values['joined_on'] > $maxJoinedOn) {
        $errors['joined_on'] = '入社日は、' . ADMIN_JOINED_ON_MIN . ' から ' . $maxJoinedOn . ' までの日を指定してください。';
    }

    if (!array_key_exists($values['role'], ACCOUNT_ROLE_LABELS)) {
        $errors['role'] = '権限を選んでください。';
    } elseif ($values['role'] === ROLE_MANAGER) {
        if (!array_key_exists($values['approval_scope'], APPROVAL_SCOPE_LABELS)) {
            $errors['approval_scope'] = '承認範囲を選んでください。';
        }
    } else {
        // 社員は承認しないので、承認範囲は既定値にする
        $values['approval_scope'] = 'department';
    }

    $errors += validatePassword(inputRaw($input, 'password'), inputRaw($input, 'password_confirm'));
    return ['errors' => $errors, 'values' => $values];
}

// アカウント（社員とログイン情報）を作る。社員コードは採番する（3章）
// 戻り値：['ok' => true, 'message' => …, 'employeeId' => …] か ['ok' => false, 'errors' => [項目 => 文], 'values' => …]
function createAccount(PDO $pdo, array $input, int $now): array {
    $today = date('Y-m-d', $now);
    $checked = validateAccount($input, fetchDepartments($pdo), $today);
    $values = $checked['values'];
    if ($checked['errors'] !== []) {
        return ['ok' => false, 'errors' => $checked['errors'], 'values' => $values];
    }
    // ハッシュは、トランザクションの外で作る（時間がかかるため、採番の行のロックを長く持たない）
    $passwordHash = password_hash(inputRaw($input, 'password'), PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $year = (int) date('Y', $now);
        $number = nextEmployeeNumber($pdo, $year);
        if ($number > EMPLOYEE_NUMBER_MAX) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['form' => '今年作れるアカウントの上限（' . EMPLOYEE_NUMBER_MAX . ' 人）に達しました。'], 'values' => $values];
        }
        $employeeId = formatEmployeeId($year, $number);
        insertEmployee($pdo, $employeeId, $values['name'], $values['email'], (int) $values['department_id'], $values['joined_on']);
        insertLoginAccount($pdo, $employeeId, $passwordHash, $values['role'], $values['approval_scope']);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // 同じメールアドレスの社員がいる（一意制約）。元に戻したので、通番も進まない
        if (isDuplicateError($e) && str_contains($e->getMessage(), 'uq_employees_email')) {
            return ['ok' => false, 'errors' => ['email' => 'このメールアドレスは、すでに使われています。'], 'values' => $values];
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [
        'ok' => true,
        'employeeId' => $employeeId,
        'message' => 'アカウント「' . $employeeId . '」（' . $values['name'] . '）を作成しました。社員コード（ログイン ID）とパスワードを、本人に伝えてください。',
    ];
}

// 一覧の操作の対象（社員・管理者）を探す。社員コードの形が違う・見つからない・システム管理者なら null
// 戻り値：['employee_id', 'name', 'joined_on', 'is_active']
function findManagedAccount(PDO $pdo, string $employeeId): ?array {
    if (!preg_match(EMPLOYEE_ID_PATTERN, $employeeId)) {
        return null;
    }
    $account = fetchLoginAccount($pdo, $employeeId);
    if ($account === null || $account['role'] === ROLE_SYSTEM_ADMIN || $account['employee_id'] !== $employeeId) {
        return null;
    }
    $profile = fetchEmployeeProfile($pdo, $employeeId);
    if ($profile === null) {
        return null;
    }
    return [
        'employee_id' => $employeeId,
        'name' => $profile['name'],
        'joined_on' => $profile['joined_on'],
        'is_active' => (int) $account['is_active'] === 1,
    ];
}

// 退社日に指定できる範囲：入社日 〜 今日（入社日が先の日付なら、入社日まで）（D-067）
function leftOnRange(string $joinedOn, string $today): array {
    return ['min' => $joinedOn, 'max' => max($joinedOn, $today)];
}

const ACCOUNT_NOT_FOUND = 'このアカウントは見つかりません。画面を読み込み直してください。';

// アカウントを無効にする（退職。D-015）。在籍状態・退社日・ログインの可否を、1つのトランザクションで変える
// 戻り値：['ok' => true, 'message' => …] か ['ok' => false, 'error' => 文]
function deactivateAccount(PDO $pdo, string $employeeId, string $leftOn, string $today): array {
    $account = findManagedAccount($pdo, $employeeId);
    if ($account === null) {
        return ['ok' => false, 'error' => ACCOUNT_NOT_FOUND];
    }
    if (!$account['is_active']) {
        return ['ok' => false, 'error' => 'このアカウントは、すでに無効です。'];
    }
    $range = leftOnRange($account['joined_on'], $today);
    if (!isValidDate($leftOn) || $leftOn < $range['min'] || $leftOn > $range['max']) {
        return ['ok' => false, 'error' => '退社日は、' . $range['min'] . ' から ' . $range['max'] . ' までの日を指定してください。'];
    }
    runInTransaction($pdo, function () use ($pdo, $employeeId, $leftOn) {
        updateEmploymentStatus($pdo, $employeeId, EMPLOYMENT_STATUS_RETIRED, $leftOn);
        updateLoginActive($pdo, $employeeId, false);
    });
    return ['ok' => true, 'message' => 'アカウント「' . $employeeId . '」（' . $account['name'] . '）を無効にしました。'];
}

// 無効にしたアカウントを、有効に戻す（在籍・退社日なし・ログインできる）
function reactivateAccount(PDO $pdo, string $employeeId): array {
    $account = findManagedAccount($pdo, $employeeId);
    if ($account === null) {
        return ['ok' => false, 'error' => ACCOUNT_NOT_FOUND];
    }
    if ($account['is_active']) {
        return ['ok' => false, 'error' => 'このアカウントは、すでに有効です。'];
    }
    runInTransaction($pdo, function () use ($pdo, $employeeId) {
        updateEmploymentStatus($pdo, $employeeId, EMPLOYMENT_STATUS_ACTIVE, null);
        updateLoginActive($pdo, $employeeId, true);
    });
    return ['ok' => true, 'message' => 'アカウント「' . $employeeId . '」（' . $account['name'] . '）を有効に戻しました。'];
}

// パスワードを再設定する（D-050 の問い合わせを受けたとき。D-066）
function resetPassword(PDO $pdo, string $employeeId, string $password, string $confirm): array {
    $account = findManagedAccount($pdo, $employeeId);
    if ($account === null) {
        return ['ok' => false, 'error' => ACCOUNT_NOT_FOUND];
    }
    $errors = validatePassword($password, $confirm);
    if ($errors !== []) {
        return ['ok' => false, 'error' => reset($errors)];
    }
    updatePasswordHash($pdo, $employeeId, password_hash($password, PASSWORD_DEFAULT));
    return ['ok' => true, 'message' => 'アカウント「' . $employeeId . '」（' . $account['name'] . '）のパスワードを再設定しました。新しいパスワードを、本人に伝えてください。'];
}

// $work を1つのトランザクションで行う（途中で失敗したら、すべて元に戻す）
function runInTransaction(PDO $pdo, callable $work): void {
    $pdo->beginTransaction();
    try {
        $work();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// アカウント管理の一覧の1行（fetchAccounts() の行から）
function accountView(array $row): array {
    $active = (int) $row['is_active'] === 1;
    // 管理者は、承認範囲を添える（例：管理者・全社員）
    $scope = $row['role'] === ROLE_MANAGER ? (APPROVAL_SCOPE_LABELS[$row['approval_scope']] ?? $row['approval_scope']) : '';
    // 状態は、ログインの可否で決める（4章）。退職は、退社日を添える
    $leftOn = !$active && $row['left_on'] !== null ? '退社日 ' . date('Y/m/d', strtotime($row['left_on'])) : '';
    return [
        'employeeId' => $row['employee_id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'department' => $row['department_name'],
        'joinedOn' => $row['joined_on'],
        'role' => ACCOUNT_ROLE_LABELS[$row['role']] ?? $row['role'],
        'scope' => $scope,
        'status' => $active ? '在籍' : '退職',
        'leftOn' => $leftOn,
        'active' => $active,
    ];
}

// 部署名の入力を確認する。誤りがあれば文、なければ null
function validateDepartmentName(string $name): ?string {
    if ($name === '') {
        return '部署名を入力してください。';
    }
    if (mb_strlen($name) > ADMIN_DEPARTMENT_NAME_MAX_LENGTH) {
        return '部署名は ' . ADMIN_DEPARTMENT_NAME_MAX_LENGTH . ' 文字までにしてください。';
    }
    return null;
}

const DEPARTMENT_DUPLICATE = '同じ名前の部署があります。別の名前にしてください。';
const DEPARTMENT_NOT_FOUND = 'この部署は見つかりません。画面を読み込み直してください。';

// 部署を追加する。戻り値：['ok' => true, 'message' => …] か ['ok' => false, 'error' => 文]
function addDepartment(PDO $pdo, string $name): array {
    $error = validateDepartmentName($name);
    if ($error !== null) {
        return ['ok' => false, 'error' => $error];
    }
    try {
        insertDepartment($pdo, $name);
    } catch (PDOException $e) {
        if (isDuplicateError($e)) {
            return ['ok' => false, 'error' => DEPARTMENT_DUPLICATE];
        }
        throw $e;
    }
    return ['ok' => true, 'message' => '部署「' . $name . '」を追加しました。'];
}

// 部署の名前を変える
function renameDepartment(PDO $pdo, string $id, string $name): array {
    $department = preg_match('/\A[1-9]\d{0,9}\z/', $id) ? findDepartment(fetchDepartments($pdo), (int) $id) : null;
    if ($department === null) {
        return ['ok' => false, 'error' => DEPARTMENT_NOT_FOUND];
    }
    $error = validateDepartmentName($name);
    if ($error !== null) {
        return ['ok' => false, 'error' => $error];
    }
    try {
        updateDepartmentName($pdo, (int) $id, $name);
    } catch (PDOException $e) {
        if (isDuplicateError($e)) {
            return ['ok' => false, 'error' => DEPARTMENT_DUPLICATE];
        }
        throw $e;
    }
    return ['ok' => true, 'message' => '部署「' . $department['name'] . '」の名前を「' . $name . '」に変えました。'];
}

// 部署を有効・無効にする（無効にしても、所属している社員はそのまま。D-067）
function setDepartmentActive(PDO $pdo, string $id, bool $isActive): array {
    $department = preg_match('/\A[1-9]\d{0,9}\z/', $id) ? findDepartment(fetchDepartments($pdo), (int) $id) : null;
    if ($department === null) {
        return ['ok' => false, 'error' => DEPARTMENT_NOT_FOUND];
    }
    if (((int) $department['is_active'] === 1) === $isActive) {
        return ['ok' => false, 'error' => $isActive ? 'この部署は、すでに有効です。' : 'この部署は、すでに無効です。'];
    }
    updateDepartmentActive($pdo, (int) $id, $isActive);
    return ['ok' => true, 'message' => '部署「' . $department['name'] . '」を' . ($isActive ? '有効に戻しました。' : '無効にしました。')];
}

// 勤務設定の入力を確認する（2-3 のルール）。$input：standard_start、standard_end、lunch_start、lunch_end（'H:i'）
// 戻り値：['errors' => [項目 => 文], 'values' => ['standard_start' => '09:00:00', …]（形が正しい項目だけ）]
function validateWorkSettings(array $input): array {
    $errors = [];
    $values = [];
    foreach (WORK_SETTING_FIELDS as $field => $label) {
        $value = inputString($input, $field);
        if ($value === '') {
            $errors[$field] = $label . 'を入力してください。';
        } elseif (!preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $value)) {
            $errors[$field] = $label . 'の形が正しくありません。';
        } else {
            $values[$field] = $value . ':00';
        }
    }
    if ($errors === []) {
        if ($values['standard_start'] >= $values['standard_end']) {
            $errors['standard_end'] = '標準終業時刻は、標準始業時刻より後にしてください。';
        } elseif ($values['lunch_start'] >= $values['lunch_end']) {
            $errors['lunch_end'] = '昼休憩の終了は、開始より後にしてください。';
        } elseif ($values['lunch_start'] < $values['standard_start'] || $values['lunch_end'] > $values['standard_end']) {
            $errors['lunch_start'] = '昼休憩は、標準始業時刻から標準終業時刻までの間にしてください。';
        }
    }
    return ['errors' => $errors, 'values' => $values];
}

// 勤務設定を保存する。戻り値：['ok' => true, 'message' => …] か ['ok' => false, 'errors' => [項目 => 文]]
function saveWorkSettings(PDO $pdo, array $input): array {
    $checked = validateWorkSettings($input);
    if ($checked['errors'] !== []) {
        return ['ok' => false, 'errors' => $checked['errors']];
    }
    updateWorkSettings($pdo, $checked['values']);
    return ['ok' => true, 'message' => '勤務設定を保存しました。'];
}
