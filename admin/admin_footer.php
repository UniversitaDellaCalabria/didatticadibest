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
<script src="<?php echo url_vendor('cdnjs/ajax/libs/tinymce/6.8.3/tinymce.min.js'); ?>" integrity="sha384-1Miaw0hyo/w0cd9ZHUnc7Z8ACgtO+lphAEziGNW4z2C1h3nJfMVVEWA5MI031P+X" crossorigin="anonymous"></script>
<script src="../assets/js/campo-scuola.js?v=1"></script>
<script src="../assets/js/ricerca-personale.js?v=1"></script>

<script>
    tinymce.init({ selector: 'textarea.editor-html', plugins: 'table lists link code', toolbar: 'undo redo | bold italic underline | alignleft aligncenter alignright | bullist numlist | link code', menubar: false, height: 200 });
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
