<?php
// セッション（ログイン状態の保持）と、CSRF 対策

// 無操作で自動ログアウトするまでの時間（秒）。2時間（2026-10-13 依頼者承認）
const SESSION_IDLE_SECONDS = 7200;

// セッションを開始する。無操作の時間が長すぎる場合は、ログイン状態を消す
function startAppSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // セッションの保存場所をアプリ専用にする。
    // Ubuntu の標準の場所は、約24分で古いセッションを消す仕組みがあり、2時間を守れないため
    $savePath = sys_get_temp_dir() . '/attendance_sessions';
    if (!is_dir($savePath)) {
        mkdir($savePath, 0700, true);
    }
    session_save_path($savePath);
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_SECONDS);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_strict_mode', '1');

    session_name('ATTENDANCE_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => appUrl(),
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['user'], $_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['timed_out'] = true;
    }
    $_SESSION['last_activity'] = $now;
}

// CSRF トークン（フォームに埋め込む、推測できない文字列）を返す
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// 送られてきた CSRF トークンが正しくなければ、処理を止める
function requireValidCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        abort(400, '不正なリクエストです。画面を開き直して、もう一度お試しください。');
    }
}
