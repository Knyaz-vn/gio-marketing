<?php
/**
 * Перевірка бюджету розміру віджета: JS + CSS < 40 KB gzip.
 * Запуск: php bin/widget-size.php
 */
$budget = 40 * 1024;
$total = 0;
foreach (['assets/widget/booking.js', 'assets/widget/booking.css'] as $f) {
    $raw = (string) file_get_contents(__DIR__ . '/../' . $f);
    $gz = strlen((string) gzencode($raw, 9));
    $total += $gz;
    printf("%-28s %6.1f KB raw  %5.1f KB gzip\n", $f, strlen($raw) / 1024, $gz / 1024);
}
printf("%-28s %20.1f KB gzip (бюджет 40 KB)\n", 'РАЗОМ', $total / 1024);
exit($total <= $budget ? 0 : 1);
