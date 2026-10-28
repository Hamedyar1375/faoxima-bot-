<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

/**
 * FX-RESELLER-SVCMGMT: reseller self-service panel management for the mini-app.
 *
 * The acting reseller is ALWAYS derived from the authenticated user (via
 * fx_res_svc_user_reseller_id) — never from client input. Every read/write is
 * then scoped to that reseller's own panels by the shared core, so a reseller
 * can only ever see or mutate its own panels.
 *
 * Modes:
 *   list   (GET)  -> the reseller's panels (credentials excluded)
 *   update (POST) -> field: password|username|url|sublink + value
 *   toggle (POST) -> field: status|status_extend
 */
final class ResellerServiceHandler extends BaseHandler
{
    public string $mode = 'list';

    public function handle(): void
    {
        $rid = function_exists('fx_res_svc_user_reseller_id')
            ? fx_res_svc_user_reseller_id(null, (int) ($this->user['id'] ?? 0))
            : null;
        $tenant = function_exists('fx_tenant_agent_id') ? (int) fx_tenant_agent_id() : 0;

        // The same Telegram account may own a reseller bot AND be a main-bot
        // admin. Access is allowed only when this authenticated session was
        // opened through that reseller bot (session tenant == rid).
        if ($rid === null || $tenant <= 0 || $tenant !== (int) $rid) {
            FaoximaResponse::forbidden('Not a reseller administrator in this session');
        }

        if ($this->mode === 'list') {
            $this->requireMethod('GET');
            $rows = function_exists('fx_res_svc_list_panels') ? fx_res_svc_list_panels(null, $rid, 100) : [];
            FaoximaResponse::ok([
                'reseller_id' => $rid,
                'panels'      => $rows,
            ]);
        } elseif ($this->mode === 'update') {
            $this->requireMethod('POST');
            $panel = (int) FaoximaInput::int($this->data, 'panel');
            $field = FaoximaInput::string($this->data, 'field');
            $value = FaoximaInput::string($this->data, 'value');
            if ($panel <= 0 || !in_array($field, ['password', 'username', 'url', 'sublink'], true)) {
                FaoximaResponse::badRequest('panel and a valid field (password|username|url|sublink) required');
            }
            if ($field === 'url' && $value !== '' && !preg_match('#^https?://#i', $value)) {
                $value = 'https://' . ltrim($value, '/');
            }
            $ok = false;
            switch ($field) {
                case 'password':  $ok = fx_res_svc_set_password(null, $rid, $panel, $value); break;
                case 'username':  $ok = fx_res_svc_set_username(null, $rid, $panel, $value); break;
                case 'url':       $ok = fx_res_svc_set_url(null, $rid, $panel, $value); break;
                case 'sublink':   $ok = fx_res_svc_set_sublink(null, $rid, $panel, $value); break;
            }
            if (!$ok) {
                FaoximaResponse::fail(400, 'Update failed (invalid value or panel not owned by this reseller)');
            }
            FaoximaResponse::ok(['ok' => true, 'field' => $field]);
        } elseif ($this->mode === 'toggle') {
            $this->requireMethod('POST');
            $panel = (int) FaoximaInput::int($this->data, 'panel');
            $field = FaoximaInput::string($this->data, 'field');
            if ($panel <= 0 || !in_array($field, ['status', 'status_extend'], true)) {
                FaoximaResponse::badRequest('panel and a valid field (status|status_extend) required');
            }
            $next = fx_res_svc_toggle(null, $rid, $panel, $field);
            if ($next === null) {
                FaoximaResponse::fail(400, 'Toggle failed (panel not owned by this reseller)');
            }
            FaoximaResponse::ok(['ok' => true, 'field' => $field, 'value' => $next]);
        } else {
            FaoximaResponse::badRequest('Unknown mode');
        }
    }
}
