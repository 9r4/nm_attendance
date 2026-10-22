// システム管理者の画面（アカウント管理・部署・勤務設定）：通知を消す、確認のウィンドウを開く・閉じる、承認範囲の出し分け
// 送信は普通のフォーム（POST）で、画面を切り替える（成功したら、サーバーが同じ画面に戻して通知を出す）
(() => {
    'use strict';

    const root = document.getElementById('admin');
    if (!root) {
        return;
    }

    const toast = document.getElementById('toast');
    if (toast) {
        setTimeout(() => {
            toast.hidden = true;
        }, 5000);
    }

    // 権限が「管理者」のときだけ、承認範囲を出す（D-066）
    const role = document.getElementById('role');
    const scopeField = document.getElementById('scope-field');
    if (role && scopeField) {
        role.addEventListener('change', () => {
            scopeField.hidden = role.value !== 'manager';
        });
    }

    // ウィンドウを開く。押したボタンの data-* の値を、ウィンドウの中に入れる
    //   data-text="名前"：文字として入れる。data-value="名前"：入力欄の値にする。data-min-from・data-max-from：日付の範囲にする
    function openDialog(button, error = '') {
        const dialog = document.getElementById(button.dataset.open);
        const form = dialog.querySelector('form');
        form.reset();
        dialog.querySelectorAll('[data-text]').forEach((element) => {
            element.textContent = button.dataset[element.dataset.text] ?? '';
        });
        dialog.querySelectorAll('[data-value]').forEach((element) => {
            element.value = button.dataset[element.dataset.value] ?? '';
        });
        dialog.querySelectorAll('[data-min-from]').forEach((element) => {
            element.min = button.dataset[element.dataset.minFrom] ?? '';
        });
        dialog.querySelectorAll('[data-max-from]').forEach((element) => {
            element.max = button.dataset[element.dataset.maxFrom] ?? '';
        });
        const errorElement = dialog.querySelector('[data-dialog-error]');
        errorElement.textContent = error;
        errorElement.hidden = error === '';
        form.querySelectorAll('[type=submit]').forEach((submit) => {
            submit.disabled = false;
        });
        dialog.showModal();
    }

    root.querySelectorAll('[data-open]').forEach((button) => {
        button.addEventListener('click', () => openDialog(button));
    });

    root.querySelectorAll('dialog').forEach((dialog) => {
        dialog.querySelectorAll('[data-close]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    // 二重送信を防ぐ（送信したら、そのフォームのボタンを押せなくする）
    root.querySelectorAll('form[method=post]').forEach((form) => {
        form.addEventListener('submit', () => {
            setTimeout(() => {
                form.querySelectorAll('[type=submit]').forEach((submit) => {
                    submit.disabled = true;
                });
            });
        });
    });

    // ウィンドウからの送信で誤りがあったときは、同じウィンドウを開き直して、誤りを出す
    const reopen = root.dataset.reopen;
    if (reopen) {
        const key = root.dataset.reopenEmployee !== undefined ? 'employeeId' : 'departmentId';
        const id = root.dataset.reopenEmployee ?? root.dataset.reopenDepartment;
        const button = Array.from(root.querySelectorAll(`[data-open="${reopen}"]`)).find((b) => b.dataset[key] === id);
        if (button) {
            openDialog(button, root.dataset.reopenError);
        }
    }
})();
