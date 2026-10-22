// PR 8 CSS・JS のキャッシュ対策の E2E 確認（Claude の環境用）
// 全画面で、このアプリの CSS・JS の URL に ?v=（ファイルの更新日時）が付き、読み込みが成功すること。ファイルを変えると URL が変わること
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const ROOT = path.join(__dirname, '..', '..');
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
const errors = [];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  async function login(id, pw) {
    const page = await (await browser.newContext({ locale: 'ja-JP' })).newPage();
    page.on('pageerror', e => errors.push(String(e)));
    // このアプリの CSS・JS の読み込み結果を記録する
    page.loaded = [];
    page.on('response', r => { if (/\/nm_attendance\/(css|js)\//.test(r.url())) page.loaded.push([r.url(), r.status()]); });
    await page.goto(BASE + 'login.php');
    if (id) {
      await page.fill('#login_id', id); await page.fill('#password', pw);
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
  }
  // 画面の中の、このアプリの CSS・JS の URL
  const assets = (page) => page.$$eval('link[rel=stylesheet], script[src]', els => els.map(e => e.getAttribute('href') || e.getAttribute('src')).filter(u => /^\/nm_attendance\/(css|js)\//.test(u)));
  const mtime = (file) => Math.floor(fs.statSync(path.join(ROOT, file)).mtimeMs / 1000);

  const pages = [
    ['未ログイン', null, null, ['login.php']],
    ['社員', '2025001', 'Test1234', ['', 'records/']],
    ['管理者', '2025002', 'Test1234', ['approvals/']],
    ['システム管理者', 'sysadmin', 'Admin1234', ['', 'admin/accounts.php', 'admin/departments.php', 'admin/settings.php']],
  ];
  const expected = {
    'login.php': ['css/style.css'],
    '': ['css/style.css', 'js/menu.js', 'js/main.js'],
    'records/': ['css/style.css', 'js/menu.js', 'js/records.js'],
    'approvals/': ['css/style.css', 'js/menu.js', 'js/approvals.js'],
    'admin/accounts.php': ['css/style.css', 'js/menu.js', 'js/admin.js'],
    'admin/departments.php': ['css/style.css', 'js/menu.js', 'js/admin.js'],
    'admin/settings.php': ['css/style.css', 'js/menu.js', 'js/admin.js'],
  };

  console.log('[1] 全画面の CSS・JS の URL に、ファイルの更新日時が付く');
  for (const [who, id, pw, urls] of pages) {
    const page = await login(id, pw);
    for (const url of urls) {
      page.loaded = [];
      await page.goto(BASE + url);
      await page.waitForLoadState('networkidle');
      const list = await assets(page);
      const want = expected[url].map(f => `/nm_attendance/${f}?v=${mtime(f)}`);
      check(`${who}：${url || 'メイン画面'} の URL（${expected[url].join('・')}）`, JSON.stringify(list.sort()) === JSON.stringify(want.sort()), JSON.stringify(list));
      check(`${who}：${url || 'メイン画面'} の読み込みがすべて成功（200・304）`, page.loaded.length > 0 && page.loaded.every(([, s]) => s === 200 || s === 304), JSON.stringify(page.loaded));
    }
    await page.context().close();
  }

  console.log('[2] ファイルを変えると URL が変わる（ブラウザは新しいファイルを読み込む）');
  const css = path.join(ROOT, 'css/style.css');
  const original = fs.statSync(css);
  try {
    const page = await login('2025001', 'Test1234');
    const before = (await assets(page)).find(u => u.includes('style.css'));
    const newTime = Math.floor(Date.now() / 1000) + 60;
    fs.utimesSync(css, original.atime, new Date(newTime * 1000)); // git pull でファイルが変わったのと同じ
    page.loaded = [];
    await page.reload(); await page.waitForLoadState('networkidle');
    const after = (await assets(page)).find(u => u.includes('style.css'));
    check('style.css の URL が新しい日時に変わる', before !== after && after === `/nm_attendance/css/style.css?v=${newTime}`, `${before} → ${after}`);
    check('新しい URL の CSS を読み込む（200）', page.loaded.some(([u, s]) => u.endsWith(after) && s === 200), JSON.stringify(page.loaded));
    check('変えていない JS の URL は変わらない', (await assets(page)).includes(`/nm_attendance/js/menu.js?v=${mtime('js/menu.js')}`));
    await page.context().close();
  } finally {
    fs.utimesSync(css, original.atime, original.mtime); // 元の日時に戻す
  }

  await browser.close();
  console.log('JS のエラー: ' + (errors.length ? errors.join('\n') : 'なし'));
  console.log(`結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail || errors.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
