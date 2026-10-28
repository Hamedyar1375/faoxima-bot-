import { waitForSDK, waitForInitData, getInitData, diagnostics } from './telegram.js?v=0.0.52';
import { getToken, setToken, clearToken, setReseller } from './state.js?v=0.0.53';

const cfg = window.__APP_CONFIG__ || {};
const API_URL = cfg.apiUrl || '/api';

function getTenantHint() {
    try {
        const fromCfg = Number(cfg.tenant || 0);
        if (fromCfg > 0) return fromCfg;
    } catch (_) {}
    try {
        const v = Number(new URLSearchParams(location.search).get('tenant') || 0);
        if (v > 0) return v;
    } catch (_) {}
    return 0;
}


export async function configFileUrl(username, index = 0) {
    let dlToken = null;
    try {
        const res = await call('config_dl_token');
        dlToken = res && res.obj ? res.obj.dl_token : null;
    } catch (_) {
        dlToken = null;
    }
    if (!dlToken) {
        dlToken = getToken();
    }
    if (!dlToken) return null;
    const qs = new URLSearchParams({
        actions: 'config_file',
        username: String(username || ''),
        i: String(index),
        dl_token: dlToken,
    });
    const base = /^https?:\/\//i.test(API_URL) ? API_URL : `${location.origin}${API_URL}`;
    return `${base}/miniapp.php?${qs.toString()}`;
}

class ApiError extends Error {
    constructor(message, { status = 0, data = null, code = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
        this.code = code;
    }
}

async function readEnvelope(res) {
    let text = '';
    try {
        text = await res.text();
    } catch (_) {
        return { body: null, text: '', parseError: null };
    }
    if (!text) {
        return { body: null, text: '', parseError: null };
    }
    try {
        const body = JSON.parse(text);
        return { body, text, parseError: null };
    } catch (err) {
        return { body: null, text, parseError: err };
    }
}

export async function verify() {
    const sdkLoaded = await waitForSDK(4000);
    if (!sdkLoaded) {
        const diag = diagnostics();
        console.error('[verify] Telegram SDK never loaded', diag);
        throw new ApiError('Telegram SDK failed to load.', {
            status: 0,
            code: 'NO_SDK',
            data: { diag },
        });
    }

    let initData = await waitForInitData(1500);
    if (!initData) initData = getInitData();

    if (!initData) {
        const diag = diagnostics();
        console.error('[verify] initData empty', diag);
        throw new ApiError('initData unavailable. Open this page from Telegram.', {
            status: 0,
            code: 'NO_INIT_DATA',
            data: { diag },
        });
    }

    const url = `${API_URL}/verify.php`;
    const tenantHint = getTenantHint();
    let res;
    try {
        const headers = {
            'Content-Type': 'application/json',
            'X-Telegram-Init-Data': initData,
        };
        if (tenantHint > 0) headers['X-FX-Tenant'] = String(tenantHint);
        res = await fetch(url, {
            method: 'POST',
            headers,
            body: JSON.stringify(tenantHint > 0 ? { initData, tenant: tenantHint } : { initData }),
        });
    } catch (err) {
        console.error('[verify] network error', err);
        throw new ApiError('اتصال به سرور برقرار نشد.', { status: 0, code: 'NETWORK' });
    }

    const { body, text, parseError } = await readEnvelope(res);

    if (res.ok && body && body.status === true && body.token) {
        setToken(body.token);
        // FX-RESELLER-SVCMGMT: remember reseller self-service context (if any).
        try {
            setReseller(body.is_reseller_admin
                ? { reseller_id: Number(body.reseller_id) || 0, is_reseller_admin: true }
                : null);
        } catch (_) {}
        return body.token;
    }

    if (res.ok && body && body.status === false && body.gate === 'phone_required') {
        throw new ApiError(body.msg || 'برای ادامه، شمارهٔ موبایل خود را تأیید کنید.', {
            status: res.status,
            code: 'PHONE_REQUIRED',
            data: body,
        });
    }

    if (res.ok && body && body.status === false && body.gate === 'force_join') {
        throw new ApiError(body.msg || 'برای استفاده از مینی‌اپ، ابتدا در کانال‌های زیر عضو شوید.', {
            status: res.status,
            code: 'FORCE_JOIN',
            data: body,
        });
    }

    let msg;
    let code = null;

    if (body && typeof body.msg === 'string' && body.msg !== '') {
        msg = body.msg;
    } else if (parseError) {
        msg = `پاسخ سرور قابل خواندن نبود (HTTP ${res.status}).`;
        code = 'BAD_JSON';
    } else if (res.ok && !text) {
        msg = `پاسخ سرور خالی بود (HTTP ${res.status}).`;
        code = 'EMPTY_BODY';
    } else if (body && body.status === true && !body.token) {
        msg = `سرور توکن صادر نکرد (HTTP ${res.status}).`;
        code = 'NO_TOKEN';
    } else {
        msg = `Verification failed (${res.status})`;
    }

    const data = body !== null
        ? body
        : (text ? { rawBody: text.length > 500 ? text.slice(0, 500) + '…' : text } : null);

    throw new ApiError(msg, { status: res.status, code, data });
}

export async function submitPhone(contactResponse) {
    const sdkLoaded = await waitForSDK(4000);
    if (!sdkLoaded) {
        throw new ApiError('Telegram SDK failed to load.', { status: 0, code: 'NO_SDK' });
    }

    let initData = await waitForInitData(1500);
    if (!initData) initData = getInitData();
    if (!initData) {
        throw new ApiError('initData unavailable. Open this page from Telegram.', { status: 0, code: 'NO_INIT_DATA' });
    }

    if (typeof contactResponse !== 'string' || contactResponse === '') {
        throw new ApiError('اطلاعات شماره دریافت نشد.', { status: 0, code: 'NO_CONTACT' });
    }

    const url = `${API_URL}/phone.php`;
    let res;
    try {
        res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Telegram-Init-Data': initData,
            },
            body: JSON.stringify({ initData, contact_response: contactResponse }),
        });
    } catch (err) {
        throw new ApiError('اتصال به سرور برقرار نشد.', { status: 0, code: 'NETWORK' });
    }

    const { body, text, parseError } = await readEnvelope(res);

    if (res.ok && body && body.status === true && body.token) {
        setToken(body.token);
        return body.token;
    }

    let msg;
    if (body && typeof body.msg === 'string' && body.msg !== '') {
        msg = body.msg;
    } else if (parseError) {
        msg = `پاسخ سرور قابل خواندن نبود (HTTP ${res.status}).`;
    } else {
        msg = `تأیید شماره ناموفق بود (${res.status}).`;
    }

    const data = body !== null
        ? body
        : (text ? { rawBody: text.length > 500 ? text.slice(0, 500) + '…' : text } : null);

    throw new ApiError(msg, { status: res.status, code: 'PHONE_SUBMIT_FAILED', data });
}

export async function call(action, { method = 'GET', params = {}, body = null, retry = true, timeoutMs = 0 } = {}) {
    const token = getToken();
    if (!token) {
        await verify();
        return call(action, { method, params, body, retry: false, timeoutMs });
    }

    const qs = new URLSearchParams({ actions: action, ...params });
    const tenantHint = getTenantHint();
    if (tenantHint > 0) qs.set('tenant', String(tenantHint));
    const url = `${API_URL}/miniapp.php?${qs.toString()}`;

    const init = {
        method,
        headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json',
        },
    };
    if (tenantHint > 0) init.headers['X-FX-Tenant'] = String(tenantHint);

    if (method !== 'GET' && method !== 'HEAD') {
        const payload = { actions: action, ...(body || {}) };
        if (tenantHint > 0) payload.tenant = tenantHint;
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(payload);
    }

    let timer = null;
    if (timeoutMs > 0) {
        const controller = new AbortController();
        init.signal = controller.signal;
        timer = setTimeout(() => controller.abort(), timeoutMs);
    }

    let res;
    try {
        res = await fetch(url, init);
    } catch (err) {
        console.error(`[api:${action}] network error`, err);
        if (err && err.name === 'AbortError') {
            throw new ApiError('پاسخ سرور بیش از حد طول کشید.', { status: 0, code: 'TIMEOUT' });
        }
        throw new ApiError('اتصال به سرور برقرار نشد.', { status: 0, code: 'NETWORK' });
    } finally {
        if (timer) clearTimeout(timer);
    }

    const { body: envelope, text, parseError } = await readEnvelope(res);

    if (res.status === 401 || res.status === 403) {
        if (retry) {
            clearToken();
            try {
                await verify();
            } catch (vErr) {
                if (vErr && vErr.code === 'FORCE_JOIN' && typeof window.__handleForceJoinGate === 'function') {
                    window.__handleForceJoinGate(vErr);
                }
                throw vErr;
            }
            return call(action, { method, params, body, retry: false });
        }
    }

    if (!res.ok) {
        const msg = (envelope && envelope.msg) || `Request failed (${res.status})`;
        throw new ApiError(msg, {
            status: res.status,
            data: envelope !== null
                ? envelope
                : (text ? { rawBody: text.length > 500 ? text.slice(0, 500) + '…' : text } : null),
        });
    }

    if (parseError) {
        throw new ApiError(
            `پاسخ سرور قابل خواندن نبود (HTTP ${res.status}).`,
            {
                status: res.status,
                code: 'BAD_JSON',
                data: text ? { rawBody: text.length > 500 ? text.slice(0, 500) + '…' : text } : null,
            }
        );
    }

    if (envelope && envelope.status === false) {
        throw new ApiError(envelope.msg || 'Operation failed', { status: res.status, data: envelope });
    }

    return envelope || { status: true, obj: null };
}

export async function callForm(action, fields = {}, files = null, { retry = true } = {}) {

    const token = getToken();
    if (!token) {
        await verify();
        return callForm(action, fields, files, { retry: false });
    }

    const fd = new FormData();
    fd.append('actions', action);
    Object.keys(fields || {}).forEach((k) => {
        const v = fields[k];
        if (v !== undefined && v !== null) fd.append(k, String(v));
    });
    if (files) {
        const arr = (typeof files !== 'string' && files.length !== undefined) ? Array.from(files) : [files];
        arr.slice(0, 5).forEach((f) => { if (f) fd.append('media[]', f); });
    }

    const url = `${API_URL}/miniapp.php?actions=${encodeURIComponent(action)}`;

    let res;
    try {
        res = await fetch(url, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' },
            body: fd,
        });
    } catch (err) {
        throw new ApiError('اتصال به سرور برقرار نشد.', { status: 0, code: 'NETWORK' });
    }

    const { body: envelope, text, parseError } = await readEnvelope(res);

    if ((res.status === 401 || res.status === 403) && retry) {
        clearToken();
        await verify();
        return callForm(action, fields, files, { retry: false });
    }
    if (!res.ok) {
        const msg = (envelope && envelope.msg) || `Request failed (${res.status})`;
        throw new ApiError(msg, { status: res.status, data: envelope });
    }
    if (parseError) {
        throw new ApiError(`پاسخ سرور قابل خواندن نبود (HTTP ${res.status}).`, { status: res.status, code: 'BAD_JSON' });
    }
    if (envelope && envelope.status === false) {
        throw new ApiError(envelope.msg || 'Operation failed', { status: res.status, data: envelope });
    }
    return envelope || { status: true, obj: null };
}

export async function callBlob(action, { params = {}, retry = true } = {}) {
    const token = getToken();
    if (!token) {
        await verify();
        return callBlob(action, { params, retry: false });
    }
    const qs = new URLSearchParams({ actions: action, ...params });
    const url = `${API_URL}/miniapp.php?${qs.toString()}`;
    let res;
    try {
        res = await fetch(url, { headers: { 'Authorization': `Bearer ${token}` } });
    } catch (err) {
        throw new ApiError('اتصال به سرور برقرار نشد.', { status: 0, code: 'NETWORK' });
    }
    if ((res.status === 401 || res.status === 403) && retry) {
        clearToken();
        await verify();
        return callBlob(action, { params, retry: false });
    }
    if (!res.ok) {
        throw new ApiError(`Request failed (${res.status})`, { status: res.status });
    }
    return await res.blob();
}

// ==== FX-RESELLER-SVCMGMT: reseller self-service panel management ====
export function resellerSvcPanels() {
    return call('reseller_svc_panels', { method: 'GET' });
}
export function resellerSvcUpdate(panel, field, value) {
    return call('reseller_svc_update', { method: 'POST', body: { panel: String(panel), field, value: String(value == null ? '' : value) } });
}
export function resellerSvcToggle(panel, field) {
    return call('reseller_svc_toggle', { method: 'POST', body: { panel: String(panel), field } });
}
// ==== end FX-RESELLER-SVCMGMT ====

export { ApiError };

