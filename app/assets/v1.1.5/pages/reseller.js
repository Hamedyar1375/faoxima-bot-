import { call, resellerSvcPanels, resellerSvcUpdate, resellerSvcToggle } from '../api.js?v=0.0.53';
import { escapeHtml, skeletonList, toast } from '../utils.js?v=0.0.52';
import { setReseller, isResellerAdmin, getResellerId } from '../state.js?v=0.0.53';
import { icon } from '../icons.js?v=0.0.52';
import { hapticNotify } from '../telegram.js?v=0.0.52';

const SAVE_BTN = 'style="padding:11px 14px;font-size:13px;flex-shrink:0"';

function fieldRow({ id, label, iconHtml, inputType, value, placeholder, dir = 'ltr' }) {
    return `
        <div class="form-row" style="margin-bottom:0">
            <label>${iconHtml} ${label}</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input id="${id}" type="${inputType}" dir="${dir}" style="flex:1;min-width:0"
                       value="${escapeHtml(value)}" placeholder="${escapeHtml(placeholder)}" autocomplete="off">
                <button id="${id}-save" class="btn btn-primary" type="button" ${SAVE_BTN}>ذخیره</button>
            </div>
        </div>
    `;
}

function toggleButton({ id, on, onLabel, offLabel }) {
    return `<button id="${id}" class="btn btn-ghost" type="button"
                    style="padding:9px 12px;font-size:12px;border-color:${on ? 'var(--danger, #ff5050)' : 'var(--border-strong, var(--border))'}">
        ${icon(on ? 'powerOff' : 'power', 'class="ico"')}
        <span>${on ? offLabel : onLabel}</span>
    </button>`;
}

function panelCard(p) {
    const id = Number(p.id);
    const name = (p.name_panel !== '' && p.name_panel != null) ? String(p.name_panel) : ('پنل #' + id);
    const type = p.type || '—';
    const svcOn = String(p.status || '') === 'active';
    const extOn = String(p.status_extend || '') === 'on_extend';
    const url = String(p.url_panel || '');
    const uname = String(p.username_panel || '');
    const sub = (p.linksubx !== '' && p.linksubx != null) ? String(p.linksubx) : String(p.sublink || '');
    return `
        <article class="card" data-panel="${id}" style="margin-bottom:12px">
            <div class="card-body" style="padding:14px 18px 18px">
                <div class="row-spread" style="gap:8px;flex-wrap:wrap">
                    <div style="min-width:0">
                        <p class="section-title" style="margin:0">${icon('box')} ${escapeHtml(name)}</p>
                        <p class="muted" style="font-size:12px;margin:2px 0 0">ID: ${id} — ${escapeHtml(String(type))}</p>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        ${toggleButton({ id: 'tg-svc-' + id, on: svcOn, onLabel: 'روشن', offLabel: 'خاموش' })}
                        ${toggleButton({ id: 'tg-ext-' + id, on: extOn, onLabel: 'روشن', offLabel: 'خاموش' })}
                    </div>
                </div>
                <div class="row-spread mt-sm" style="font-size:12px">
                    <span class="muted">${icon('power', 'class="ico"')} سرویس: <b>${svcOn ? 'روشن' : 'خاموش'}</b></span>
                    <span class="muted">${icon('box', 'class="ico"')} فروش/تمدید: <b>${extOn ? 'روشن' : 'خاموش'}</b></span>
                </div>
                <div class="card-section" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
                    <p class="section-title">${icon('settings')} تنظیمات اتصال</p>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
                        ${fieldRow({ id: 'f-pass-' + id, label: 'رمز عبور جدید', iconHtml: icon('shield', 'class="ico"'), inputType: 'password', value: '', placeholder: 'رمز جدید را وارد کنید' })}
                        ${fieldRow({ id: 'f-user-' + id, label: 'نام کاربری', iconHtml: icon('user', 'class="ico"'), inputType: 'text', value: uname, placeholder: 'نام کاربری' })}
                        ${fieldRow({ id: 'f-url-' + id, label: 'آدرس اصلی پنل', iconHtml: icon('globe', 'class="ico"'), inputType: 'text', value: url, placeholder: 'https://panel.example.com' })}
                        ${fieldRow({ id: 'f-sub-' + id, label: 'دامنه ساب', iconHtml: icon('link', 'class="ico"'), inputType: 'text', value: sub, placeholder: 'https://sub.example.com/sub' })}
                    </div>
                    <p class="muted" style="font-size:11px;margin:10px 0 0;line-height:1.8">
                        تغییر رمز / نام کاربری / آدرس، نشست‌های قبلی پنل را باطل می‌کند. تغییرات بلافاصله اعمال می‌شوند.
                    </p>
                </div>
            </div>
        </article>
    `;
}

function renderPanels(host, panels) {
    if (!panels.length) {
        host.innerHTML = `
            <div class="empty">
                ${icon('box', 'class="ico ico-xxl ico-muted"')}
                <h3>پنلی یافت نشد</h3>
                <p class="muted">هنوز پنلی برای این نمایندگی تعریف نشده است.</p>
            </div>
        `;
        return;
    }
    host.innerHTML = panels.map(panelCard).join('');
}

async function reloadPanels(view) {
    const host = view.querySelector('#reseller-panels-host');
    if (!host) return;
    try {
        const res = await resellerSvcPanels();
        const panels = (res && res.obj && Array.isArray(res.obj.panels)) ? res.obj.panels : [];
        renderPanels(host, panels);
        bindPanels(view, panels);
    } catch (err) {
        host.innerHTML = `
            <div class="empty">
                ${icon('alert', 'class="ico ico-xxl ico-warn"')}
                <h3>خطا در دریافت پنل‌ها</h3>
                <p class="muted">${escapeHtml(err && err.message ? err.message : '')}</p>
            </div>
        `;
    }
}

function bindPanels(view, panels) {
    for (const p of panels) {
        const id = Number(p.id);

        const bindField = (inputId, field) => {
            const input = view.querySelector('#' + inputId);
            const btn = view.querySelector('#' + inputId + '-save');
            if (!input || !btn) return;
            btn.addEventListener('click', async () => {
                const value = input.value.trim();
                if (value === '') {
                    toast('مقدار را وارد کنید.', 'error');
                    return;
                }
                if (field === 'url' && !/^https?:\/\//i.test(value)) {
                    input.value = 'https://' + value.replace(/^\/+/, '');
                }
                const old = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'در حال ذخیره…';
                try {
                    await resellerSvcUpdate(id, field, input.value.trim());
                    hapticNotify('success');
                    toast('با موفقیت ذخیره شد.', 'success');
                    if (field === 'password') input.value = '';
                    await reloadPanels(view);
                } catch (err) {
                    hapticNotify('error');
                    toast(err && err.message ? err.message : 'ذخیره ناموفق بود.', 'error');
                    btn.disabled = false;
                    btn.textContent = old;
                }
            });
        };
        bindField('f-pass-' + id, 'password');
        bindField('f-user-' + id, 'username');
        bindField('f-url-' + id, 'url');
        bindField('f-sub-' + id, 'sublink');

        const bindToggle = (btnId, field) => {
            const btn = view.querySelector('#' + btnId);
            if (!btn) return;
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                try {
                    await resellerSvcToggle(id, field);
                    hapticNotify('success');
                    toast('وضعیت به‌روزرسانی شد.', 'success');
                    await reloadPanels(view);
                } catch (err) {
                    hapticNotify('error');
                    toast(err && err.message ? err.message : 'تغییر وضعیت ناموفق بود.', 'error');
                    btn.disabled = false;
                }
            });
        };
        bindToggle('tg-svc-' + id, 'status');
        bindToggle('tg-ext-' + id, 'status_extend');
    }
}

export async function reseller(view) {
    view.innerHTML = `
        <article class="card card-window card-bare">
            <header class="card-window-bar">
                <span class="dots"><span></span><span></span><span></span></span>
                <span class="window-url">faoxima/reseller</span>
            </header>
            <div class="card-body" id="reseller-body">
                ${skeletonList(4)}
            </div>
        </article>
    `;

    const $body = view.querySelector('#reseller-body');

    if (!isResellerAdmin()) {
        // FX-RESELLER-SVCMGMT: deep-link fallback — resolve reseller context from
        // user_info when verify() state is unavailable (e.g. frozen cache).
        try {
            const res = await call('user_info');
            const info = (res && res.obj) ? res.obj : null;
            if (info && info.is_reseller_admin) {
                setReseller({ reseller_id: Number(info.reseller_id) || 0, is_reseller_admin: true });
            }
        } catch (_) {}
    }

    try { if (window.__fxSyncResellerTab) window.__fxSyncResellerTab(); } catch (_) {}

    if (!isResellerAdmin()) {
        $body.innerHTML = `
            <div class="empty">
                ${icon('shield', 'class="ico ico-xxl ico-muted"')}
                <h3>دسترسی محدود</h3>
                <p class="muted">این بخش فقط برای مدیران ربات نمایندگی در دسترس است.</p>
                <a href="#/" class="btn btn-primary mt-md">${icon('home', 'class="ico ico-leading"')}<span>بازگشت به خانه</span></a>
            </div>
        `;
        return;
    }

    $body.innerHTML = `
        <div class="list-head">
            <p class="section-title">${icon('wrench')} مدیریت سرویس‌های من</p>
        </div>
        <p class="muted" style="font-size:12px;margin:2px 0 12px">
            نمایندگی: <b>${escapeHtml(String(getResellerId()))}</b> — تغییرات فقط روی پنل‌های خودتان اعمال می‌شود.
        </p>
        <div id="reseller-panels-host">
            ${skeletonList(3)}
        </div>
    `;

    await reloadPanels(view);
}
