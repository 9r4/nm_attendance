<?php
// 申請（src/requests.php）のテスト：入力のルール、変更前 → 申請値、バッジ、カレンダーへの反映。DB は使わない

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/records.php';

final class RequestsTest extends TestCase {
    private const NOW = '2026-10-15 14:30:20';
    private const JOINED = '2025-04-01';

    // 勤怠の行（申請の対象日の今の値）
    private static function attendance(?string $in, ?string $out, ?string $bs = null, ?string $be = null, string $date = '2026-10-14', ?string $overtime = null): array {
        $at = fn (?string $t) => $t === null ? null : $date . ' ' . $t . ':00';
        return ['check_in_at' => $at($in), 'check_out_at' => $at($out), 'break_start_at' => $at($bs), 'break_end_at' => $at($be), 'overtime_end_at' => $at($overtime)];
    }

    private static function input(string $date, string $in, string $out, string $bs = '', string $be = '', string $reason = ''): array {
        return ['target_date' => $date, 'check_in' => $in, 'check_out' => $out, 'break_start' => $bs, 'break_end' => $be, 'reason' => $reason];
    }

    // ---- 修正・新規登録の入力のルール ----
    public static function invalidCorrections(): array {
        $current = self::attendance('09:00', '18:00');
        return [
            '対象日が空' => [self::input('', '09:00', '18:00'), null, 'target_date'],
            '対象日の形が違う' => [self::input('2026/10/14', '09:00', '18:00'), null, 'target_date'],
            '存在しない日' => [self::input('2026-02-30', '09:00', '18:00'), null, 'target_date'],
            '未来の日' => [self::input('2026-10-16', '09:00', '18:00'), null, 'target_date'],
            '入社日より前' => [self::input('2025-03-31', '09:00', '18:00'), null, 'target_date'],
            '時刻の形が違う' => [self::input('2026-10-14', '9:00', '18:00'), $current, 'check_in'],
            '時刻が 24 時' => [self::input('2026-10-14', '09:00', '24:00'), $current, 'check_out'],
            '出勤が空' => [self::input('2026-10-14', '', '18:00'), $current, 'check_in'],
            '休憩の開始だけ' => [self::input('2026-10-14', '09:00', '18:00', '12:00', ''), $current, 'break_end'],
            '休憩の終了だけ' => [self::input('2026-10-14', '09:00', '18:00', '', '12:30'), $current, 'break_start'],
            '退勤が出勤より前' => [self::input('2026-10-14', '09:00', '08:00'), $current, 'check_out'],
            '退勤が出勤と同じ' => [self::input('2026-10-14', '09:00', '09:00'), $current, 'check_out'],
            '休憩開始が出勤より前' => [self::input('2026-10-14', '09:00', '18:00', '08:30', '08:45'), $current, 'break_start'],
            '休憩終了が休憩開始より前' => [self::input('2026-10-14', '09:00', '18:00', '15:00', '14:00'), $current, 'break_end'],
            '退勤が休憩終了より前' => [self::input('2026-10-14', '09:00', '15:00', '14:00', '16:00'), $current, 'check_out'],
            '今日のまだ来ていない時刻' => [self::input('2026-10-15', '09:00', '14:31'), null, 'check_out'],
            '理由が 501 文字' => [self::input('2026-10-14', '09:00', '19:00', '', '', str_repeat('あ', 501)), $current, 'reason'],
            '今の記録と同じ' => [self::input('2026-10-14', '09:00', '18:00'), $current, 'form'],
        ];
    }

    #[DataProvider('invalidCorrections')]
    public function testInvalidCorrection(array $input, ?array $current, string $errorField): void {
        $result = validateCorrection($input, $current, self::NOW, self::JOINED);
        $this->assertArrayHasKey($errorField, $result['errors'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    }

    public function testErrorMessages(): void {
        $current = self::attendance('09:00', '18:00');
        $errors = fn (array $input) => validateCorrection($input, $current, self::NOW, self::JOINED)['errors'];
        $this->assertSame(['check_out' => '退勤時刻は、出勤時刻より後にしてください。'], $errors(self::input('2026-10-14', '09:00', '08:00')));
        $this->assertSame(['check_in' => '出勤時刻を入力してください。'], $errors(self::input('2026-10-14', '', '18:00')));
        $this->assertSame(['form' => '今の記録から変更がありません。'], $errors(self::input('2026-10-14', '09:00', '18:00')));
    }

    public function testValidCorrection(): void {
        // 退勤の修正（休憩は空欄＝なし、理由は前後の空白を取る）
        $result = validateCorrection(self::input('2026-10-14', '09:00', '19:00', '', '', '  打刻漏れ  '), self::attendance('09:00', '18:00'), self::NOW, self::JOINED);
        $this->assertSame([], $result['errors']);
        $this->assertSame(['check_in_at' => '2026-10-14 09:00:00', 'break_start_at' => null, 'break_end_at' => null, 'check_out_at' => '2026-10-14 19:00:00'], $result['requested']);
        $this->assertSame('打刻漏れ', $result['reason']);

        // 新規登録（記録なし）。退勤は空欄でもよい。理由が空なら null
        $result = validateCorrection(self::input('2026-10-14', '09:00', ''), null, self::NOW, self::JOINED);
        $this->assertSame([], $result['errors']);
        $this->assertNull($result['reason']);

        // 間違って記録された休憩を消す修正
        $result = validateCorrection(self::input('2026-10-14', '09:00', '18:00'), self::attendance('09:00', '18:00', '15:00', '15:10'), self::NOW, self::JOINED);
        $this->assertSame([], $result['errors']);

        // 今日：今の時刻（14:30）までなら申請できる。入社日・理由 500 文字ちょうども可
        $this->assertSame([], validateCorrection(self::input('2026-10-15', '09:00', '14:30'), null, self::NOW, self::JOINED)['errors']);
        $this->assertSame([], validateCorrection(self::input('2025-04-01', '09:00', '18:00'), null, self::NOW, self::JOINED)['errors']);
        $this->assertSame([], validateCorrection(self::input('2026-10-14', '09:00', '19:00', '', '', str_repeat('あ', 500)), self::attendance('09:00', '18:00'), self::NOW, self::JOINED)['errors']);
    }

    public function testNonStringInputIsTreatedAsEmpty(): void {
        $input = self::input('2026-10-14', '09:00', '18:00');
        $input['check_in'] = ['09:00'];
        $this->assertSame('出勤時刻を入力してください。', validateCorrection($input, null, self::NOW, self::JOINED)['errors']['check_in']);
    }

    // ---- 簡単残業申請 ----
    public function testOvertime(): void {
        $today = '2026-10-15';
        $out1930 = self::attendance('09:00', '19:30', null, null, $today);
        $check = fn (?array $attendance, string $end) => validateOvertime(['overtime_end' => $end], $attendance, $today, '18:00:00');

        $this->assertSame(['errors' => [], 'overtimeEndAt' => '2026-10-15 19:00:00'], $check($out1930, '19:00'));
        $this->assertSame([], $check($out1930, '19:30')['errors']); // 退勤時刻ちょうどは可
        $this->assertSame([], $check($out1930, '18:01')['errors']);
        $this->assertSame(['overtime_end' => '標準終了時刻（18:00）より後の時刻を指定してください。'], $check($out1930, '18:00')['errors']);
        $this->assertSame(['overtime_end' => '退勤時刻（19:30）を超える時刻は指定できません。'], $check($out1930, '19:31')['errors']);
        $this->assertSame(['overtime_end' => '残業の終了時刻を入力してください。'], $check($out1930, '')['errors']);
        $this->assertSame(['overtime_end' => '時刻の形が正しくありません。'], $check($out1930, '7pm')['errors']);
        // 押せない状態：退勤前・退勤が 18:00 ちょうど・記録なし
        foreach ([self::attendance('09:00', null, null, null, $today), self::attendance('09:00', '18:00', null, null, $today), null] as $attendance) {
            $this->assertArrayHasKey('form', $check($attendance, '19:00')['errors']);
        }
    }

    public function testOvertimeAvailable(): void {
        $this->assertTrue(overtimeAvailable(self::attendance('09:00', '18:01'), '18:00:00'));
        $this->assertFalse(overtimeAvailable(self::attendance('09:00', '18:00'), '18:00:00'));
        $this->assertFalse(overtimeAvailable(self::attendance('09:00', null), '18:00:00'));
        $this->assertFalse(overtimeAvailable(null, '18:00:00'));
        $this->assertTrue(attendanceView(self::attendance('09:00', '19:00'), '18:00:00')['buttons']['overtime']);
        $this->assertFalse(attendanceView(self::attendance('09:00', '17:00'), '18:00:00')['buttons']['overtime']);
        $this->assertArrayNotHasKey('overtime', attendanceView(null)['buttons']);
    }

    // ---- 申請内容の表示 ----
    private static function request(string $type, array $requested, array $original, string $status = 'pending', string $date = '2026-10-14', string $created = '2026-10-14 20:00:00'): array {
        $row = ['id' => 1, 'target_date' => $date, 'request_type' => $type, 'reason' => null, 'status' => $status, 'review_comment' => null, 'created_at' => $created];
        foreach (['check_in_at', 'check_out_at', 'break_start_at', 'break_end_at', 'overtime_end_at'] as $field) {
            $row['requested_' . $field] = $requested[$field] ?? null;
            $row['original_' . $field] = $original[$field] ?? null;
        }
        return $row;
    }

    public function testRequestView(): void {
        $current = ['check_in_at' => '2026-10-14 09:00:00', 'check_out_at' => '2026-10-14 17:30:00'];
        $correction = self::request('correction', ['check_out_at' => '2026-10-14 18:45:00'] + $current, $current);
        $correction['reason'] = '打刻漏れ';
        $view = requestView($correction);
        $this->assertSame('修正', $view['type']);
        $this->assertSame([['label' => '退勤', 'before' => '17:30', 'after' => '18:45']], $view['changes']);
        $this->assertSame(['申請中', '2026/10/14 20:00', '打刻漏れ', ''], [$view['statusLabel'], $view['createdAt'], $view['reason'], $view['reviewComment']]);

        $new = requestView(self::request('correction', ['check_in_at' => '2026-10-14 09:00:00', 'check_out_at' => '2026-10-14 18:00:00'], []));
        $this->assertSame('新規登録', $new['type']);
        $this->assertSame([['label' => '出勤', 'before' => 'なし', 'after' => '09:00'], ['label' => '退勤', 'before' => 'なし', 'after' => '18:00']], $new['changes']);

        $removeBreak = requestView(self::request('correction', $current, $current + ['break_start_at' => '2026-10-14 15:00:00', 'break_end_at' => '2026-10-14 15:10:00']));
        $this->assertSame(['休憩開始', '15:00', 'なし'], array_values($removeBreak['changes'][0]));

        $overtime = self::request('overtime', ['overtime_end_at' => '2026-10-14 20:00:00'], [], 'rejected');
        $overtime['review_comment'] = '事前申請が必要';
        $view = requestView($overtime);
        $this->assertSame(['残業', '却下', '事前申請が必要'], [$view['type'], $view['statusLabel'], $view['reviewComment']]);
        $this->assertSame([['label' => '残業終了', 'before' => 'なし', 'after' => '20:00']], $view['changes']);
    }

    public function testBadgeAndPending(): void {
        $approved = self::request('correction', [], [], 'approved');
        $rejected = self::request('overtime', [], [], 'rejected');
        $pendingOvertime = self::request('overtime', [], [], 'pending');
        $pendingCorrection = self::request('correction', [], [], 'pending');

        $this->assertNull(requestBadgeStatus([]));
        $this->assertSame('rejected', requestBadgeStatus([$approved, $rejected]));      // 最新
        $this->assertSame('approved', requestBadgeStatus([$rejected, $approved]));
        $this->assertSame('pending', requestBadgeStatus([$pendingOvertime, $approved])); // 申請中を優先
        $this->assertFalse(hasPendingCorrection([$pendingOvertime, $approved]));       // 残業の申請中は、修正の申請を止めない
        $this->assertTrue(hasPendingCorrection([$approved, $pendingCorrection]));
    }

    // カレンダー：バッジ、申請の一覧、申請できるか、申請ウィンドウに入れる値
    public function testCalendarWithRequests(): void {
        $month = resolveMonth(null, '2026-10-15', '2026-10-05');
        $settings = ['lunch_start' => '12:00:00', 'lunch_end' => '13:00:00'];
        $rows = [['work_date' => '2026-10-14'] + self::attendance('09:00', '18:00', '15:00', '15:10')];
        $requests = [
            self::request('correction', [], [], 'approved', '2026-10-13'),
            self::request('correction', [], [], 'pending', '2026-10-14'),
        ];
        $calendar = buildCalendar($month, $rows, $settings, '2026-10-15', $requests, '2026-10-05');
        $cells = [];
        foreach (array_merge(...$calendar['weeks']) as $cell) {
            if ($cell !== null) {
                $cells[$cell['date']] = $cell;
            }
        }
        $this->assertSame(['approved', '承認済み', true], [$cells['2026-10-13']['badge'], $cells['2026-10-13']['badgeLabel'], $cells['2026-10-13']['canRequest']]);
        $this->assertSame(['pending', true, false], [$cells['2026-10-14']['badge'], $cells['2026-10-14']['pendingCorrection'], $cells['2026-10-14']['canRequest']]);
        $this->assertCount(1, $cells['2026-10-14']['requests']);
        $this->assertSame(['check_in' => '09:00', 'check_out' => '18:00', 'break_start' => '15:00', 'break_end' => '15:10'], $cells['2026-10-14']['values']);
        $this->assertSame(['check_in' => '', 'check_out' => '', 'break_start' => '', 'break_end' => ''], $cells['2026-10-15']['values']);
        $this->assertNull($cells['2026-10-15']['badge']);
        $this->assertTrue($cells['2026-10-15']['canRequest']);   // 今日は申請できる
        $this->assertFalse($cells['2026-10-16']['canRequest']);  // 未来の日
        $this->assertTrue($cells['2026-10-16']['isFuture']);
        $this->assertFalse($cells['2026-10-04']['canRequest']);  // 入社日より前
        $this->assertTrue($cells['2026-10-05']['canRequest']);
    }
}
