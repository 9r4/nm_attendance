<?php
// 画面の共通の上部（<head> と、画面上部のヘッダー）
// 使う前に $pageTitle（画面の名前）を用意しておく。ログイン中の画面では $currentUser（requireLogin() の戻り値）も用意する
// 状態バッジを出す画面（メイン画面）では、$headerStatus（attendanceView() の戻り値）も用意する
$pageTitle = $pageTitle ?? '';
$currentUser = $currentUser ?? null;
$headerStatus = $headerStatus ?? null;
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
    <?php if ($currentUser !== null): ?>
        <script src="<?= h(appUrl('js/menu.js')) ?>" defer></script>
    <?php endif; ?>
</head>

<body>
    <header class="app-header">
        <div class="app-header__left">
            <?php if ($currentUser !== null): ?>
                <button class="app-header__menu-button" type="button" id="menu-open" aria-label="メニューを開く" aria-controls="menu" aria-expanded="false">
                    <span class="material-symbols-outlined" aria-hidden="true">menu</span>
                </button>
            <?php endif; ?>
            <a class="app-header__brand" href="<?= h(appUrl()) ?>">
                <span class="material-symbols-outlined app-header__icon" aria-hidden="true">schedule</span>
                <span class="app-header__name">勤怠管理</span>
            </a>
        </div>
        <?php if ($currentUser !== null): ?>
            <div class="app-header__user">
                <div class="app-header__profile">
                    <div class="app-header__user-name"><?= h($currentUser['name']) ?></div>
                    <?php if ($currentUser['department_name'] !== ''): ?>
                        <div class="app-header__department"><?= h($currentUser['department_name']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($headerStatus !== null): ?>
                    <span class="status-badge status-badge--<?= h(str_replace('_', '-', $headerStatus['status'])) ?>" id="status-badge"><?= h($headerStatus['statusLabel']) ?></span>
                <?php endif; ?>
                <form method="post" action="<?= h(appUrl('logout.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <button class="button button--outline" type="submit">ログアウト</button>
                </form>
            </div>
        <?php endif; ?>
    </header>
    <?php if ($currentUser !== null): ?>
        <?php include(__DIR__ . '/menu.php'); ?>
    <?php endif; ?>
