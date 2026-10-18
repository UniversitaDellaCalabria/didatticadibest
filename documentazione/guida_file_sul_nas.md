# File del portale sul NAS (verbali, convenzioni, pratiche degli studenti)

Guida passo per passo, da seguire con calma (circa 2 ore, la prima volta). Non cambia il codice del portale: le cartelle dei file caricati (`uploads/…`) restano dove sono per il portale, ma **dietro ci sono le cartelle del NAS** (collegamento simbolico). Il database resta sul server.

## 0. Cosa cambia e cosa no

| | Prima | Dopo |
|---|---|---|
| Verbali, convenzioni, allegati delle pratiche, lettere di incarico… | su disco del server, `uploads/…` | **sul NAS**, il portale li vede come prima |
| Database (utenti, pratiche, testi) | server | server (non va sul NAS) |
| Backup notturno | database + file → NAS | database → NAS; i file **sono già** sul NAS |

**Vantaggio:** 8 TB invece del disco del server, file lontani dal sito, snapshot e RAID del NAS.
**Rischi da conoscere (e come li copriamo):**
1. **NAS spento o rete interrotta** → il portale non apre/salva i file finché non torna. Le altre pagine funzionano. Montiamo con `nofail` e controlliamo la raggiungibilità (punto 7).
2. **I file hanno una sola copia** (prima c'era anche la copia del backup). Il NAS deve avere RAID e **snapshot giornalieri**, e meglio una copia fuori dal NAS (disco esterno o altro NAS) almeno ogni settimana.
3. **Più lento** del disco locale: per PDF e allegati non si nota.

## 1. Cosa mi serve sapere prima (scrivilo qui sotto)

- Marca e modello del NAS (Synology, QNAP, altro): ______
- IP o nome del NAS nella rete del server: ______
- Il NAS è già montato sul server per il backup? Sul server: `mount | grep -i -E "cifs|nfs"` e `grep BACKUP_NAS_PATH /opt/lampp/htdocs/didattica/.env` → percorso: ______
- Utente con cui gira Apache di XAMPP: sul server `ps -eo user,comm | grep -E "httpd|apache" | sort -u` (di solito `daemon`): ______

## 2. Sul NAS: cartella condivisa e utente (esempio Synology; su QNAP i nomi dei menu cambiano poco)

1. **Pannello di controllo → Cartella condivisa → Crea.** Nome: `portale_didattica`. Non attivare «Nascondi nei risultati di ricerca» se non serve; **attiva il Cestino**; **non cifrare** (o, se cifri, il volume deve montarsi da solo all'avvio).
2. **Pannello di controllo → Utente → Crea.** Nome: `portale`. Password lunga (20+ caratteri, la salvi nel gestore di password). Nessun gruppo di amministratori. Assegna **lettura/scrittura solo** alla cartella `portale_didattica`, nessun accesso alle altre cartelle, nessuna applicazione (disattiva File Station, DSM, ecc.).
3. **Servizi file → SMB:** abilita SMB; protocollo minimo **SMB2** (meglio SMB3).
4. Dentro `portale_didattica` crea queste sottocartelle (da Nextcloud, File Station o dalla cartella di rete):
   `verbali` (con dentro `archivio`), `pratiche`, `convenzioni`, `convenzioni_compilate`, `convenzioni_tesi`, `autorizzazioni`, `incarichi`, `allegati_prenotazioni`, e `backup`.
   **Non spostare** `personale` (foto), `modulistica` (moduli da scaricare), `modelli_convenzione` (modelli), `slide_home`, i loghi e i banner: sono file pubblici, il sito li mostra direttamente e devono restare sul server. Le sottocartelle che il portale usa dentro le altre (`firmate`, `definitive`, …) le crea da solo.
5. **Snapshot:** *Snapshot Replication* (o equivalente) → programma uno snapshot **ogni giorno** della cartella con conservazione di 30 giorni. Così anche un file cancellato per errore si recupera.
6. **Sicurezza di rete:** il NAS non deve essere raggiungibile da Internet (niente port forwarding). Se hai un firewall sul NAS, consenti SMB (porta 445) solo dall'IP del server.
7. Controlla che il volume sia in **RAID** (Gestione archiviazione → stato «Integro») e che esista una copia periodica altrove.

## 3. Sul server: montare la cartella

Tutto da utente con `sudo`. Sostituisci `IP_DEL_NAS`.

```bash
sudo apt install -y cifs-utils
sudo mkdir -p /mnt/nas_portale
```

File con le credenziali (leggibile solo da root):

```bash
sudo nano /root/.nas_portale
```
Contenuto (due righe):
```
username=portale
password=LA_PASSWORD_DELL_UTENTE_PORTALE
```
```bash
sudo chmod 600 /root/.nas_portale
```

Prima del montaggio fisso, **prova a mano** (usa lo stesso utente di Apache; con `daemon` trovi uid/gid con `id daemon`):

```bash
sudo mount -t cifs //IP_DEL_NAS/portale_didattica /mnt/nas_portale \
  -o credentials=/root/.nas_portale,uid=$(id -u daemon),gid=$(id -g daemon),file_mode=0640,dir_mode=0750,vers=3.0
ls /mnt/nas_portale
sudo -u daemon touch /mnt/nas_portale/verbali/prova.txt && sudo -u daemon rm /mnt/nas_portale/verbali/prova.txt && echo "SCRITTURA OK"
```

Se dice `SCRITTURA OK`, rendi il montaggio permanente:

```bash
sudo umount /mnt/nas_portale
echo "//IP_DEL_NAS/portale_didattica /mnt/nas_portale cifs credentials=/root/.nas_portale,uid=$(id -u daemon),gid=$(id -g daemon),file_mode=0640,dir_mode=0750,vers=3.0,_netdev,nofail,x-systemd.automount,x-systemd.idle-timeout=0 0 0" | sudo tee -a /etc/fstab
sudo systemctl daemon-reload
sudo mount -a
df -h /mnt/nas_portale
```

`nofail` fa partire comunque il server se il NAS è spento; `x-systemd.automount` lo rimonta appena torna.

## 4. Spostare i file esistenti (orario tranquillo, es. sera)

### Metodo veloce: lo script (consigliato)
Lo script `strumenti/sposta_su_nas.sh` fa tutto il punto 4 per te, cartella per cartella, con controlli e ritorno indietro automatico se qualcosa non va. **Non fa parte del pacchetto** (le cartelle `strumenti/` non vanno sul server): copialo da solo.

```bash
# dal tuo PC (cartella del progetto)
scp strumenti/sposta_su_nas.sh UTENTE@160.97.37.188:/tmp/
# sul server
sudo bash /tmp/sposta_su_nas.sh --prova      # mostra cosa farebbe, non cambia nulla
sudo bash /tmp/sposta_su_nas.sh              # sposta tutte le cartelle riservate
```

Predefiniti: portale in `/opt/lampp/htdocs/didattica`, NAS in `/mnt/nas_nuovo/portale_didattica`, utente di Apache `daemon`. Se sono diversi: `sudo SITO=... NAS=... WEB=... bash /tmp/sposta_su_nas.sh`.
Si può anche scegliere: `sudo bash /tmp/sposta_su_nas.sh pratiche convenzioni`. Per tornare indietro su una cartella: `sudo bash /tmp/sposta_su_nas.sh --ripristina pratiche`.
Fai prima un backup manuale (Sistema → Backup → «Esegui ora») e **aspetta che finisca**. Dopo lo script vai al punto 5 (prove).

### Metodo a mano (se preferisci vedere ogni passo)

Cartella del portale: `/opt/lampp/htdocs/didattica`. Fai prima un backup manuale (`Sistema → Backup → Esegui ora`) e **aspetta che finisca**.

Per **ogni** cartella della lista (esempio con `verbali`; ripeti cambiando il nome):

```bash
cd /opt/lampp/htdocs/didattica/uploads
# 1) copia sul NAS (conserva date e permessi non servono: li decide il montaggio)
sudo rsync -rt --info=progress2 verbali/ /mnt/nas_portale/verbali/
# 2) controllo: non deve elencare differenze
sudo rsync -rcn --itemize-changes verbali/ /mnt/nas_portale/verbali/
# 3) metti da parte la cartella vecchia e collega quella del NAS
sudo mv verbali verbali.vecchia
sudo ln -s /mnt/nas_portale/verbali verbali
ls -la verbali | head
```

Se `uploads/<cartella>` non esiste ancora (cartella mai usata), salta rsync e fai solo `ln -s`.
Le cartelle `.htaccess` di protezione (se c'erano in `verbali.vecchia`) copiale nella cartella del NAS: `sudo cp -a verbali.vecchia/.htaccess /mnt/nas_portale/verbali/` (il comando rsync sopra le copia già).

> Non spostare mai `uploads/.htaccess` (quello della radice): resta nel server.

## 5. Provare che funziona (prima di cancellare qualunque cosa)

1. Pannello → apri un **verbale** esistente e scarica il PDF.
2. Apri una **pratica di uno studente con allegato** e scaricalo.
3. Carica un file nuovo (allegato a una pratica di prova) e scaricalo. Sul NAS (File Station) lo ritrovi in `portale_didattica/pratiche/`.
4. Una convenzione di tesi o FSL: scarica il Word/PDF.
5. Se qualcosa non va: `sudo tail -n 30 /opt/lampp/logs/error_log`. Errori «Permission denied» → controlla uid/gid del punto 3.

Per **2 settimane** tieni le cartelle `*.vecchia`. Poi, dopo aver verificato che gli snapshot del NAS funzionano, le cancelli: `sudo rm -rf uploads/*.vecchia`.

## 6. Tornare indietro (se serve)

```bash
cd /opt/lampp/htdocs/didattica/uploads
sudo rm verbali            # è solo il collegamento
sudo mv verbali.vecchia verbali
```
Ma se nel frattempo sono arrivati file nuovi, prima copiali dal NAS: `sudo rsync -rt /mnt/nas_portale/verbali/ verbali.vecchia/`.

## 7. Cosa controlla il portale (già pronto)

- In **Sistema → Controllo del sito** compare una riga per ogni cartella spostata sul NAS: «File sul NAS: uploads/pratiche → … (raggiungibile e scrivibile)». Se il NAS è spento o non montato, o non si può scrivere, il controllo notturno (dalle 3) manda un'email agli amministratori. Con il pulsante del controllo lo vedi subito.
- (Idee per dopo, non ancora fatte:)
- Il backup notturno non scarica più i file dal NAS (non segue i collegamenti): l'archivio contiene database e codice; i file hanno gli snapshot del NAS. Il report settimanale segnala se il NAS non risponde.
- Messaggio chiaro agli utenti se un file non si apre perché il NAS è fuori linea (invece di una pagina vuota).

## 8. Dati personali

I file con dati degli studenti (pratiche, tesi) restano comunque in una rete dell'Ateneo, quindi la sede cambia ma non esce dall'Ateneo. Se il NAS è condiviso con altri, mantieni l'utente `portale` limitato a questa cartella e non dare accesso ad altri utenti. Aggiorna, se serve, l'elenco delle sedi di conservazione dell'informativa privacy.


## 9. Archivio verbali sul NAS: i verbali vecchi si copiano e il portale li legge da soli

Funziona **già ora** (anche senza spostare le altre cartelle sul NAS) e funziona meglio con il NAS: la cartella dei verbali vecchi è `uploads/verbali/archivio/`. Se hai fatto il punto 4 per `verbali`, quella cartella sta sul NAS in `portale_didattica/verbali/archivio/`.

**Come si organizza.** Una cartella per corso di studi; dentro, le cartelle per organo (facoltative) e per anno. Per esempio:

```
archivio/
  Biologia/                          ← verbali del Consiglio di corso di studi (con le cartelle degli anni: 2017, 2018, …)
    2017/ … 2025/
    Commissione didattica/2019/ …
    Gruppo di riesame/2020/ …
    Riunioni con gli studenti/2021/ …
  Chimica/ …
```

1. **Copia** le tue cartelle dei verbali (quelle con gli anni 2017…2025) dentro `archivio/`, una per corso. Su Windows basta il copia-incolla nella cartella di rete del NAS.
2. In **Didattica → Sedute e verbali → Archivio verbali → «Organi e cartelle sul NAS»**:
   - le cartelle trovate compaiono sotto «Cartelle trovate sul NAS da collegare»: per ognuna scegli a quale organo appartiene (il consiglio già esistente, oppure «Un organo nuovo») e premi **Collega**;
   - per un corso crei in un colpo i tre organi (commissione didattica, gruppo di riesame, riunioni con gli studenti) con **«Crea i tre organi»**; poi metti i PDF nelle loro cartelle;
   - puoi creare altri organi a mano (nome, tipo, corso, cartella).
3. Il portale **legge i PDF da solo ogni ora**; con **«Scansiona ora»** lo fai subito. I PDF non vengono copiati: restano sul NAS.
4. Data, numero del verbale e anno accademico si ricavano dal nome del file e dalla cartella (per esempio `Verbale_08_2019-03-12.pdf`, `2024-2025/…`) o dal testo del PDF. Quelli incompleti compaiono in «Da completare» e si correggono con la matita.
5. **Quando cambi qualcosa sul NAS:** un file sostituito si rilegge; un file spostato in un'altra cartella tiene i dati già corretti; un file cancellato sparisce dall'archivio alla scansione successiva (il portale non cancella mai i file del NAS). Se il NAS è spento o non montato il portale non toglie nulla.
6. Nell'archivio puoi filtrare per consiglio o per altro organo. Gli altri organi (commissione, riesame, studenti) li vede solo l'Ufficio didattico.

Limiti: file fino a 30 MB, percorso (cartelle + nome) non oltre 230 caratteri circa; vengono ignorati i file che non sono PDF e le cartelle di sistema del NAS (`@eaDir`, `#recycle`).

### Collegamento automatico (consigliato)
Se le cartelle sono fatte come «Corso / Organo / anno» (per esempio `VERBALI_GEOLOGIA/CONSIGLIO_CORSO_DI_STUDIO/2019/…`, `VERBALI_GEOLOGIA/COMMISSIONE_DIDATTICA/2019/…`), nella sezione «Organi per corso di studi e cartelle sul NAS» compare il riquadro **«Collegamento automatico: il portale propone»**: per ogni cartella dice se diventa il consiglio già nel portale (abbinato per nome) o un organo nuovo, con tipo e corso di studi. Se la proposta è giusta, **«Collega tutto come proposto»**. Non legge ancora i PDF: li leggi tu con «Scansiona ora» (o ogni ora in automatico).

**Cartelle di verbali con allegati.** Se un verbale ha molti allegati lo metti in una cartella dentro l'anno, per esempio `2025/Verb. CCL_02-10-2025/`. Il portale la tratta come **un solo verbale**: il PDF che ha lo stesso nome della cartella (o che comincia per «Verb» / contiene «verbale») è il verbale, e **tutti gli altri file della cartella** — PDF, Excel, Word, anche in sottocartelle — sono i suoi **allegati**: nell'elenco compare «📎 N allegati» e si scarica ciascuno. Quando aggiungi o togli un allegato sul NAS, la scansione successiva aggiorna l'elenco. I PDF sciolti il cui nome comincia per «Allegato» (fuori da una cartella di verbale) e le cartelle che contengono solo allegati non diventano verbali: li trovi nell'avviso azzurro/giallo dopo la scansione. Gli altri PDF che stanno nella cartella dell'anno (per esempio i rapporti di riesame) entrano come verbali «da completare».

**Pausa.** Con «Sospendi la scansione automatica» il portale non legge più nulla da solo ogni ora (resta «Scansiona ora»): utile mentre sistemi le cartelle. Con «Riattiva» riparte.
