const TOKEN_KEY = 'faoxima.token';
const buyDraftKey = 'faoxima.buyDraft';
const resellerKey = 'faoxima.reseller';

const memory = {
    user: null,
    settings: null,
    reseller: null,
};

export function getToken() {
    try { return sessionStorage.getItem(TOKEN_KEY) || ''; } catch (_) { return ''; }
}
export function setToken(token) {
    try { sessionStorage.setItem(TOKEN_KEY, token); } catch (_) {}
}
export function clearToken() {
    try { sessionStorage.removeItem(TOKEN_KEY); } catch (_) {}
}

export function getUser() { return memory.user; }
export function setUser(u) { memory.user = u; }


export function getBuyDraft() {
    try {
        const raw = sessionStorage.getItem(buyDraftKey);
        return raw ? JSON.parse(raw) : { step: 1 };
    } catch (_) { return { step: 1 }; }
}
export function setBuyDraft(draft) {
    try { sessionStorage.setItem(buyDraftKey, JSON.stringify(draft)); } catch (_) {}
}
export function clearBuyDraft() {
    try { sessionStorage.removeItem(buyDraftKey); } catch (_) {}
}

// FX-RESELLER-SVCMGMT: reseller self-service session state.
// { reseller_id: number, is_reseller_admin: boolean } or null for customers.
export function getReseller() {
    if (memory.reseller) return memory.reseller;
    try {
        const raw = sessionStorage.getItem(resellerKey);
        memory.reseller = raw ? JSON.parse(raw) : null;
    } catch (_) { memory.reseller = null; }
    return memory.reseller;
}
export function setReseller(r) {
    memory.reseller = r;
    try {
        if (r) sessionStorage.setItem(resellerKey, JSON.stringify(r));
        else sessionStorage.removeItem(resellerKey);
    } catch (_) {}
}
export function isResellerAdmin() {
    const r = getReseller();
    return !!(r && r.is_reseller_admin && Number(r.reseller_id) > 0);
}
export function getResellerId() {
    const r = getReseller();
    return r ? Number(r.reseller_id) || 0 : 0;
}

