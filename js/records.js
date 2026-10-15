// 記録表示：日付を押したときに、その日の記録をウィンドウで表示する
// 表示する値は、サーバーが画面に埋め込んだもの（#calendar の data-days）を使う
(() => {
    'use strict';

    const calendar = document.getElementById('calendar');
    const dialog = document.getElementById('day-dialog');
    if (!calendar || !dialog) {
        return;
    }
    const days = JSON.parse(calendar.dataset.days);
    const text = (id, value) => {
        document.getElementById(id).textContent = value;
    };

    calendar.addEventListener('click', (event) => {
        const button = event.target.closest('[data-date]');
        if (!button) {
            return;
        }
        const day = days[button.dataset.date];
        text('day-title', day.label);
        const holiday = document.getElementById('day-holiday');
        holiday.textContent = day.holiday;
        holiday.hidden = day.holiday === '';
        document.getElementById('day-detail').hidden = !day.hasRecord;
        document.getElementById('day-empty').hidden = day.hasRecord;
        text('day-check-in', day.checkIn);
        text('day-check-out', day.checkOut);
        text('day-break', day.break);
        text('day-work-time', day.workTime);
        dialog.showModal();
    });

    // ウィンドウの外（暗い部分）を押したら閉じる
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
})();
