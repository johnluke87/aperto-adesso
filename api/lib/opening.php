<?php
declare(strict_types=1);

const OH_DAYS = ['mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4, 'fr' => 5, 'sa' => 6, 'su' => 7];

function oh_minutes(string $hhmm): int {
    $hhmm = str_replace('.', ':', $hhmm);
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
        return 0;
    }
    return ((int) $m[1]) * 60 + (int) $m[2];
}

/**
 * Parse the realistic Italian subset of OSM opening_hours into rules:
 * [['days' => [1..7], 'ph' => bool, 'off' => bool, 'spans' => [[from, to], …]], …]
 * Returns null when the string uses syntax we do not understand (months, weeks, "by appointment"…).
 */
function oh_parse(string $oh): ?array {
    $oh = trim($oh);
    if ($oh === '') {
        return null;
    }
    $rules = [];
    foreach (preg_split('/\s*;\s*/', $oh) ?: [] as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        if (preg_match('/^24\/7(\s+open)?$/i', $raw)) {
            $rules[] = ['days' => range(1, 7), 'ph' => true, 'off' => false, 'spans' => [[0, 1440]]];
            continue;
        }
        $days = [];
        $ph = false;
        $tok = '(?:Mo|Tu|We|Th|Fr|Sa|Su|PH)';
        if (preg_match('/^(' . $tok . '(?:\s*-\s*' . $tok . ')?(?:\s*,\s*' . $tok . '(?:\s*-\s*' . $tok . ')?)*)\s*:?\s*(.*)$/i', $raw, $m)) {
            foreach (preg_split('/\s*,\s*/', $m[1]) as $part) {
                if (strcasecmp($part, 'PH') === 0) {
                    $ph = true;
                    continue;
                }
                $ab = preg_split('/\s*-\s*/', strtolower($part));
                if (!isset(OH_DAYS[$ab[0]]) || (isset($ab[1]) && !isset(OH_DAYS[$ab[1]]))) {
                    return null;
                }
                $a = OH_DAYS[$ab[0]];
                $b = isset($ab[1]) ? OH_DAYS[$ab[1]] : $a;
                for ($d = $a; ; $d = $d % 7 + 1) {
                    $days[] = $d;
                    if ($d === $b) {
                        break;
                    }
                }
            }
            $rest = trim($m[2]);
        } else {
            $days = range(1, 7);
            $rest = $raw;
        }
        if ($rest === '' ) {
            // "Mo-Su" alone means open all day.
            $rules[] = ['days' => $days, 'ph' => $ph, 'off' => false, 'spans' => [[0, 1440]]];
            continue;
        }
        if (preg_match('/^(off|closed)$/i', $rest)) {
            $rules[] = ['days' => $days, 'ph' => $ph, 'off' => true, 'spans' => []];
            continue;
        }
        $spans = [];
        foreach (preg_split('/\s*,\s*/', $rest) as $span) {
            if (!preg_match('/^(\d{1,2}[:.]\d{2})\s*-\s*(\d{1,2}[:.]\d{2})\+?(\s+open)?$/i', $span, $tm)) {
                return null;
            }
            $spans[] = [oh_minutes($tm[1]), oh_minutes($tm[2])];
        }
        $rules[] = ['days' => $days, 'ph' => $ph, 'off' => false, 'spans' => $spans];
    }
    return $rules ?: null;
}

/** Spans in force on a given weekday; later rules override earlier ones (OSM semantics). */
function oh_day_spans(array $rules, int $dow, bool $holiday): ?array {
    $spans = null;
    foreach ($rules as $r) {
        $applies = $holiday ? ($r['ph'] || (in_array($dow, $r['days'], true) && !oh_has_ph_rule($rules)))
            : in_array($dow, $r['days'], true);
        if ($applies) {
            $spans = $r['off'] ? [] : $r['spans'];
        }
    }
    return $spans;
}

function oh_has_ph_rule(array $rules): bool {
    foreach ($rules as $r) {
        if ($r['ph'] && count($r['days']) < 7) {
            return true;
        }
    }
    return false;
}

/** true = open, false = closed, null = unknown (missing or unparsable hours). */
function oh_is_open(?string $oh, DateTimeImmutable $at, bool $holiday): ?bool {
    if ($oh === null) {
        return null;
    }
    $rules = oh_parse($oh);
    if ($rules === null) {
        return null;
    }
    $dow = (int) $at->format('N');
    $nowm = ((int) $at->format('G')) * 60 + (int) $at->format('i');
    foreach (oh_day_spans($rules, $dow, $holiday) ?? [] as [$a, $b]) {
        if ($b <= $a ? $nowm >= $a : ($nowm >= $a && $nowm < $b)) {
            return true;
        }
    }
    // Overnight span started yesterday (e.g. Fr 20:00-08:00 seen on Saturday at 03:00).
    $y = $at->modify('-1 day');
    $yHoliday = function_exists('is_italian_holiday') && is_italian_holiday($y);
    foreach (oh_day_spans($rules, (int) $y->format('N'), $yHoliday) ?? [] as [$a, $b]) {
        if ($b < $a && $nowm < $b) {
            return true;
        }
        if ($b > 1440 && $nowm < $b - 1440) {
            return true;
        }
    }
    return false;
}
