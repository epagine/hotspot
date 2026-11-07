<?php declare(strict_types=1); ?>
<script>
(function () {
    var BUSY_LABEL = 'Processando…';

    function submitButtons(form) {
        return form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
    }

    function markBusy(btn) {
        if (!btn || btn.dataset.formBusy === '1') {
            return;
        }
        btn.dataset.formBusy = '1';
        btn.classList.add('is-busy');
        btn.setAttribute('aria-busy', 'true');
        if (btn.tagName === 'INPUT') {
            if (!btn.dataset.idleLabel) {
                btn.dataset.idleLabel = btn.value;
            }
            btn.value = BUSY_LABEL;
        } else {
            if (!btn.dataset.idleLabel) {
                btn.dataset.idleLabel = btn.textContent;
            }
            btn.textContent = BUSY_LABEL;
        }
        btn.disabled = true;
    }

    function lockForm(form, submitter) {
        if (!form || form.dataset.formBusy === '1') {
            return false;
        }
        form.dataset.formBusy = '1';
        submitButtons(form).forEach(markBusy);
        if (submitter && submitter.form === form) {
            markBusy(submitter);
        }
        return true;
    }

    function isPostForm(form) {
        if (!form || form.tagName !== 'FORM') {
            return false;
        }
        if (form.hasAttribute('data-no-busy')) {
            return false;
        }
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        return method === 'post';
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!isPostForm(form)) {
            return;
        }
        if (form.dataset.formBusy === '1') {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        lockForm(form, e.submitter || null);
    }, true);

    document.addEventListener('click', function (e) {
        var target = e.target;
        if (!target || !target.closest) {
            return;
        }
        var btn = target.closest('button[type="submit"], button:not([type]), input[type="submit"]');
        if (!btn || btn.type === 'button' || btn.type === 'reset') {
            return;
        }
        var form = btn.form || btn.closest('form');
        if (!isPostForm(form)) {
            return;
        }
        if (form.dataset.formBusy === '1' || btn.disabled) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);
})();
</script>
