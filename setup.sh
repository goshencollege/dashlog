#!/usr/bin/env bash
# DashLog setup — development or production.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSL_DIR="$SCRIPT_DIR/docker/ssl"
COMPOSE_PROJECT=$(basename "$SCRIPT_DIR" | tr '[:upper:]' '[:lower:]')

# ── Terminal colours (disabled when not a TTY) ────────────────────────────────
if [[ -t 1 ]]; then
    RED='\033[0;31m' GREEN='\033[0;32m' YELLOW='\033[1;33m'
    CYAN='\033[0;36m' BOLD='\033[1m' NC='\033[0m'
else
    RED='' GREEN='' YELLOW='' CYAN='' BOLD='' NC=''
fi

header()     { echo; echo -e "${BOLD}${CYAN}── $* ──${NC}"; }
ok()         { echo -e "  ${GREEN}✓${NC}  $*"; }
warn()       { echo -e "  ${YELLOW}!${NC}  $*"; }
die()        { echo -e "  ${RED}✗  $*${NC}" >&2; exit 1; }

ask() {
    local _var="$1" _prompt="$2" _default="${3:-}" _val
    if [[ -n "$_default" ]]; then
        read -rp "$(echo -e "  ${BOLD}${_prompt}${NC} [${_default}]: ")" _val
        printf -v "$_var" '%s' "${_val:-$_default}"
    else
        read -rp "$(echo -e "  ${BOLD}${_prompt}${NC}: ")" _val
        [[ -z "$_val" ]] && die "$_prompt is required."
        printf -v "$_var" '%s' "$_val"
    fi
}

ask_secret() {
    local _var="$1" _prompt="$2" _val
    read -rsp "$(echo -e "  ${BOLD}${_prompt}${NC}: ")" _val; echo
    [[ -z "$_val" ]] && die "$_prompt is required."
    printf -v "$_var" '%s' "$_val"
}

ask_yn() {
    local _prompt="$1" _default="${2:-y}" _val
    read -rp "$(echo -e "  ${BOLD}${_prompt}${NC} [${_default}]: ")" _val
    _val="${_val:-$_default}"
    [[ "${_val,,}" == y* ]]
}

cd "$SCRIPT_DIR"

# ── Environment ───────────────────────────────────────────────────────────────
echo
echo -e "${BOLD}DashLog Setup${NC}"
echo
echo "  1) Development / test  (self-signed cert, containerised DB, dev tools)"
echo "  2) Production          (SSL options, optional external DB, hardened containers)"
echo
ask ENV_CHOICE "Environment" "1"

case "$ENV_CHOICE" in
    1) APP_ENV="dev" ;;
    2) APP_ENV="prod" ;;
    *) die "Invalid choice." ;;
esac

APP_IMAGE="${COMPOSE_PROJECT}-${APP_ENV}-app"

COMPOSE_FILE="$SCRIPT_DIR/docker-compose.${APP_ENV}.yml"

echo
if [[ "$APP_ENV" == "dev" ]]; then
    echo -e "${BOLD}DashLog Development Setup${NC}"
    echo "  Creates docker-compose.dev.yml and starts the stack with APP_ENV=dev."
else
    echo -e "${BOLD}DashLog Production Setup${NC}"
    echo "  Creates docker-compose.prod.yml and starts the stack."
    echo "  Have your IdP SAML metadata (XML file or URL) ready before you begin."
fi
echo

# ── 1. Prerequisites ──────────────────────────────────────────────────────────
header "Checking prerequisites"

REQUIRED_CMDS="docker openssl"
[[ "$APP_ENV" == "dev" ]] && REQUIRED_CMDS="$REQUIRED_CMDS sed"

for cmd in $REQUIRED_CMDS; do
    command -v "$cmd" >/dev/null 2>&1 || die "$cmd is required but not found in PATH."
    ok "$cmd"
done
docker compose version >/dev/null 2>&1 || die "docker compose (plugin v2) is required."
ok "docker compose"

if [[ "$APP_ENV" == "dev" ]]; then
    DIST_FILE="$SCRIPT_DIR/docker-compose.dev.yml.dist"
    [[ -f "$DIST_FILE" ]] || die "docker-compose.dev.yml.dist not found."
    ok "docker-compose.dev.yml.dist"
fi

if [[ -f "$COMPOSE_FILE" ]]; then
    warn "docker-compose.${APP_ENV}.yml already exists."
    ask_yn "Overwrite and re-run full setup?" "n" \
        || { echo "  Aborted."; exit 0; }
    docker compose -f "$COMPOSE_FILE" down -v 2>/dev/null || true
fi

# ── 2. Base URL ───────────────────────────────────────────────────────────────
header "Base URL"

if [[ "$APP_ENV" == "dev" ]]; then
    ask FQDN        "Hostname or IP (e.g. localhost, 192.168.1.50, mydev.local)" "localhost"
    ask HTTP_PORT   "HTTP port"  "8080"
    ask HTTPS_PORT  "HTTPS port" "8443"
else
    ask FQDN        "Fully-qualified domain name (e.g. dashlog.example.com)"
    ask HTTP_PORT   "HTTP port"  "80"
    ask HTTPS_PORT  "HTTPS port" "443"
fi

if [[ "$HTTPS_PORT" == "443" ]]; then
    BASE_URL="https://${FQDN}"
else
    BASE_URL="https://${FQDN}:${HTTPS_PORT}"
fi
ok "Base URL: $BASE_URL"

# ── 3. Secrets ────────────────────────────────────────────────────────────────
header "Generating secrets"

APP_SECRET=$(openssl rand -hex 32)
ok "APP_SECRET generated"

APP_ENCRYPTION_KEY=$(openssl rand -base64 32)
ok "APP_ENCRYPTION_KEY generated"

# ── 4. SSL certificate ────────────────────────────────────────────────────────
header "SSL Certificate"

mkdir -p "$SSL_DIR"

if [[ "$APP_ENV" == "dev" ]]; then
    REGEN_CERT=true
    if [[ -f "$SSL_DIR/cert.pem" && -f "$SSL_DIR/key.pem" ]]; then
        warn "Existing certificate found in docker/ssl/."
        if ask_yn "Regenerate?" "n"; then
            REGEN_CERT=true
        else
            REGEN_CERT=false
            ok "Keeping existing certificate"
        fi
    fi

    if [[ "$REGEN_CERT" == "true" ]]; then
        if [[ "$FQDN" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            SAN="IP:${FQDN}"
        else
            SAN="DNS:${FQDN},IP:127.0.0.1"
        fi
        openssl req -x509 -newkey rsa:2048 -nodes \
            -keyout "$SSL_DIR/key.pem" \
            -out    "$SSL_DIR/cert.pem" \
            -days 825 \
            -subj   "/CN=${FQDN}" \
            -addext "subjectAltName=${SAN}" \
            2>/dev/null
        ok "Self-signed certificate written to docker/ssl/"
        warn "Browsers will show a security warning — expected for dev/test."
    fi
else
    echo "  1) Generate self-signed certificate (fine for internal/testing use)"
    echo "  2) Use Let's Encrypt  (requires certbot, public DNS, and port 80 reachable)"
    echo "  3) I will provide my own certificate"
    echo
    ask SSL_CHOICE "Choice" "1"

    case "$SSL_CHOICE" in
    1)
        echo
        echo "  Generating self-signed RSA-4096 certificate (valid 10 years)…"
        openssl req -x509 -newkey rsa:4096 -nodes \
            -keyout "$SSL_DIR/key.pem" \
            -out    "$SSL_DIR/cert.pem" \
            -days 3650 \
            -subj   "/CN=${FQDN}" \
            -addext "subjectAltName=DNS:${FQDN}" \
            2>/dev/null
        ok "Certificate written to docker/ssl/"
        warn "Browsers will show a security warning for self-signed certs."
        warn "For a trusted cert, install certbot and run 'certbot certonly --standalone'"
        warn "then replace docker/ssl/cert.pem and key.pem with the issued files."
        ;;
    2)
        command -v certbot >/dev/null 2>&1 || die "certbot is not installed (apt install certbot)."
        echo
        warn "Port 80 must be reachable from the internet for the HTTP-01 challenge."
        warn "Stop any service listening on port 80 before continuing."
        echo
        certbot certonly --standalone -d "$FQDN" \
            --non-interactive --agree-tos \
            --register-unsafely-without-email \
            || die "certbot failed — check the output above."
        cp "/etc/letsencrypt/live/${FQDN}/fullchain.pem" "$SSL_DIR/cert.pem"
        cp "/etc/letsencrypt/live/${FQDN}/privkey.pem"   "$SSL_DIR/key.pem"
        ok "Let's Encrypt certificate copied to docker/ssl/"
        warn "For certificate renewal, run:"
        warn "  certbot renew"
        warn "  cp /etc/letsencrypt/live/${FQDN}/fullchain.pem $SSL_DIR/cert.pem"
        warn "  cp /etc/letsencrypt/live/${FQDN}/privkey.pem $SSL_DIR/key.pem"
        warn "  docker run --rm -v ${COMPOSE_PROJECT}-${APP_ENV}_ssl_certs:/ssl -v $SSL_DIR:/src:ro alpine sh -c 'cp /src/cert.pem /src/key.pem /ssl/'"
        warn "  docker compose -f docker-compose.prod.yml restart nginx"
        ;;
    3)
        if [[ -f "$SSL_DIR/cert.pem" && -f "$SSL_DIR/key.pem" ]]; then
            ok "Found existing docker/ssl/cert.pem and key.pem"
        else
            warn "No certificate found at docker/ssl/ — copy cert.pem and key.pem there before starting."
        fi
        ;;
    *)
        die "Invalid choice."
        ;;
    esac
fi

# ── 5. Database ───────────────────────────────────────────────────────────────
if [[ "$APP_ENV" == "prod" ]]; then
    header "Database (MySQL 8)"
    echo "  1) Run MySQL in a container  (recommended for standalone deployments)"
    echo "  2) Use an external MySQL server"
    echo
    ask DB_CHOICE "Choice" "1"

    case "$DB_CHOICE" in
    1)
        USE_CONTAINER_DB=true
        DB_HOST="db"
        DB_PORT="3306"
        DB_NAME="dashlog"
        DB_USER="dash"
        DB_PASSWORD=$(openssl rand -hex 24)
        DB_ROOT_PASSWORD=$(openssl rand -hex 24)
        ok "MySQL will run in a container with auto-generated credentials"
        ;;
    2)
        USE_CONTAINER_DB=false
        ask        DB_HOST       "MySQL hostname or IP"
        ask        DB_PORT       "MySQL port" "3306"
        ask        DB_NAME       "Database name" "dashlog"
        ask        DB_USER       "MySQL username"
        ask_secret DB_PASSWORD   "MySQL password"
        ok "External MySQL: ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
        warn "Ensure the database and user already exist with the correct privileges."
        ;;
    *)
        die "Invalid choice."
        ;;
    esac

    DB_SERVER_VERSION="8.0"
    if [[ "$USE_CONTAINER_DB" == "false" ]]; then
        ask DB_SERVER_VERSION "MySQL server version (used in DSN)" "8.0"
    fi
    DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/${DB_NAME}?serverVersion=${DB_SERVER_VERSION}&charset=utf8mb4"
else
    USE_CONTAINER_DB=true
    DB_HOST="db"
    DB_PORT="3306"
    DB_NAME="dashlog"
    DB_USER="dash"
    DB_PASSWORD=$(openssl rand -hex 16)
    DB_ROOT_PASSWORD=$(openssl rand -hex 16)
fi

# ── Container log forwarding ──────────────────────────────────────────────────
header "Container log forwarding"
echo
echo "  Container stdout/stderr (Docker logs) can be forwarded to a remote syslog server."
echo "  This is separate from the collected syslog data DashLog stores in its database."
echo
CONTAINER_SYSLOG_ENABLED=false
if ask_yn "Forward container logs to a remote syslog server?" "n"; then
    CONTAINER_SYSLOG_ENABLED=true
    ask CONTAINER_SYSLOG_PROTOCOL "Protocol (udp or tcp)" "udp"
    ask CONTAINER_SYSLOG_HOST     "Syslog server hostname or IP"
    ask CONTAINER_SYSLOG_PORT     "Port" "514"
    CONTAINER_SYSLOG_ADDRESS="${CONTAINER_SYSLOG_PROTOCOL}://${CONTAINER_SYSLOG_HOST}:${CONTAINER_SYSLOG_PORT}"
    ok "Container logs → $CONTAINER_SYSLOG_ADDRESS"
else
    ok "Container logs will be stored locally (json-file driver)"
fi

# ── 6. Write compose file ─────────────────────────────────────────────────────
if [[ "$APP_ENV" == "dev" ]]; then
    header "Writing docker-compose.dev.yml"

    sed \
        -e "s|replace_with_compose_project|${COMPOSE_PROJECT}-dev|g" \
        -e "s|replace_with_32plus_char_secret|${APP_SECRET}|g" \
        -e "s|run: docker compose exec app php bin/console app:generate-encryption-key|${APP_ENCRYPTION_KEY}|g" \
        -e "s|https://your-dev-hostname.example.com|${BASE_URL}|g" \
        -e "s|dash_password|${DB_PASSWORD}|g" \
        -e "s|root_password|${DB_ROOT_PASSWORD}|g" \
        -e "s|\"8080:80\"|\"${HTTP_PORT}:80\"|g" \
        -e "s|\"8443:443\"|\"${HTTPS_PORT}:443\"|g" \
        "$DIST_FILE" > "$COMPOSE_FILE"

    ok "docker-compose.dev.yml written"
else
    header "Writing docker-compose.prod.yml"

    if [[ "$USE_CONTAINER_DB" == "true" ]]; then
        IFS= read -r -d '' DB_SERVICE_BLOCK << YAML || true

  db:
    image: mysql:8.0
    read_only: true
    environment:
      MYSQL_DATABASE: ${DB_NAME}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
    tmpfs:
      - /var/run/mysqld
      - /tmp
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "${DB_USER}", "-p${DB_PASSWORD}"]
      interval: 5s
      timeout: 5s
      retries: 12
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"

YAML

        IFS= read -r -d '' DEPENDS_ON_BLOCK << YAML || true
    depends_on:
      db:
        condition: service_healthy
YAML

        IFS= read -r -d '' VOLUMES_BLOCK << YAML || true
volumes:
  ssl_certs:
  mysql_data:
  symfony_var:
YAML

    else
        DB_SERVICE_BLOCK=""
        DEPENDS_ON_BLOCK=""
        IFS= read -r -d '' VOLUMES_BLOCK << YAML || true
volumes:
  ssl_certs:
  symfony_var:
YAML
    fi

    cat > "$COMPOSE_FILE" << EOF
name: ${COMPOSE_PROJECT}-prod

services:
  app:
    build:
      context: .
      target: prod
      args:
        APP_ENV: prod
    image: ${APP_IMAGE}
    read_only: true
    restart: unless-stopped
    volumes:
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
      APP_ENV: prod
      APP_SECRET: "${APP_SECRET}"
      APP_ENCRYPTION_KEY: "${APP_ENCRYPTION_KEY}"
      DATABASE_URL: "${DATABASE_URL}"
      DEFAULT_URI: "${BASE_URL}"
      MESSENGER_TRANSPORT_DSN: "doctrine://default?auto_setup=0"
    healthcheck:
      test: ["CMD-SHELL", "nc -z 127.0.0.1 9000"]
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 10s
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
${DEPENDS_ON_BLOCK}

  worker:
    image: ${APP_IMAGE}
    read_only: true
    restart: unless-stopped
    command: ["php", "bin/console", "messenger:consume", "async", "failed", "--time-limit=3600"]
    volumes:
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
      APP_ENV: prod
      APP_SECRET: "${APP_SECRET}"
      APP_ENCRYPTION_KEY: "${APP_ENCRYPTION_KEY}"
      DATABASE_URL: "${DATABASE_URL}"
      DEFAULT_URI: "${BASE_URL}"
      MESSENGER_TRANSPORT_DSN: "doctrine://default?auto_setup=0"
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
${DEPENDS_ON_BLOCK}

  # Rebuild order: docker compose build app && docker compose build nginx
  nginx:
    build:
      context: docker/nginx
      args:
        APP_IMAGE: ${APP_IMAGE}
    read_only: true
    restart: unless-stopped
    ports:
      - "${HTTP_PORT}:80"
      - "${HTTPS_PORT}:443"
    volumes:
      - ssl_certs:/etc/nginx/ssl:ro
    tmpfs:
      - /var/cache/nginx
      - /var/run
      - /tmp
    depends_on:
      app:
        condition: service_healthy
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
${DB_SERVICE_BLOCK}
${VOLUMES_BLOCK}
EOF

    ok "docker-compose.prod.yml written"
fi

# Replace json-file logging with syslog if configured
if [[ "$CONTAINER_SYSLOG_ENABLED" == "true" ]]; then
    awk -v addr="$CONTAINER_SYSLOG_ADDRESS" '
/^[[:space:]]+logging:[[:space:]]*$/ {
    ind = $0; gsub(/logging:.*/, "", ind)
    si = ind "  "; ssi = si "  "
    print ind "logging:"
    print si "driver: syslog"
    print si "options:"
    print ssi "syslog-address: \"" addr "\""
    print ssi "tag: \"dashlog/{{.Name}}\""
    skip = 4; next
}
skip > 0 { skip--; next }
{ print }
' "$COMPOSE_FILE" > "${COMPOSE_FILE}.tmp" && mv "${COMPOSE_FILE}.tmp" "$COMPOSE_FILE"
    ok "Logging driver set to syslog ($CONTAINER_SYSLOG_ADDRESS)"
fi

# ── 7. Build image(s) ─────────────────────────────────────────────────────────
header "Building image"

docker compose -f "$COMPOSE_FILE" build app
ok "Application image built (${APP_IMAGE})"

if [[ "$APP_ENV" == "prod" ]]; then
    docker compose -f "$COMPOSE_FILE" build nginx
    ok "Nginx image built"
fi

# ── 8. PHP dependencies (dev only) ────────────────────────────────────────────
if [[ "$APP_ENV" == "dev" ]]; then
    header "Installing PHP dependencies"

    docker run --rm \
        --volume "$SCRIPT_DIR:/var/www/html" \
        --workdir /var/www/html \
        --env COMPOSER_HOME=/tmp/composer \
        "${APP_IMAGE}" \
        composer install --no-interaction --no-progress
    ok "Dependencies installed"
fi

# ── 9. SSL certificate volume ─────────────────────────────────────────────────
header "Copying SSL certificates into volume"

docker run --rm \
    -v "${COMPOSE_PROJECT}-${APP_ENV}_ssl_certs:/ssl" \
    -v "$SSL_DIR:/src:ro" \
    alpine sh -c "cp /src/cert.pem /src/key.pem /ssl/ && chmod 644 /ssl/*.pem"
ok "SSL certificates ready in named volume"

# ── 10. Start services ────────────────────────────────────────────────────────
header "Starting services"

docker compose -f "$COMPOSE_FILE" up -d
ok "Containers started"

echo "  Waiting for the application container to be ready…"
for i in $(seq 1 30); do
    if docker compose -f "$COMPOSE_FILE" exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q "ok"; then
        break
    fi
    sleep 2
    [[ $i -eq 30 ]] && die "App container did not become ready in time. Check: docker compose -f docker-compose.${APP_ENV}.yml logs app"
done
ok "App container ready"

echo "  Waiting for database to accept connections…"
for i in $(seq 1 30); do
    if docker compose -f "$COMPOSE_FILE" exec -T app \
        php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASSWORD}'); exit(0); } catch(Exception \$e) { exit(1); }" \
        2>/dev/null; then
        break
    fi
    sleep 2
    [[ $i -eq 30 ]] && die "Database did not become ready. Check: docker compose -f docker-compose.${APP_ENV}.yml logs db"
done
ok "Database ready"

# ── 11. Database migrations ───────────────────────────────────────────────────
header "Running database migrations"

docker compose -f "$COMPOSE_FILE" exec -T app \
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
ok "Migrations complete"

# ── 12. Cache warmup ──────────────────────────────────────────────────────────
header "Warming up cache"

docker compose -f "$COMPOSE_FILE" exec -T app \
    php bin/console cache:warmup
ok "Cache warm"

# ── 13. Fixtures (dev only) ───────────────────────────────────────────────────
if [[ "$APP_ENV" == "dev" ]]; then
    header "Loading fixtures"
    if compgen -G "$SCRIPT_DIR/src/DataFixtures/*.php" > /dev/null 2>&1; then
        docker compose -f "$COMPOSE_FILE" exec -T app \
            php bin/console doctrine:fixtures:load --no-interaction --append
        ok "Fixtures loaded"
    else
        ok "No fixture files found — skipped"
    fi
fi

# ── 14. SAML identity provider ───────────────────────────────────────────────
header "SAML Identity Provider"
echo
if [[ "$APP_ENV" == "prod" ]]; then
    echo -e "  ${BOLD}SP metadata URL (give this to your IdP administrator):${NC}"
    echo -e "  ${CYAN}${BASE_URL}/saml/metadata${NC}"
    echo
    echo "  Your IdP administrator needs to register that URL as a Service Provider"
    echo "  in their system before users can log in."
    echo
fi

read -rp "$(echo -e "  ${BOLD}Path to IdP metadata XML file, or URL (leave blank to configure later): ${NC}")" SAML_SOURCE

if [[ -n "${SAML_SOURCE:-}" ]]; then
    ask SAML_NAME "Provider name (e.g. 'Okta', 'Azure AD', 'Entra ID')" "IdP"

    if [[ "$SAML_SOURCE" =~ ^https?:// ]]; then
        SAML_ARG="$SAML_SOURCE"
        SAML_TMP=""
    else
        [[ -f "$SAML_SOURCE" ]] || die "File not found: $SAML_SOURCE"
        SAML_TMP="$SCRIPT_DIR/.saml-setup-metadata.xml"
        cp "$SAML_SOURCE" "$SAML_TMP"
        if [[ "$APP_ENV" == "dev" ]]; then
            SAML_ARG="/var/www/html/.saml-setup-metadata.xml"
        else
            APP_CONTAINER=$(docker compose -f "$COMPOSE_FILE" ps -q app | head -1)
            docker cp "$SAML_TMP" "${APP_CONTAINER}:/tmp/saml-metadata.xml"
            SAML_ARG="/tmp/saml-metadata.xml"
        fi
    fi

    docker compose -f "$COMPOSE_FILE" exec -T app \
        php bin/console app:saml:import-metadata "$SAML_ARG" \
        --name="$SAML_NAME" --activate

    [[ -n "${SAML_TMP:-}" ]] && rm -f "$SAML_TMP"
    ok "SAML provider '$SAML_NAME' imported and set as active"
    echo
    echo -e "  ${BOLD}SP metadata URL for your IdP:${NC}"
    echo -e "  ${CYAN}${BASE_URL}/saml/metadata${NC}"
else
    warn "Skipped — no one will be able to log in until a SAML provider is configured."
    warn "Run this when ready:"
    warn "  docker compose -f docker-compose.${APP_ENV}.yml exec app php bin/console app:saml:import-metadata <file-or-url> --activate"
    warn "SP metadata URL (accessible after import):  ${BASE_URL}/saml/metadata"
fi

# ── 15. Summary ───────────────────────────────────────────────────────────────
header "Setup complete"
echo
if [[ "$APP_ENV" == "dev" ]]; then
    echo -e "  ${BOLD}DashLog (dev) is running at:  ${CYAN}${BASE_URL}${NC}"
else
    echo -e "  ${BOLD}DashLog is running at:  ${CYAN}${BASE_URL}${NC}"
fi
echo

echo -e "  ${BOLD}MySQL credentials (save these — they are not stored elsewhere):${NC}"
if [[ "$APP_ENV" == "dev" ]]; then
    echo "    App user:  dash / $DB_PASSWORD"
    echo "    Root:      root / $DB_ROOT_PASSWORD"
elif [[ "$USE_CONTAINER_DB" == "true" ]]; then
    echo "    App user:  $DB_USER / $DB_PASSWORD"
    echo "    Root:      root / $DB_ROOT_PASSWORD"
fi
echo

if [[ "$APP_ENV" == "dev" ]]; then
    warn "Self-signed cert in use — browsers will show a security warning."
elif [[ "${SSL_CHOICE:-}" == "1" ]]; then
    warn "Self-signed cert in use — browsers will show a warning."
    warn "To switch to a trusted cert, replace docker/ssl/cert.pem and key.pem, then run:"
    warn "  docker run --rm -v ${COMPOSE_PROJECT}-${APP_ENV}_ssl_certs:/ssl -v $SSL_DIR:/src:ro alpine sh -c 'cp /src/cert.pem /src/key.pem /ssl/'"
    warn "  docker compose -f docker-compose.${APP_ENV}.yml restart nginx"
fi
echo

echo "  Common commands:"
echo "    Start:    docker compose -f docker-compose.${APP_ENV}.yml up -d"
echo "    Stop:     docker compose -f docker-compose.${APP_ENV}.yml down"
echo "    Logs:     docker compose -f docker-compose.${APP_ENV}.yml logs -f"
echo "    Migrate:  docker compose -f docker-compose.${APP_ENV}.yml exec app php bin/console doctrine:migrations:migrate"
echo
echo -e "  ${BOLD}SP metadata URL:${NC}  ${CYAN}${BASE_URL}/saml/metadata${NC}"
echo
