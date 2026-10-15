<?php
// 社員（employees テーブル）を読み書きする関数

// 社員 ID で、社員の氏名と部署名を取得する（ヘッダーの表示に使う）
// 戻り値：['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部', 'joined_on' => '2025-04-01']。見つからなければ null
// TODO（依頼者・PR 4）：取得する列に、入社日 e.joined_on を追加する（記録表示で、前の月へ戻れる範囲に使う）
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
    $sql = 'SELECT e.employee_id, e.name, d.name AS department_name FROM employees e JOIN departments d ON e.department_id = d.id WHERE e.employee_id = :employee_id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
