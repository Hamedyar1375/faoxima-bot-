<?php

/**
 * fx_nav_guard_selftest.php — global keyboard navigation checks.
 * Read-only; no database mutation.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');

define('REFACTORED_LEGACY_ROOT', dirname(__DIR__));
define('FAOXIMA_SKIP_BOTAPI_ROUTER', true);
require_once REFACTORED_LEGACY_ROOT . '/config.php';
require_once REFACTORED_LEGACY_ROOT . '/botapi.php';
require_once REFACTORED_LEGACY_ROOT . '/function.php';

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n";
};

$check('nav guard loaded', function_exists('rx_nav_guard_keyboard'));
$decode = static function ($json): array {
    $d = json_decode((string) $json, true);
    return is_array($d) ? $d : [];
};
$callbacks = static function (array $d): array {
    $out = [];
    foreach (($d['inline_keyboard'] ?? []) as $row) {
        foreach ((array) $row as $btn) {
            if (isset($btn['callback_data'])) $out[] = (string) $btn['callback_data'];
        }
    }
    return $out;
};

$empty = $decode(rx_nav_guard_keyboard(null, 123));
$emptyCb = $callbacks($empty);
$check('no-keyboard page gets back button', in_array('fx_nav_back', $emptyCb, true));
$check('no-keyboard page gets main-menu button', in_array('fx_nav_home', $emptyCb, true));

$backOnly = $decode(rx_nav_guard_keyboard(json_encode([
    'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'helpbtns']]],
]), 123));
$backOnlyCb = $callbacks($backOnly);
$check('existing back is preserved', in_array('helpbtns', $backOnlyCb, true));
$check('existing back gets main-menu button', in_array('fx_nav_home', $backOnlyCb, true));
$check('existing back does not get duplicate back', !in_array('fx_nav_back', $backOnlyCb, true));

$homeOnly = $decode(rx_nav_guard_keyboard(json_encode([
    'inline_keyboard' => [[['text' => '🏠 منوی اصلی', 'callback_data' => 'backuser']]],
]), 123));
$homeOnlyCb = $callbacks($homeOnly);
$check('existing main-menu is preserved', in_array('backuser', $homeOnlyCb, true));
$check('existing main-menu gets back button', in_array('fx_nav_back', $homeOnlyCb, true));
$check('existing main-menu does not get duplicate home', !in_array('fx_nav_home', $homeOnlyCb, true));

$both = $decode(rx_nav_guard_keyboard(json_encode([
    'inline_keyboard' => [[
        ['text' => '🔙 بازگشت به منوی قبل', 'callback_data' => 'backmenu'],
        ['text' => '🏠 منوی اصلی', 'callback_data' => 'backuser'],
    ]],
]), 123));
$check('fully navigable keyboard is unchanged', count($both['inline_keyboard'] ?? []) === 1);

$replyKb = '{"keyboard":[[{"text":"خرید"}]],"resize_keyboard":true}';
$check('reply keyboard is not modified', rx_nav_guard_keyboard($replyKb, 123) === $replyKb);

$faq = $decode(rx_nav_guard_keyboard(json_encode([
    'inline_keyboard' => [[
        ['text' => '📚 آموزش اتصال', 'callback_data' => 'helpos_1'],
    ], [
        ['text' => '🔙 بازگشت به لیست راهنما', 'callback_data' => 'helpbtns'],
    ]],
], JSON_UNESCAPED_UNICODE), 123));
$faqCb = $callbacks($faq);
$check('FAQ keeps content buttons', in_array('helpos_1', $faqCb, true));
$check('FAQ keeps back-to-list button', in_array('helpbtns', $faqCb, true));
$check('FAQ gets main-menu button', in_array('fx_nav_home', $faqCb, true));

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
