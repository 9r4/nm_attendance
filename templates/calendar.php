<?php
// 月カレンダー（記録表示と、社員の勤怠の詳細で使う。D-070）：月の切り替え・出勤日数と総労働時間・カレンダー・その日の記録と申請のウィンドウ
// 日付を押したときの動きは js/records.js。読み込む前に、次を用意しておく
// $month：resolveMonth() の戻り値。$calendar：buildCalendar() の戻り値。$days：calendarDays() の戻り値
// $monthQuery：月の切り替えのリンクの、month= より前の部分（記録表示は '?'、詳細は '?id=2025001&'）
// $monthHeading：月の見出しのタグ（画面の一番大きい見出しなら 'h1'、ほかに h1 がある画面は 'h2'）
// $readOnly：見るだけなら true（「申請」ボタンと、申請の API の URL・CSRF トークンを出さない）
// $readOnly が false のときは、$requestMin・$requestMax（「申請」ボタンで選べる日の範囲）も用意する
$monthHeading = $monthHeading === 'h2' ? 'h2' : 'h1';
?>
    <div class="records__bar">
        <nav class="month-nav" aria-label="月の切り替え">
            <?php if ($month['prev'] !== null): ?>
                <a class="month-nav__button" href="<?= h($monthQuery . 'month=' . $month['prev']) ?>" aria-label="前の月"><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></a>
            <?php else: ?>
                <span class="month-nav__button month-nav__button--disabled" aria-hidden="true"><span class="material-symbols-outlined">chevron_left</span></span>
            <?php endif; ?>
            <<?= $monthHeading ?> class="month-nav__label"><?= h($month['label']) ?></<?= $monthHeading ?>>
            <?php if ($month['next'] !== null): ?>
                <a class="month-nav__button" href="<?= h($monthQuery . 'month=' . $month['next']) ?>" aria-label="次の月"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></a>
            <?php else: ?>
                <span class="month-nav__button month-nav__button--disabled" aria-hidden="true"><span class="material-symbols-outlined">chevron_right</span></span>
            <?php endif; ?>
        </nav>
        <div class="summary">
            <span class="summary__item">出勤日数 <b id="summary-days"><?= h($calendar['workDays']) ?>日</b></span>
            <span class="summary__item">総労働時間 <b id="summary-total"><?= h(formatMinutes($calendar['totalMinutes'])) ?></b></span>
        </div>
        <?php if (!$readOnly && $requestMin <= $requestMax): ?>
            <button class="button button--primary records__request" type="button" id="request-open">申請</button>
        <?php endif; ?>
    </div>

    <!-- 見るだけのときは、申請の API の URL と CSRF トークンを出さない（js/records.js は data-readonly で、見るだけの動きにする） -->
    <?php if ($readOnly): ?>
        <div class="calendar" id="calendar" data-readonly="true"
            data-days="<?= h(json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <?php else: ?>
        <div class="calendar" id="calendar"
            data-days="<?= h(json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"
            data-api-url="<?= h(appUrl('api/requests.php')) ?>"
            data-login-url="<?= h(appUrl('login.php')) ?>"
            data-csrf-token="<?= h(csrfToken()) ?>">
    <?php endif; ?>
        <div class="calendar__head" aria-hidden="true">
            <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $weekdayName): ?>
                <div class="calendar__weekday calendar__weekday--<?= h($i) ?>"><?= h($weekdayName) ?></div>
            <?php endforeach; ?>
        </div>
        <?php foreach ($calendar['weeks'] as $week): ?>
            <div class="calendar__week">
                <?php foreach ($week as $cell): ?>
                    <?php if ($cell === null): ?>
                        <div class="calendar__blank"></div>
                    <?php else: ?>
                        <?php
                        $classes = ['calendar__day'];
                        if ($cell['isRestDay']) {
                            $classes[] = 'calendar__day--rest';
                        }
                        if ($cell['isToday']) {
                            $classes[] = 'calendar__day--today';
                        }
                        $dateClass = ($cell['weekday'] === 0 || $cell['holiday'] !== '') ? ' calendar__date--sunday' : ($cell['weekday'] === 6 ? ' calendar__date--saturday' : '');
                        $ariaLabel = $cell['label'] . ($cell['holiday'] !== '' ? ' ' . $cell['holiday'] : '') . ($cell['badge'] !== null ? ' ' . $cell['badgeLabel'] : '') . 'の記録';
                        ?>
                        <button class="<?= h(implode(' ', $classes)) ?>" type="button" data-date="<?= h($cell['date']) ?>" aria-label="<?= h($ariaLabel) ?>"<?= $cell['isToday'] ? ' aria-current="date"' : '' ?>>
                            <span class="calendar__top">
                                <span class="calendar__date<?= h($dateClass) ?>"><?= h($cell['day']) ?></span>
                                <?php if ($cell['holiday'] !== ''): ?>
                                    <span class="calendar__holiday"><?= h($cell['holiday']) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($cell['hasRecord']): ?>
                                <span class="calendar__times">
                                    <span><span class="calendar__mark">出</span> <?= h($cell['checkIn']) ?></span>
                                    <span><span class="calendar__mark">退</span> <?= h($cell['checkOut']) ?></span>
                                </span>
                            <?php endif; ?>
                            <?php if ($cell['badge'] !== null): ?>
                                <span class="request-badge request-badge--<?= h($cell['badge']) ?>"><?= h($cell['badgeLabel']) ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- その日の記録と申請 -->
    <dialog class="dialog" id="day-dialog" aria-labelledby="day-title">
        <form method="dialog" class="dialog__body">
            <h2 class="dialog__title" id="day-title"></h2>
            <p class="dialog__note" id="day-holiday" hidden></p>
            <dl class="day-detail" id="day-detail">
                <div class="day-detail__row"><dt>出勤</dt><dd id="day-check-in"></dd></div>
                <div class="day-detail__row"><dt>退勤</dt><dd id="day-check-out"></dd></div>
                <div class="day-detail__row"><dt>休憩</dt><dd id="day-break"></dd></div>
                <div class="day-detail__row"><dt>労働時間</dt><dd id="day-work-time"></dd></div>
            </dl>
            <p class="dialog__text" id="day-empty" hidden>記録はありません。</p>
            <section class="request-list" id="day-requests" hidden>
                <h3 class="request-list__title">申請</h3>
                <ul class="request-list__items" id="day-request-items"></ul>
            </section>
            <p class="alert alert--info" id="day-pending" hidden>申請中のため、新しい申請はできません。新しく申請する場合は、申請中の申請を取り消してください。</p>
            <div class="dialog__actions">
                <button class="button button--outline" type="submit" value="close">閉じる</button>
                <button class="button button--primary" type="button" id="day-request" hidden></button>
            </div>
        </form>
    </dialog>
