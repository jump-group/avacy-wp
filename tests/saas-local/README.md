# Test plugin WP → SaaS locale

Prova il viaggio del consenso con il SaaS vero, tutto in locale:
form Contact Form 7 → plugin WP → SaaS Avacy (stack e2e) → archivio consensi.
Niente finto SaaS. Va eseguito prima di ogni PR che tocca il plugin WP o la parte consensi del SaaS.

## Comando

Dalla radice della monorepo:

```bash
yarn test:plugin-saas
```

Il comando fa tutto da solo:

1. controlla che non ci sia già uno stack e2e acceso. Se c'è, si ferma con il codice **75** e non lo tocca: si riprova più tardi;
2. accende solo `traefik-e2e`, `mysql-e2e` e `api-e2e`, con il progetto Docker dedicato `avacy-plugin-saas` (non `avacy`, che è lo sviluppo). Con `compose.loopback.yml` il SaaS ascolta solo su `127.0.0.1:3000` e MariaDB non è pubblicata;
3. aspetta MariaDB, esegue `migrate` e aspetta che il SaaS risponda tramite Traefik;
4. crea utente, team, spazio web, Access Key e token di lettura sul database locale (`provision.sh`);
5. svuota `guard-log/` e avvia WordPress Playground sulla porta 9431 (`SAAS_WP_PORT` per cambiarla);
6. esegue i test (`plugin-to-saas.spec.ts`);
7. controlla i log degli host: fallisce se un host non locale è stato ammesso, o se c'è un tentativo verso Avacy fuori dalla prova del blocco;
8. spegne Playground e lo stack (`down -v`), anche in caso di errore o Ctrl-C.

Durata: da 1 a 2 minuti con le immagini già in locale (67–116 s nei giri del 12/11). I test da soli: circa 36 s.

## Casi coperti

1. Sicurezza: il plugin usa gli host locali; la produzione è bloccata da WordPress e dal runner Node.
2. Collegamento dalla dashboard con Webspace Key e Access Key.
3. Form con email identificativo e un dato: consenso in archivio con l'email giusta.
4. Casella collegata alla newsletter: la caratteristica c'è solo se la casella è spuntata.
5. Identificativo senza dati: il plugin invia l'identificativo e nessun `consent_data`, il SaaS accetta e il consenso arriva in archivio (dalla PR #1098).
6. Access Key cambiata sul SaaS dopo la configurazione: il consenso non arriva (403). È il comportamento di oggi.

## Protezioni

- mu-plugin `000-avacy-network-guard.php`, montata prima dell'installazione: WordPress parla solo con host locali, ogni tentativo va in `guard-log/hosts.log`.
- Costanti `AVACY_API_URL` e `AVACY_CDN_URL` scritte insieme in `wp-config.php` (`rewrite-wp-config`: valgono per tutti i worker di Playground).
- Primo test: prova che le chiamate verso la produzione tornano errore del blocco.
- Runner Node (`support/local-only.ts`): rifiuta ogni socket verso host non locali e ogni `SAAS_LOCAL_API` non locale.
- Browser della prova: le richieste verso host non locali vengono interrotte.
- Il container del SaaS (`api-e2e`) invece non ha blocco di rete. Il comando si ferma se una di queste chiavi non è vuota: `SENTRY_DSN`, `SENTRY_LARAVEL_DSN`, `POSTHOG_PROJECT_KEY`, `BREVO_API_KEY`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `GA4_API_SECRET`, `APIFY_TOKEN`, `MAILGUN_SECRET`, `POSTMARK_TOKEN`, `LOG_SLACK_WEBHOOK_URL`, `ACTIVE_CAMPAIGN_API_KEY`. Si ferma anche se `STRIPE_SECRET` non è una chiave di test (`sk_test_`).
- Nessuna chiave vera: le credenziali nascono a ogni giro sul database locale e spariscono con `down -v`.
- Playground ascolta su tutte le interfacce (la CLI 3.1.58 non si lega a `127.0.0.1`): niente auto-login, password admin casuale a ogni giro, editor e installazione di plugin spenti (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`). Non lanciarlo su reti condivise o non fidate.
- Canale di controllo della mu-plugin (`/avacy-saas-local/state`, `probe`, `set-form`): risponde 403 senza il segreto casuale del giro nell'header `X-Avacy-Saas-Local`. In Playground `REMOTE_ADDR` vale sempre `127.0.0.1`, quindi non serve a distinguere chi chiama.

## Requisiti

- Docker Compose 2.24.4 o successivo: `compose.loopback.yml` usa `!override` e `!reset`.
- Docker acceso, con le immagini `php_service:8.5`, `traefik:v2.11`, `mariadb:10.11` (il comando non scarica e non costruisce immagini).
- `services/api/vendor` presente (`composer install` del SaaS, una volta).
- `yarn install` alla radice e il Chromium di Playwright (`yarn test:ensure-browser`, una volta).
- Il nome `apiavacy.test` verso `127.0.0.1`: è la riga di `yarn insert-host` in `/etc/hosts`.
  Senza la riga il comando usa `http://127.0.0.1:3000` con l'header `Host: apiavacy.test` (Traefik instrada sull'Host). Per forzarlo: `SAAS_LOCAL_HOST_MODE=header`.

Accessi esterni, tutti fuori da WordPress e nessuno verso Avacy:

- Contact Form 7, versione fissa: scaricato da `downloads.wordpress.org` al primo giro e tenuto in `~/.cache/avacy-saas-local` (con verifica dell'hash);
- `@wp-playground/cli@3.1.58` via `npx`: dal registro npm solo se manca nella cache;
- la CLI di Playground chiede ad `api.wordpress.org` le versioni di WordPress all'avvio (WordPress 7.1.4 resta in cache in `~/.wordpress-playground`).

## File

- `run.sh`: il comando completo. `provision.sh`: i dati locali. `run-playground.sh`: Playground con il blueprint.
- `compose.loopback.yml`: sovrapposto a `docker-compose.e2e.yml`, SaaS solo su `127.0.0.1:3000` e MariaDB non pubblicata.
- `blueprint.json`: costanti, Contact Form 7, i due form (`cf7-saas-local`, `cf7-solo-email`).
- `mu-plugins/`: il blocco e il canale di controllo del test (stato, prova del blocco, log dei corpi e delle risposte).
- `guard-log/`: i log dell'ultimo giro (ignorati da git).
