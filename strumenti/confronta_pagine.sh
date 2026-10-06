#!/usr/bin/env bash
# =========================================================================
# strumenti/confronta_pagine.sh - Fotografia dell'HTML delle pagine per verificare che la migrazione a classi
# non cambi nulla di visibile (MIGRATION_PLAN.md): prima e dopo una modifica si salvano le pagine e si confrontano.
#   bash strumenti/confronta_pagine.sh foto <cartella> <elenco_url.txt>   salva le pagine (ambiente locale acceso)
#   bash strumenti/confronta_pagine.sh confronta <cartella_prima> <cartella_dopo>
# elenco_url.txt: una riga per pagina "utente percorso", es. "0 agenda.php" (0 = senza accesso), "1 admin/eventi.php"
# (1 = amministratore, 4 = docente, 5 = studente: utenti dell'ambiente locale, accesso da /__accesso?u=N).
# Si tolgono dall'HTML le parti che cambiano a ogni richiesta (token CSRF, nonce, orari): il resto deve essere identico.
# BASE=http://127.0.0.1:8080 (o la porta della propria copia).
# =========================================================================
set -e
BASE="${BASE:-http://127.0.0.1:8080}"
normalizza() {
    sed -E -e 's/(csrf_token" value=")[a-f0-9]+/\1X/g' -e 's/name="csrf-token" content="[a-f0-9]+/name="csrf-token" content="X/g' \
        -e 's/nonce="[^"]+"/nonce="X"/g' -e 's/\?v=[0-9]+/?v=X/g' -e 's/[0-9]{2}:[0-9]{2}:[0-9]{2}/HH:MM:SS/g' -e 's/r=[0-9]{9,}/r=X/g'
}
case "$1" in
    foto)
        mkdir -p "$2"; jar=$(mktemp -d)
        while read -r u percorso; do
            [ -z "$percorso" ] && continue
            c="$jar/c$u"
            [ "$u" != "0" ] && [ ! -f "$c" ] && curl -s -c "$c" -b "$c" -o /dev/null "$BASE/__accesso?u=$u"
            nome=$(echo "u${u}_$percorso" | tr '/?&=' '____')
            if [ "$u" = "0" ]; then curl -s -w '\n<!-- HTTP %{http_code} -->\n' "$BASE/eventi/$percorso"; else curl -s -c "$c" -b "$c" -w '\n<!-- HTTP %{http_code} -->\n' "$BASE/eventi/$percorso"; fi | normalizza > "$2/$nome.html"
        done < "$3"
        rm -rf "$jar"; echo "Salvate $(ls "$2" | wc -l) pagine in $2" ;;
    confronta)
        diversi=0
        for f in "$2"/*.html; do
            g="$3/$(basename "$f")"
            if ! cmp -s "$f" "$g"; then echo "DIVERSA: $(basename "$f")"; diff <(tr '>' '\n' < "$f") <(tr '>' '\n' < "$g") | head -20; diversi=$((diversi+1)); fi
        done
        echo "$diversi pagine diverse"; [ "$diversi" = 0 ] ;;
    *) sed -n '3,12p' "$0"; exit 1 ;;
esac
