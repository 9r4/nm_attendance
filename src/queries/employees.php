<?php
// 社員（employees テーブル）を読み書きする関数

// 社員 ID で、社員の氏名と部署名を取得する（ヘッダーの表示に使う）
// 戻り値：['employee_id' => '2025001', 'name' => '山田 太郎', 'department_name' => '営業部']。見つからなければ null
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
    throw new RuntimeException('fetchEmployeeProfile は未実装です');
}
