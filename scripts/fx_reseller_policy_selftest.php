<?php
/**
 * fx_reseller_policy_selftest.php — verify main-only gate for reseller admins.
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_policy_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';

$pass = 0;
$fail = 0;
$check = static function ($label, $ok) use (&$pass, &$fail) {
    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
};

$blocked = [
    ['', 'admin_settings', ''],
    ['', 'set_webhook', ''],
    ['', 'admin_shop', ''],
    ['', 'cat_add', ''],
    ['', 'cat_del', ''],
    ['', 'shopitem_add', ''],
    ['', 'shopitem_edit', ''],
    ['', 'shop_giftadd', ''],
    ['', 'shop_discountadd', ''],
    ['', 'editstsuts-randomwallet-1', ''],
    ['', 'randomwalletlimit', ''],
    ['', 'premium_emoji_settings', ''],
    ['', 'infocard_color_menu', ''],
    ['', 'changeloclimit', ''],
    ['', 'hidepanel_123', ''],
    ['', 'productedit_123', ''],
    ['', 'typeagenteditproduct_123', ''],
    ['', 'backup_now', ''],
    ['🏬 تنظیمات فروشگاه', '', ''],
    ['⚙️ تنظیمات فنی و ربات', '', ''],
    ['⚙️ وضعیت قابلیت ها پنل', '', ''],
    ['📦 بکاپ‌گیری', '', ''],
    ['', '', 'getcategory'],
    ['', '', 'endstep'],
    ['', '', 'getpricef'],
    ['', '', 'getpanelgift'],
];
foreach ($blocked as $case) {
    $check('blocked ' . json_encode($case, JSON_UNESCAPED_UNICODE), fx_reseller_main_only_hit($case[0], $case[1], $case[2]) === true);
}

$allowed = [
    ['', 'set_channel', ''],
    ['', 'set_reports', ''],
    ['', 'admin_finance', ''],
    ['', 'admin_channelhub', ''],
    ['', 'admin_usershub', ''],
    ['', 'admin_support', ''],
    ['', 'cart_manage_inline', ''],
    ['', 'card_select_1', ''],
    ['', 'card_add_new', ''],
    ['', 'shop_products', ''],
    ['', 'backproductadmin', ''],
    ['', 'fx_myprices_menu', ''],
    ['', 'fx_myprice_set_5', ''],
    ['', 'fx_myvis_off_5', ''],
    ['', 'removebotsell_6327424512', ''],
    ['', 'createbot_6327424512', ''],
    ['🛍 مدیریت محصولات', '', ''],
    ['💳 مدیریت شماره کارت', '', ''],
    ['', '', 'card_add_cardnumber'],
    ['', '', 'channel'],
    ['', '', 'fx_res_my_do_retail'],
];
foreach ($allowed as $case) {
    $check('allowed ' . json_encode($case, JSON_UNESCAPED_UNICODE), fx_reseller_main_only_hit($case[0], $case[1], $case[2]) === false);
}

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
