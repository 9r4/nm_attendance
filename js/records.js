// 記録表示：日付を押したときの表示（その日の記録と申請）と、修正・新規登録の申請ウィンドウ
// 表示する値は、サーバーが画面に埋め込んだもの（#calendar の data-days）を使う
// 申請は api/requests.php に送る。入力の誤りはウィンドウの中に出し、受け付けられたら画面を読み込み直す
(() => {
    'use strict';

    const calendar = document.getElementById('calendar');
    const dayDialog = document.getElementById('day-dialog');
    const requestDialog = document.getElementById('request-dialog');
    if (!calendar || !dayDialog || !requestDialog) {
        return;
    }
    const days = JSON.parse(calendar.dataset.days);
    const form = document.getElementById('request-form');
    const dateInput = document.getElementById('request-date');
    const submitButton = document.getElementById('request-submit');
    const timeNames = ['check_in', 'check_out', 'break_start', 'break_end'];
    const errorNames = ['form', 'target_date', ...timeNames, 'reason'];
    const element = (id) => document.getElementById(id);
    let currentDate = null;

    // 開いた画面のウィンドウの外（暗い部分）を押したら閉じる
    [dayDialog, requestDialog].forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    // ---- 通知 ----
    const toast = element('toast');
    if (toast && !toast.hidden) {
        setTimeout(() => {
            toast.hidden = true;
        }, 5000);
    }

    // ---- その日の記録と申請 ----
    function requestItem(request) {
        const item = document.createElement('li');
        item.className = 'request-item';

        const head = document.createElement('div');
        head.className = 'request-item__head';
        const type = document.createElement('span');
        type.className = 'request-item__type';
        type.textContent = request.type;
        const badge = document.createElement('span');
        badge.className = `request-badge request-badge--${request.status}`;
        badge.textContent = request.statusLabel;
        const created = document.createElement('span');
        created.className = 'request-item__date';
        created.textContent = `申請：${request.createdAt}`;
        head.append(type, badge, created);
        item.append(head);

        const changes = document.createElement('ul');
        changes.className = 'request-item__changes';
        request.changes.forEach((change) => {
            const line = document.createElement('li');
            line.textContent = `${change.label}　${change.before} → ${change.after}`;
            changes.append(line);
        });
        item.append(changes);

        [['理由', request.reason], ['却下コメント', request.reviewComment]].forEach(([label, text]) => {
            if (text !== '') {
                const note = document.createElement('p');
                note.className = 'request-item__note';
                note.textContent = `${label}：${text}`;
                item.append(note);
            }
        });

        // 申請中の申請は、取り消せる（D-063）
        if (request.canCancel) {
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'button button--outline request-item__cancel';
            cancel.textContent = '取り消す';
            cancel.addEventListener('click', () => openCancel(request));
            item.append(cancel);
        }
        return item;
    }

    function openDay(date) {
        const day = days[date];
        currentDate = date;
        element('day-title').textContent = day.label;
        const holiday = element('day-holiday');
        holiday.textContent = day.holiday;
        holiday.hidden = day.holiday === '';
        element('day-detail').hidden = !day.hasRecord;
        element('day-empty').hidden = day.hasRecord;
        element('day-check-in').textContent = day.checkIn;
        element('day-check-out').textContent = day.checkOut;
        element('day-break').textContent = day.break;
        element('day-work-time').textContent = day.workTime;

        const items = element('day-request-items');
        items.replaceChildren(...day.requests.map(requestItem));
        element('day-requests').hidden = day.requests.length === 0;
        element('day-pending').hidden = !day.pendingCorrection;

        // 記録がある日は「修正」、無い日は「申請する」（申請中・未来の日・入社前は出さない）
        const requestButton = element('day-request');
        requestButton.hidden = !day.canRequest;
        requestButton.textContent = day.hasRecord ? '修正' : '申請する';
        dayDialog.showModal();
    }

    calendar.addEventListener('click', (event) => {
        const button = event.target.closest('[data-date]');
        if (!button) {
            return;
        }
        const day = days[button.dataset.date];
        // 記録も申請も無い日は、そのまま空の申請ウィンドウを開く（D-051 ②）
        if (!day.hasRecord && day.requests.length === 0 && day.canRequest) {
            openRequest(button.dataset.date, true);
        } else {
            openDay(button.dataset.date);
        }
    });

    element('day-request').addEventListener('click', () => {
        dayDialog.close();
        openRequest(currentDate, true);
    });

    // ---- 申請ウィンドウ ----
    function clearErrors() {
        errorNames.forEach((name) => {
            const error = element(`request-error-${name}`);
            error.hidden = true;
            error.textContent = '';
        });
        form.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
    }

    function showErrors(errors) {
        clearErrors();
        let first = null;
        Object.entries(errors).forEach(([name, message]) => {
            const error = element(`request-error-${name}`) ?? element('request-error-form');
            error.textContent = message;
            error.hidden = false;
            const input = form.elements[name];
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                first ??= input;
            }
        });
        if (first) {
            first.focus();
        }
    }

    // 対象日の今の値を入れる（空欄は「なし」）。申請中の日は、申請できないことを出す
    function fillValues(date) {
        const day = days[date];
        timeNames.forEach((name) => {
            form.elements[name].value = day ? day.values[name] : '';
        });
        clearErrors();
        const pending = Boolean(day && day.pendingCorrection);
        submitButton.disabled = pending;
        if (pending) {
            showErrors({ form: '申請中のため、新しい申請はできません。新しく申請する場合は、申請中の申請を取り消してください。' });
        }
    }

    // fixedDate が true なら対象日を固定（カレンダーから開いたとき）。false なら対象日を選ぶ（「申請」ボタン）
    function openRequest(date, fixedDate) {
        form.reset();
        dateInput.value = date ?? '';
        dateInput.readOnly = fixedDate;
        // カレンダーから開いたときは、その日（と祝日名）を出す（スマホではカレンダーに祝日名が出ないため）
        const dayLabel = element('request-day');
        dayLabel.textContent = date ? [days[date].label, days[date].holiday].filter(Boolean).join('　') : '';
        dayLabel.hidden = !date;
        submitButton.disabled = false;
        if (date) {
            fillValues(date);
        } else {
            clearErrors();
        }
        requestDialog.showModal();
        (fixedDate ? form.elements.check_in : dateInput).focus();
    }

    dateInput.addEventListener('change', () => {
        if (!dateInput.readOnly && days[dateInput.value]) {
            fillValues(dateInput.value);
        }
    });

    const openButton = element('request-open');
    if (openButton) {
        openButton.addEventListener('click', () => openRequest(null, false));
    }
    element('request-cancel').addEventListener('click', () => requestDialog.close());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submitButton.disabled = true;
        const body = new FormData(form);
        body.append('csrf_token', calendar.dataset.csrfToken);
        body.append('type', 'correction');

        let data = null;
        try {
            const response = await fetch(calendar.dataset.apiUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 401) {
                window.location.href = calendar.dataset.loginUrl;
                return;
            }
            data = await response.json().catch(() => null);
            if (!response.ok || data === null) {
                showErrors({ form: data?.message ?? 'エラーが発生しました。画面を読み込み直して、もう一度お試しください。' });
                submitButton.disabled = false;
                return;
            }
        } catch (error) {
            showErrors({ form: '通信できませんでした。接続を確かめて、もう一度お試しください。' });
            submitButton.disabled = false;
            return;
        }

        if (data.ok) {
            // 受け付けられたら、読み込み直してバッジと通知を出す
            window.location.reload();
            return;
        }
        showErrors(data.errors);
        submitButton.disabled = false;
    });

    // ---- 申請の取り消し（D-063）：確認のあと api/requests.php に送り、受け付けられたら読み込み直す ----
    const cancelDialog = element('cancel-dialog');
    const cancelSubmit = element('cancel-submit');
    let cancelTarget = null;

    function openCancel(request) {
        cancelTarget = request;
        element('cancel-text').textContent = `${days[currentDate].label}の${request.type}の申請を取り消します。取り消した申請は、元に戻せません。`;
        element('cancel-error').hidden = true;
        cancelSubmit.disabled = false;
        dayDialog.close();
        cancelDialog.showModal();
    }

    element('cancel-back').addEventListener('click', () => cancelDialog.close());
    cancelDialog.addEventListener('click', (event) => {
        if (event.target === cancelDialog) {
            cancelDialog.close();
        }
    });

    function showCancelError(message) {
        const error = element('cancel-error');
        error.textContent = message;
        error.hidden = false;
        cancelSubmit.disabled = false;
    }

    cancelSubmit.addEventListener('click', async () => {
        cancelSubmit.disabled = true;
        const body = new FormData();
        body.append('csrf_token', calendar.dataset.csrfToken);
        body.append('type', 'cancel');
        body.append('request_id', String(cancelTarget.id));
        try {
            const response = await fetch(calendar.dataset.apiUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 401) {
                window.location.href = calendar.dataset.loginUrl;
                return;
            }
            const data = await response.json().catch(() => null);
            if (!response.ok || data === null) {
                showCancelError(data?.message ?? 'エラーが発生しました。画面を読み込み直して、もう一度お試しください。');
                return;
            }
            if (data.ok) {
                window.location.reload();
                return;
            }
            showCancelError(data.errors.form);
        } catch (error) {
            showCancelError('通信できませんでした。接続を確かめて、もう一度お試しください。');
        }
    });
})();
