import { apiFetchJson, apiFetch, toQueryString } from '../../shared/services/apiClient';

export const adminService = {
  // Dashboard
  dashboard: () => apiFetchJson('/api/admin/dashboard'),

  // System audit log
  logs: (params = {}) => apiFetchJson(`/api/admin/logs${toQueryString(params)}`),

  // Token usage
  usage: () => apiFetchJson('/api/admin/usage'),

  // Schema Metadata
  listMetadata: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return apiFetchJson(`/api/admin/schema/metadata${qs ? '?' + qs : ''}`);
  },
  updateMetadata: (id, payload) =>
    apiFetch(`/api/admin/schema/metadata/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  archiveMetadata: (id, hard = false) =>
    apiFetch(`/api/admin/schema/metadata/${id}?hard=${hard}`, { method: 'DELETE' }),
  discover: (clientId, options = {}) =>
    apiFetch('/api/admin/schema/discover', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ client_id: clientId, ...options }),
    }).then((r) => r.json()),
  importDescriptions: (entries, force = false) =>
    apiFetch('/api/admin/schema/import-descriptions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ entries, force }),
    }).then((r) => r.json()),

  // Clients
  listClients: (params = {}) => apiFetchJson(`/api/admin/clients${toQueryString(params)}`),
  getClient: (id) => apiFetchJson(`/api/admin/clients/${id}`),
  clientDashboard: (id, params = {}) => apiFetchJson(`/api/admin/clients/${id}/dashboard${toQueryString(params)}`),
  createClient: (payload) =>
    apiFetch('/api/admin/clients', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  updateClient: (id, payload) =>
    apiFetch(`/api/admin/clients/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  deleteClient: (id, password) =>
    apiFetch(`/api/admin/clients/${id}`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password }),
    }).then((r) => r.json()),
  deactivateClient: (id) =>
    apiFetch(`/api/admin/clients/${id}/deactivate`, { method: 'POST' }).then((r) => r.json()),
  reactivateClient: (id) =>
    apiFetch(`/api/admin/clients/${id}/reactivate`, { method: 'POST' }).then((r) => r.json()),
  testConnection: (dsn, username, password) =>
    apiFetch('/api/admin/clients/test-connection', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ dsn, username, password }),
    }).then((r) => r.json()),
  generateKeys: (id, password) =>
    apiFetch(`/api/admin/clients/${id}/keys/generate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password }),
    }).then((r) => r.json()),
  discoverUsers: (id) =>
    apiFetch(`/api/admin/clients/${id}/users/discover`, { method: 'GET' }).then((r) => r.json()),
  syncUsers: (id) =>
    apiFetch(`/api/admin/clients/${id}/users/sync`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
    }).then((r) => r.json()),
  deactivateUser: (clientId, userId) =>
    apiFetch(`/api/admin/clients/${clientId}/users/${userId}/deactivate`, { method: 'POST' }).then((r) => r.json()),
  activateUser: (clientId, userId) =>
    apiFetch(`/api/admin/clients/${clientId}/users/${userId}/activate`, { method: 'POST' }).then((r) => r.json()),

  // Business Context
  listBusinessContext: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return apiFetchJson(`/api/admin/business-context${qs ? '?' + qs : ''}`);
  },
  getBusinessContextConfig: () => apiFetchJson('/api/admin/business-context/config'),
  getBusinessContext: (id) => apiFetchJson(`/api/admin/business-context/${id}`),
  createBusinessContext: (payload) =>
    apiFetch('/api/admin/business-context', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  updateBusinessContext: (id, payload) =>
    apiFetch(`/api/admin/business-context/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  deleteBusinessContext: (id) =>
    apiFetch(`/api/admin/business-context/${id}`, { method: 'DELETE' }).then((r) => r.json()),

  // Users
  listUsers: (type) => {
    const qs = type ? `?type=${type}` : '';
    return apiFetchJson(`/api/admin/users${qs}`);
  },
  getUser: (id) => apiFetchJson(`/api/admin/users/${id}`),
  createUser: (payload) =>
    apiFetch('/api/admin/users', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  updateUser: (id, payload) =>
    apiFetch(`/api/admin/users/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  deleteUser: (id) =>
    apiFetch(`/api/admin/users/${id}`, { method: 'DELETE' }).then((r) => r.json()),

  // Permission Tokens
  listPermissionTokens: () => apiFetchJson('/api/admin/permission-tokens'),
  createPermissionToken: (payload) =>
    apiFetch('/api/admin/permission-tokens', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  updatePermissionToken: (id, payload) =>
    apiFetch(`/api/admin/permission-tokens/${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).then((r) => r.json()),
  deletePermissionToken: (id) =>
    apiFetch(`/api/admin/permission-tokens/${id}`, { method: 'DELETE' }).then((r) => r.json()),
};
