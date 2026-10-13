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
    throw new RuntimeException('fetchLoginAccount は未実装です');
}

// 最終ログイン日時を、今の日時に更新する
function updateLastLoginAt(PDO $pdo, string $loginId): void {
    // TODO（依頼者）：last_login_at を今の日時にする
    // 1. SQL を書く：UPDATE login_accounts SET last_login_at = NOW() WHERE login_id = :login_id
    // 2. $pdo->prepare() で準備する
    // 3. :login_id に $loginId を渡す（型は PDO::PARAM_STR）
    // 4. execute() する（UPDATE なので、fetch() はしない。戻り値も無い）
    throw new RuntimeException('updateLastLoginAt は未実装です');
}
