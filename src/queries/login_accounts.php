<?php
// ログイン情報（login_accounts テーブル）を読み書きする関数

// ログイン ID で、ログイン情報を1行取得する
// 戻り値：['login_id', 'employee_id', 'password_hash', 'role', 'approval_scope', 'is_active'] の連想配列。見つからなければ null
function fetchLoginAccount(PDO $pdo, string $loginId): ?array {
    // TODO（依頼者）：ログイン情報を取得して返す
    // 1. SQL を書く：login_accounts から login_id, employee_id, password_hash, role, approval_scope, is_active を取得（条件は login_id = :login_id）
    // 2. $pdo->prepare() で準備する
    // 3. :login_id に $loginId を渡す（ログイン ID は文字列なので、型は PDO::PARAM_STR）
    // 4. execute() して、fetch() で1行取得する
    // 5. 見つからなかったときは fetch() が false を返すので、null を返す（見つかったら、その行を返す）
    //    例：return $row === false ? null : $row;

    $sql = 'SELECT login_id, employee_id, password_hash, role, approval_scope, is_active FROM login_accounts WHERE login_id = :login_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login_id', $loginId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
    // throw new RuntimeException('fetchLoginAccount は未実装です');
}

// 最終ログイン日時を、今の日時に更新する
function updateLastLoginAt(PDO $pdo, string $loginId): void {
    // TODO（依頼者）：last_login_at を今の日時にする
    // 1. SQL を書く：UPDATE login_accounts SET last_login_at = NOW() WHERE login_id = :login_id
    // 2. $pdo->prepare() で準備する
    // 3. :login_id に $loginId を渡す（型は PDO::PARAM_STR）
    // 4. execute() する（UPDATE なので、fetch() はしない。戻り値も無い）
    $sql = 'UPDATE login_accounts SET last_login_at = NOW() WHERE login_id = :login_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login_id', $loginId, PDO::PARAM_STR);
    $stmt->execute();
    // throw new RuntimeException('updateLastLoginAt は未実装です');
}

// 社員・管理者のログイン情報を追加する（PR 7 のアカウント作成。ログイン ID＝社員コード。D-011）
// $passwordHash：password_hash() で作ったハッシュ（平文は渡さない）。$role：'employee' か 'manager'。$approvalScope：'department' か 'all'
function insertLoginAccount(PDO $pdo, string $employeeId, string $passwordHash, string $role, string $approvalScope): void {
    // TODO（依頼者）：ログイン情報を追加する
    // 1. SQL を書く：INSERT INTO login_accounts (login_id, employee_id, password_hash, role, approval_scope) VALUES (:login_id, :employee_id, :password_hash, :role, :approval_scope)
    // 2. prepare → bindValue（5つとも PDO::PARAM_STR。:login_id と :employee_id には、どちらも $employeeId を渡す）→ execute
    throw new RuntimeException('insertLoginAccount は未実装です');
}

// ログインの利用可否を変える。$isActive：true＝ログインできる、false＝ログインできない
// 社員の在籍状態（updateEmploymentStatus()）と一緒に、呼び出し側（src/admin.php）が1つのトランザクションで呼ぶ
function updateLoginActive(PDO $pdo, string $loginId, bool $isActive): void {
    // TODO（依頼者）：利用可否を変える
    // 1. SQL を書く：UPDATE login_accounts SET is_active = :is_active WHERE login_id = :login_id
    // 2. prepare → bindValue：
    //    - :is_active には $isActive ? 1 : 0 を渡す（DB の列は数値の 1／0 なので PDO::PARAM_INT）
    //    - :login_id には $loginId を渡す（PDO::PARAM_STR）
    // 3. execute
    throw new RuntimeException('updateLoginActive は未実装です');
}

// パスワードを変える（パスワード再設定）。$passwordHash：password_hash() で作ったハッシュ（平文は渡さない）
function updatePasswordHash(PDO $pdo, string $loginId, string $passwordHash): void {
    // TODO（依頼者）：パスワードのハッシュを変える
    // 1. SQL を書く：UPDATE login_accounts SET password_hash = :password_hash WHERE login_id = :login_id
    // 2. prepare → bindValue（2つとも PDO::PARAM_STR）→ execute
    throw new RuntimeException('updatePasswordHash は未実装です');
}
