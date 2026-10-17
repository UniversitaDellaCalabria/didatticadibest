# Prova della firma digitale vera: convenzioni di tesi (e convenzioni FSL)

In locale la firma PAdES di azienda e Direttore non si può provare (servono certificati e PDF firmati veri). Questa è la prova da fare **una volta sul server**, prima di dare il link a un'azienda. Dura circa 20 minuti. Serve: la tua firma digitale (ArubaSign, Dike, Acrobat… con certificato valido) e due email tue (una farà da «azienda»).

## 0. Prima di tutto: controlla un PDF firmato con lo strumento (2 minuti)
Su qualunque computer con PHP (anche il tuo, dalla cartella del progetto):

```
php strumenti/verifica_firma_pdf.php firmato.pdf
php strumenti/verifica_firma_pdf.php firmato_dal_direttore.pdf firmato_dall_azienda.pdf CODICEFISCALE
```

Dice con le stesse regole del portale se il file sarebbe accettato: presenza e formato della firma PAdES, se copre tutto il file, se è integra e, nel secondo caso, se è «il file dell'azienda con una firma in più» e se il certificato è del codice fiscale indicato.
Un PDF firmato con il tuo software e poi **non salvato di nuovo** deve dare tutte righe `[OK]`.

## 1. Prova intera sul portale
1. **Prepara il Direttore**: Gestione del portale → Uffici → Direzione deve avere il Direttore (o tu, per la prova) con l'email con cui accedi. Se vuoi che il controllo verifichi il certificato, nell'utente deve esserci il **codice fiscale** (compare dopo il primo accesso con SPID/CIE/SSO).
2. **Invita te stesso come azienda**: Didattica → Convenzioni di tesi → «Invita un'azienda» con la tua seconda email e il nome «PROVA FIRMA S.r.l.».
3. **Compila come azienda** dal link ricevuto (senza accedere: apri il link in una finestra privata). Prova anche a proporre la modifica di un articolo e a rispondere dal pannello.
4. **Scarica il PDF**, firmalo in **PAdES** (non CAdES / .p7m) e **non riaprirlo né salvarlo di nuovo**. Caricalo nel riquadro «Firma e ricarica».
   - Se dice «Nel PDF non trovo una firma PAdES» → hai usato il formato .p7m o un software che non incorpora la firma nel PDF.
   - Se dice «Il PDF è stato modificato dopo la firma» → il file è stato salvato di nuovo dopo la firma (di solito da un lettore PDF).
5. **Protocollo in entrata** dal pannello (numero di prova): il Direttore riceve l'email con il link.
6. **Firma del Direttore**: apri il link con l'accesso del Direttore. Due strade:
   - firma remota Aruba (se le variabili `ARUBA_*` sono nel `.env`): utente, password/PIN e OTP;
   - oppure scarica il PDF, firmalo in PAdES (stesso file dell'azienda, **una firma in più**) e caricalo.
   - Se dice «non corrisponde» → non hai firmato il file scaricato dal portale (o l'hai salvato di nuovo). Se dice che la firma è di un'altra persona → il certificato non è del codice fiscale del Direttore.
7. **Protocollo in uscita**: numero, data e validità → «convenzione stipulata». Scarica il PDF finale dall'archivio e **aprilo con Acrobat**: devono comparire le due firme (azienda e Direttore).
8. **Pulizia**: Didattica → Convenzioni di tesi → Archivio → elimina «PROVA FIRMA S.r.l.».

## Cosa controllare alla fine
- Acrobat (o il verificatore del tuo software di firma) mostra due firme valide, nell'ordine azienda → Direttore.
- Il Word e il PDF scaricati hanno il testo concordato degli articoli modificati e i dati del Direttore (nome, luogo e data di nascita) corretti.
- Le email sono arrivate: all'azienda (ricevuta, presa in carico, stipulata), al Direttore (da firmare), all'ufficio (da prendere in carico, firmata).
- Lo stesso vale per la **convenzione FSL**: la scuola carica il PDF firmato dal Dirigente (stesso controllo) e il Direttore firma dal link.

## Se qualcosa non va
Manda il PDF che il portale rifiuta (di prova, non con dati veri) con il messaggio esatto che compare: con `verifica_firma_pdf.php` si vede subito quale controllo non passa.
