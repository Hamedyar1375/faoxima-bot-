<?php
// Phase 2: Reseller bot runs the MAIN engine (re/rx) as a tenant.
// Identity = this bot's token + botsaz.admin_ids + agent-scoped products/panels.
require_once __DIR__ . '/config.php';

define('FX_RESELLER_ID', BotUserIdNew);
define('FX_RESELLER_TOKEN', $ApiToken);

if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', dirname(__DIR__, 2));
}
@chdir(REFACTORED_LEGACY_ROOT);

require_once REFACTORED_LEGACY_ROOT . '/re/index.php';

// Phase 2: load admin module for reseller (same pattern as finalize.php)
if (isset($from_id) && isset($admin_ids) && is_array($admin_ids) && in_array((string)$from_id, array_map('strval', $admin_ids), true)) {
    require_once REFACTORED_LEGACY_ROOT . '/re/rx/admin/index.php';
}