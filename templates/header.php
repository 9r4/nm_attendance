<?php
// 画面の共通の上部（<head> と、画面上部のヘッダー）
// 使う前に $pageTitle（画面の名前）を用意しておく
$pageTitle = $pageTitle ?? '';
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle === '' ? '勤怠管理' : $pageTitle . '｜勤怠管理') ?></title>
    <link rel="stylesheet" href="https://unpkg.com/ress@5.0.2/dist/ress.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+JP:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=block">
    <link rel="stylesheet" href="<?= h(appUrl('css/style.css')) ?>">
</head>

<body>
    <header class="app-header">
        <a class="app-header__brand" href="<?= h(appUrl()) ?>">
            <span class="material-symbols-outlined app-header__icon" aria-hidden="true">schedule</span>
            <span class="app-header__name">勤怠管理</span>
        </a>
    </header>
