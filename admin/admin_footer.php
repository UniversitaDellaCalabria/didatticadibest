</div> <!-- Fine container-fluid -->
    </div> <!-- Fine page-content-wrapper -->
</div> <!-- Fine wrapper -->

<!-- Modal conferma azione globale (intercetta tutti i data-confirm) -->
<div class="modal fade" id="modConfirmAction" tabindex="-1" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" id="modConfirmContent">
            <div class="modal-header py-2" id="modConfirmHeader">
                <h6 class="modal-title fw-bold" id="modConfirmTitle"><i class="fa fa-exclamation-triangle me-1"></i> Conferma</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3 text-center">
                <p class="mb-0" id="modConfirmMsg"></p>
            </div>
            <div class="modal-footer py-2 justify-content-center gap-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fa fa-times me-1"></i> Annulla
                </button>
                <button type="button" class="btn btn-danger btn-sm fw-bold px-4" id="modConfirmBtn">
                    <i class="fa fa-check me-1"></i> Conferma
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo url_vendor('jsdelivr/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'); ?>" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('datatables/1.13.6/js/jquery.dataTables.min.js'); ?>" integrity="sha384-k5vbMeKHbxEZ0AEBTSdR7UjAgWCcUfrS8c0c5b2AfIh7olfhNkyCZYwOfzOQhauK" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('datatables/1.13.6/js/dataTables.bootstrap5.min.js'); ?>" integrity="sha384-PgPBH0hy6DTJwu7pTf6bkRqPlf/+pjUBExpr/eIfzszlGYFlF9Wi9VTAJODPhgCO" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('jsdelivr/npm/chart.js@4.4.0/dist/chart.umd.min.js'); ?>" integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('jsdelivr/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'); ?>" integrity="sha384-d3UHjPdzJkZuk5H3qKYMLRyWLAQBJbby2yr2Q58hXXtAGF8RSNO9jpLDlKKPv5v3" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('tinymce/6.8.6/tinymce.min.js'); ?>"></script>
<script src="../assets/js/campo-scuola.js?v=3"></script>
<script src="../assets/js/ricerca-personale.js?v=1"></script>
<script src="../assets/js/pannello.js?v=1"></script>

<script>
    // Editor completo (TinyMCE 6, MIT): formattazione, titoli, elenchi, tabelle, link, immagini (caricate sul sito o incollate), video, emoji, cerca/sostituisci, schermo intero, codice HTML
    tinymce.init({
        selector: 'textarea.editor-html', language: 'it', language_url: '<?php echo url_vendor('tinymce/6.8.6/langs/it.js'); ?>',
        base_url: '<?php echo url_vendor('tinymce/6.8.6'); ?>', suffix: '.min',
        plugins: 'advlist autolink lists link image media table charmap anchor searchreplace visualblocks code codesample fullscreen insertdatetime preview emoticons wordcount autoresize help pagebreak nonbreaking directionality quickbars accordion',
        menubar: 'file edit view insert format table tools help',
        toolbar: 'undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table | blockquote hr charmap emoticons | removeformat | fullscreen code help',
        toolbar_mode: 'sliding', quickbars_selection_toolbar: 'bold italic | quicklink h2 h3 blockquote', quickbars_insert_toolbar: false,
        block_formats: 'Paragrafo=p; Titolo 2=h2; Titolo 3=h3; Titolo 4=h4; Citazione=blockquote; Codice=pre',
        min_height: 320, max_height: 900, autoresize_bottom_margin: 20, branding: false, promotion: false, resize: true,
        relative_urls: false, convert_urls: false, link_default_target: '_blank', link_default_protocol: 'https', link_assume_external_targets: 'https',
        image_caption: true, image_advtab: true, image_title: true, automatic_uploads: true, paste_data_images: true,
        media_alt_source: false, media_poster: false, media_dimensions: true,
        content_style: 'body{font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:16px;line-height:1.6;padding:8px} img{max-width:100%;height:auto} table{border-collapse:collapse} td,th{border:1px solid #ccc;padding:4px 8px}',
        images_upload_handler: function (blob) {
            return new Promise(function (ok, no) {
                var f = new FormData();
                f.append('file', blob.blob(), blob.filename());
                f.append('csrf_token', <?php echo json_encode((string)(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->token()); ?>);
                fetch('editor_upload.php', { method: 'POST', body: f, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { if (j && j.location) ok(j.location); else no({ message: (j && j.errore) || 'Caricamento non riuscito.', remove: true }); })
                    .catch(function () { no({ message: 'Caricamento non riuscito.', remove: true }); });
            });
        }
    });
    // Campi brevi (card): solo formattazione in linea; il contatore conta il testo visibile
    tinymce.init({
        selector: 'textarea.editor-breve', menubar: false, statusbar: false, height: 130,
        toolbar: 'undo redo | bold italic underline | removeformat', valid_elements: 'strong/b,em/i,u,br,p',
        setup: function (ed) {
            var conta = function () {
                var out = ed.getElement().parentElement.querySelector('.desc-breve-conta');
                if (out) { var n = ed.getContent({ format: 'text' }).trim().length; out.textContent = n; out.parentElement.classList.toggle('text-danger', n > 300); }
            };
            ed.on('init input change keyup SetContent', conta);
        }
    });

    // ── Confirm modal globale (intercetta data-confirm su qualsiasi elemento) ───
    (function() {
        var _pendingAction = null;

        function showConfirm(msg, level, action) {
            var modal    = document.getElementById('modConfirmAction');
            if (!modal)  { if (window.confirm(msg)) action(); return; }

            var isDanger = /elimin|cancell|definitiv|irrev|rimuov/i.test(msg) || level === 'danger';
            var header   = document.getElementById('modConfirmHeader');
            var btn      = document.getElementById('modConfirmBtn');
            var title    = document.getElementById('modConfirmTitle');

            document.getElementById('modConfirmMsg').textContent = msg;

            if (isDanger) {
                header.className  = 'modal-header py-2 bg-danger text-white';
                btn.className     = 'btn btn-danger btn-sm fw-bold px-4';
                title.innerHTML   = '<i class="fa fa-trash me-1"></i> Sei sicuro?';
                btn.innerHTML     = '<i class="fa fa-trash me-1"></i> Sì, elimina';
            } else {
                header.className  = 'modal-header py-2 bg-warning text-dark';
                btn.className     = 'btn btn-warning text-dark btn-sm fw-bold px-4';
                title.innerHTML   = '<i class="fa fa-exclamation-triangle me-1"></i> Conferma';
                btn.innerHTML     = '<i class="fa fa-check me-1"></i> Sì, procedi';
            }

            _pendingAction = action;
            var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
            bsModal.show();
        }

        document.getElementById('modConfirmBtn').addEventListener('click', function() {
            bootstrap.Modal.getInstance(document.getElementById('modConfirmAction')).hide();
            document.getElementById('modConfirmAction').addEventListener('hidden.bs.modal', function once() {
                this.removeEventListener('hidden.bs.modal', once);
                if (_pendingAction) { _pendingAction(); _pendingAction = null; }
            });
        });

        // Intercetta click su qualsiasi elemento con data-confirm (fase di cattura)
        document.addEventListener('click', function(e) {
            var el = e.target.closest('[data-confirm]');
            if (!el) return;
            e.preventDefault();
            e.stopImmediatePropagation();

            var msg   = el.getAttribute('data-confirm');
            var level = el.getAttribute('data-confirm-level') || 'auto';

            showConfirm(msg, level, function() {
                if (el.tagName === 'A') {
                    window.location.href = el.href;
                } else {
                    var form = el.closest('form');
                    if (form) {
                        // I submit button con name= non vengono inclusi in form.submit()
                        if (el.name) {
                            var hi = document.createElement('input');
                            hi.type = 'hidden';
                            hi.name = el.name;
                            hi.value = (el.value !== undefined && el.value !== '') ? el.value : '1';
                            form.appendChild(hi);
                        }
                        form.submit();
                    }
                }
            });
        }, true);
    })();

    // Accessibilità: dà un nome ai pulsanti con la sola icona (titolo, altrimenti dall'icona)
    document.addEventListener('DOMContentLoaded', function () {
        var nomi = { 'fa-edit': 'Modifica', 'fa-pen': 'Modifica', 'fa-pencil': 'Modifica', 'fa-trash': 'Elimina', 'fa-trash-alt': 'Elimina',
            'fa-times': 'Chiudi', 'fa-xmark': 'Chiudi', 'fa-plus': 'Aggiungi', 'fa-plus-circle': 'Aggiungi', 'fa-bars': 'Menu',
            'fa-moon': 'Cambia tema', 'fa-sun': 'Cambia tema', 'fa-circle-half-stroke': 'Cambia tema', 'fa-magnifying-glass': 'Cerca',
            'fa-search': 'Cerca', 'fa-copy': 'Copia', 'fa-eye': 'Mostra', 'fa-eye-slash': 'Nascondi', 'fa-download': 'Scarica',
            'fa-print': 'Stampa', 'fa-arrow-up': 'Sposta su', 'fa-arrow-down': 'Sposta giù', 'fa-ellipsis-v': 'Altre azioni' };
        document.querySelectorAll('button, a.btn, .btn-close').forEach(function (b) {
            if (b.textContent.trim() || b.getAttribute('aria-label') || b.getAttribute('aria-labelledby')) return;
            var nome = b.getAttribute('title');
            if (!nome && b.classList.contains('btn-close')) nome = 'Chiudi';
            if (!nome) {
                var i = b.querySelector('i.fa, i[class*="fa-"]');
                if (i) i.classList.forEach(function (c) { if (!nome && nomi[c]) nome = nomi[c]; });
            }
            if (nome) b.setAttribute('aria-label', nome);
        });
    });

    $(document).ready(function() {
        // Logica apertura/chiusura Sidebar su mobile
        $('#sidebarToggle').on('click', function() {
            $('#sidebar').toggleClass('active');
            $('#sidebarOverlay').toggleClass('active');
        });
        $('#closeSidebar, #sidebarOverlay').on('click', function() {
            $('#sidebar').removeClass('active');
            $('#sidebarOverlay').removeClass('active');
        });
        
        // Pulizia URL se c'è un parametro status
        if (window.history.replaceState) {
            const url = new URL(window.location);
            if (url.searchParams.has('status')) { 
                url.searchParams.delete('status');
                window.history.replaceState({path:url.href}, '', url.href); 
            }
        }
    });
</script>
</body>
</html>
