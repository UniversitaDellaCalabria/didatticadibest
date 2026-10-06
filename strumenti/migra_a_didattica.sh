#!/usr/bin/env bash
# =========================================================================
# Sposta il portale da https://dibest2.unical.it/eventi a https://dibest2.unical.it/didattica
# e lascia al vecchio indirizzo un rimando permanente (301), pagina per pagina e con i parametri:
# continuano a funzionare i link già inviati per email e i QR degli attestati.
#
#   sudo bash /opt/lampp/htdocs/eventi/strumenti/migra_a_didattica.sh          esegue lo spostamento
#   sudo bash /opt/lampp/htdocs/didattica/strumenti/migra_a_didattica.sh --annulla   torna a /eventi
#
# Dopo lo spostamento (o l'annullamento) aggiornare il crontab dell'utente che lancia i cron:
# lo script stampa il comando da eseguire.
# =========================================================================
set -e
HT="${HTDOCS:-/opt/lampp/htdocs}"
VECCHIA="$HT/eventi"
NUOVA="$HT/didattica"
HOST="https://dibest2.unical.it"

imposta_url() {   # URL_SITO nel file .env (usato dai cron lanciati da riga di comando)
    local env="$1/.env" url="$2"
    [ -f "$env" ] || return 0
    if grep -q '^URL_SITO=' "$env"; then sed -i "s#^URL_SITO=.*#URL_SITO=$url#" "$env"; else printf '\nURL_SITO=%s\n' "$url" >> "$env"; fi
}

if [ "$1" = "--annulla" ]; then
    [ -d "$NUOVA" ] || { echo "Non trovo $NUOVA: niente da annullare."; exit 1; }
    if [ -e "$VECCHIA" ]; then
        [ -f "$VECCHIA/.rimando" ] || { echo "$VECCHIA esiste e non è la cartella del rimando: mi fermo."; exit 1; }
        rm -rf "$VECCHIA"
    fi
    mv "$NUOVA" "$VECCHIA"
    imposta_url "$VECCHIA" "$HOST/eventi"
    echo "Fatto: il portale è di nuovo su $HOST/eventi."
    echo "Aggiorna il crontab (senza sudo, con l'utente dei cron):"
    echo "  crontab -l | sed 's#/didattica/#/eventi/#g' | crontab -"
    exit 0
fi

[ -d "$VECCHIA" ] || { echo "Non trovo $VECCHIA."; exit 1; }
[ -f "$VECCHIA/.rimando" ] && { echo "$VECCHIA è già la cartella del rimando: lo spostamento è già stato fatto."; exit 1; }
[ -e "$NUOVA" ] && { echo "$NUOVA esiste già: mi fermo per non sovrascrivere nulla."; exit 1; }

PROPRIETARIO="$(stat -c '%U:%G' "$VECCHIA")"
mv "$VECCHIA" "$NUOVA"
imposta_url "$NUOVA" "$HOST/didattica"

# Cartella del rimando: ogni indirizzo /eventi/... porta a /didattica/... (con i parametri)
mkdir "$VECCHIA"
cat > "$VECCHIA/.htaccess" <<'EOF'
# Il portale si è spostato in /didattica: rimando permanente pagina per pagina (parametri compresi)
RewriteEngine On
RewriteRule ^(.*)$ /didattica/$1 [R=301,L]
EOF
touch "$VECCHIA/.rimando"
chown -R "$PROPRIETARIO" "$VECCHIA"

echo "Fatto: il portale è su $HOST/didattica e $HOST/eventi rimanda lì."
echo
echo "1) Aggiorna il crontab (senza sudo, con l'utente dei cron):"
echo "   crontab -l | sed 's#/eventi/#/didattica/#g' | crontab -"
echo "2) Controlla il sito:"
echo "   cd $NUOVA && PHP_BIN=/opt/lampp/bin/php bash strumenti/verifica_sito.sh $HOST/didattica"
echo "   curl -sI $HOST/eventi/privacy.php | grep -i '^location'"
echo "3) Prova l'accesso con SSO. Se qualcosa non va: sudo bash $NUOVA/strumenti/migra_a_didattica.sh --annulla"
