#!/usr/bin/env python3
"""Schermate per la guida alle scuole sull'iter della convenzione FSL (documentazione/schermate/fsl_*.png).

Prima: portale locale acceso (bash strumenti/locale/avvia.sh). Poi: python strumenti/locale/schermate_fsl.py
Porta la convenzione di una scuola di prova (prenotazione OP-PROVA001) a tappe con strumenti/locale/scenario_fsl.php e fotografa ogni tappa."""
import glob, json, os, subprocess, sys

radice = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
USCITA = os.path.join(radice, 'documentazione', 'schermate')
PHP = os.environ.get('PHP', r'C:\xampp\php\php.exe')
P = '/eventi/'


def php(tappa):
    r = subprocess.run([PHP, '-d', 'extension=zip', '-d', 'extension=gd', '-d', 'extension=intl', os.path.join(radice, 'strumenti', 'locale', 'scenario_fsl.php'), tappa], capture_output=True, text=True, cwd=radice)
    print(tappa, '->', (r.stdout or r.stderr).strip().splitlines()[-1] if (r.stdout or r.stderr).strip() else '')


def scatta(elenco):
    tmp = os.path.join(radice, 'cache', 'schermate_fsl.json')
    json.dump(elenco, open(tmp, 'w', encoding='utf8'), indent=1)
    subprocess.check_call([sys.executable, os.path.join(radice, 'strumenti', 'locale', 'schermate.py'), tmp, USCITA])


def pagina(nome, url, alto, larg=1280, utente=None, js=None):
    v = {'nome': nome, 'url': P + url, 'larghezza': larg, 'altezza': 1000, 'attesa': 1.4, 'clip': [0, 175, larg, alto]}
    if utente:
        v['utente'] = utente
    if js:
        v['js'] = js
    return v


def stato(nome, url, alto=330):
    """Il riquadro «Convenzione firmata» della pagina della scuola (sotto i documenti pronti)."""
    return {'nome': nome, 'url': P + url, 'larghezza': 1280, 'altezza': 1000, 'attesa': 1.4, 'clip': [150, 905, 980, alto]}


def area_fsl(nome, utente=4):
    """L'Area personale con la scheda «Programma FSL» aperta."""
    return {'nome': nome, 'utente': utente, 'url': P + 'area_personale.php', 'larghezza': 1280, 'altezza': 820, 'attesa': 1.4,
            'js': "document.getElementById('pills-fsl-tab').click(); setTimeout(function(){ document.getElementById('pills-fsl').scrollIntoView(); window.scrollBy(0, -150); }, 300);"}


def email_nuove(prima):
    """I file delle email arrivate dopo l'ultimo controllo (oggetto, nome del file)."""
    out = []
    for f in sorted(glob.glob(os.path.join(radice, 'cache', 'email_locali', '*.html'))):
        n = os.path.basename(f)
        if n not in prima:
            s = open(f, encoding='utf8').read()
            out.append((s.split('<title>')[1].split('</title>')[0], n))
    return out


def email_shot(nome, file):
    return {'nome': nome, 'url': '/__email?f=' + file, 'larghezza': 760, 'altezza': 620, 'attesa': 0.8}


def link():
    return json.load(open(os.path.join(radice, 'cache', 'scenario_fsl.json'), encoding='utf8'))['token']


vecchie = set()


def nuove():
    global vecchie
    tutte = {os.path.basename(f) for f in glob.glob(os.path.join(radice, 'cache', 'email_locali', '*.html'))}
    n = email_nuove(vecchie)
    vecchie = tutte
    return n


php('nuova')
vecchie = set()
# 1. Ricevuta e accesso alla convenzione
scatta([pagina('fsl_modulo.png', 'convenzione_online.php?code=OP-PROVA001', 1000)])
# 2. La scuola compila: documenti pronti
php('compila')
t = link()
em = nuove()
scatta([pagina('fsl_documenti_pronti.png', 'convenzione_online.php?t=%s' % t, 700), area_fsl('fsl_area_programma.png')])
for oggetto, f in em[:1]:
    scatta([email_shot('fsl_email_link.png', f)])
# 3. Carica il PDF firmato
php('carica')
nuove()
scatta([stato('fsl_firmata_caricata.png', 'convenzione_online.php?t=%s' % t, 420)])
# 4. Presa in carico e protocollo in entrata
php('presa')
em = nuove()
scatta([stato('fsl_presa_in_carico.png', 'convenzione_online.php?t=%s' % t)])
for oggetto, f in em:
    if 'presa in carico' in oggetto.lower():
        scatta([email_shot('fsl_email_presa.png', f)])
# 5. Il Direttore firma
php('firmata')
nuove()
scatta([stato('fsl_firmata_direttore.png', 'convenzione_online.php?t=%s' % t)])
# 6. Convenzione attiva
php('attiva')
em = nuove()
scatta([stato('fsl_attiva.png', 'convenzione_online.php?t=%s' % t), area_fsl('fsl_area_attiva.png')])
for oggetto, f in em:
    if 'attiva' in oggetto.lower():
        scatta([email_shot('fsl_email_attiva.png', f)])
print('fatto')
