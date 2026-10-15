<?php
// 共通の関数

// 時刻は、すべて日本時間（BR-004）
date_default_timezone_set('Asia/Tokyo');

// 画面に出す値をエスケープする（XSS 対策）。NULL は空文字にする
function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// アプリのパスから URL を作る（例：appUrl('css/style.css') → /attendance/css/style.css）
// アプリを置いた場所（/var/www/html/attendance など）に合わせて、先頭のパスを自動で付ける
function appUrl(string $path = ''): string {
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appRoot = realpath(__DIR__ . '/..');
    $base = '';
    if ($documentRoot !== false && $appRoot !== false && str_starts_with($appRoot, $documentRoot)) {
        $base = str_replace('\\', '/', substr($appRoot, strlen($documentRoot)));
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// 'HH:MM:SS' 形式の時刻を 'HH:MM' にする（NULL は '--:--'）
function formatTime(?string $time): string {
    return $time === null ? '--:--' : substr($time, 0, 5);
}

// アプリ内の画面へ移動する（例：redirect('login.php')）
function redirect(string $path = ''): never {
    header('Location: ' . appUrl($path));
    exit;
}

// JSON を返して、処理を終える（API の応答）
function jsonResponse(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

// 定型の文と HTTP の状態を返して、処理を終える（例：abort(403, 'この画面を使う権限がありません。')）
function abort(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}
