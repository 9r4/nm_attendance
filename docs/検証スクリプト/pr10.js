// PR 10 社員の勤怠の E2E 確認（Claude の環境用）
// 最初と最後に、勤怠・申請を消し、確認用に足した社員（2025901・2025902）を消す
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const SHOT = __dirname + '/pr10_';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', '--default-character-set=utf8mb4', 'attendance_training', '-e', q]).toString().trim();
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
let alerted = false;
const errors = [];
async function login(browser, id, pw, opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ja-JP', ...opts });
  const page = await ctx.newPage();
  page.on('dialog', d => { alerted = true; d.dismiss(); });
  page.on('console', m => { if (m.type() === 'error' && !/ERR_TUNNEL_CONNECTION_FAILED|status of 40[01345]/.test(m.text())) errors.push(id + ': ' + m.text()); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('response', r => { if (r.status() >= 500) errors.push(r.status() + ' ' + r.url()); });
  await page.goto(BASE + 'login.php');
  await page.fill('#login_id', id); await page.fill('#password', pw);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return page;
}
const rows = (page) => page.$$eval('.member-item', els => els.map(el => el.innerText.replace(/\s+/g, ' ').trim()));
const ids = (page) => page.$$eval('.member-item__sub', els => els.map(el => el.textContent.trim()));
const menu = async (page) => { await page.click('#menu-open'); const l = await page.$$eval('#menu .menu__link', as => as.map(a => a.textContent)); await page.keyboard.press('Escape'); return l; };
function cleanup() {
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  sql("DELETE FROM employees WHERE employee_id IN ('2025901','2025902')");
  sql("UPDATE employees SET employment_status = 'active', left_on = NULL WHERE employee_id IN ('2025001','2025002','2025003','2025004')");
}

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  const M = D.slice(0, 7);
  const prev = new Date(Date.UTC(+M.slice(0, 4), +M.slice(5, 7) - 2, 1));
  const PM = prev.toISOString().slice(0, 7);
  const P = `${PM}-10`, P2 = `${PM}-11`;
  cleanup();
  sql("INSERT INTO employees (employee_id, name, email, department_id, joined_on) VALUES ('2025901', '<script>alert(1)</script>伊藤', 'ito901@example.com', (SELECT id FROM departments WHERE name = '営業部'), '2025-04-01')");
  sql("INSERT INTO employees (employee_id, name, email, department_id, joined_on, left_on, employment_status) VALUES ('2025902', '退職 花子', 'retired902@example.com', (SELECT id FROM departments WHERE name = '営業部'), '2025-04-01', '2026-09-30', 'retired')");
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at, break_start_at, break_end_at) VALUES
    ('2025001','${D}','${D} 09:01:00',NULL,'${D} 15:00:00',NULL),
    ('2025001','${P}','${P} 09:00:00','${P} 18:00:00',NULL,NULL),
    ('2025901','${D}','${D} 09:00:00','${D} 18:30:00','${D} 15:00:00','${D} 15:15:00'),
    ('2025902','${D}','${D} 09:00:00',NULL,NULL,NULL),
    ('2025002','${D}','${D} 09:00:00',NULL,NULL,NULL),
    ('2025004','${D}','${D} 10:00:00',NULL,NULL,NULL)`);
  sql(`INSERT INTO attendance_requests (employee_id, target_date, request_type, requested_check_in_at, requested_check_out_at, original_check_in_at, original_check_out_at, reason)
    VALUES ('2025001','${P}','correction','${P} 08:30:00','${P} 18:00:00','${P} 09:00:00','${P} 18:00:00','<script>alert(2)</script>電車遅延')`);

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  console.log('[1] メニューと権限');
  const yamada = await login(browser, '2025001', 'Test1234');
  check('社員のメニューに「社員の勤怠」なし', !(await menu(yamada)).includes('社員の勤怠'));
  check('社員は members/ が 403', (await yamada.goto(BASE + 'members/')).status() === 403);
  check('社員は members/detail.php が 403', (await yamada.goto(BASE + 'members/detail.php?id=2025901')).status() === 403);
  const sato = await login(browser, '2025002', 'Test1234');
  check('管理者のメニューの「承認」の下に「社員の勤怠」', JSON.stringify(await menu(sato)) === JSON.stringify(['メイン画面', '記録表示', '承認', '社員の勤怠']));
  await sato.click('#menu-open');
  await Promise.all([sato.waitForNavigation(), sato.click('#menu >> text=社員の勤怠')]);
  check('メニューから一覧を開く', sato.url() === BASE + 'members/');
  check('今いる画面に印', (await sato.getAttribute('#menu a[href$="members/"]', 'aria-current')) === 'page');
  const admin = await login(browser, 'sysadmin', 'Admin1234');
  check('システム管理者のメニューに「社員の勤怠」なし', !(await menu(admin)).includes('社員の勤怠'));
  check('システム管理者は members/ が 403', (await admin.goto(BASE + 'members/')).status() === 403);
  check('システム管理者は members/detail.php が 403', (await admin.goto(BASE + 'members/detail.php?id=2025001')).status() === 403);
  const anon = await (await browser.newContext()).newPage();
  await anon.goto(BASE + 'members/');
  check('未ログインはログイン画面へ', anon.url().includes('login.php'));

  console.log('[2] 一覧（佐藤：営業部）');
  check('見出しと対象（営業部）', (await sato.textContent('.members__title')).trim() === '社員の勤怠' && (await sato.textContent('.members__note')).includes('対象：営業部'));
  const [y, mo, d] = D.split('-').map(Number);
  check('今日の日付', (await sato.textContent('.members__note')).includes(`${y}年${mo}月${d}日`));
  check('営業部の在籍中だけ（自分・退職・開発部は出ない）、社員コードの順', JSON.stringify(await ids(sato)) === JSON.stringify(['2025001', '2025901']), JSON.stringify(await ids(sato)));
  let list = await rows(sato);
  check('休憩中の人：状態・出勤・休憩・労働時間', list[0].includes('山田 太郎') && list[0].includes('休憩中') && list[0].includes('09:01') && list[0].includes('15:00–休憩中') && /労働時間 --|-- 詳細/.test(list[0]), list[0]);
  check('退勤済みの人：労働時間（昼休憩と休憩を引く）', list[1].includes('退勤済み') && list[1].includes('18:30') && list[1].includes('15:00–15:15') && list[1].includes('8時間15分'), list[1]);
  check('氏名の <script> は文字として出る', list[1].includes('<script>alert(1)</script>伊藤'));
  check('状態バッジの色（メイン画面と同じクラス）', JSON.stringify(await sato.$$eval('.member-item .status-badge', els => els.map(e => e.className))) === JSON.stringify(['status-badge status-badge--on-break', 'status-badge status-badge--finished']));
  check('PC は表（見出しの行あり）', await sato.isVisible('.member-list__head'));
  check('PC では時刻の見出し（ラベル）を行に出さない', !(await sato.isVisible('.member-item__label')));
  check('PC：「詳細」が行の中に収まる', await sato.evaluate(() => [...document.querySelectorAll('.member-item')].every(r => r.querySelector('.member-item__actions .button').getBoundingClientRect().right <= r.getBoundingClientRect().right - 16)));
  await sato.screenshot({ path: SHOT + 'pc_list.png', fullPage: true });

  console.log('[3] 一覧（鈴木：全社）');
  const suzuki = await login(browser, '2025003', 'Test1234');
  await suzuki.goto(BASE + 'members/');
  check('対象：全社', (await suzuki.textContent('.members__note')).includes('対象：全社'));
  check('自分以外の在籍中の全員、部署 → 社員コードの順（ログイン不可の高橋さんも出る）', JSON.stringify(await ids(suzuki)) === JSON.stringify(['2025001', '2025002', '2025901', '2025004']), JSON.stringify(await ids(suzuki)));
  list = await rows(suzuki);
  check('ほかの管理者（佐藤）は勤務中', list[1].includes('佐藤 花子') && list[1].includes('勤務中') && list[1].includes('09:00'), list[1]);
  check('開発部の高橋さんは勤務中 10:00', list[3].includes('高橋 次郎') && list[3].includes('開発部') && list[3].includes('10:00'), list[3]);
  sql(`DELETE FROM attendances WHERE employee_id = '2025004' AND work_date = '${D}'`);
  await suzuki.reload();
  list = await rows(suzuki);
  check('打刻していない人も出て「未出勤」', list[3].includes('高橋 次郎') && list[3].includes('未出勤') && (list[3].match(/--:--/g) || []).length === 3, list[3]);

  console.log('[4] 詳細（佐藤 → 山田）');
  await Promise.all([sato.waitForNavigation(), sato.locator('.member-item').first().getByRole('link', { name: /詳細/ }).click()]);
  check('詳細の URL', sato.url() === BASE + 'members/detail.php?id=2025001', sato.url());
  check('見出し：氏名・社員コード・部署', (await sato.textContent('.member-detail__name')).replace(/\s+/g, '') === '山田太郎（2025001・営業部）');
  check('ページの h1 は1つ（月は h2）', (await sato.$$('h1')).length === 1 && (await sato.$$('h2.month-nav__label')).length === 1);
  check('今月を表示', (await sato.textContent('.month-nav__label')).trim() === `${y}年${mo}月`);
  check('次の月へは進めない（今月まで）', !(await sato.$('a[aria-label="次の月"]')));
  check('「申請」ボタン・申請ウィンドウ・取り消しの確認が無い', !(await sato.$('#request-open')) && !(await sato.$('#request-dialog')) && !(await sato.$('#cancel-dialog')));
  check('申請の API の URL と CSRF トークンを出さない', (await sato.evaluate(() => { const c = document.getElementById('calendar'); return c.dataset.readonly === 'true' && !c.dataset.apiUrl && !c.dataset.csrfToken; })));
  check('今日のセルに山田さんの出勤', (await sato.textContent(`[data-date="${D}"]`)).includes('09:01'));
  await sato.click(`[data-date="${D}"]`);
  await sato.waitForSelector('#day-dialog[open]');
  check('今日のウィンドウ：出勤・休憩', (await sato.textContent('#day-check-in')) === '09:01' && (await sato.textContent('#day-break')) === '15:00–休憩中');
  check('今日のウィンドウ：「修正」ボタンなし', await sato.isHidden('#day-request'));
  await sato.click('#day-dialog button[value=close]');
  await Promise.all([sato.waitForNavigation(), sato.click('a[aria-label="前の月"]')]);
  check('前の月（社員 ID 付きの URL）', sato.url() === BASE + `members/detail.php?id=2025001&month=${PM}`, sato.url());
  check('前の月の出勤日数・総労働時間', (await sato.textContent('#summary-days')) === '1日' && (await sato.textContent('#summary-total')) === '8時間00分');
  check('申請中のバッジ', (await sato.textContent(`[data-date="${P}"]`)).includes('申請中'));
  await sato.click(`[data-date="${P}"]`);
  await sato.waitForSelector('#day-dialog[open]');
  const req = (await sato.textContent('#day-request-items')).replace(/\s+/g, ' ');
  check('申請の内容（変わった項目・理由は文字として）', req.includes('出勤 09:00 → 08:30') && req.includes('理由：<script>alert(2)</script>電車遅延'), req);
  check('「取り消す」ボタンなし', !(await sato.$('#day-dialog .request-item__cancel')));
  check('申請中の案内を出さない', await sato.isHidden('#day-pending'));
  check('「修正」ボタンなし', await sato.isHidden('#day-request'));
  await sato.click('#day-dialog button[value=close]');
  await sato.click(`[data-date="${P2}"]`);
  await sato.waitForSelector('#day-dialog[open]');
  check('記録の無い日は「記録はありません。」（申請ウィンドウは開かない）', await sato.isVisible('#day-empty') && (await sato.isHidden('#day-request')));
  await sato.screenshot({ path: SHOT + 'pc_detail_day.png' });
  await sato.keyboard.press('Escape');
  await sato.goto(BASE + 'members/detail.php?id=2025001&month=2025-04');
  check('入社した月までは戻れる（前の月は押せない）', (await sato.textContent('.month-nav__label')).trim() === '2025年4月' && !(await sato.$('a[aria-label="前の月"]')));
  await sato.goto(BASE + 'members/detail.php?id=2025001&month=2025-03');
  check('入社前の月は今月になる', (await sato.textContent('.month-nav__label')).trim() === `${y}年${mo}月`);
  await Promise.all([sato.waitForNavigation(), sato.click('.member-detail__back')]);
  check('「＜ 社員の勤怠」で一覧へ戻る', sato.url() === BASE + 'members/');

  console.log('[5] 見られない社員は 404（理由を区別しない）');
  for (const [label, page, q] of [
    ['佐藤：開発部の社員', sato, 'id=2025004'], ['佐藤：自分', sato, 'id=2025002'], ['佐藤：退職した社員', sato, 'id=2025902'],
    ['存在しない社員', sato, 'id=9999999'], ['ID なし', sato, ''], ['ID が配列', sato, 'id[]=2025001'], ['鈴木：自分', suzuki, 'id=2025003'],
  ]) {
    const res = await page.goto(BASE + 'members/detail.php?' + q);
    check(label, res.status() === 404 && (await page.textContent('body')).trim() === 'この社員の勤怠は表示できません。', String(res.status()));
  }
  check('鈴木（全社）は開発部の高橋さんを見られる', (await suzuki.goto(BASE + 'members/detail.php?id=2025004')).status() === 200);
  check('鈴木（全社）はほかの管理者（佐藤）を見られる', (await suzuki.goto(BASE + 'members/detail.php?id=2025002')).status() === 200);

  console.log('[6] 記録表示（自分の分）は今までどおり');
  await yamada.goto(BASE + 'records/');
  check('「申請」ボタン・申請ウィンドウ・CSRF トークンがある', !!(await yamada.$('#request-open')) && !!(await yamada.$('#request-dialog')) && !!(await yamada.$eval('#calendar', c => c.dataset.csrfToken)));
  check('月の見出しは h1', !!(await yamada.$('h1.month-nav__label')));
  await yamada.goto(BASE + `records/?month=${PM}`);
  await yamada.click(`[data-date="${P}"]`);
  await yamada.waitForSelector('#day-dialog[open]');
  check('自分の申請中の申請は「取り消す」がある', !!(await yamada.$('#day-dialog .request-item__cancel')) && await yamada.isVisible('#day-pending'));

  console.log('[7] 対象の社員がいないとき');
  sql("UPDATE employees SET employment_status = 'retired', left_on = '2026-10-01' WHERE employee_id IN ('2025001','2025901')");
  await sato.goto(BASE + 'members/');
  check('「対象の社員はいません。」', (await sato.textContent('.member-list__empty')).trim() === '対象の社員はいません。' && (await sato.$$('.member-item')).length === 0);
  sql("UPDATE employees SET employment_status = 'active', left_on = NULL WHERE employee_id IN ('2025001','2025901')");

  console.log('[8] 画面の幅');
  const m = await login(browser, '2025003', 'Test1234', { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
  await m.goto(BASE + 'members/');
  check('スマホ：横にはみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));
  check('スマホ：カード型（見出しの行を出さず、時刻のラベルを出す）', !(await m.isVisible('.member-list__head')) && await m.isVisible('.member-item__label'));
  const card = await m.evaluate(() => { const c = document.querySelector('.member-item'); const r = (s) => c.querySelector(s).getBoundingClientRect(); return { nameTop: r('.member-item__person').top, statusTop: r('.member-item__status').top, statusRight: r('.member-item__status').right, cardRight: c.getBoundingClientRect().right, button: r('.member-item__actions .button').width, width: c.getBoundingClientRect().width }; });
  check('スマホ：1行目に氏名と状態（右端）、「詳細」はカードの中で幅いっぱい', Math.abs(card.nameTop - card.statusTop) < 2 && card.cardRight - card.statusRight < 20 && card.button > card.width - 40 && card.button <= card.width - 32, JSON.stringify(card));
  check('スマホ：時刻が折り返さない', await m.evaluate(() => [...document.querySelectorAll('.member-item__time')].every(e => e.getBoundingClientRect().height < 50)));
  await m.screenshot({ path: SHOT + 'sp_list.png', fullPage: true });
  await Promise.all([m.waitForNavigation(), m.locator('.member-item').first().getByRole('link', { name: /詳細/ }).click()]);
  check('スマホ：詳細も横にはみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));
  await m.screenshot({ path: SHOT + 'sp_detail.png', fullPage: true });
  const t = await login(browser, '2025003', 'Test1234', { viewport: { width: 800, height: 900 } });
  await t.goto(BASE + 'members/');
  check('幅 800px：表のまま、横にはみ出さない', await t.isVisible('.member-list__head') && !(await t.evaluate(() => document.documentElement.scrollWidth > 800)));
  check('幅 800px：時刻が折り返さない', await t.evaluate(() => [...document.querySelectorAll('.member-item__time')].every(e => e.scrollWidth <= e.clientWidth + 1)));
  await t.screenshot({ path: SHOT + 'tablet_list.png', fullPage: true });

  check('<script> が実行されない', !alerted);
  check('ブラウザのエラーなし', errors.length === 0, errors.join(' | '));
  await browser.close();
  cleanup();
  console.log(`\n結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
