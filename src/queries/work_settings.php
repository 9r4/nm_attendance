<?php
// 勤務設定（work_settings テーブル）を読み書きする関数

// 勤務設定（全社共通の1行）を取得する
// 戻り値：['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00', 'updated_at' => '2026-10-15 10:00:00']
// TODO（依頼者・PR 7）：取得する列に、最終更新日時 updated_at を追加する（勤務設定の画面に出す）
function fetchWorkSettings(PDO $pdo): array {
    // TODO（依頼者）：勤務設定（work_settings の id = 1 の行）を取得して返す
    // 1. SQL を書く：work_settings から standard_start, standard_end, lunch_start, lunch_end を取得（条件は id = :id）
    // 2. $pdo->prepare() で準備する
    // 3. :id に 1 を渡す（bindParam は変数を渡すので、$id = 1; を用意。型は PDO::PARAM_INT）
    // 4. execute() して、fetch() で1行取得する
    // 5. 取得できなかった（false）ときは、例外を投げる：throw new RuntimeException('勤務設定がありません');
    // 6. 取得した行（連想配列）を return する

    // throw new RuntimeException('fetchWorkSettings は未実装です');

    $sql = 'SELECT standard_start, standard_end, lunch_start, lunch_end, updated_at FROM work_settings WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $id = 1;
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('勤務設定がありません');
    }
    return $row;
}

// 勤務設定を変える（PR 7。全社共通の1行。変えた日の履歴は持たない。D-019、D-067）
// $settings：['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00']（呼び出し側が確認済み）
function updateWorkSettings(PDO $pdo, array $settings): void {
    // TODO（依頼者）：勤務設定の4つの時刻を変える（updated_at は、DB が自動で今の日時にする）
    // 1. SQL を書く：UPDATE work_settings SET standard_start = :standard_start, standard_end = :standard_end, lunch_start = :lunch_start, lunch_end = :lunch_end WHERE id = :id
    // 2. prepare → bindValue：
    //    - 4つの時刻は $settings['standard_start'] などを渡す（PDO::PARAM_STR）
    //    - :id には 1 を渡す（PDO::PARAM_INT）
    // 3. execute
    // throw new RuntimeException('updateWorkSettings は未実装です');
    $sql = 'UPDATE work_settings SET standard_start = :standard_start, standard_end = :standard_end, lunch_start = :lunch_start, lunch_end = :lunch_end WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':standard_start', $settings['standard_start'], PDO::PARAM_STR);
    $stmt->bindValue(':standard_end', $settings['standard_end'], PDO::PARAM_STR);
    $stmt->bindValue(':lunch_start', $settings['lunch_start'], PDO::PARAM_STR);
    $stmt->bindValue(':lunch_end', $settings['lunch_end'], PDO::PARAM_STR);
    $stmt->bindValue(':id', 1, PDO::PARAM_INT);
    $stmt->execute();
}
