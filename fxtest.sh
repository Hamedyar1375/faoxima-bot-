#!/bin/bash
# fxtest.sh <main|reseller> <user_id> <message|callback> <value>
KIND_BOT="$1"; UID_="$2"; K="$3"; V="$4"
cd /opt/faoxima
PW=$(grep '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2)
DBQ(){ docker exec faoxima-db-1 mysql -uroot -p"$PW" faoxima -N -e "$1" 2>/dev/null; }
if [ "$KIND_BOT" = reseller ]; then
  BTOK=$(DBQ "SELECT bot_token FROM botsaz WHERE id_user=$UID_;" | tail -1)
  BUNAME=$(DBQ "SELECT username FROM botsaz WHERE id_user=$UID_;" | tail -1)
  URL="https://faoxima.downloadbazi.fun/faoxima/vpnbot/${UID_}${BUNAME}/index.php"
else
  BTOK=$(grep '^TELEGRAM_BOT_TOKEN=' .env | cut -d= -f2)
  URL="https://faoxima.downloadbazi.fun/faoxima/index.php"
fi
SECRET=$(printf '%s_faoxima_webhook_secret' "$BTOK" | sha256sum | awk '{print $1}')
STEPB=$(DBQ "SELECT \`step\` FROM user WHERE id=$UID_;" | tail -1)
MID=$(( (RANDOM % 9000) + 10000 ))
if [ "$K" = message ]; then
  PAYLOAD="{\"update_id\":$MID,\"message\":{\"message_id\":$MID,\"date\":$(date +%s),\"chat\":{\"id\":$UID_,\"type\":\"private\",\"first_name\":\"T\"},\"from\":{\"id\":$UID_,\"is_bot\":false,\"first_name\":\"T\",\"username\":\"testuser\"},\"text\":\"$V\"}}"
else
  PAYLOAD="{\"update_id\":$MID,\"callback_query\":{\"id\":\"sim_${MID}_$(date +%s%N)\",\"from\":{\"id\":$UID_,\"is_bot\":false,\"first_name\":\"T\",\"username\":\"testuser\"},\"message\":{\"message_id\":$MID,\"date\":$(date +%s),\"chat\":{\"id\":$UID_,\"type\":\"private\"}},\"chat_instance\":\"999\",\"data\":\"$V\"}}"
fi
RESP=$(curl -sk -m 40 -w '|%{http_code}' -X POST "$URL" -H 'Content-Type: application/json' -H "X-Telegram-Bot-Api-Secret-Token: $SECRET" -d "$PAYLOAD")
CODE=${RESP##*|}
BODY=${RESP%|*}
STEPS=$(DBQ "SELECT \`step\` FROM user WHERE id=$UID_;" | tail -1)
echo "http=$CODE step:${STEPB}->${STEPS} body=$(head -c 80 <<< "$BODY")"
