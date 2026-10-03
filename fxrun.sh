#!/bin/bash
# fxrun.sh — full E2E sweep, logs results compactly
cd /opt/faoxima
run(){ # run <main|reseller> <uid> <message|callback> <value>
  bash fxtest.sh "$1" "$2" "$3" "$4" | sed "s/^/  /"
}
echo "############ MAIN BOT admin 8400624309 ############"
echo "[/start]";                 run main 8400624309 message /start
echo "[admin_status]";            run main 8400624309 callback admin_status
echo "[admin_usershub]";          run main 8400624309 callback admin_usershub
echo "[admin_managepanel]";       run main 8400624309 callback admin_managepanel
echo "[admin_shop]";              run main 8400624309 callback admin_shop
echo "[admin_finance]";           run main 8400624309 callback admin_finance
echo "[admin_invoices]";          run main 8400624309 callback admin_invoices
echo "[admin_panels]";            run main 8400624309 callback admin_panels
echo "[admin_channelhub]";        run main 8400624309 callback admin_channelhub
echo "[admin_settings]";          run main 8400624309 callback admin_settings
echo "[admin_help]";              run main 8400624309 callback admin_help
echo "[admin_support]";           run main 8400624309 callback admin_support
echo "[admin_timeprice]";         run main 8400624309 callback admin_timeprice
echo "[admin_users]";             run main 8400624309 callback admin_users
echo "[account]";                 run main 8400624309 callback account
echo "[fx_reseller_mgmt_menu]";   run main 8400624309 callback fx_reseller_mgmt_menu
echo "[fx_res_create_start]";     run main 8400624309 callback fx_res_create_start
echo "[cancel after create]";     run main 8400624309 message /cancel
echo "[adm_hub_main]";            run main 8400624309 callback adm_hub_main
echo "[Add_Balance]";             run main 8400624309 callback Add_Balance
echo "[/cancel]";                 run main 8400624309 message /cancel
echo "############ RESELLER BOT owner 6327424512 ############"
echo "[/start]";                  run reseller 6327424512 message /start
echo "[admin_status]";            run reseller 6327424512 callback admin_status
echo "[admin_usershub]";          run reseller 6327424512 callback admin_usershub
echo "[admin_shop]";              run reseller 6327424512 callback admin_shop
echo "[admin_finance]";           run reseller 6327424512 callback admin_finance
echo "[admin_settings]";          run reseller 6327424512 callback admin_settings
echo "[admin_panels]";            run reseller 6327424512 callback admin_panels
echo "[account]";                 run reseller 6327424512 callback account
echo "[fx_reseller_mgmt_menu]";   run reseller 6327424512 callback fx_reseller_mgmt_menu
echo "[adm_hub_main]";            run reseller 6327424512 callback adm_hub_main
echo "############ FINAL STATE ############"
PW=$(grep '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2)
docker exec faoxima-db-1 mysql -uroot -p"$PW" faoxima -N -e "SELECT id,\`step\`,Balance FROM user WHERE id IN (8400624309,6327424512);" 2>/dev/null
TOK=$(grep '^TELEGRAM_BOT_TOKEN=' .env | cut -d= -f2)
echo "main pending: $(curl -sk "https://api.telegram.org/bot$TOK/getWebhookInfo" | python3 -c "import json,sys; print(json.load(sys.stdin)['result'].get('pending_update_count'))")"
BTOK=$(docker exec faoxima-db-1 mysql -uroot -p"$PW" faoxima -N -e "SELECT bot_token FROM botsaz WHERE id_user=6327424512;" 2>/dev/null)
echo "reseller pending: $(curl -sk "https://api.telegram.org/bot$BTOK/getWebhookInfo" | python3 -c "import json,sys; print(json.load(sys.stdin)['result'].get('pending_update_count'))")"
echo "=== NEW runtime errors during sweep ==="
tail -60 logs/runtime.log | grep -iE 'error|throw|fatal|5xx' | tail -10 || echo "(none)"
echo "=== error_log new lines ==="
docker exec faoxima-app-1 sh -c 'tail -20 /var/www/faoxima/error_log' 2>/dev/null
