<?php
// ログアウト（FR-002）。画面のログアウトボタン（POST）からのみ受け付ける
include_once(__DIR__ . '/src/functions.php');
include_once(__DIR__ . '/src/errors.php');
include_once(__DIR__ . '/src/auth.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    abort(405, 'ログアウトは、画面のログアウトボタンから行ってください。');
}

startAppSession();
// すでにログアウトしている（自動ログアウトなど）なら、確かめずにログイン画面へ移す。
// ログインしていない人を「ログアウト」させても何も起きないので、CSRF の確認は要らない（D-069）
if (!isset($_SESSION['user'])) {
    redirect('login.php');
}
requireValidCsrfToken();
logoutUser();
redirect('login.php');
