# Tirocini di Scienze motorie: posti per azienda (30/10/2026)

Obiettivo: i tirocini di Scienze motorie sono tanti e ogni azienda ne può accogliere solo un numero limitato insieme (di partenza 2). Una pagina,
visibile dopo l'accesso e senza nomi, dice per ogni azienda se è **libera / ultimo posto / satura** e da quando si libera un posto.

## Regole decise
- Limite **per azienda**, per tutto il corso di Scienze motorie; lo sceglie chi compila (di partenza 2).
- Occupa il posto anche la richiesta **approvata ma non ancora cominciata**, fino alla data di fine.
- I tirocini **interni** («Università della Calabria») non si contano.
- L'azienda si sceglie dall'elenco delle **convenzionate** della piattaforma (Didattica › Tirocini).
- Pagina **con login**, **nessun nome** di studente.
- Compilano gli amministratori, chi lavora in Didattica e le persone dell'ufficio fisso «Ufficio tirocini (Scienze motorie)» (Gestione del portale › Uffici e persone).
- Se gli occupati superano il limite la pagina interna lo segnala, ma non blocca.

## Realizzato in locale (non committato, non caricato)
| Passo | Cosa | File |
|---|---|---|
| T1 | Tabelle `tirocini_motorie_posti` e `tirocini_motorie_periodi`, ufficio fisso `tirocini_motorie` (schema v105) | `Definizioni.php`, `Migrazioni.php` |
| T2 | Servizio: posti, periodi, stato libera/ultimo/satura, data in cui si libera, lettura e applicazione del report TSP | `src/Tirocini/ServizioMotorie.php` |
| T3 | Pannello Didattica › Tirocini › Scienze motorie (aggiungi azienda, posti, nota, tirocini, «concluso oggi», annulla, caricamento del report con controllo riga per riga) | `admin/tirocini_motorie.php`, menu in `admin_header.php` |
| T4 | Pagina per gli studenti (login, ricerca, «solo con posti liberi») | `tirocini_motorie.php` |
| T5 | Prove (24) | `strumenti/prove/oop/tirocini_motorie.php` |

## Limiti noti
- Il report TSP non ha l'ID dell'azienda: si ritrova dal nome (ignorando forma societaria e punteggiatura); se non coincide si sceglie a mano nella schermata di controllo.
- Il conteggio è «tirocini aperti oggi o futuri»: non verifica sovrapposizioni giorno per giorno (scelta prudente).
- Un tirocinio nuovo approvato sul TSP non arriva da solo: si carica il report o si scrive a mano.
