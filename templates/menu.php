<?php
// ハンバーガーメニュー（ほかの画面への移動）。templates/header.php から読み込む（ログイン中のみ）
// 項目は権限ごとに出し分ける（承認は管理者だけ）。システム管理者の画面は、PR 7 で追加する
$menuItems = [['path' => '', 'label' => 'メイン画面']];
if ($currentUser['role'] !== ROLE_SYSTEM_ADMIN) {
    $menuItems[] = ['path' => 'records/', 'label' => '記録表示'];
}
if ($currentUser['role'] === ROLE_MANAGER) {
    $menuItems[] = ['path' => 'approvals/', 'label' => '承認'];
}
// 今いる画面の項目に印を付ける（index.php は、フォルダの URL と同じ扱い）
$currentPath = preg_replace('#index\.php$#', '', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
?>
<dialog class="menu" id="menu" aria-label="メニュー">
    <nav class="menu__body">
        <div class="menu__head">
            <span class="menu__title">メニュー</span>
            <button class="menu__close" type="button" id="menu-close" aria-label="メニューを閉じる"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
        </div>
        <?php foreach ($menuItems as $item): ?>
            <a class="menu__link" href="<?= h(appUrl($item['path'])) ?>"<?= appUrl($item['path']) === $currentPath ? ' aria-current="page"' : '' ?>><?= h($item['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</dialog>
