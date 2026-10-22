<?php
// 社員コードの採番用のカウンタ（employee_id_sequences テーブル）を読み書きする関数
// 値は bindValue で渡す（D-060）。年は数値なので、型は PDO::PARAM_INT

// その年の通番を1つ進めて、進めたあとの通番を返す（その年の最初の1人なら 1）。社員コードの採番に使う（D-013、D-067）
// 呼び出し側（src/admin.php）がトランザクションの中で呼ぶ。行は、トランザクションが終わるまでロックされるので、同時に作っても重複しない
// 戻り値：通番（例：1、2、…）
function nextEmployeeNumber(PDO $pdo, int $year): int {
    // TODO（依頼者）：通番を1つ進めて、読み直す（1つの関数で、SQL を2つ順に実行する）
    // 1. 1つ目の SQL：その年の行が無ければ 1 で作り、あれば +1 する
    //    INSERT INTO employee_id_sequences (year, last_number) VALUES (:year, 1) AS new
    //      ON DUPLICATE KEY UPDATE last_number = employee_id_sequences.last_number + 1
    //    （employee_id_sequences.last_number は「今、表にある値」。new は「追加しようとした値」に付けた別名で、ここでは使わない）
    // 2. prepare → :year に $year を bindValue（PDO::PARAM_INT）→ execute
    // 3. 2つ目の SQL：SELECT last_number FROM employee_id_sequences WHERE year = :year
    // 4. prepare → :year に $year を bindValue（PDO::PARAM_INT）→ execute → fetchColumn() で値を1つ取り出す
    // 5. 取り出した値を、数値にして返す：return (int) $value;
    // throw new RuntimeException('nextEmployeeNumber は未実装です');
    $sql = 'INSERT INTO employee_id_sequences (year, last_number) VALUES (:year, 1) AS new
        ON DUPLICATE KEY UPDATE last_number = employee_id_sequences.last_number + 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':year', $year, PDO::PARAM_INT);
    $stmt->execute();

    $sql2 = 'SELECT last_number FROM employee_id_sequences WHERE year = :year';
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->bindValue(':year', $year, PDO::PARAM_INT);
    $stmt2->execute();
    $value = $stmt2->fetchColumn();

    return (int) $value;
}
