// Passkey / security key support. Plain script file (the page policy allows no inline script).
// Two jobs: register a key on the Security page, and answer a sign-in or confirm challenge.
(function () {
    'use strict';

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function b64uToBuf(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = atob(s), buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }

    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf), bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body === undefined ? '{}' : JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (!r.ok) {
                    if (data.error === 'confirm' && data.redirect) { window.location = data.redirect; }
                    throw new Error(data.error && data.error !== 'confirm' ? data.error : 'Something went wrong. Please try again.');
                }
                return data;
            });
        });
    }

    function describeError(e) {
        if (e && e.name === 'NotAllowedError') return 'The request was cancelled or timed out.';
        return (e && e.message) || 'Something went wrong. Please try again.';
    }

    function prepareDescriptors(list) {
        return (list || []).map(function (d) { d.id = b64uToBuf(d.id); return d; });
    }

    function credentialToJson(cred) {
        var r = cred.response, out = {
            id: cred.id,
            rawId: bufToB64u(cred.rawId),
            type: cred.type,
            response: { clientDataJSON: bufToB64u(r.clientDataJSON) }
        };
        if (r.attestationObject) {
            out.response.attestationObject = bufToB64u(r.attestationObject);
            if (r.getTransports) out.response.transports = r.getTransports();
        } else {
            out.response.authenticatorData = bufToB64u(r.authenticatorData);
            out.response.signature = bufToB64u(r.signature);
            out.response.userHandle = r.userHandle ? bufToB64u(r.userHandle) : null;
        }
        if (cred.authenticatorAttachment) out.authenticatorAttachment = cred.authenticatorAttachment;
        return out;
    }

    function show(el, text) { if (el) el.textContent = text || ''; }

    function supported() {
        return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
    }

    // Reveal passkey controls only where the browser can use them.
    if (supported()) {
        document.querySelectorAll('[data-passkey-only]').forEach(function (el) { el.hidden = false; });
    }

    // Security page: add a passkey.
    var add = document.querySelector('[data-passkey-add]');
    if (add) {
        add.addEventListener('click', function () {
            var err = document.querySelector('[data-passkey-error]');
            var name = (document.querySelector('[data-passkey-name]') || {}).value || '';
            show(err, '');
            add.disabled = true;
            post(add.dataset.optionsUrl).then(function (opts) {
                opts.challenge = b64uToBuf(opts.challenge);
                opts.user.id = b64uToBuf(opts.user.id);
                opts.excludeCredentials = prepareDescriptors(opts.excludeCredentials);
                return navigator.credentials.create({ publicKey: opts });
            }).then(function (cred) {
                return post(add.dataset.storeUrl + '?name=' + encodeURIComponent(name), credentialToJson(cred));
            }).then(function (data) {
                window.location = data.redirect;
            }).catch(function (e) {
                show(err, describeError(e));
                add.disabled = false;
            });
        });
    }

    // Sign-in and confirm pages: use a passkey.
    var use = document.querySelector('[data-passkey-use]');
    if (use) {
        use.addEventListener('click', function () {
            var err = document.querySelector('[data-passkey-error]');
            show(err, '');
            use.disabled = true;
            post(use.dataset.optionsUrl).then(function (opts) {
                opts.challenge = b64uToBuf(opts.challenge);
                opts.allowCredentials = prepareDescriptors(opts.allowCredentials);
                return navigator.credentials.get({ publicKey: opts });
            }).then(function (cred) {
                return post(use.dataset.verifyUrl, credentialToJson(cred));
            }).then(function (data) {
                window.location = data.redirect;
            }).catch(function (e) {
                show(err, describeError(e));
                use.disabled = false;
            });
        });
    }
})();
