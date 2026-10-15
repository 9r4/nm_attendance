// ハンバーガーメニュー：「≡」で開き、×・Esc・外側を押すと閉じる
(() => {
    'use strict';

    const menu = document.getElementById('menu');
    const openButton = document.getElementById('menu-open');
    if (!menu || !openButton) {
        return;
    }
    openButton.addEventListener('click', () => {
        menu.showModal();
        openButton.setAttribute('aria-expanded', 'true');
    });
    document.getElementById('menu-close').addEventListener('click', () => menu.close());
    menu.addEventListener('click', (event) => {
        if (event.target === menu) {
            menu.close();
        }
    });
    menu.addEventListener('close', () => {
        openButton.setAttribute('aria-expanded', 'false');
    });
})();
