// assets/js/busy.js
//
// Every form, on every page: once it is sent, the button that sent it shows a spinner and the
// form's submit buttons are disabled until the next page loads, so a slow save (sending an SMS,
// making a receipt, uploading a screenshot) can't be sent twice by a second click or Enter.
//
// A form can opt out with data-no-busy. Forms that start a download and stay on the page can
// set data-busy-reset="<ms>" to get their buttons back after that long.
(function () {
    'use strict';

    function setBusy(form, submitter) {
        form.dataset.busy = '1';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach(function (btn) {
            btn.dataset.busyWasDisabled = btn.disabled ? '1' : '';
            btn.disabled = true;
        });
        if (!submitter) return;
        if (submitter.tagName === 'INPUT') {
            submitter.dataset.busyLabel = submitter.value;
            submitter.value = 'Please wait...';
            return;
        }
        // Same width as before, so the layout doesn't jump; the spinner replaces the icon.
        submitter.style.minWidth = submitter.offsetWidth + 'px';
        submitter.dataset.busyLabel = submitter.innerHTML;
        var text = submitter.textContent.trim();
        submitter.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'
                            + (text ? '<span></span>' : '<span class="visually-hidden">Loading</span>');
        if (text) submitter.lastChild.textContent = text;
    }

    function clearBusy(form) {
        delete form.dataset.busy;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button, input[type="submit"]').forEach(function (btn) {
            if (btn.dataset.busyLabel !== undefined) {
                if (btn.tagName === 'INPUT') btn.value = btn.dataset.busyLabel;
                else btn.innerHTML = btn.dataset.busyLabel;
                btn.style.minWidth = '';
                delete btn.dataset.busyLabel;
            }
            if (btn.dataset.busyWasDisabled !== undefined) {
                btn.disabled = btn.dataset.busyWasDisabled === '1';
                delete btn.dataset.busyWasDisabled;
            }
        });
    }

    // Bubbling listener on the document: runs after the form's own onsubmit (e.g. a confirm()),
    // so a cancelled confirm leaves the buttons alone.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy')) return;
        if (form.dataset.busy) {
            e.preventDefault();   // already on its way
            return;
        }
        var submitter = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
        // Disabled on the next tick: the clicked button's name/value (e.g. decision=approve) is
        // read into the request as part of this submit, which a disabled button would drop.
        setTimeout(function () {
            setBusy(form, submitter);
            var reset = parseInt(form.dataset.busyReset || '', 10);
            if (reset > 0) setTimeout(function () { clearBusy(form); }, reset);
        }, 0);
    });

    // Back button: the browser may show the page as it was left, spinners and all.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) document.querySelectorAll('form[data-busy]').forEach(clearBusy);
    });
})();
