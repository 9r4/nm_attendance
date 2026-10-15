// PR 6 承認の E2E 確認（Claude の環境用。コミットしない）
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = 'http://127.0.0.1:8080/nm_attendance/';
const SHOT = __dirname + '/pr6_';
const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', '--default-character-set=utf8mb4', 'attendance_training', '-e', q]).toString().trim();
let pass = 0, fail = 0;
function check(name, cond, detail = '') { if (cond) { pass++; console.log('  OK  ' + name); } else { fail++; console.log('  NG  ' + name + ' ' + detail); } }
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
let alerted = false;
const errors = [];
// 社員の画面から申請を送る（API）
async function submit(page, body) {
  return page.evaluate(async (b) => {
    const fd = new FormData(); fd.append('csrf_token', document.querySelector('[data-csrf-token]').dataset.csrfToken);
    for (const [k, v] of Object.entries(b)) fd.append(k, v);
    return (await fetch('/nm_attendance/api/requests.php', { method: 'POST', body: fd })).json();
  }, body);
}
async function review(page, body) {
  return page.evaluate(async (b) => {
    const fd = new FormData(); fd.append('csrf_token', b.token ?? document.getElementById('approvals').dataset.csrfToken);
    for (const [k, v] of Object.entries(b)) if (k !== 'token') fd.append(k, v);
    const r = await fetch('/nm_attendance/api/approvals.php', { method: 'POST', body: fd });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, body);
}
const items = (page) => page.$$eval('.approval-item', els => els.map(el => el.innerText.replace(/\s+/g, ' ')));

(async () => {
  const D = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(new Date());
  const M = D.slice(0, 7);
  const A = `${M}-01`, B = `${M}-02`;
  sql('DELETE FROM attendance_requests'); sql('DELETE FROM attendances');
  sql(`INSERT INTO attendances (employee_id, work_date, check_in_at, check_out_at) VALUES ('2025001','${A}','${A} 09:00:00','${A} 17:45:00'), ('2025001','${D}','${D} 09:00:00','${D} 19:45:00')`);

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  console.log('[1] 申請を出す（2025001・2025002・2025003）');
  const yamada = await login(browser, '2025001', 'Test1234');
  await yamada.goto(BASE + 'records/');
  let r = await submit(yamada, { type: 'correction', target_date: A, check_in: '09:00', check_out: '18:30', reason: '<script>alert(1)</script>打刻忘れ' });
  check('山田：修正の申請', r.ok, JSON.stringify(r));
  r = await submit(yamada, { type: 'overtime', overtime_end: '19:30' });
  check('山田：残業の申請', r.ok, JSON.stringify(r));
  r = await submit(yamada, { type: 'correction', target_date: B, check_in: '09:00', check_out: '18:00' });
  check('山田：新規登録の申請', r.ok, JSON.stringify(r));
  const sato = await login(browser, '2025002', 'Test1234');
  await sato.goto(BASE + 'records/');
  check('佐藤（管理者）：自分の申請', (await submit(sato, { type: 'correction', target_date: A, check_in: '09:00', check_out: '18:00' })).ok);
  const suzuki = await login(browser, '2025003', 'Test1234');
  await suzuki.goto(BASE + 'records/');
  check('鈴木（開発部・全社）：自分の申請', (await submit(suzuki, { type: 'correction', target_date: A, check_in: '09:00', check_out: '18:00' })).ok);

  console.log('[2] メニューと権限');
  await yamada.goto(BASE);
  await yamada.click('#menu-open');
  check('社員のメニューに「承認」なし', !(await yamada.$$eval('#menu .menu__link', as => as.map(a => a.textContent))).includes('承認'));
  check('社員は approvals/ が 403', (await yamada.goto(BASE + 'approvals/')).status() === 403);
  await sato.goto(BASE);
  await sato.click('#menu-open');
  check('管理者のメニューに「承認」', JSON.stringify(await sato.$$eval('#menu .menu__link', as => as.map(a => a.textContent))) === JSON.stringify(['メイン画面', '記録表示', '承認']));
  await Promise.all([sato.waitForNavigation(), sato.click('#menu >> text=承認')]);

  console.log('[3] 未処理の一覧（佐藤：営業部）');
  let list = await items(sato);
  check('営業部の3件だけ（自分・開発部は出ない）、古い順', list.length === 3 && list.every(t => t.includes('山田 太郎')) && list[0].includes('修正') && list[1].includes('残業') && list[2].includes('新規登録'), JSON.stringify(list));
  check('件数', (await sato.textContent('.tabs__item[aria-current=page]')).trim() === '未処理（3）');
  check('修正：変わった項目だけ・理由（エスケープ）', list[0].includes('退勤 17:45 → 18:30') && !list[0].includes('出勤 09:00 →') && list[0].includes('理由：<script>alert(1)</script>打刻忘れ'), list[0]);
  check('残業：参考の時刻', list[1].includes('残業終了 なし → 19:30') && list[1].includes('標準終了 18:00・今の退勤 19:45'), list[1]);
  check('新規登録：なし →', list[2].includes('出勤 なし → 09:00') && list[2].includes('退勤 なし → 18:00'));
  check('承認範囲の表示', (await sato.textContent('.approvals__scope')).includes('営業部'));
  await sato.screenshot({ path: SHOT + 'pc_todo.png', fullPage: true });

  console.log('[4] 承認');
  const reqA = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025001' AND target_date='${A}'`);
  await Promise.all([sato.waitForNavigation(), sato.click(`[data-approve="${reqA}"]`)]);
  check('通知', (await sato.textContent('#toast')) === '山田 太郎 さんの申請を承認し、勤怠に反映しました。');
  check('一覧から消える', (await items(sato)).length === 2);
  check('勤怠に反映', sql(`SELECT CONCAT(check_in_at,'|',check_out_at) FROM attendances WHERE employee_id='2025001' AND work_date='${A}'`) === `${A} 09:00:00|${A} 18:30:00`);
  check('申請は承認済み・承認者・日時', sql(`SELECT CONCAT(status,'|',reviewer_id,'|',reviewed_at IS NOT NULL) FROM attendance_requests WHERE id=${reqA}`) === 'approved|2025002|1');
  const reqB = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025001' AND target_date='${B}'`);
  await Promise.all([sato.waitForNavigation(), sato.click(`[data-approve="${reqB}"]`)]);
  check('新規登録の承認で行ができる', sql(`SELECT CONCAT(check_in_at,'|',check_out_at) FROM attendances WHERE employee_id='2025001' AND work_date='${B}'`) === `${B} 09:00:00|${B} 18:00:00`);

  console.log('[5] 却下');
  const reqOt = sql(`SELECT id FROM attendance_requests WHERE request_type='overtime'`);
  await sato.click(`[data-reject="${reqOt}"]`); await sato.waitForSelector('#reject-dialog[open]');
  check('却下のウィンドウ', (await sato.textContent('#reject-target')).includes('山田 太郎 さん・残業'));
  await sato.click('#reject-cancel'); await sato.waitForTimeout(150);
  check('キャンセルで何も変わらない', sql(`SELECT status FROM attendance_requests WHERE id=${reqOt}`) === 'pending');
  await sato.click(`[data-reject="${reqOt}"]`); await sato.waitForSelector('#reject-dialog[open]');
  await sato.fill('#reject-comment', '<b>事前申請</b>が必要');
  await sato.screenshot({ path: SHOT + 'pc_reject.png' });
  await Promise.all([sato.waitForNavigation(), sato.click('#reject-submit')]);
  check('却下の通知・勤怠は変わらない', (await sato.textContent('#toast')) === '山田 太郎 さんの申請を却下しました。' && sql(`SELECT IFNULL(overtime_end_at,'NULL') FROM attendances WHERE employee_id='2025001' AND work_date='${D}'`) === 'NULL');
  check('未処理は 0 件', (await sato.textContent('.approval-list__empty')) === '未処理の申請はありません。');

  console.log('[6] 処理済み');
  await sato.click('text=処理済み');
  await sato.waitForURL(/tab=done/);
  list = await items(sato);
  // 並び順は「処理した日時の新しい順、同じ秒なら申請の新しい順」（テストでは同じ秒に処理するため、DB の値で確かめる）
  const expectedOrder = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025001' AND status <> 'pending' ORDER BY reviewed_at DESC, id DESC`).split('\n');
  const shownOrder = await sato.$$eval('.approval-item', els => els.length);
  const rejected = list.find(t => t.includes('却下'));
  check('処理済み 3 件', list.length === 3 && shownOrder === 3 && expectedOrder.length === 3 && list.filter(t => t.includes('承認済み')).length === 2, JSON.stringify(list));
  check('却下：処理した管理者・コメント（エスケープ）', rejected && rejected.includes('残業') && rejected.includes('佐藤 花子') && rejected.includes('コメント：<b>事前申請</b>が必要'), rejected);
  const typeById = Object.fromEntries(sql(`SELECT id, request_type, IF(original_check_in_at IS NULL AND request_type='correction','new','x') FROM attendance_requests WHERE employee_id='2025001'`).split('\n').map(l => l.split('\t')).map(([id, t, n]) => [id, t === 'overtime' ? '残業' : (n === 'new' ? '新規登録' : '修正')]));
  check('新しい順（処理した日時、同じなら申請の新しい順）', expectedOrder.every((id, i) => list[i].includes(typeById[id])), JSON.stringify(expectedOrder));
  await sato.screenshot({ path: SHOT + 'pc_done.png', fullPage: true });
  check('処理済みの申請日時が正しい（1/1 にならない）', !list.some(t => t.includes(' 1/1 ')) && list.every(t => /\d+\/\d+ \d\d:\d\d/.test(t)), JSON.stringify(list));
  check('選んでいるタブの文字は白（マウスが乗っていても）', await sato.$eval('.tabs__item[aria-current=page]', el => getComputedStyle(el).color === 'rgb(255, 255, 255)'));

  console.log('[7] 社員の画面に反映');
  await yamada.goto(BASE + 'records/');
  check('承認済みのバッジ・時刻', (await yamada.locator(`[data-date="${A}"]`).innerText()).includes('承認済み') && (await yamada.locator(`[data-date="${A}"]`).innerText()).includes('18:30'));
  await yamada.locator(`[data-date="${D}"]`).click(); await yamada.waitForSelector('#day-dialog[open]');
  const dItem = await yamada.$eval('#day-request-items .request-item', el => el.innerText.replace(/\s+/g, ' '));
  check('却下とコメント', dItem.includes('却下') && dItem.includes('却下コメント：<b>事前申請</b>が必要'), dItem);
  await yamada.keyboard.press('Escape');

  console.log('[8] 全社の管理者（鈴木）');
  const suzukiApprovals = await suzuki.goto(BASE + 'approvals/');
  list = await items(suzuki);
  check('佐藤の申請が出る・自分の申請は出ない', list.length === 1 && list[0].includes('佐藤 花子'), JSON.stringify(list));
  const reqSato = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025002'`);
  const reqSuzuki = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025003'`);

  console.log('[9] API を直接');
  await sato.goto(BASE + 'approvals/');
  let a = await review(sato, { request_id: reqSuzuki, decision: 'approve' });
  check('範囲外（開発部）→ 権限なし', a.json.ok === false && a.json.errors.form === 'この申請を処理する権限がありません。' && sql(`SELECT status FROM attendance_requests WHERE id=${reqSuzuki}`) === 'pending');
  a = await review(sato, { request_id: reqSato, decision: 'approve' });
  check('自分の申請 → 権限なし', a.json.ok === false && sql(`SELECT status FROM attendance_requests WHERE id=${reqSato}`) === 'pending');
  a = await review(sato, { request_id: reqA, decision: 'reject' });
  check('処理済み → すでに処理', a.json.ok === false && a.json.errors.form.includes('すでに処理されています'));
  a = await review(sato, { request_id: '999999', decision: 'approve' });
  check('存在しない → 見つからない', a.json.ok === false && a.json.errors.form.includes('見つかりません'));
  a = await review(sato, { request_id: 'abc', decision: 'approve' });
  check('ID が正しくない → 400', a.status === 400);
  a = await review(sato, { request_id: reqSato, decision: 'delete' });
  check('知らない操作 → 400', a.status === 400);
  a = await review(sato, { request_id: reqSato, decision: 'approve', token: 'bad' });
  check('CSRF 違い → 400', a.status === 400);
  check('GET → 405', (await sato.evaluate(async () => (await fetch('/nm_attendance/api/approvals.php')).status)) === 405);
  const empApi = await yamada.evaluate(async () => { const fd = new FormData(); fd.append('csrf_token', document.getElementById('calendar').dataset.csrfToken); fd.append('request_id', '1'); fd.append('decision', 'approve'); return (await fetch('/nm_attendance/api/approvals.php', { method: 'POST', body: fd })).status; });
  check('社員 → 403', empApi === 403);
  const anon = await (await browser.newContext()).request.post(BASE + 'api/approvals.php', { form: { request_id: '1', decision: 'approve' } });
  check('未ログイン → 401', anon.status() === 401);

  console.log('[10] 同時に処理');
  const both = await suzuki.evaluate(async (id) => {
    const send = () => { const fd = new FormData(); fd.append('csrf_token', document.getElementById('approvals').dataset.csrfToken); fd.append('request_id', id); fd.append('decision', 'approve'); return fetch('/nm_attendance/api/approvals.php', { method: 'POST', body: fd }).then(r => r.json()); };
    return Promise.all([send(), send(), send()]);
  }, reqSato);
  check('3回同時に送っても、承認は1回', both.filter(x => x.ok).length === 1 && both.filter(x => !x.ok && x.errors.form.includes('すでに処理')).length === 2 && sql(`SELECT COUNT(*) FROM attendances WHERE employee_id='2025002'`) === '1', JSON.stringify(both));

  console.log('[11] 古い画面から押す');
  await yamada.goto(BASE + 'records/');
  await submit(yamada, { type: 'correction', target_date: A, check_in: '08:30', check_out: '18:30' });
  await sato.goto(BASE + 'approvals/');
  const sato2 = await sato.context().newPage();
  await sato2.goto(BASE + 'approvals/');
  const reqA2 = sql(`SELECT id FROM attendance_requests WHERE employee_id='2025001' AND target_date='${A}' AND status='pending'`);
  await Promise.all([sato2.waitForNavigation(), sato2.click(`[data-approve="${reqA2}"]`)]);
  await sato.click(`[data-approve="${reqA2}"]`);
  await sato.waitForSelector('#approval-error:not([hidden])');
  check('すでに処理された申請を押すと、案内', (await sato.textContent('#approval-error')).includes('すでに処理されています'));

  console.log('[12] スマホの幅');
  await submit(yamada, { type: 'correction', target_date: B, check_in: '08:00', check_out: '18:00', reason: '電車遅延' });
  const m = await login(browser, '2025002', 'Test1234', { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
  await m.goto(BASE + 'approvals/');
  check('横にはみ出さない', !(await m.evaluate(() => document.documentElement.scrollWidth > 375)));
  check('カード型（見出しの行を出さない）', !(await m.isVisible('.approval-list__head')));
  await m.screenshot({ path: SHOT + 'sp_todo.png', fullPage: true });
  await m.click('[data-reject]'); await m.waitForSelector('#reject-dialog[open]');
  const sheet = await m.evaluate(() => { const r = document.getElementById('reject-dialog').getBoundingClientRect(); return Math.round(r.bottom) === window.innerHeight && r.left === 0; });
  check('却下のウィンドウは下から', sheet);
  await m.screenshot({ path: SHOT + 'sp_reject.png' });

  console.log('[13] システム管理者');
  const admin = await login(browser, 'sysadmin', 'Admin1234');
  await admin.click('#menu-open');
  check('メニューに「承認」なし', !(await admin.$$eval('#menu .menu__link', as => as.map(x => x.textContent))).includes('承認'));
  check('approvals/ は 403', (await admin.goto(BASE + 'approvals/')).status() === 403);

  check('<script> が実行されない', !alerted);
  check('ブラウザのエラーなし', errors.length === 0, errors.join(' | '));
  await browser.close();
  console.log(`\n結果: ${pass} 件 OK / ${fail} 件 NG`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
