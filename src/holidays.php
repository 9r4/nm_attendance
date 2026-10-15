<?php
// 日本の祝日の計算（D-044）。DB には持たず、法律（国民の祝日に関する法律）のルールで計算する
// 対象：2000〜2099 年（春分・秋分の日の計算式が使える範囲。記録表示で使う年は、これより狭い）

const HOLIDAYS_MIN_YEAR = 2000;
const HOLIDAYS_MAX_YEAR = 2099;

// その年の祝日・休日を返す。戻り値：['2026-01-01' => '元日', …]（日付の順）
function japaneseHolidays(int $year): array {
    if ($year < HOLIDAYS_MIN_YEAR || $year > HOLIDAYS_MAX_YEAR) {
        throw new InvalidArgumentException('祝日を計算できない年です: ' . $year);
    }

    $date = fn (int $month, int $day) => sprintf('%04d-%02d-%02d', $year, $month, $day);
    $holidays = [];

    // 1. 国民の祝日
    $holidays[$date(1, 1)] = '元日';
    $holidays[nthMonday($year, 1, 2)] = '成人の日';
    $holidays[$date(2, 11)] = '建国記念の日';
    if ($year >= 2020) {
        $holidays[$date(2, 23)] = '天皇誕生日';
    }
    $holidays[$date(3, vernalEquinoxDay($year))] = '春分の日';
    $holidays[$date(4, 29)] = $year >= 2007 ? '昭和の日' : 'みどりの日';
    $holidays[$date(5, 3)] = '憲法記念日';
    if ($year >= 2007) {
        $holidays[$date(5, 4)] = 'みどりの日';
    }
    $holidays[$date(5, 5)] = 'こどもの日';

    // 海の日・山の日・スポーツの日（2020・2021 年は、オリンピックのため移動）
    if ($year === 2020) {
        $holidays[$date(7, 23)] = '海の日';
        $holidays[$date(7, 24)] = 'スポーツの日';
        $holidays[$date(8, 10)] = '山の日';
    } elseif ($year === 2021) {
        $holidays[$date(7, 22)] = '海の日';
        $holidays[$date(7, 23)] = 'スポーツの日';
        $holidays[$date(8, 8)] = '山の日';
    } else {
        $holidays[$year >= 2003 ? nthMonday($year, 7, 3) : $date(7, 20)] = '海の日';
        if ($year >= 2016) {
            $holidays[$date(8, 11)] = '山の日';
        }
        $holidays[nthMonday($year, 10, 2)] = $year >= 2020 ? 'スポーツの日' : '体育の日';
    }

    $holidays[$year >= 2003 ? nthMonday($year, 9, 3) : $date(9, 15)] = '敬老の日';
    $holidays[$date(9, autumnalEquinoxDay($year))] = '秋分の日';
    $holidays[$date(11, 3)] = '文化の日';
    $holidays[$date(11, 23)] = '勤労感謝の日';
    if ($year <= 2018) {
        $holidays[$date(12, 23)] = '天皇誕生日';
    }

    // 2019 年だけの祝日（天皇の即位）
    if ($year === 2019) {
        $holidays[$date(5, 1)] = '即位の日';
        $holidays[$date(10, 22)] = '即位礼正殿の儀';
    }

    // 2. 振替休日：祝日が日曜日のときは、その後の最も近い「祝日でない日」が休日（2006 年までは、翌日の月曜日のみ）
    $substitutes = [];
    foreach (array_keys($holidays) as $day) {
        if (date('w', strtotime($day)) !== '0') {
            continue;
        }
        $next = date('Y-m-d', strtotime($day . ' +1 day'));
        if ($year >= 2007) {
            while (isset($holidays[$next])) {
                $next = date('Y-m-d', strtotime($next . ' +1 day'));
            }
        } elseif (isset($holidays[$next])) {
            continue;
        }
        if (substr($next, 0, 4) === (string) $year) {
            $substitutes[$next] = '振替休日';
        }
    }
    $holidays += $substitutes;

    // 3. 国民の休日：前の日と次の日が祝日で、その日が祝日でない日（2006 年までは、日曜日を除く）
    $citizens = [];
    foreach (array_keys($holidays) as $day) {
        $middle = date('Y-m-d', strtotime($day . ' +1 day'));
        $after = date('Y-m-d', strtotime($day . ' +2 day'));
        if (isset($holidays[$after]) && !isset($holidays[$middle]) && ($year >= 2007 || date('w', strtotime($middle)) !== '0')) {
            $citizens[$middle] = '国民の休日';
        }
    }
    $holidays += $citizens;

    ksort($holidays);
    return $holidays;
}

// その月の第 n 月曜日（例：nthMonday(2026, 1, 2) → '2026-01-12'）
function nthMonday(int $year, int $month, int $n): string {
    $firstWeekday = (int) date('w', mktime(0, 0, 0, $month, 1, $year));
    $firstMonday = 1 + (8 - $firstWeekday) % 7;
    return sprintf('%04d-%02d-%02d', $year, $month, $firstMonday + 7 * ($n - 1));
}

// 春分の日（3月の日）。1980〜2099 年に使える計算式
function vernalEquinoxDay(int $year): int {
    return (int) floor(20.8431 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
}

// 秋分の日（9月の日）。1980〜2099 年に使える計算式
function autumnalEquinoxDay(int $year): int {
    return (int) floor(23.2488 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
}
