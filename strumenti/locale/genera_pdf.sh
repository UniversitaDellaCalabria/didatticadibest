#!/usr/bin/env bash
# =========================================================================
# PDF delle guide per ruolo e delle presentazioni dell'iter (documentazione/guide/*.pdf e documentazione/*_iter.pdf), con Chrome in modalità headless.
#   bash strumenti/locale/genera_pdf.sh            (portale locale acceso: bash strumenti/locale/avvia.sh)
# Le guide sono la pagina guida.php?r=<ruolo>&stampa=1; le presentazioni sono i file .html in documentazione/ (generati da strumenti/locale/presentazioni.py).
# =========================================================================
set -e
SITO="$(cd "$(dirname "$0")/../.." && (pwd -W 2>/dev/null || pwd))"
CHROME="${CHROME:-/c/Program Files/Google/Chrome/Application/chrome.exe}"
PORTA="${PORTA:-8080}"
PROFILO="${TEMP:-/tmp}/chrome_pdf_$$"
mkdir -p "$SITO/documentazione/guide"

stampa() { # $1 = URL o file, $2 = PDF di uscita
    if "$CHROME" --headless=new --disable-gpu --no-pdf-header-footer --user-data-dir="$PROFILO" --virtual-time-budget=10000 --print-to-pdf="$2" "$1" >/dev/null 2>&1 && [ -s "$2" ]; then
        echo "  $(basename "$2")"
    else
        echo "  ERRORE: $(basename "$2") non creato"
    fi
}

echo "Guide per ruolo"
for r in "studente:Studente" "docente:Docente_relatore" "protocollo:Ufficio_protocollo" "referente:Ufficio_del_corso" "consiglio:Referente_consiglio" "risorsa:Responsabile_risorsa" "scuola:Scuole_convenzione_FSL" "elenco:Docenti_elenco_studenti_FSL" "tutor:Tutor" "direttore:Direttore" "assistenza:Assistenza_Didacticus" "amministratore:Amministratore"; do
    stampa "http://127.0.0.1:$PORTA/eventi/guida.php?r=${r%%:*}&stampa=1" "$SITO/documentazione/guide/Guida_${r##*:}.pdf"
done

echo "Presentazioni dell'iter"
for f in "$SITO"/documentazione/*_iter.html; do
    [ -e "$f" ] || continue
    stampa "file:///${f}" "${f%.html}.pdf"
done
rm -rf "$PROFILO"
