<?php
declare(strict_types=1);

function easter_sunday(int $year): DateTimeImmutable {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone('Europe/Rome'));
}

function italian_holidays(int $year): array {
    $easter = easter_sunday($year);
    return [
        sprintf('%04d-01-01', $year) => 'Capodanno',
        sprintf('%04d-01-06', $year) => 'Epifania',
        $easter->modify('+1 day')->format('Y-m-d') => 'Lunedì dell’Angelo',
        sprintf('%04d-04-25', $year) => 'Liberazione',
        sprintf('%04d-05-01', $year) => 'Festa del lavoro',
        sprintf('%04d-06-02', $year) => 'Festa della Repubblica',
        sprintf('%04d-08-15', $year) => 'Ferragosto',
        sprintf('%04d-11-01', $year) => 'Ognissanti',
        sprintf('%04d-12-08', $year) => 'Immacolata',
        sprintf('%04d-12-25', $year) => 'Natale',
        sprintf('%04d-12-26', $year) => 'Santo Stefano',
    ];
}

function is_italian_holiday(DateTimeImmutable $d): bool {
    $map = italian_holidays((int) $d->format('Y'));
    return isset($map[$d->format('Y-m-d')]);
}
