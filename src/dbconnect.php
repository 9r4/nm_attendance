<?php
// DB に接続して、接続結果を $pdo に入れる（ほかのファイルは $pdo を使う）
// 書き方の見本：docs/PHPコーディング方針.md の 4章「src/dbconnect.php」

include_once(__DIR__ . '/errors.php');

// TODO（依頼者）：DB に接続する
// 1. try { … } catch (\PDOException $e) { … } で囲む
// 2. try の中：new PDO(…) で接続して、$pdo に入れる
//    - DSN：'mysql:dbname=attendance_training;host=localhost;charset=utf8mb4'
//    - ユーザー：'attendance_app'
//    - パスワード：attendance_app の実際のパスワード（この DB 専用のもの）
//    - オプション（配列で渡す）：
//        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION（エラーを例外にする）
//        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC（取得結果を連想配列にする）
//        PDO::ATTR_EMULATE_PREPARES => false（SQL とデータを DB 側で分けて処理させる）
// 3. 接続できたら、$pdo->exec("SET time_zone = '+09:00'"); で日本時間にする
// 4. catch の中：
//    - error_log($e->getMessage()); で、エラーの内容をログに記録する（画面には出さない）
//    - header('Content-Type: text/plain; charset=UTF-8', true, 500); で、エラーの状態にする
//    - exit('データベースに接続できません。'); で、定型の文を出して終わる
throw new RuntimeException('src/dbconnect.php は未実装です');
