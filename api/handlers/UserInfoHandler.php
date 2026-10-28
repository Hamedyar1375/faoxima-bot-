<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

final class UserInfoHandler extends BaseHandler
{
    public function handle(): void
    {
        $this->requireMethod('GET');

        $user = $this->user;


        if (empty($user['codeInvitation'])) {
            $code = bin2hex(random_bytes(4));
            update('user', 'codeInvitation', $code, 'id', $user['id']);
            $user['codeInvitation'] = $code;
        }


        $phone = $user['number'] ?? 'none';
        if ($phone === 'none' || $phone === null || $phone === '') {
            $phoneDisplay = '🔴 ارسال نشده است 🔴';
        } elseif ($phone === 'confrim number by admin' || $phone === 'confirm number by admin') {
            $phoneDisplay = '✅ تایید شده توسط ادمین';
        } else {
            $phoneDisplay = $phone;
        }

        $countOrder = (int) FaoximaDb::fetchScalar(
            "SELECT COUNT(*) FROM invoice
              WHERE id_user = :id_user
                AND name_product != 'سرویس تست'
                AND (Status = 'active' OR Status = 'end_of_time' OR Status = 'end_of_volume'
                     OR Status = 'sendedwarn' OR Status = 'send_on_hold')",
            [':id_user' => $user['id']]
        );

        $countPayment = (int) FaoximaDb::fetchScalar(
            "SELECT COUNT(*) FROM Payment_report WHERE id_user = :id_user AND payment_Status = 'paid'",
            [':id_user' => $user['id']]
        );

        $groupMap = [
            'f'  => 'عادی',
            'n'  => 'نماینده',
            'n2' => 'نمایندگی پیشرفته',
        ];
        $groupType = $groupMap[$user['agent'] ?? 'f'] ?? 'عادی';

        $userJoin = !empty($user['register']) ? jdate('Y/m/d', $user['register']) : '-';

        $isAdmin = $this->userIsAdmin();

        // FX-RESELLER-SVCMGMT: expose reseller-admin status so the mini-app can
        // show the self-service service-management entry to the right people.
        $resellerId = function_exists('fx_res_svc_user_reseller_id')
            ? fx_res_svc_user_reseller_id(null, (int) ($user['id'] ?? 0))
            : null;
        // Only expose reseller-admin UI when this authenticated session was
        // opened through that reseller bot (tenant context), not in the main bot.
        $sessionTenant = function_exists('fx_tenant_agent_id') ? (int) fx_tenant_agent_id() : 0;
        if ($resellerId !== null && (int) $resellerId !== $sessionTenant) {
            $resellerId = null;
        }

        FaoximaResponse::ok([
            'codeInvitation'  => $user['codeInvitation'],
            'balance'         => $user['Balance'] ?? 0,
            'phone'           => $phoneDisplay,
            'count_order'     => $countOrder,
            'count_payment'   => $countPayment,
            'group_type'      => $groupType,
            'time_join'       => $userJoin,
            'affiliatescount' => $user['affiliatescount'] ?? 0,
            'is_admin'        => $isAdmin,
            'is_reseller_admin' => $resellerId !== null,
            'reseller_id'     => $resellerId,
        ]);
    }
}

