# -*- coding: utf-8 -*-
"""Genera le due mappe del portale in documentazione/mappa/: pubblico.html (per tutti) e tecnica.html (per sviluppatori).
Uso:  python strumenti/mappa/genera_mappe.py
Prima: le schermate sono in documentazione/mappa/img/ (si rigenerano con strumenti/locale/schermate_mappa.php e si copiano/convertono in JPEG)."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from mappa_comune import pagina, USCITA
import mappa_pubblica, mappa_tecnica

os.makedirs(USCITA, exist_ok=True)

pub = mappa_pubblica.costruisci()
open(os.path.join(USCITA, 'pubblico.html'), 'w', encoding='utf8').write(pagina(
    'Didattica DiBEST: cos\'è e cosa fa il portale', 'Guida alla piattaforma',
    'Eventi, scuole, pratiche degli studenti, verbali e prenotazioni di aule: tutto in un posto solo, con un solo accesso.',
    [('cose', 'Cos\'è', False), ('per-chi', 'Per chi è', False), ('fsl', 'Formazione Scuola Lavoro', True), ('pratiche', 'Pratiche studenti', True)],
    pub, ('tecnica.html', 'Versione tecnica per sviluppatori'),
    'Che cos\'è il portale Didattica DiBEST e che cosa permette di fare, spiegato con le schermate.'))

tec = mappa_tecnica.costruisci()
open(os.path.join(USCITA, 'tecnica.html'), 'w', encoding='utf8').write(pagina(
    'Didattica DiBEST: mappa tecnica', 'Documentazione per sviluppatori',
    'Architettura, oggetti, funzioni, database, flussi, sicurezza, test e rilascio del portale, con le schermate delle parti principali.',
    [('architettura', 'Architettura', False), ('core', 'Core e container', False), ('db', 'Database', True), ('flussi', 'Flussi chiave', True)],
    tec, ('pubblico.html', 'Versione per il pubblico'),
    'Mappa tecnica del portale Didattica DiBEST: stack, architettura, classi, database, flussi, test e rilascio.'))

open(os.path.join(USCITA, 'index.html'), 'w', encoding='utf8').write("""<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Didattica DiBEST: mappa del portale</title>
<style>body{font-family:"Segoe UI",system-ui,Arial,sans-serif;margin:0;background:#f8fafc;color:#1e293b;display:flex;min-height:100vh;align-items:center;justify-content:center}
.b{max-width:760px;padding:30px 22px}h1{color:#8e0f1f;margin:0 0 8px}p{color:#475569}.g{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin-top:22px}
a.c{display:block;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;text-decoration:none;color:inherit}a.c:hover{border-color:#8e0f1f}a.c b{display:block;color:#8e0f1f;font-size:1.2rem;margin-bottom:6px}</style></head>
<body><div class="b"><h1>Didattica DiBEST</h1><p>La mappa del portale del Dipartimento di Biologia, Ecologia e Scienze della Terra, in due versioni.</p>
<div class="g"><a class="c" href="pubblico.html"><b>Per tutti</b>Che cos'è la piattaforma e cosa permette di fare, per chi la usa: studenti, scuole, aziende, docenti e uffici.</a>
<a class="c" href="tecnica.html"><b>Per sviluppatori</b>Architettura, classi, funzioni, database, flussi, sicurezza, test e rilascio.</a></div></div></body></html>""")
print('Mappe create in', USCITA)
