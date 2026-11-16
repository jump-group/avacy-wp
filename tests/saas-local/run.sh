#!/usr/bin/env bash
# Test plugin WP → SaaS locale, un solo comando: `yarn test:plugin-saas` dalla radice.
# Accende lo stack e2e ridotto (progetto Docker dedicato), crea i dati, avvia Playground,
# esegue i test, controlla gli host contattati e spegne tutto, anche in caso di errore.
# Tutto locale: mai staging o produzione.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../../.." && pwd)"
PLUGIN_DIR="$ROOT/services/wp-plugin"
LOG_DIR="$HERE/guard-log"
COMPOSE_FILE="$ROOT/docker-compose.e2e.yml"
# solo per questo test: SaaS su 127.0.0.1:3000 e MariaDB non pubblicata (l'e2e le apre su 0.0.0.0)
COMPOSE_LOOPBACK="$HERE/compose.loopback.yml"
# nome dedicato: "avacy" e' lo stack di sviluppo, e i nomi dei container e2e sono fissi
export COMPOSE_PROJECT="avacy-plugin-saas"
export SAAS_WP_PORT="${SAAS_WP_PORT:-9431}"
SERVICES=(traefik-e2e mysql-e2e api-e2e)
IMAGES=(traefik:v2.11 mariadb:10.11 php_service:8.5)
CF7_VERSION="6.2.5"
CF7_SHA256="0573098bdfed5bdfbd9f2d04185e4c52bf8dd27776e145699ab8f63cbb62927f"
CACHE_DIR="${XDG_CACHE_HOME:-$HOME/.cache}/avacy-saas-local"
LOCAL_RE='^(localhost|(::ffff:)?127\.0\.0\.1|::1|\[::1\]|host\.docker\.internal|apiavacy\.test|avacy\.test|([a-z0-9-]+\.)+avacy\.test)$'

START=$(date +%s)
STACK_UP=0
PG_PID=""
RUN_DIR=""

say() { printf '\n▶ [+%ss] %s\n' "$(( $(date +%s) - START ))" "$*"; }
die() { printf '\n✘ %s\n' "$*" >&2; exit 1; }
dc() { docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" -f "$COMPOSE_LOOPBACK" "$@"; }
port_busy() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }

kill_tree() {
  local pid=$1 child
  for child in $(pgrep -P "$pid" 2>/dev/null); do kill_tree "$child"; done
  kill "$pid" 2>/dev/null || true
}

stop_playground() {
  [ -n "$PG_PID" ] || return 0
  kill_tree "$PG_PID"
  wait "$PG_PID" 2>/dev/null || true
  PG_PID=""
  for _ in $(seq 1 20); do port_busy "$SAAS_WP_PORT" || return 0; sleep 0.5; done
  echo "attenzione: la porta $SAAS_WP_PORT risulta ancora occupata" >&2
}

cleanup() {
  local code=$?
  trap - EXIT INT TERM
  say "Pulizia"
  stop_playground
  if [ "$STACK_UP" = 1 ]; then
    dc down -v --remove-orphans >/dev/null 2>&1 || echo "attenzione: docker compose down non riuscito" >&2
  fi
  [ -n "$RUN_DIR" ] && rm -rf "$RUN_DIR"
  local left
  left=$(docker ps -a -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT" | wc -l | tr -d ' ')
  echo "container del progetto $COMPOSE_PROJECT rimasti: $left; Playground: $(port_busy "$SAAS_WP_PORT" && echo acceso || echo spento)"
  echo "durata totale: $(( $(date +%s) - START )) s"
  case "$code" in
    0) echo "✔ test plugin → SaaS locale: OK" ;;
    75) echo "test plugin → SaaS locale: NON ESEGUITO, Docker occupato da un altro stack e2e (codice 75)" ;;
    130|143) echo "test plugin → SaaS locale: INTERROTTO (codice $code)" ;;
    *) echo "✘ test plugin → SaaS locale: FALLITO (codice $code). Log in $LOG_DIR" ;;
  esac
  exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# --- 1. controlli prima di toccare qualcosa -------------------------------------------
say "Controlli preliminari"
docker info >/dev/null 2>&1 || die "Docker non risponde: avvia Docker Desktop."

# i nomi dei container e2e sono fissi: uno stack e2e di un altro progetto si ferma qui, senza toccarlo
others=$(docker ps -a --format '{{.Names}}\t{{.Label "com.docker.compose.project"}}' | awk -F'\t' -v p="$COMPOSE_PROJECT" '$1 ~ /^avacy-.*-e2e$/ && $2 != p')
if [ -n "$others" ]; then
  printf '%s\n' "$others" | sed 's/^/  /' >&2
  printf "\n✘ C'e' gia' uno stack e2e di un altro progetto (sopra). Non lo tocco: aspetta che finisca e rilancia.\n" >&2
  exit 75 # occupato: si riprova piu' tardi
fi
# avanzi di un nostro giro interrotto: sono nostri, si tolgono
if [ -n "$(docker ps -a -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT")" ]; then
  echo "avanzi di un giro precedente di $COMPOSE_PROJECT: li tolgo"
  dc down -v --remove-orphans >/dev/null
fi
for p in 3000 "$SAAS_WP_PORT"; do
  port_busy "$p" && die "La porta $p e' occupata da un altro processo. Liberala o, per Playground, usa SAAS_WP_PORT=<porta>."
done

for img in "${IMAGES[@]}"; do
  docker image inspect "$img" >/dev/null 2>&1 \
    || die "Manca l'immagine $img. Una volta sola: docker compose -f docker-compose.e2e.yml build api-e2e && docker pull $img"
done
[ -f "$ROOT/services/api/vendor/autoload.php" ] || die "Manca services/api/vendor: esegui una volta composer install del SaaS."
[ -x "$ROOT/node_modules/.bin/playwright" ] || die "Manca node_modules alla radice: esegui yarn install."

# risoluzione di apiavacy.test: con la riga in /etc/hosts si usa il nome, senza si usa 127.0.0.1 con l'Host scritto a mano
MODE="${SAAS_LOCAL_HOST_MODE:-auto}"
if [ "$MODE" = auto ]; then
  if node -e "require('dns').lookup('apiavacy.test',(e,a)=>process.exit(!e&&/^(127\.|::1$)/.test(a)?0:1))"; then MODE=hosts; else MODE=header; fi
fi
case "$MODE" in
  hosts) export SAAS_LOCAL_API="http://apiavacy.test:3000"; unset SAAS_HOST_HEADER ;;
  header) export SAAS_LOCAL_API="http://127.0.0.1:3000"; export SAAS_HOST_HEADER="apiavacy.test" ;;
  *) die "SAAS_LOCAL_HOST_MODE vale auto, hosts o header" ;;
esac
echo "API del SaaS: $SAAS_LOCAL_API (modo $MODE)"

# Contact Form 7 a versione fissa, scaricato una volta sola (dal runner, non da WordPress) e verificato
CF7_ZIP="$CACHE_DIR/contact-form-7.$CF7_VERSION.zip"
if [ ! -f "$CF7_ZIP" ]; then
  echo "primo giro: scarico Contact Form 7 $CF7_VERSION da downloads.wordpress.org"
  mkdir -p "$CACHE_DIR"
  curl -fsSL -o "$CF7_ZIP.part" "https://downloads.wordpress.org/plugin/contact-form-7.$CF7_VERSION.zip"
  mv "$CF7_ZIP.part" "$CF7_ZIP"
fi
[ "$(shasum -a 256 "$CF7_ZIP" | cut -d' ' -f1)" = "$CF7_SHA256" ] || die "Lo zip di Contact Form 7 in $CACHE_DIR non corrisponde all'hash atteso: cancellalo e rilancia."

RUN_DIR="$(mktemp -d)"
mkdir -p "$RUN_DIR/cache"
cp "$CF7_ZIP" "$RUN_DIR/cache/contact-form-7.zip"
# Playground ascolta su tutte le interfacce: password admin casuale a ogni giro, solo nella copia temporanea.
# Assegnazione separata da export: un errore di openssl non passa inosservato.
SAAS_WP_ADMIN_PASSWORD="$(openssl rand -hex 16 || true)"
[ -n "$SAAS_WP_ADMIN_PASSWORD" ] || die "openssl non ha generato la password admin di Playground."
export SAAS_WP_ADMIN_PASSWORD
# segreto del canale di controllo (mu-plugin): i test lo mandano nell'header X-Avacy-Saas-Local
SAAS_CONTROL_SECRET="$(openssl rand -hex 16 || true)"
[ -n "$SAAS_CONTROL_SECRET" ] || die "openssl non ha generato il segreto del canale di controllo."
export SAAS_CONTROL_SECRET
SAAS_LOCAL_API="$SAAS_LOCAL_API" node -e '
  const fs = require("fs"); const [src, dst] = process.argv.slice(1);
  const bp = JSON.parse(fs.readFileSync(src, "utf8"));
  const consts = bp.steps.find((s) => s.step === "defineWpConfigConsts").consts;
  consts.AVACY_API_URL = process.env.SAAS_LOCAL_API;
  if (process.env.SAAS_HOST_HEADER) consts.AVACY_SAAS_HOST_HEADER = process.env.SAAS_HOST_HEADER;
  consts.AVACY_SAAS_LOCAL_SECRET = process.env.SAAS_CONTROL_SECRET;
  // primo passo: la password di installazione ("password") vive il meno possibile
  bp.steps.unshift({ step: "runPHP", code: `<?php require "/wordpress/wp-load.php"; wp_set_password("${process.env.SAAS_WP_ADMIN_PASSWORD}", get_user_by("login", "admin")->ID);` });
  fs.writeFileSync(dst, JSON.stringify(bp, null, 2));
' "$HERE/blueprint.json" "$RUN_DIR/blueprint.json"
export SAAS_RUN_DIR="$RUN_DIR"

# --- 2. stack ridotto, migrate, salute ------------------------------------------------
say "Stack e2e ridotto ($COMPOSE_PROJECT): ${SERVICES[*]}"
STACK_UP=1
dc up -d --no-build --pull never "${SERVICES[@]}"

# il container del SaaS non ha blocco di rete: chiavi dei servizi esterni vuote, Stripe solo in modalita' test
env_api=$(dc exec -T api-e2e printenv) || die "Non riesco a leggere le variabili del container api-e2e (docker compose exec printenv)."
external=$(printf '%s\n' "$env_api" | awk -F= '
  /^(SENTRY_DSN|SENTRY_LARAVEL_DSN|POSTHOG_PROJECT_KEY|BREVO_API_KEY|AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|GA4_API_SECRET|APIFY_TOKEN|MAILGUN_SECRET|POSTMARK_TOKEN|LOG_SLACK_WEBHOOK_URL|ACTIVE_CAMPAIGN_API_KEY)=./ { print $1 }
  /^STRIPE_SECRET=./ && $2 !~ /^sk_test_/ { print $1 }')
[ -z "$external" ] || die "Chiavi esterne non vuote nel container api-e2e: $(echo $external). Svuotale in services/api/.env.e2e(.local)."

art() { dc exec -T api-e2e php -d display_errors=Off -d log_errors=Off artisan "$@"; }
# ping in TCP: il server temporaneo dell'inizializzazione di MariaDB accetta solo il socket
echo "attendo il database"
for i in $(seq 1 60); do
  dc exec -T -e MYSQL_PWD=password mysql-e2e mariadb-admin ping -h 127.0.0.1 --protocol=tcp -uroot --silent >/dev/null 2>&1 && break
  [ "$i" = 60 ] && die "il database non risponde dopo 120 s"
  sleep 2
done
echo "migro"
out=$(art migrate --force 2>&1) || die "migrate non riuscito: $out"

# salute DOPO migrate: prima il SaaS risponde 503. Serve la risposta di Laravel a un team
# che non esiste (team_not_found), arrivata da Traefik per Host: database, app e instradamento pronti.
health() { curl -s -m 5 -H 'Host: apiavacy.test' -H 'Accept: application/json' http://127.0.0.1:3000/wp/validate/x/y; }
ok=0
for i in $(seq 1 45); do
  if health | grep -q 'team_not_found'; then ok=1; break; fi
  # Traefik a volte non vede ancora il container del SaaS e risponde il suo 404 a ogni Host:
  # dopo 10 s si riavvia il nostro, una volta sola
  if [ "$i" = 5 ] && health | grep -q '404 page not found'; then
    echo "Traefik non instrada: lo riavvio"
    dc restart traefik-e2e >/dev/null
  fi
  sleep 2
done
[ "$ok" = 1 ] || die "Il SaaS non risponde tramite Traefik dopo migrate. Ultima risposta: $(health || true)"
echo "SaaS pronto: $(health)"

# --- 3. dati locali e log ------------------------------------------------------------
say "Dati locali (provision)"
PROVISION=$(bash "$HERE/provision.sh")
eval "$PROVISION"
echo "team $SAAS_TENANT, spazio web $SAAS_WEBSPACE_ID"

find "$LOG_DIR" -mindepth 1 ! -name .gitignore -exec rm -rf {} +

# --- 4. Playground, con al massimo 3 tentativi (il blueprint a volte non arriva in fondo) ---
ready() { curl -s -m 5 -H "X-Avacy-Saas-Local: $SAAS_CONTROL_SECRET" "http://127.0.0.1:$SAAS_WP_PORT/avacy-saas-local/state" | grep -q '"ready":true'; }
for attempt in 1 2 3; do
  say "Playground sulla porta $SAAS_WP_PORT (tentativo $attempt)"
  bash "$HERE/run-playground.sh" "$SAAS_WP_PORT" >"$LOG_DIR/playground-$attempt.log" 2>&1 &
  PG_PID=$!
  for _ in $(seq 1 180); do
    ready && break
    kill -0 "$PG_PID" 2>/dev/null || break
    sleep 1
  done
  ready && break
  echo "Playground non pronto: ultime righe del log"; tail -5 "$LOG_DIR/playground-$attempt.log" || true
  stop_playground
  [ "$attempt" = 3 ] && die "Playground non e' partito in 3 tentativi."
done

# --- 5. test ---------------------------------------------------------------------------
say "Test"
set +e
(cd "$PLUGIN_DIR" && "$ROOT/node_modules/.bin/playwright" test --config=playwright.saas-local.config.ts)
TESTS=$?
set -e

# --- 6. host contattati: ammessi solo host locali, e Avacy di produzione solo nella prova del blocco ---
say "Host contattati"
bad=0
# file, colonne esito/host/tag, host sospetti se bloccati fuori dalla prova del blocco
check_log() {
  local f="$LOG_DIR/$1"
  [ -f "$f" ] || { echo "  $1: nessuna riga"; return 0; }
  awk -F'\t' -v e="$2" -v h="$3" '{ host=$h; sub(/:[0-9]+$/, "", host); print $e" "host }' "$f" | sort | uniq -c | sed "s/^/  $1 /"
  LOCAL_RE="$LOCAL_RE" SUSPECT="$5" awk -F'\t' -v e="$2" -v h="$3" -v t="$4" '
    { host=$h; sub(/:[0-9]+$/, "", host) }
    $e=="ALLOW" && host !~ ENVIRON["LOCAL_RE"] { print "  NON LOCALE AMMESSO: " $0; bad=1 }
    $e=="BLOCK" && $t!="probe" && host ~ ENVIRON["SUSPECT"] { print "  TENTATIVO FUORI DALLA PROVA DEL BLOCCO: " $0; bad=1 }
    END { exit bad }' "$f" || bad=1
}
check_log hosts.log 2 3 5 'avacy'   # WordPress: i controlli aggiornamenti verso wordpress.org sono attesi e bloccati
check_log node-hosts.log 2 3 4 '.'  # runner Node: nessun host non locale, mai
[ -f "$LOG_DIR/browser-hosts.log" ] && awk -F'\t' '{print $3}' "$LOG_DIR/browser-hosts.log" | sort | uniq -c | sed 's/^/  browser bloccato /'
if [ "$bad" != 0 ]; then
  printf '\n✘ Il log degli host contiene host non locali ammessi o tentativi fuori dalla prova del blocco.\n' >&2
  exit $(( TESTS != 0 ? TESTS : 1 )) # se falliscono anche i test, vale il loro codice
fi
echo "solo host locali ammessi"

[ "$TESTS" = 0 ] || exit "$TESTS"
