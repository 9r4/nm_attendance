<?php
// 社員（employees テーブル）を読み書きする関数

// 社員 ID で、社員の氏名と部署名を取得する（ヘッダーの表示に使う）
// 戻り値：['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部', 'joined_on' => '2025-04-01', 'department_id' => 1]。見つからなければ null
// TODO（依頼者・PR 4）：取得する列に、入社日 e.joined_on を追加する（記録表示で、前の月へ戻れる範囲に使う）
// TODO（依頼者・PR 6）：取得する列に、部署 ID e.department_id を追加する（承認範囲が「部署」の管理者の、部署の判断に使う）
function fetchEmployeeProfile(PDO $pdo, string $employeeId): ?array {
    // TODO（依頼者）：社員と部署を結合（JOIN）して取得する
    // 1. SQL を書く：
    //    - employees に e、departments に d という別名を付ける（FROM employees e JOIN departments d ON …）
    //    - 結ぶ条件：e.department_id = d.id
    //    - 取得する列：e.employee_id, e.name, d.name AS department_name（両方に name があるので、部署名には別名を付ける）
    //    - 条件：e.employee_id = :employee_id
    // 2. $pdo->prepare() で準備する
    // 3. :employee_id に $employeeId を渡す（社員 ID は文字列なので、型は PDO::PARAM_STR）
    // 4. execute() して、fetch() で1行取得する
    // 5. 見つからなかったら null、見つかったらその行を返す
    // throw new RuntimeException('fetchEmployeeProfile は未実装です');

    $sql = 'SELECT e.employee_id, e.name, d.name AS department_name, e.joined_on, e.department_id FROM employees e JOIN departments d ON e.department_id = d.id WHERE e.employee_id = :employee_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

// 社員を追加する（PR 7 のアカウント作成。在籍状態は DB の既定値の「在籍（active）」、退社日は NULL になる）
// $employeeId：採番した社員コード（例：'2026001'）。$joinedOn：入社日（'2026-10-15'）
// 同じメールアドレスの社員がいると、DB の一意制約のエラー（PDOException）になる。呼び出し側（src/admin.php）が受け止めるので、ここでは確かめない
function insertEmployee(PDO $pdo, string $employeeId, string $name, string $email, int $departmentId, string $joinedOn): void {
    // TODO（依頼者）：社員を追加する
    // 1. SQL を書く：INSERT INTO employees (employee_id, name, email, department_id, joined_on) VALUES (:employee_id, :name, :email, :department_id, :joined_on)
    // 2. prepare → bindValue（:department_id だけ数値なので PDO::PARAM_INT、ほかは PDO::PARAM_STR）→ execute
    // throw new RuntimeException('insertEmployee は未実装です');
    $sql = 'INSERT INTO employees (employee_id, name, email, department_id, joined_on) VALUES (:employee_id, :name, :email, :department_id, :joined_on)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':department_id', $departmentId, PDO::PARAM_INT);
    $stmt->bindValue(':joined_on', $joinedOn, PDO::PARAM_STR);
    $stmt->execute();
}

// アカウント管理の一覧：社員・管理者の全員（退職も含む）を、社員コード順に取得する
// システム管理者は employees に無いので、JOIN で自然に除かれる
// 戻り値：行の配列。[['employee_id', 'name', 'email', 'joined_on', 'left_on', 'employment_status', 'department_name', 'role', 'approval_scope', 'is_active'], …]
function fetchAccounts(PDO $pdo): array {
    // TODO（依頼者）：3つの表（社員・部署・ログイン情報）を JOIN して取得する
    // 1. SQL を書く：
    //    SELECT e.employee_id, e.name, e.email, e.joined_on, e.left_on, e.employment_status,
    //      d.name AS department_name, l.role, l.approval_scope, l.is_active
    //    FROM employees e
    //    JOIN departments d ON d.id = e.department_id
    //    JOIN login_accounts l ON l.employee_id = e.employee_id
    //    ORDER BY e.employee_id
    // 2. prepare → execute（プレースホルダが無いので、bindValue は不要）
    // 3. fetchAll() の結果を返す
    // throw new RuntimeException('fetchAccounts は未実装です');
    $sql = 'SELECT e.employee_id, e.name, e.email, e.joined_on, e.left_on, e.employment_status,
        d.name AS department_name, l.role, l.approval_scope, l.is_active
    FROM employees e
    JOIN departments d ON d.id = e.department_id
    JOIN login_accounts l ON l.employee_id = e.employee_id
    ORDER BY e.employee_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 社員の在籍状態と退社日を変える（無効化：'retired' と退社日、有効に戻す：'active' と null）
// ログイン情報の利用可否（updateLoginActive()）と一緒に、呼び出し側（src/admin.php）が1つのトランザクションで呼ぶ
function updateEmploymentStatus(PDO $pdo, string $employeeId, string $status, ?string $leftOn): void {
    // TODO（依頼者）：在籍状態と退社日を変える
    // 1. SQL を書く：UPDATE employees SET employment_status = :status, left_on = :left_on WHERE employee_id = :employee_id
    // 2. prepare → bindValue（3つとも PDO::PARAM_STR。$leftOn が null なら、そのまま渡せば DB に NULL が入る）→ execute
    // throw new RuntimeException('updateEmploymentStatus は未実装です');
    $sql = 'UPDATE employees SET employment_status = :status, left_on = :left_on WHERE employee_id = :employee_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':left_on', $leftOn, PDO::PARAM_STR);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->execute();
}
