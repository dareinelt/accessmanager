/* UniFi Access Manager – global helper module (Vanilla JS, no dependencies). */
(function () {
    'use strict';

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
        const res = await fetch(url, opts);
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
        el.textContent = message;
        root.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }

    function modal(title, bodyHTML, options) {
        options = options || {};
        const root = document.getElementById('modal-root');
        root.innerHTML = '';
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML =
            '<div class="modal" role="dialog" aria-modal="true">' +
            '  <div class="modal-head"><span></span><button type="button" class="modal-close" aria-label="Schließen">&times;</button></div>' +
            '  <div class="modal-body"></div>' +
            '</div>';
        backdrop.querySelector('.modal-head span').textContent = title;
        backdrop.querySelector('.modal-body').innerHTML = bodyHTML;
        const close = () => backdrop.remove();
        backdrop.querySelector('.modal-close').addEventListener('click', close);
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
        root.appendChild(backdrop);
        if (options.onMount) { options.onMount(backdrop.querySelector('.modal'), close); }
        return { close: close, el: backdrop.querySelector('.modal') };
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
            const body = formObject(form);
            if (typeof form.dataset.beforeSubmit === 'function') { /* placeholder */ }
            await api(method, url, body);
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

    // Auto-dismiss flash messages.
    window.addEventListener('DOMContentLoaded', () => {
        const flash = document.getElementById('flash');
        if (flash) { setTimeout(() => { flash.style.display = 'none'; }, 6000); }
    });

    window.UAM = { api: api, toast: toast, modal: modal, serialize: serialize, formObject: formObject, csrf: csrf };
})();
