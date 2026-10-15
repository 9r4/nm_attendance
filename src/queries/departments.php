<?php
// 部署マスタ（departments テーブル）を読み書きする関数
// 値は bindValue で渡す（D-060）。部署 ID と利用可否（1／0）は数値なので PDO::PARAM_INT、部署名は PDO::PARAM_STR

// 部署を全部（無効も含めて）、登録した順に取得する。在籍している社員の数も数える（部署の画面と、アカウント作成の選択欄に使う）
// 戻り値：行の配列。[['id' => 1, 'name' => '営業部', 'is_active' => 1, 'active_count' => 2], …]
function fetchDepartments(PDO $pdo): array {
    // TODO（依頼者）：部署ごとに、在籍している社員の数を数える（初めての GROUP BY と COUNT）
    // 1. SQL を書く：
    //    SELECT d.id, d.name, d.is_active, COUNT(e.employee_id) AS active_count
    //    FROM departments d
    //    LEFT JOIN employees e ON e.department_id = d.id AND e.employment_status = 'active'
    //    GROUP BY d.id, d.name, d.is_active
    //    ORDER BY d.id
    //    - GROUP BY：部署ごとに行をまとめる。COUNT(列)：まとめた行のうち、その列が NULL でない数
    //    - 社員がいない部署は、LEFT JOIN で社員の列が NULL になるので、COUNT は 0 になる
    //    - 在籍の条件（e.employment_status = 'active'）は、WHERE ではなく ON に書く（WHERE に書くと、社員がいない部署の行が消える）
    // 2. prepare → execute（プレースホルダが無いので、bindValue は不要）
    // 3. fetchAll() の結果を返す
    throw new RuntimeException('fetchDepartments は未実装です');
}

// 部署を追加する（利用可否は DB の既定値の「有効（1）」になる）
// 同じ名前の部署があると、DB の一意制約のエラー（PDOException）になる。呼び出し側（src/admin.php）が受け止めるので、ここでは確かめない
function insertDepartment(PDO $pdo, string $name): void {
    // TODO（依頼者）：部署を追加する
    // 1. SQL を書く：INSERT INTO departments (name) VALUES (:name)
    // 2. prepare → :name に $name を bindValue（PDO::PARAM_STR）→ execute
    throw new RuntimeException('insertDepartment は未実装です');
}

// 部署の名前を変える（同じ名前の部署があると、一意制約のエラーになる。insertDepartment() と同じく、ここでは確かめない）
function updateDepartmentName(PDO $pdo, int $id, string $name): void {
    // TODO（依頼者）：部署の名前を変える
    // 1. SQL を書く：UPDATE departments SET name = :name WHERE id = :id
    // 2. prepare → :name（PDO::PARAM_STR）と :id（PDO::PARAM_INT）を bindValue → execute
    throw new RuntimeException('updateDepartmentName は未実装です');
}

// 部署を有効・無効にする。$isActive：true＝有効、false＝無効
function updateDepartmentActive(PDO $pdo, int $id, bool $isActive): void {
    // TODO（依頼者）：部署の利用可否を変える
    // 1. SQL を書く：UPDATE departments SET is_active = :is_active WHERE id = :id
    // 2. prepare → bindValue：
    //    - :is_active には $isActive ? 1 : 0 を渡す（DB の列は数値の 1／0 なので PDO::PARAM_INT）
    //    - :id には $id を渡す（PDO::PARAM_INT）
    // 3. execute
    throw new RuntimeException('updateDepartmentActive は未実装です');
}
