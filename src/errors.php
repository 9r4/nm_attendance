<?php
// 共通のエラー処理
// 想定外の例外は、詳細をエラーログに記録し、画面には定型の文（HTTP 500）だけを出す。

ini_set('display_errors', '0');

set_exception_handler(function (\Throwable $e) {
    error_log($e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('エラーが発生しました。時間をおいて、もう一度お試しください。');
});
