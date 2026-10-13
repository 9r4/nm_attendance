<?php
// メイン画面（PR 1 では、DB の接続を確かめるための仮の画面。PR 3 で本来のメイン画面に置き換える）
include_once(__DIR__ . '/src/functions.php');
include_once(__DIR__ . '/src/dbconnect.php');
include_once(__DIR__ . '/src/queries/work_settings.php');

$settings = fetchWorkSettings($pdo);

$pageTitle = 'メイン（仮）';
include(__DIR__ . '/templates/header.php');
?>
<main class="page">
    <section class="card">
        <h1 class="card__title">勤務設定（DB から取得）</h1>
        <p class="card__note">PR 1 の確認用の仮の画面です。DB の接続と取得ができていれば、下に勤務設定が表示されます。</p>
        <dl class="settings">
            <div class="settings__row">
                <dt>始業</dt>
                <dd><?= h(formatTime($settings['standard_start'])) ?></dd>
            </div>
            <div class="settings__row">
                <dt>終業</dt>
                <dd><?= h(formatTime($settings['standard_end'])) ?></dd>
            </div>
            <div class="settings__row">
                <dt>昼休憩</dt>
                <dd><?= h(formatTime($settings['lunch_start'])) ?>〜<?= h(formatTime($settings['lunch_end'])) ?></dd>
            </div>
        </dl>
    </section>
</main>
<?php include(__DIR__ . '/templates/footer.php'); ?>
