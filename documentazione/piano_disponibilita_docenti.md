# Piano — Disponibilità dei docenti per tesi e tirocini

Richiesta del 28/10/2026: i docenti indicano dalla propria area personale se accettano tesisti e tirocinanti; nel sito pubblico un semaforo per ogni docente, in **due pagine separate** (`disponibilita_tesi.php`, `disponibilita_tirocini.php`), dove compare **solo chi ha messo la disponibilità**.

## Decisioni

- Bozze grafiche approvate nella chat del 28/10/2026: **elenco con semafori** per le tesi, **carte con barra dei posti** per i tirocini.
- Il docente sceglie i **corsi di laurea dall'anagrafe di Ateneo** (tabella `corsi_studio`); nessun corso scelto = tutti. Campi in più: posti totali e occupati, temi, attività previste, obiettivi formativi, laboratorio o sede, come candidarsi.
- **Il colore lo calcola il portale** dai posti liberi (`App\Disponibilita\Semaforo`): verde, arancione se resta un solo posto, rosso se completo, grigio se non accetta oppure se non è aggiornata da 90 giorni («da riconfermare»): un semaforo fermo non dice la verità.
- I tirocini si attivano su TSP 2.0: qui è solo disponibilità, con il collegamento alla piattaforma.
- Sulla pagina pubblica: nome, area scientifica, temi, semaforo, pulsante «Scrivi al docente» (email istituzionale dall'anagrafe). Mai i nomi degli studenti.

## Step

| Step | Cosa | Stato | Data |
|---|---|---|---|
| D1 | Tabella `disponibilita_docenti` (schema v98), `Semaforo`, `DisponibilitaRepository` | **fatto** | 28/10/2026 |
| D2 | `la_mia_disponibilita.php` e tessera «La mia disponibilità» (docenti e amministratore) | **fatto** | 28/10/2026 |
| D3 | Pagine pubbliche tesi e tirocini, filtri, voci nel catalogo del menu | **fatto** | 28/10/2026 |
| D4 | Prove, guida del docente, mappe, pacchetto per il server | **fatto** | 28/10/2026 |
| D5 | Promemoria ai docenti con disponibilità da riconfermare | da decidere | |

## Da sapere

- Le pagine pubbliche sono vuote finché i docenti non inseriscono i dati.
- L'elenco dei corsi di laurea viene dall'anagrafe di Ateneo: serve che sia stato caricato (Gestione → Anagrafi).
