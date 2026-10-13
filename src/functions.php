<?php
// 共通の関数

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
