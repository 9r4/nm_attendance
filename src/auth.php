<?php
// ログイン・ログアウトと、ログイン中の利用者・権限の確認

include_once(__DIR__ . '/session.php');
include_once(__DIR__ . '/queries/login_accounts.php');
include_once(__DIR__ . '/queries/employees.php');

const ROLE_EMPLOYEE = 'employee';
const ROLE_MANAGER = 'manager';
const ROLE_SYSTEM_ADMIN = 'system_admin';

// 存在しない ID でも、パスワードの照合にかかる時間を同じにするためのダミー（何とも一致しない）
const DUMMY_PASSWORD_HASH = '$2y$10$Qae.ksyHzAjZva91gzGTHerEEdLz5O1RwvcW2jmo4Ueb2A4xD8n6K';

// ID とパスワードでログインする。成功したら true
// 失敗の理由（ID が無い・パスワード違い・利用不可）は、呼び出し側に区別させない
function attemptLogin(PDO $pdo, string $loginId, string $password): bool {
    $account = fetchLoginAccount($pdo, $loginId);
    $passwordOk = password_verify($password, $account['password_hash'] ?? DUMMY_PASSWORD_HASH);
    if ($account === null || !$passwordOk || (int) $account['is_active'] !== 1) {
        return false;
    }

    // セッション ID を作り直す（ログイン前の ID を使い回されないようにする）
    session_regenerate_id(true);
    $_SESSION['user'] = ['login_id' => $account['login_id']];
    unset($_SESSION['timed_out']);
    updateLastLoginAt($pdo, $account['login_id']);
    return true;
}

// ログアウトする（ログイン状態と、セッションの Cookie を消す）
function logoutUser(): void {
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}

// ログインしていなければログイン画面へ移す（BR-003）。ログイン中の利用者を返す
// 戻り値：['login_id', 'employee_id', 'role', 'approval_scope', 'name', 'department_name']
function requireLogin(PDO $pdo): array {
    $user = currentUser($pdo);
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

// ログイン中の利用者を返す。ログインしていなければ null（画面は移さない。API で使う）
// 戻り値は requireLogin() と同じ
function currentUser(PDO $pdo): ?array {
    startAppSession();
    $loginId = $_SESSION['user']['login_id'] ?? null;
    if ($loginId === null) {
        return null;
    }

    // ログイン中に利用不可にされた場合も、すぐに使えなくする
    $account = fetchLoginAccount($pdo, $loginId);
    if ($account === null || (int) $account['is_active'] !== 1) {
        logoutUser();
        return null;
    }

    $user = [
        'login_id' => $account['login_id'],
        'employee_id' => $account['employee_id'],
        'role' => $account['role'],
        'approval_scope' => $account['approval_scope'],
        'name' => 'システム管理者',
        'department_name' => '',
    ];
    if ($account['role'] !== ROLE_SYSTEM_ADMIN) {
        $profile = fetchEmployeeProfile($pdo, $account['employee_id']);
        if ($profile === null) {
            throw new RuntimeException('社員の情報がありません: ' . $account['employee_id']);
        }
        $user['name'] = $profile['name'];
        $user['department_name'] = $profile['department_name'];
    }
    return $user;
}

// 指定した権限のどれかを持っていなければ、処理を止める（例：requireRole($user, [ROLE_MANAGER])）
function requireRole(array $user, array $roles): void {
    if (!in_array($user['role'], $roles, true)) {
        abort(403, 'この画面を使う権限がありません。');
    }
}
