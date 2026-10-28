# Guida — Modello linguistico aperto sul server (Didacticus)

Per far rispondere Didacticus con un modello linguistico **che gira sul server dell'Ateneo** (nessun dato esce). Facoltativo: senza queste operazioni Didacticus funziona a regole come sempre.

**Prima di cominciare:** il server ha 4 core, 15 GB di RAM e nessuna GPU. Una risposta richiede 20-40 secondi: per questo il modello interviene solo quando Didacticus non trova una voce sicura (modalità «incerto») e un solo calcolo alla volta. Provalo fuori dall'orario di punta e controlla che il sito non rallenti.

Servono i permessi di amministratore (`sudo`) sul server.

## 1. Installare Ollama

```bash
curl -fsSL https://ollama.com/install.sh | sudo sh
```

Crea un servizio (`ollama`) in ascolto solo su `127.0.0.1:11434`: non è raggiungibile da fuori. Se preferisci non eseguire lo script, scarica a mano il pacchetto da ollama.com.

## 2. Limitare la potenza usata e tenere il modello in memoria

```bash
sudo systemctl edit ollama
```

Nell'editor incolla (tra le righe di commento) e salva:

```ini
[Service]
Environment="OLLAMA_NUM_PARALLEL=1"
Environment="OLLAMA_MAX_LOADED_MODELS=1"
Environment="OLLAMA_KEEP_ALIVE=30m"
Environment="OLLAMA_HOST=127.0.0.1:11434"
CPUQuota=200%
Nice=10
```

```bash
sudo systemctl restart ollama
```

`CPUQuota=200%` lascia al modello al massimo 2 core su 4; `Nice=10` dà la precedenza al sito e al database.

## 3. Scaricare il modello

```bash
ollama pull llama3.2:3b
```

Circa 2 GB. Prova: `ollama run llama3.2:3b "Ciao, chi sei?"` (la prima volta carica il modello: mezzo minuto).

## 4. Collegare il portale

Nel file `.env` del portale (`/opt/lampp/htdocs/didattica/.env`) aggiungi:

```ini
LLM_URL=http://127.0.0.1:11434/v1
LLM_MODELLO=llama3.2:3b
LLM_TIMEOUT=45
LLM_MODALITA=incerto
```

Subito dopo, da **Gestione → Didacticus → Pagine di Ateneo** il portale mostra «modello collegato».

## 5. Le pagine di Ateneo come fonti

Da **Didacticus → Pagine di Ateneo** aggiungi gli indirizzi (solo `https://…unical.it`, pagine pubbliche, non PDF): sedute di laurea, tasse, calendari, segreterie, pagine del Dipartimento. Il portale le legge subito e poi ogni settimana (cron). La risposta cita la pagina con il collegamento.

## 6. Come spegnere

Cancella (o lascia vuoto) `LLM_URL` nel `.env`: Didacticus torna subito a regole. Per fermare del tutto il servizio: `sudo systemctl disable --now ollama`.

## 7. Cosa controllare nei primi giorni

- Il sito non rallenta (`top` mostra `ollama` sotto il 200%).
- Le risposte «preparate automaticamente» in **Chat di assistenza → Con Didacticus**: le sbagliate diventano nuove voci in **Didacticus → Domande e risposte**.
- Se il modello non risponde in 45 secondi o è occupato, la chat riceve la risposta a regole: nel registro degli errori di PHP compaiono righe `[Didacticus] modello …`.

---

# Capire il significato delle domande (embedding) — consigliato

Un piccolo modello (**bge-m3**, circa 1,2 GB) confronta il significato della domanda con quello delle voci. **Non scrive risposte**: mostra sempre il testo delle voci, quindi non può inventare. Nella prova su 20 domande parafrasate trova la voce giusta in 15 casi contro 7 delle sole regole. Funziona anche senza GPU: sul PC di prova ogni domanda richiede qualche decimo di secondo.

## 1. Scaricare il modello

Con Ollama già installato (vedi sopra):

```bash
ollama pull bge-m3
```

Prova di velocità sul server (la prima chiamata carica il modello in memoria):

```bash
time curl -s http://127.0.0.1:11434/api/embed -d '{"model":"bge-m3","input":["posso fare lo stage fuori dall Italia"]}' | head -c 100
time curl -s http://127.0.0.1:11434/api/embed -d '{"model":"bge-m3","input":["posso fare lo stage fuori dall Italia"]}' | head -c 100
```

La seconda deve richiedere meno di un secondo. Se anche la seconda è lenta, il server non regge: lascia spento.

## 2. Tenerlo in memoria

Senza istruzioni Ollama scarica il modello dopo 5 minuti di inattività e la prima domanda dopo una pausa deve ricaricarlo (qualche secondo: in quel caso Didacticus risponde a regole). Per tenerlo caricato (circa 1,2 GB di memoria):

```bash
sudo systemctl edit ollama
```

e nell'editor:

```ini
[Service]
Environment="OLLAMA_KEEP_ALIVE=24h"
Environment="OLLAMA_MAX_LOADED_MODELS=1"
```

```bash
sudo systemctl restart ollama
```

Se avevi scaricato anche un modello linguistico (llama3.2) puoi toglierlo per liberare spazio: `ollama rm llama3.2:3b`.

## 3. Collegare il portale

Nel `.env` del portale:

```ini
EMB_URL=http://127.0.0.1:11434
EMB_MODELLO=bge-m3
```

Poi **Gestione → Didacticus → Impostazioni → Aggiorna ora** calcola i vettori delle voci (circa 13 secondi per 80 voci sul PC di prova). Da lì in poi si aggiornano da soli ogni notte (cron) solo per le voci nuove o cambiate.

## 4. Controllare

- In **Didacticus → Prova** scrivi una domanda con parole diverse da quelle delle voci: sotto compare la tabella «Le voci più vicine per significato».
- Soglie (facoltative, nel `.env`): `EMB_SOGLIA=0.62` (da qui risponde direttamente) e `EMB_SOGLIA_SUGGERISCI=0.50` (da qui propone «forse intendevi…»). Se sbaglia troppo alza la prima; se non propone mai niente abbassa la seconda.
- Se il servizio non risponde, Didacticus torna alle regole senza errori per chi scrive.

## 5. Come spegnere

Cancella `EMB_URL` dal `.env`.

