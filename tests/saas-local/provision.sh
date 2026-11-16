#!/usr/bin/env bash
# Crea sul database LOCALE dello stack e2e utente, team, spazio web e Access Key per il test plugin → SaaS.
# Lo chiama run.sh dopo migrate. Stampa le variabili da esportare: eval "$(bash provision.sh)".
# Solo `docker compose exec` sullo stack e2e: mai ECS, mai staging o produzione.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
PROJECT="${COMPOSE_PROJECT:?manca COMPOSE_PROJECT: usa run.sh}"
TENANT="${SAAS_TENANT:-wpsaas$(date +%s)}"
EMAIL="${TENANT}@avacy.test"

art() {
  docker compose -p "$PROJECT" -f "$ROOT/docker-compose.e2e.yml" exec -T api-e2e \
    php -d display_errors=Off -d log_errors=Off artisan "$@"
}

art e2e:be create User --data="{\"first_name\":\"WP\",\"last_name\":\"Saas\",\"email\":\"$EMAIL\",\"password\":\"Test123?\",\"email_verified_at\":\"2026-01-01 00:00:00\"}" --json >/dev/null
USER_ID=$(art e2e:be get User --query="{\"email\":\"$EMAIL\"}" --json | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
art e2e:be create Tenant --data="{\"id\":\"$TENANT\",\"user_id\":$USER_ID}" --json >/dev/null
WS_ID=$(art e2e:setup-consent --tenant="$TENANT" --user-email="$EMAIL" | tail -1 | python3 -c 'import sys,json;print(json.load(sys.stdin)["webspace_id"])')

art tinker --execute="tenancy()->initialize('$TENANT'); \$w=App\\WebSpace::find($WS_ID); \$t=\$w->createToken('wp-saas-local', ['domains_groups:$WS_ID']); tenancy()->end(); \$u=App\\User::find($USER_ID); echo json_encode(['uuid'=>\$w->uuid,'access'=>\$t->plainTextToken,'user'=>\$u->createToken('wp-saas-local-read')->plainTextToken]);" \
  | tail -1 | python3 -c "
import sys,json
d=json.load(sys.stdin)
print('export SAAS_TENANT=$TENANT')
print('export SAAS_WEBSPACE_ID=$WS_ID')
print('export SAAS_WEBSPACE_UUID=%s' % d['uuid'])
print(\"export SAAS_ACCESS_KEY='%s'\" % d['access'])
print(\"export SAAS_USER_TOKEN='%s'\" % d['user'])
"
