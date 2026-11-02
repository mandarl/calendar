#!/usr/bin/env bash
D=/c/Users/mandarlimaye/src/dms/calendar-cake5-d/tests/differential/events-moderation
OUT=$D/out
mkdir -p "$OUT"
TAG=$1
CASE=$2
USER=$3
EVENT=$4
SETUP=${5:-none}
EVENTS=${6:-1015,1012,1013,1014,1026,1003,1011,1018,1001}
REGEVENTS=${7:-1015,1003,1011,1018,1026,1001}
RESETSQL="UPDATE events SET status=\"approved\" WHERE id IN (1001,1003,1011,1012,1013,1014); UPDATE events SET status=\"pending\", rejected_by=NULL, rejection_reason=NULL WHERE id IN (1015,1026); UPDATE events SET status=\"completed\" WHERE id=1018; UPDATE events SET event_start=\"2031-01-19 00:00:00\", event_end=\"2031-01-19 02:00:00\" WHERE id=1001; UPDATE registrations SET status=\"confirmed\", attended=0, ad_assigned=0 WHERE id IN (3001,3003,3004); UPDATE registrations SET status=\"confirmed\", attended=1, ad_assigned=0 WHERE id=3014; UPDATE registrations SET status=\"cancelled\" WHERE id IN (3002,3005); DELETE FROM registrations WHERE id=3999; UPDATE prerequisites SET ad_group=\"Woodshop Basics\" WHERE id=4; UPDATE registrations SET phone=\"555-0203\", send_text=0 WHERE id=3003;"
reset_all() {
  docker exec calc5d-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "$RESETSQL" >/dev/null
  docker exec calc5d3-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "$RESETSQL" >/dev/null
  for c in calc5d-ldap-1 calc5d3-ldap-1; do
    docker exec -i $c ldapmodify -x -H ldap://localhost -D "cn=admin,dc=dms,dc=local" -w "Adm1n!" <<LDIF 2>/dev/null || true
dn: cn=Woodshop Basics,ou=Security,ou=Groups,dc=dms,dc=local
changetype: modify
delete: member
member: cn=user1,ou=Members,dc=dms,dc=local
LDIF
  done
}
setup_all() {
  if [ "$SETUP" = sms ]; then
    docker exec calc5d-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE registrations SET phone=\"5551234567\", send_text=1 WHERE id=3003;" >/dev/null
    docker exec calc5d3-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE registrations SET phone=\"5551234567\", send_text=1 WHERE id=3003;" >/dev/null
  fi
  if [ "$SETUP" = seriespending ]; then
    docker exec calc5d-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE events SET status=\"pending\" WHERE id IN (1012,1013,1014);" >/dev/null
    docker exec calc5d3-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE events SET status=\"pending\" WHERE id IN (1012,1013,1014);" >/dev/null
  fi
  if [ "$SETUP" = attendance ]; then
    docker exec calc5d-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE events SET event_start=\"2031-01-15 14:00:00\", event_end=\"2031-01-15 15:00:00\" WHERE id=1001;" >/dev/null
    docker exec calc5d3-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE events SET event_start=\"2031-01-15 14:00:00\", event_end=\"2031-01-15 15:00:00\" WHERE id=1001;" >/dev/null
  fi
  if [ "$SETUP" = ad ]; then
    for db in calc5d-db-1 calc5d3-db-1; do
      docker exec $db mariadb -ucalendar -pcalendar dms_calendar -e "INSERT INTO registrations (id,event_id,type,name,email,phone,ad_username,send_text,edit_key,status,attended,ad_assigned,transaction_id,created,modified) VALUES (3999,1011,\"free\",\"Test AD Assignee\",\"adassignee@example.test\",\"555-0299\",\"user1\",0,\"edit-key-3999\",\"confirmed\",0,1,NULL,\"2031-01-14 16:00:00\",\"2031-01-14 16:00:00\");" >/dev/null
    done
  fi
  if [ "$SETUP" = adnotfound ]; then
    docker exec calc5d-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE prerequisites SET ad_group=\"No Such Group 99999\" WHERE id=4;" >/dev/null
    docker exec calc5d3-db-1 mariadb -ucalendar -pcalendar dms_calendar -e "UPDATE prerequisites SET ad_group=\"No Such Group 99999\" WHERE id=4;" >/dev/null
    for db in calc5d-db-1 calc5d3-db-1; do
      docker exec $db mariadb -ucalendar -pcalendar dms_calendar -e "INSERT INTO registrations (id,event_id,type,name,email,phone,ad_username,send_text,edit_key,status,attended,ad_assigned,transaction_id,created,modified) VALUES (3999,1011,\"free\",\"Test AD Assignee\",\"adassignee@example.test\",\"555-0299\",\"user1\",0,\"edit-key-3999\",\"confirmed\",0,1,NULL,\"2031-01-14 16:00:00\",\"2031-01-14 16:00:00\");" >/dev/null
    done
  fi
}
run_side() {
  local side=$1 appc=$2 fakec=$3 ldapc=$4
  docker exec -e BASE=http://app -e MAIL=http://mail:8025 -e GATEWAY=http://app:3000 -e SINK=http://app:4010 $fakec node /tmp/drive.mjs --case "$CASE" --user "$USER" --event "$EVENT" > "$OUT/$TAG-$side.json" 2>&1
  docker exec $appc php /tmp/dump.php "$EVENTS" "$REGEVENTS" > "$OUT/$TAG-$side-db.json" 2>&1
  docker exec $ldapc ldapsearch -x -H ldap://localhost -D "cn=admin,dc=dms,dc=local" -w "Adm1n!" -b "cn=Woodshop Basics,ou=Security,ou=Groups,dc=dms,dc=local" member 2>/dev/null | grep -c "cn=user1" > "$OUT/$TAG-$side-ldap.txt" 2>&1 || true
  echo "$side done"
}
reset_all
setup_all
run_side cake5 calc5d-app-1 calc5d-fakes-1 calc5d-ldap-1
reset_all
setup_all
run_side cake3 calc5d3-app-1 calc5d3-fakes-1 calc5d3-ldap-1
reset_all
echo "ALL DONE $TAG"
