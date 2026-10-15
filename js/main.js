// メイン画面：時計と、出退勤・休憩のボタン
// 押せるかどうか・上書き確認が要るかは、サーバー（api/attendance.php）が判断する。ここでは、結果で画面を書き換えるだけ
(() => {
    'use strict';

    // ---- 時計（サーバーの時計に合わせて、日本時間で表示する） ----
    const clock = document.getElementById('clock');
    if (!clock) {
        return;
    }
    const clockDate = document.getElementById('clock-date');
    const clockTime = document.getElementById('clock-time');
    const offset = Number(clock.dataset.serverTime) - Date.now();
    const formatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });
    let shownDate = null;

    function tick() {
        const parts = {};
        for (const part of formatter.formatToParts(new Date(Date.now() + offset))) {
            parts[part.type] = part.value;
        }
        clockDate.textContent = `${parts.year}年${Number(parts.month)}月${Number(parts.day)}日（${parts.weekday}）`;
        clockTime.textContent = `${parts.hour}:${parts.minute}:${parts.second}`;

        // 表示中に日付が変わったら、新しい日の記録を表示するため、画面を読み込み直す
        const date = `${parts.year}-${parts.month}-${parts.day}`;
        if (shownDate === null) {
            shownDate = date;
        } else if (date !== shownDate) {
            window.location.reload();
            return;
        }
        // 次の秒の変わり目で、もう一度表示する
        setTimeout(tick, 1000 - ((Date.now() + offset) % 1000) + 5);
    }
    tick();

    // ---- 出退勤・休憩のボタン ----
    const attendance = document.getElementById('attendance');
    if (!attendance) {
        return; // システム管理者（ボタンなし）
    }
    const buttons = attendance.querySelectorAll('[data-action]');
    const statusBadge = document.getElementById('status-badge');
    const toast = document.getElementById('toast');
    const dialog = document.getElementById('confirm-dialog');
    const overtimeButton = document.getElementById('overtime-open');
    let currentView = JSON.parse(attendance.dataset.view);
    let pendingAction = null;
    let toastTimer = null;

    // サーバーから受け取った表示（状態・本日の記録・押せるボタン）を、画面に反映する
    function render(view) {
        currentView = view;
        document.getElementById('today-check-in').textContent = view.checkIn;
        document.getElementById('today-check-out').textContent = view.checkOut;
        document.getElementById('today-break').textContent = view.break;
        if (statusBadge) {
            statusBadge.textContent = view.statusLabel;
            statusBadge.className = `status-badge status-badge--${view.status.replace('_', '-')}`;
        }
        buttons.forEach((button) => {
            button.disabled = !view.buttons[button.dataset.action];
        });
        overtimeButton.disabled = !view.buttons.overtime;
        // 今日の修正・新規登録が申請中のときは、打刻できないことを出す（D-063）
        document.getElementById('punch-lock').hidden = !view.locked;
    }

    function showToast(message, isError) {
        clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.toggle('toast--error', isError);
        toast.hidden = false;
        toastTimer = setTimeout(() => {
            toast.hidden = true;
        }, isError ? 8000 : 4000);
    }

    // 送信中は、二重に押せないようにする
    function setBusy(busy) {
        if (busy) {
            buttons.forEach((button) => {
                button.disabled = true;
            });
        } else {
            render(currentView);
        }
    }

    async function send(action, overwrite) {
        setBusy(true);
        const body = new FormData();
        body.append('csrf_token', attendance.dataset.csrfToken);
        body.append('action', action);
        if (overwrite) {
            body.append('overwrite', '1');
        }

        let response;
        try {
            response = await fetch(attendance.dataset.apiUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
        } catch (error) {
            setBusy(false);
            showToast('通信できませんでした。接続を確かめて、もう一度お試しください。', true);
            return;
        }

        // ログインが切れていたら（自動ログアウトなど）、ログイン画面へ
        if (response.status === 401) {
            window.location.href = attendance.dataset.loginUrl;
            return;
        }

        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = null;
        }
        if (!response.ok || data === null) {
            setBusy(false);
            showToast(data?.message ?? 'エラーが発生しました。画面を読み込み直して、もう一度お試しください。', true);
            return;
        }

        render(data.view);
        if (data.result === 'confirm') {
            pendingAction = action;
            document.getElementById('confirm-title').textContent = data.confirm.title;
            document.getElementById('confirm-text').textContent = data.confirm.body;
            dialog.showModal();
        } else {
            showToast(data.message, data.result === 'unavailable' || data.result === 'conflict');
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => send(button.dataset.action, false));
    });

    // 上書き確認：「上書きする」なら、もう一度送る（今の時刻で上書き）。キャンセル・Esc なら何もしない
    dialog.addEventListener('close', () => {
        const action = pendingAction;
        pendingAction = null;
        if (dialog.returnValue === 'ok' && action !== null) {
            send(action, true);
        }
        dialog.returnValue = '';
    });

    // ---- 簡単残業申請（FR-008）：api/requests.php に送る。入力の誤りはウィンドウの中に出す ----
    const overtimeDialog = document.getElementById('overtime-dialog');
    const overtimeForm = document.getElementById('overtime-form');
    const overtimeEnd = document.getElementById('overtime-end');
    const overtimeSubmit = document.getElementById('overtime-submit');
    const overtimeErrors = ['form', 'overtime_end'].map((name) => document.getElementById(`overtime-error-${name}`));

    function showOvertimeErrors(errors) {
        overtimeErrors.forEach((error) => {
            error.hidden = true;
            error.textContent = '';
        });
        overtimeEnd.removeAttribute('aria-invalid');
        Object.entries(errors).forEach(([name, message]) => {
            const error = document.getElementById(`overtime-error-${name}`) ?? overtimeErrors[0];
            error.textContent = message;
            error.hidden = false;
            if (name === 'overtime_end') {
                overtimeEnd.setAttribute('aria-invalid', 'true');
                overtimeEnd.focus();
            }
        });
    }

    overtimeButton.addEventListener('click', () => {
        overtimeForm.reset();
        showOvertimeErrors({});
        // 退勤時刻は、最新の表示の値。最初は退勤時刻を入れておく（画面モックのとおり）
        document.getElementById('overtime-check-out').textContent = currentView.checkOut;
        overtimeEnd.value = currentView.checkOut;
        overtimeSubmit.disabled = false;
        overtimeDialog.showModal();
        overtimeEnd.focus();
    });
    document.getElementById('overtime-cancel').addEventListener('click', () => overtimeDialog.close());
    overtimeDialog.addEventListener('click', (event) => {
        if (event.target === overtimeDialog) {
            overtimeDialog.close();
        }
    });

    overtimeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        overtimeSubmit.disabled = true;
        const body = new FormData(overtimeForm);
        body.append('csrf_token', attendance.dataset.csrfToken);
        body.append('type', 'overtime');

        let data = null;
        try {
            const response = await fetch(attendance.dataset.requestApiUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 401) {
                window.location.href = attendance.dataset.loginUrl;
                return;
            }
            data = await response.json().catch(() => null);
            if (!response.ok || data === null) {
                showOvertimeErrors({ form: data?.message ?? 'エラーが発生しました。画面を読み込み直して、もう一度お試しください。' });
                overtimeSubmit.disabled = false;
                return;
            }
        } catch (error) {
            showOvertimeErrors({ form: '通信できませんでした。接続を確かめて、もう一度お試しください。' });
            overtimeSubmit.disabled = false;
            return;
        }

        if (data.ok) {
            overtimeDialog.close();
            showToast(data.message, false);
            return;
        }
        showOvertimeErrors(data.errors);
        overtimeSubmit.disabled = false;
    });
})();
