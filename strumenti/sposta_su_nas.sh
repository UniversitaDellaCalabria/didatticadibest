#!/usr/bin/env bash
# =========================================================================
# Sposta sul NAS le cartelle dei file riservati del portale (pratiche, convenzioni, verbali…).
# Per ogni cartella: copia con rsync, controllo che sia identica, poi uploads/<cartella> diventa un collegamento alla cartella del NAS.
# La cartella vecchia resta come uploads/<cartella>.vecchia (si cancella a mano dopo qualche settimana). Il portale non cambia.
#
# Da eseguire SUL SERVER con sudo, a un'ora tranquilla:
#   sudo bash strumenti/sposta_su_nas.sh --prova                 mostra cosa farebbe, senza toccare nulla
#   sudo bash strumenti/sposta_su_nas.sh                         sposta tutte le cartelle
#   sudo bash strumenti/sposta_su_nas.sh pratiche convenzioni    sposta solo quelle indicate
#   sudo bash strumenti/sposta_su_nas.sh --ripristina pratiche   torna indietro per quella cartella (riporta i file nuovi sul server)
# Impostazioni (variabili d'ambiente, con questi valori predefiniti):
#   SITO=/opt/lampp/htdocs/didattica   NAS=/mnt/nas_nuovo/portale_didattica   WEB=daemon (utente di Apache)
# =========================================================================
set -u
SITO="${SITO:-/opt/lampp/htdocs/didattica}"
NAS="${NAS:-/mnt/nas_nuovo/portale_didattica}"
WEB="${WEB:-daemon}"
TUTTE=(verbali pratiche convenzioni convenzioni_compilate convenzioni_tesi autorizzazioni incarichi allegati_prenotazioni)

PROVA=0; RIPRISTINA=0; SCELTE=()
for a in "$@"; do
    case "$a" in
        --prova) PROVA=1 ;;
        --ripristina) RIPRISTINA=1 ;;
        -*) echo "Opzione sconosciuta: $a"; exit 2 ;;
        *) SCELTE+=("$a") ;;
    esac
done
[ ${#SCELTE[@]} -eq 0 ] && SCELTE=("${TUTTE[@]}")

ok()  { echo "  [OK]  $*"; }
ko()  { echo "  [KO]  $*"; }
inf() { echo "        $*"; }
fai() { if [ "$PROVA" = 1 ]; then echo "  (prova) $*"; else eval "$@"; fi; }

echo "Portale:  $SITO"
echo "NAS:      $NAS"
echo "Utente web: $WEB"
[ "$PROVA" = 1 ] && echo "MODO PROVA: non cambia nulla."

# ---- controlli di partenza
[ "$(id -u)" = 0 ] || { ko "Serve sudo (esegui: sudo bash $0 ...)"; exit 1; }
[ -d "$SITO/uploads" ] || { ko "Non trovo $SITO/uploads"; exit 1; }
[ -d "$NAS" ] || { ko "Cartella del NAS non trovata: $NAS (NAS non montato? controlla con: mount | grep nas)"; exit 1; }
id "$WEB" >/dev/null 2>&1 || { ko "Utente web '$WEB' inesistente: cerca quello di Apache con  ps -eo user,comm | grep -E 'httpd|apache2' | sort -u  e rilancia con WEB=nome"; exit 1; }
command -v rsync >/dev/null || { ko "rsync non installato: sudo apt install -y rsync"; exit 1; }
PROVA_FILE="$NAS/.prova_scrittura_$$"
if sudo -u "$WEB" touch "$PROVA_FILE" 2>/dev/null; then rm -f "$PROVA_FILE"; ok "L'utente $WEB scrive sul NAS"; else ko "L'utente $WEB NON può scrivere in $NAS (uid/gid del montaggio?)"; exit 1; fi

ERRORI=0
for c in "${SCELTE[@]}"; do
    echo
    echo "== $c"
    SRC="$SITO/uploads/$c"
    DST="$NAS/$c"

    if [ "$RIPRISTINA" = 1 ]; then
        if [ ! -L "$SRC" ]; then ko "uploads/$c non è un collegamento: niente da ripristinare"; continue; fi
        fai "rm '$SRC'"
        if [ -d "$SRC.vecchia" ]; then fai "mv '$SRC.vecchia' '$SRC'"; else fai "mkdir -p '$SRC'"; fi
        fai "rsync -rt '$DST/' '$SRC/'"
        fai "chown -R '$WEB' '$SRC' 2>/dev/null || true"
        ok "ripristinato su disco del server (i file sono rimasti anche sul NAS)"
        continue
    fi

    if [ -L "$SRC" ]; then ok "già sul NAS: uploads/$c → $(readlink "$SRC")"; continue; fi
    if [ -e "$SRC.vecchia" ]; then ko "esiste già uploads/$c.vecchia: controlla e rinominala o cancellala, poi rilancia"; ERRORI=$((ERRORI+1)); continue; fi

    fai "mkdir -p '$DST'"
    if [ -d "$SRC" ]; then
        N=$(find "$SRC" -type f | wc -l); D=$(du -sh "$SRC" 2>/dev/null | cut -f1)
        inf "da copiare: $N file, $D"
        fai "rsync -rt '$SRC/' '$DST/'"
        if [ "$PROVA" = 0 ]; then
            DIFF=$(rsync -rcn --itemize-changes "$SRC/" "$DST/" | head -5)
            if [ -n "$DIFF" ]; then ko "il controllo trova differenze, non sposto: $DIFF"; ERRORI=$((ERRORI+1)); continue; fi
            ok "copia identica (controllo sui contenuti)"
        fi
        fai "mv '$SRC' '$SRC.vecchia'"
    else
        inf "uploads/$c non esiste ancora: si crea il solo collegamento"
    fi
    if ! fai "ln -s '$DST' '$SRC'"; then
        ko "collegamento non riuscito: torno indietro"; [ -d "$SRC.vecchia" ] && mv "$SRC.vecchia" "$SRC"; ERRORI=$((ERRORI+1)); continue
    fi
    if [ "$PROVA" = 0 ]; then
        if sudo -u "$WEB" test -d "$SRC" && sudo -u "$WEB" touch "$SRC/.prova_$$" 2>/dev/null; then
            rm -f "$SRC/.prova_$$"; ok "uploads/$c → $DST (l'utente $WEB legge e scrive)"
        else
            ko "collegamento fatto ma $WEB non scrive: TORNO INDIETRO"
            rm -f "$SRC"; [ -d "$SRC.vecchia" ] && mv "$SRC.vecchia" "$SRC"
            ERRORI=$((ERRORI+1))
        fi
        # protezione del web nella cartella (se la vecchia ce l'aveva, rsync l'ha già copiata)
        if [ -d "$SRC.vecchia" ] && [ -f "$SRC.vecchia/.htaccess" ] && [ ! -f "$DST/.htaccess" ]; then cp -a "$SRC.vecchia/.htaccess" "$DST/.htaccess"; fi
    fi
done

echo
if [ "$PROVA" = 1 ]; then echo "Fine della prova. Se tutto ti sembra giusto, rilancia senza --prova."; exit 0; fi
if [ "$ERRORI" -gt 0 ]; then echo "Finito con $ERRORI problemi (vedi i [KO] sopra)."; exit 1; fi
echo "Fatto. Ora prova il portale (scarica un verbale, un allegato di pratica, una convenzione) e poi controlla in Sistema → Controllo del sito."
echo "Le cartelle uploads/*.vecchia si cancellano a mano dopo 2 settimane, quando sei sicuro: sudo rm -rf $SITO/uploads/*.vecchia"
