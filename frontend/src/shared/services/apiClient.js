function getCsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : '';
}

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '');

export function apiFetch(input, init = {}) {
  const headers = new Headers(init.headers || {});
  headers.set('Accept', 'application/json');
  headers.set('X-Requested-With', 'XMLHttpRequest');
  const csrf = getCsrfToken();
  if (csrf) headers.set('X-XSRF-TOKEN', csrf);
  if (init.body && typeof init.body === 'string' && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
  const url = typeof input === 'string' && input.startsWith('/') ? `${apiBaseUrl}${input}` : input;
  return fetch(url, { ...init, headers, credentials: 'include' }).then((res) => {
    return res;
  });
}

export function apiFetchJson(url, options = {}) {
  return apiFetch(url, options).then(async (r) => {
    const data = await r.json();
    if (!r.ok) throw data;
    return data;
  });
}

export function toQueryString(params = {}) {
  const qs = new URLSearchParams(
    Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''))
  ).toString();
  return qs ? '?' + qs : '';
}
