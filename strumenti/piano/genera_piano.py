#!/usr/bin/env python3
"""genera_piano.py - MIGRATION_PLAN.md in HTML stampabile (A4) e PDF.
Uso: python3 strumenti/piano/genera_piano.py   (serve: pip install markdown; per il PDF Chromium via Playwright o il browser: Stampa → Salva come PDF)
Scrive strumenti/piano/MIGRATION_PLAN.html (e .pdf se Playwright è disponibile)."""
import datetime, pathlib, re, subprocess, markdown

RADICE = pathlib.Path(__file__).resolve().parents[2]
QUI = pathlib.Path(__file__).resolve().parent
md = (RADICE / 'MIGRATION_PLAN.md').read_text(encoding='utf-8')
titolo = re.search(r'^# (.+)$', md, re.M).group(1)
md = re.sub(r'^# .+\n', '', md, count=1)
corpo = markdown.markdown(md, extensions=['tables', 'fenced_code', 'sane_lists', 'toc'])
oggi = datetime.date.today().strftime('%d/%m/%Y')

CSS = """
:root { --rosso:#b30000; --testo:#1e293b; --tenue:#64748b; --bordo:#cbd5e1; --fondo:#f1f5f9; }
* { box-sizing: border-box; }
body { font-family: "DejaVu Sans", "Segoe UI", Arial, sans-serif; color: var(--testo); background: #fff; font-size: 10pt; line-height: 1.45; margin: 0; }
.pagina { max-width: 190mm; margin: 0 auto; padding: 10mm 0; }
header.copertina { border-bottom: 3px solid var(--rosso); padding-bottom: 6mm; margin-bottom: 6mm; }
header.copertina .ente { color: var(--rosso); font-weight: 700; letter-spacing: .04em; text-transform: uppercase; font-size: 9pt; }
header.copertina h1 { font-size: 19pt; margin: 2mm 0 1mm; line-height: 1.2; }
header.copertina .meta { color: var(--tenue); font-size: 9pt; }
h2 { color: var(--rosso); font-size: 14pt; margin: 9mm 0 3mm; padding-bottom: 1mm; border-bottom: 1px solid var(--bordo); break-after: avoid; }
h3 { font-size: 11.5pt; margin: 6mm 0 2mm; break-after: avoid; }
p, li { orphans: 3; widows: 3; }
blockquote { margin: 0 0 4mm; padding: 2mm 4mm; background: #fef2f2; border-left: 4px solid var(--rosso); }
blockquote p { margin: 1mm 0; }
code { font-family: "DejaVu Sans Mono", Consolas, monospace; font-size: 8.4pt; background: var(--fondo); padding: 0 1mm; border-radius: 2px; }
pre { background: var(--fondo); border: 1px solid var(--bordo); border-radius: 3px; padding: 3mm; font-size: 8pt; line-height: 1.35; white-space: pre-wrap; word-break: break-word; break-inside: avoid; }
pre code { background: none; padding: 0; font-size: inherit; }
table { width: 100%; border-collapse: collapse; margin: 3mm 0 5mm; font-size: 8.4pt; }
th, td { border: 1px solid var(--bordo); padding: 1.4mm 2mm; vertical-align: top; text-align: left; }
th { background: var(--fondo); }
tr { break-inside: avoid; }
td code { word-break: break-word; }
@page { size: A4; margin: 14mm 12mm 16mm; }
@media print { .pagina { padding: 0; max-width: none; } a { color: inherit; text-decoration: none; } }
"""
html = f"""<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Piano di migrazione OOP</title><style>{CSS}</style></head>
<body><div class="pagina">
<header class="copertina"><div class="ente">Università della Calabria · Dipartimento di Biologia, Ecologia e Scienze della Terra</div>
<h1>{titolo}</h1><div class="meta">Portale Didattica DiBEST · ramo <code>feature/refactoring-oop</code> · versione del {oggi}</div></header>
{corpo}
</div></body></html>"""
out_html = QUI / 'MIGRATION_PLAN.html'
out_html.write_text(html, encoding='utf-8')
print('scritto', out_html)

# PDF con Chromium (Playwright) se disponibile
js = f"""
const {{ chromium }} = require('playwright');
(async () => {{ const b = await chromium.launch(); const p = await b.newPage();
  await p.goto('file://{out_html}'); await p.pdf({{ path: '{QUI / 'MIGRATION_PLAN.pdf'}', format: 'A4', printBackground: true,
    displayHeaderFooter: true, headerTemplate: '<span></span>',
    footerTemplate: '<div style="font-size:7pt;color:#64748b;width:100%;text-align:center;">Piano di migrazione OOP – Didattica DiBEST – pag. <span class=pageNumber></span> di <span class=totalPages></span></div>',
    margin: {{ top: '14mm', bottom: '16mm', left: '12mm', right: '12mm' }} }}); await b.close(); }})();
"""
try:
    subprocess.run(['node', '-e', js], check=True, env={**__import__('os').environ, 'NODE_PATH': '/opt/node-tools/node_modules'})
    print('scritto', QUI / 'MIGRATION_PLAN.pdf')
except Exception as e:
    print('PDF non generato (apri l\'HTML e usa Stampa → Salva come PDF):', e)
