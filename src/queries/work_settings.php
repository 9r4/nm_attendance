<?php
// 勤務設定（work_settings テーブル）を読み書きする関数

// 勤務設定（全社共通の1行）を取得する
// 戻り値：['standard_start' => '09:00:00', 'standard_end' => '18:00:00', 'lunch_start' => '12:00:00', 'lunch_end' => '13:00:00']
function fetchWorkSettings(PDO $pdo): array
{
    // TODO（依頼者）：勤務設定（work_settings の id = 1 の行）を取得して返す
    // 1. SQL を書く：work_settings から standard_start, standard_end, lunch_start, lunch_end を取得（条件は id = :id）
    // 2. $pdo->prepare() で準備する
    // 3. :id に 1 を渡す（bindParam は変数を渡すので、$id = 1; を用意。型は PDO::PARAM_INT）
    // 4. execute() して、fetch() で1行取得する
    // 5. 取得できなかった（false）ときは、例外を投げる：throw new RuntimeException('勤務設定がありません');
    // 6. 取得した行（連想配列）を return する
    throw new RuntimeException('fetchWorkSettings は未実装です');
}
