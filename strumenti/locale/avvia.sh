#!/usr/bin/env bash
# =========================================================================
# Ambiente locale di prova del portale (Windows con XAMPP, da Git Bash).
#   bash strumenti/locale/avvia.sh            avvia database e portale su http://127.0.0.1:8080/eventi/
#   bash strumenti/locale/avvia.sh --nuovo    ricrea da zero il database di prova con i dati di esempio
#   bash strumenti/locale/avvia.sh --ferma    ferma il portale e il database
# Accesso di prova: http://127.0.0.1:8080/__accesso · email intercettate: /__email · cron: /__cron
# Usa un database separato (eventi_locale) e il file .env.locale: il file .env del server non viene letto
# e nessuna email viene inviata davvero.
# =========================================================================
set -e
XAMPP="${XAMPP:-/c/xampp}"
PHP="$XAMPP/php/php.exe"; MYSQL="$XAMPP/mysql/bin/mysql.exe"
SITO="$(cd "$(dirname "$0")/../.." && pwd)"
LOC="$SITO/strumenti/locale"
PORTA="${PORTA:-8080}"
DB=eventi_locale

db_attivo() { "$MYSQL" -u root -e "SELECT 1" >/dev/null 2>&1; }

if [ "$1" = "--ferma" ]; then
    taskkill //F //IM php.exe //FI "WINDOWTITLE eq eventi_locale*" >/dev/null 2>&1 || true
    pkill -f "php.exe -S 127.0.0.1:$PORTA" 2>/dev/null || true
    "$XAMPP/mysql/bin/mysqladmin.exe" -u root shutdown 2>/dev/null || true
    echo "Fermati."; exit 0
fi

# 1. Database (MariaDB di XAMPP)
if ! db_attivo; then
    echo "Avvio MariaDB…"
    ( "$XAMPP/mysql/bin/mysqld.exe" --defaults-file="$XAMPP/mysql/bin/my.ini" --standalone >/dev/null 2>&1 & )
    for i in $(seq 1 20); do db_attivo && break; sleep 1; done
fi
if [ "$1" = "--nuovo" ] || ! "$MYSQL" -u root -e "USE $DB" >/dev/null 2>&1; then
    echo "Creo il database di prova $DB…"
    "$MYSQL" -u root -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    "$MYSQL" -u root "$DB" < "$SITO/database/schema.sql"
    rm -f "$SITO"/cache/schema_v*.ok "$SITO"/cache/configurazione_portale.json
    NUOVO=1
fi

# 2. Configurazione locale (mai il .env del server)
if [ ! -f "$SITO/.env.locale" ]; then
    cat > "$SITO/.env.locale" <<EOF
; Ambiente locale di prova (strumenti/locale): database separato, email salvate in cache/email_locali/
DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=
DB_NAME=$DB
CRON_KEY=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
CONSERVAZIONE_LOG_MESI=12
CONSERVAZIONE_AUDIT_MESI=24
CONSERVAZIONE_PRENOTAZIONI_MESI=0
CONSERVAZIONE_UTENTI_MESI=0
EOF
    echo "Creato .env.locale"
fi

# 3. Dati di esempio (dopo lo schema completo creato dal portale alla prima pagina)
if [ -n "$NUOVO" ]; then
    "$PHP" -d extension=zip -r '
        chdir($argv[1]); $_SERVER["HTTP_HOST"] = "127.0.0.1"; define("AMBIENTE_LOCALE_CLI", 1);
        $e = parse_ini_file(".env.locale");
        $conn = new mysqli($e["DB_HOST"], $e["DB_USER"], $e["DB_PASS"], $e["DB_NAME"]); $conn->set_charset("utf8mb4");
        require "functions.php"; assicura_schema($conn); echo "Schema aggiornato\n";' "$SITO"
    "$MYSQL" -u root "$DB" < "$LOC/dati_prova.sql"
    # Campo "numero di studenti" delle aree con attività per le scuole (sul server si crea salvando progetti ed eventi)
    "$PHP" -r '
        chdir($argv[1]); $e = parse_ini_file(".env.locale");
        $conn = new mysqli($e["DB_HOST"], $e["DB_USER"], $e["DB_PASS"], $e["DB_NAME"]); $conn->set_charset("utf8mb4");
        require "functions.php"; assicura_campi_progetto($conn, 1); assicura_campi_progetto($conn, 2);' "$SITO"
    echo "Dati di esempio caricati."
fi

# 4. Portale
mkdir -p "$LOC/www"
echo "Portale su http://127.0.0.1:$PORTA/eventi/  (accesso di prova: http://127.0.0.1:$PORTA/__accesso)"
exec "$PHP" -d extension=zip -d extension=gd -d extension=intl -d display_errors=1 -d log_errors=1 \
     -d upload_max_filesize=20M -d post_max_size=25M -S 127.0.0.1:$PORTA -t "$LOC/www" "$LOC/router.php"
