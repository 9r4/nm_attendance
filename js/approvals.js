// 承認画面：「承認」はすぐ送り、「却下」はコメントのウィンドウのあとに送る（api/approvals.php）
// 受け付けられたら、画面を読み込み直して一覧と通知を出す
(() => {
    'use strict';

    const root = document.getElementById('approvals');
    if (!root) {
        return;
    }
    const element = (id) => document.getElementById(id);
    const dialog = element('reject-dialog');
    const form = element('reject-form');
    const submitButton = element('reject-submit');
    const actionButtons = root.querySelectorAll('[data-approve], [data-reject]');
    let rejectId = null;

    const toast = element('toast');
    if (toast && !toast.hidden) {
        setTimeout(() => {
            toast.hidden = true;
        }, 5000);
    }

    // 送る。受け付けられたら true（画面を読み込み直す）、誤りなら文を返す
    async function send(requestId, decision, comment) {
        const body = new FormData();
        body.append('csrf_token', root.dataset.csrfToken);
        body.append('request_id', String(requestId));
        body.append('decision', decision);
        body.append('comment', comment);
        try {
            const response = await fetch(root.dataset.apiUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 401) {
                window.location.href = root.dataset.loginUrl;
                return true;
            }
            const data = await response.json().catch(() => null);
            if (!response.ok || data === null) {
                return data?.message ?? 'エラーが発生しました。画面を読み込み直して、もう一度お試しください。';
            }
            if (data.ok) {
                window.location.reload();
                return true;
            }
            return data.errors.form ?? data.errors.comment;
        } catch (error) {
            return '通信できませんでした。接続を確かめて、もう一度お試しください。';
        }
    }

    function setBusy(busy) {
        actionButtons.forEach((button) => {
            button.disabled = busy;
        });
    }

    function showError(id, message) {
        const error = element(id);
        error.textContent = message;
        error.hidden = false;
    }

    // 承認：確認なしで、すぐ反映する（D-064）
    root.querySelectorAll('[data-approve]').forEach((button) => {
        button.addEventListener('click', async () => {
            setBusy(true);
            element('approval-error').hidden = true;
            const result = await send(button.dataset.approve, 'approve', '');
            if (result !== true) {
                showError('approval-error', result);
                setBusy(false);
            }
        });
    });

    // 却下：コメント（任意）のウィンドウを開く
    root.querySelectorAll('[data-reject]').forEach((button) => {
        button.addEventListener('click', () => {
            rejectId = button.dataset.reject;
            form.reset();
            element('reject-target').textContent = button.dataset.target;
            element('reject-error').hidden = true;
            submitButton.disabled = false;
            dialog.showModal();
        });
    });
    element('reject-cancel').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submitButton.disabled = true;
        const result = await send(rejectId, 'reject', element('reject-comment').value);
        if (result !== true) {
            showError('reject-error', result);
            submitButton.disabled = false;
        }
    });
})();
