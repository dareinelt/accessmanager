/* UniFi Access Manager – global helper module (Vanilla JS, no dependencies). */
(function () {
    'use strict';

    // Single HTML-escaping helper (was duplicated in several views).
    function esc(value) {
        return String(value === undefined || value === null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function csrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    async function api(method, url, body) {
        const opts = {
            method: method,
            headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() },
        };
        if (body !== undefined && body !== null) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(url, Object.assign(opts, { credentials: 'same-origin' }));
        let data = null;
        try { data = await res.json(); } catch (e) { /* no body */ }
        if (!res.ok) {
            const msg = (data && data.error && data.error.message) || ('Fehler (HTTP ' + res.status + ')');
            throw new Error(msg);
        }
        return data ? data.data : null;
    }

    function toast(message, type) {
        type = type || 'success';
        const root = document.getElementById('toast-root');
        if (!root) { alert(message); return; }
        const el = document.createElement('div');
        el.className = 'toast toast-' + type;
        // Errors are announced assertively for screen readers.
        if (type === 'error') { el.setAttribute('role', 'alert'); }
        el.textContent = message;
        root.appendChild(el);
        setTimeout(() => el.remove(), type === 'error' ? 8000 : 4000);
    }

    let modalSeq = 0;

    // Accessible dialog: labelled title, Escape to close, focus moved into
    // the dialog, Tab kept inside and focus restored on close.
    function modal(title, bodyHTML, options) {
        options = options || {};
        const root = document.getElementById('modal-root');
        root.innerHTML = '';
        const previousFocus = document.activeElement;
        const titleId = 'modal-title-' + (++modalSeq);
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="' + titleId + '">' +
            '  <div class="modal-head"><h2 class="modal-title" id="' + titleId + '"></h2><button type="button" class="modal-close" aria-label="Schließen">&times;</button></div>' +
            '  <div class="modal-body"></div>' +
            '</div>';
        backdrop.querySelector('.modal-title').textContent = title;
        backdrop.querySelector('.modal-body').innerHTML = bodyHTML;
        const dialog = backdrop.querySelector('.modal');

        function focusable() {
            return Array.from(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
        }
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            if (e.key !== 'Tab') { return; }
            const items = focusable();
            if (!items.length) { return; }
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
        function close() {
            document.removeEventListener('keydown', onKey);
            backdrop.remove();
            if (previousFocus && typeof previousFocus.focus === 'function') { previousFocus.focus(); }
        }

        backdrop.querySelector('.modal-close').addEventListener('click', close);
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
        document.addEventListener('keydown', onKey);
        root.appendChild(backdrop);
        const items = focusable();
        const firstField = items.find(el => !el.classList.contains('modal-close'));
        (firstField || items[0] || dialog).focus();
        if (options.onMount) { options.onMount(dialog, close); }
        return { close: close, el: dialog };
    }

    function serialize(form) {
        const out = {};
        new FormData(form).forEach((value, key) => {
            if (out[key] === undefined) { out[key] = value; }
            else if (Array.isArray(out[key])) { out[key].push(value); }
            else { out[key] = [out[key], value]; }
        });
        return out;
    }

    // Build a JSON-ready object from a form, handling checkboxes, numbers
    // and multi-selects. Use this for [data-json-form] submissions.
    function formObject(form) {
        const out = {};
        for (const el of form.elements) {
            if (!el.name || el.disabled) { continue; }
            if (el.type === 'checkbox') { out[el.name] = el.checked; continue; }
            if (el.type === 'radio') { if (el.checked) { out[el.name] = el.value; } continue; }
            if (el.tagName === 'SELECT' && el.multiple) {
                out[el.name] = Array.from(el.selectedOptions).map(o => o.value);
                continue;
            }
            if (el.type === 'number') {
                const n = Number(el.value);
                out[el.name] = (el.value !== '' && Number.isFinite(n)) ? n : el.value;
                continue;
            }
            out[el.name] = el.value;
        }
        return out;
    }

    // Generic submit handler for [data-api-form]
    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('[data-api-form]');
        if (!form) { return; }
        e.preventDefault();
        const method = form.dataset.method || 'POST';
        const url = form.dataset.action || form.getAttribute('action');
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; }
        try {
            await api(method, url, serialize(form));
            toast('Gespeichert.');
            if (form.dataset.reload !== 'false') { window.location.reload(); }
        } catch (err) {
            toast(err.message, 'error');
            if (submitBtn) { submitBtn.disabled = false; }
        }
    });

    // Submit handler for [data-json-form]: sends a JSON body built with formObject.
    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('[data-json-form]');
        if (!form) { return; }
        e.preventDefault();
        const method = form.dataset.method || 'POST';
        const url = form.dataset.action || form.getAttribute('action');
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; }
        try {
            await api(method, url, formObject(form));
            toast('Gespeichert.');
            if (form.dataset.reload !== 'false') { window.location.reload(); }
        } catch (err) {
            toast(err.message, 'error');
            if (submitBtn) { submitBtn.disabled = false; }
        }
    });

    // Generic click handler for [data-api] buttons (confirm + call + reload).
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-api]');
        if (!btn) { return; }
        e.preventDefault();
        const method = btn.dataset.method || 'POST';
        const url = btn.dataset.api;
        let body = null;
        if (btn.dataset.body) {
            try { body = JSON.parse(btn.dataset.body); } catch (err) { body = null; }
        }
        if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) { return; }
        const original = btn.textContent;
        btn.disabled = true;
        try {
            await api(method, url, body);
            toast(btn.dataset.success || 'Erledigt.');
            if (btn.dataset.redirect) { window.location = btn.dataset.redirect; }
            else if (btn.dataset.reload !== 'false') { window.location.reload(); }
            else { btn.textContent = original; btn.disabled = false; }
        } catch (err) {
            toast(err.message, 'error');
            btn.textContent = original;
            btn.disabled = false;
        }
    });

    // Flash messages: success messages fade out, errors stay until closed
    // (UX FIX: error messages used to disappear after 6 s).
    window.addEventListener('DOMContentLoaded', () => {
        const flash = document.getElementById('flash');
        if (!flash) { return; }
        const closeBtn = flash.querySelector('.flash-close');
        if (closeBtn) { closeBtn.addEventListener('click', () => flash.remove()); }
        if (flash.dataset.autohide === 'true') { setTimeout(() => flash.remove(), 6000); }
    });

    window.UAM = { api: api, toast: toast, modal: modal, serialize: serialize, formObject: formObject, csrf: csrf, esc: esc };
})();
