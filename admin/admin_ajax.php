/**
 * admin_ajax.js — Shared AJAX + UX utilities for PBI Admin sub-pages
 * Include this in every iframe page ONCE, at the bottom of <body>.
 */

/* ═══════════════════════════════════════════════════════════
   1. TOAST  (no reload required)
   ═══════════════════════════════════════════════════════════ */
window.PBI = window.PBI || {};

PBI.toast = function (msg, type = 'success') {
    const existing = document.getElementById('pbi-toast');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.id = 'pbi-toast';
    const isSuccess = type === 'success';
    el.style.cssText = `
        position:fixed;top:20px;right:20px;z-index:9999;
        background:${isSuccess ? 'rgba(34,197,94,.15)' : 'rgba(240,84,84,.15)'};
        border:1px solid ${isSuccess ? 'rgba(34,197,94,.35)' : 'rgba(240,84,84,.35)'};
        color:${isSuccess ? '#6BCFA9' : '#E78D9C'};
        padding:13px 20px;border-radius:8px;font-size:13px;
        display:flex;align-items:center;gap:9px;
        box-shadow:0 6px 24px rgba(0,0,0,.4);
        font-family:'DM Sans',sans-serif;max-width:360px;
        animation:pbi-slideIn .28s ease;
    `;
    const icon = isSuccess ? 'fa-circle-check' : 'fa-circle-exclamation';
    el.innerHTML = `<i class="fa-solid ${icon}"></i><span>${msg}</span>`;
    document.body.appendChild(el);

    // inject keyframe once
    if (!document.getElementById('pbi-toast-style')) {
        const s = document.createElement('style');
        s.id = 'pbi-toast-style';
        s.textContent = `
            @keyframes pbi-slideIn{from{opacity:0;transform:translateX(18px)}to{opacity:1;transform:none}}
            @keyframes pbi-fadeOut{to{opacity:0;transform:translateX(18px);pointer-events:none}}
        `;
        document.head.appendChild(s);
    }

    setTimeout(() => {
        el.style.animation = 'pbi-fadeOut .35s ease forwards';
        setTimeout(() => el.remove(), 380);
    }, 3500);
};

/* ═══════════════════════════════════════════════════════════
   2. AJAX FETCH WRAPPER
   POST data (FormData or plain object) → JSON response
   ═══════════════════════════════════════════════════════════ */
PBI.post = async function (url, data) {
    let body;
    if (data instanceof FormData) {
        body = data;
    } else {
        body = new FormData();
        for (const [k, v] of Object.entries(data)) body.append(k, v);
    }
    const res  = await fetch(url, { method: 'POST', body });
    const json = await res.json();
    return json;          // { ok: bool, message: string, [extra fields] }
};

PBI.get = async function (url) {
    const res  = await fetch(url);
    const json = await res.json();
    return json;
};

/* ═══════════════════════════════════════════════════════════
   3. SPINNER  (shows inside a button while awaiting)
   ═══════════════════════════════════════════════════════════ */
PBI.spin = function (btn, on) {
    if (!btn) return;
    if (on) {
        btn._origHTML = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Processing…`;
    } else {
        btn.disabled  = false;
        btn.innerHTML = btn._origHTML || btn.innerHTML;
    }
};

/* ═══════════════════════════════════════════════════════════
   4. ROW HIGHLIGHT  (flash a table row after update)
   ═══════════════════════════════════════════════════════════ */
PBI.highlight = function (el, color = 'rgba(43,108,176,.22)') {
    if (!el) return;
    el.style.transition = 'background .1s';
    el.style.background = color;
    setTimeout(() => { el.style.background = ''; }, 900);
};

/* ═══════════════════════════════════════════════════════════
   5. CONFIRM DIALOG  (Promise-based, no blocking native confirm())
   Replaces window.confirm() everywhere so the browser never shows
   its own "this site says" / "localhost says" popup.
   Usage: if (!(await PBI.confirm('Delete this?'))) return false;
   Supports \n / \n\n for line breaks, same as native confirm() text.
   ═══════════════════════════════════════════════════════════ */
PBI.confirm = function (msg, opts = {}) {
    return new Promise(resolve => {
        const okLabel     = opts.okLabel     || 'Confirm';
        const cancelLabel = opts.cancelLabel || 'Cancel';
        const okColor     = opts.danger === false ? '#0F9F6E' : '#D6455D';
        // Theme-aware colours. admin_appearance.css repaints white inline backgrounds in dark
        // mode, which used to leave this dialog with dark text on a dark card.
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        const C = dark
            ? { bg:'#172A45', border:'rgba(255,255,255,.16)', text:'#E7ECF3', btnBg:'#0F1F3D', btnText:'#E7ECF3', shadow:'rgba(0,0,0,.55)' }
            : { bg:'#FFFFFF', border:'rgba(30,82,144,.13)', text:'#0B1F3A', btnBg:'#F8FAFC', btnText:'#0B1F3A', shadow:'rgba(30,82,144,.13)' };

        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10000;
            display:flex;align-items:center;justify-content:center;padding:20px;
            backdrop-filter:blur(4px);animation:pbi-slideIn .2s ease;
            font-family:'DM Sans',sans-serif;
        `;
        overlay.innerHTML = `
            <div style="background:${C.bg};border:1px solid ${C.border};
                        border-radius:14px;padding:28px 26px;max-width:400px;width:100%;
                        box-shadow:0 20px 60px ${C.shadow};">
                <p style="font-size:14px;color:${C.text};line-height:1.6;margin-bottom:22px;white-space:pre-line;"></p>
                <div style="display:flex;gap:10px;">
                    <button id="pbi-no"
                        style="flex:1;padding:10px;background:${C.btnBg};border:1px solid ${C.border};
                               border-radius:8px;color:${C.btnText};font-size:14px;font-weight:600;cursor:pointer;">
                        ${cancelLabel}
                    </button>
                    <button id="pbi-yes"
                        style="flex:1;padding:10px;background:${okColor};border:none;border-radius:8px;
                               color:#fff;font-size:14px;font-weight:600;cursor:pointer;">
                        ${okLabel}
                    </button>
                </div>
            </div>`;
        // set as text, not innerHTML, so user-provided names (docs, periods, etc.) can't inject markup
        overlay.querySelector('p').textContent = msg;
        document.body.appendChild(overlay);
        overlay.querySelector('#pbi-yes').onclick = () => { overlay.remove(); resolve(true);  };
        overlay.querySelector('#pbi-no').onclick  = () => { overlay.remove(); resolve(false); };
        overlay.onclick = e => { if (e.target === overlay) { overlay.remove(); resolve(false); } };
    });
};