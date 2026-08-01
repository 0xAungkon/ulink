<script setup>
import { computed, onMounted, ref } from 'vue';

const page = ref(location.pathname === '/admin' ? 'admin' : location.pathname === '/manage' ? 'manage' : 'home');
const busy = ref(false);
const error = ref('');
const notice = ref('');
const created = ref(null);
const destination = ref('');
const expiry = ref('');
const tokenId = ref('');
const secretKey = ref('');
const details = ref(null);
const newDestination = ref('');
const adminUser = ref('');
const adminPassword = ref('');
const adminData = ref(null);
const adminAuthenticated = ref(false);
const adminLinkDetails = ref(null);
const selectedVisitDetails = ref(null);
const adminSection = ref('dashboard');
const adminSidebarOpen = ref(false);
const publicDomains = ref([]);
const selectedDomainId = ref(null);
const deliveryMode = ref('redirect');
const newDomainLabel = ref('');
const newDomainUrl = ref('');
const editingDomainId = ref(null);
const editDomainLabel = ref('');
const editDomainUrl = ref('');

const defaultExpiry = () => {
  const d = new Date(); d.setFullYear(d.getFullYear() + 1); d.setDate(d.getDate() - 1);
  return d.toISOString().slice(0, 10);
};
expiry.value = defaultExpiry();

const api = async (url, options = {}) => {
  error.value = ''; notice.value = '';
  const response = await fetch(url, { ...options, headers: { 'Content-Type': 'application/json', ...(options.headers || {}) } });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const validation = body.errors ? Object.values(body.errors).flat().join(' ') : '';
    throw new Error(validation || body.message || 'Something went wrong.');
  }
  return body;
};

const createLink = async () => {
  busy.value = true;
  try {
    created.value = await api('/api/links', { method: 'POST', body: JSON.stringify({ url: destination.value, expire_at: new Date(`${expiry.value}T23:59:59`).toISOString(), type: deliveryMode.value, domain_id: selectedDomainId.value }) });
    tokenId.value = created.value.token_id; secretKey.value = created.value.secret_key;
    localStorage.setItem('ulink_credentials', JSON.stringify({ token_id: tokenId.value, secret_key: secretKey.value }));
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
};

const inspect = async () => {
  busy.value = true;
  try {
    details.value = await api(`/api/links/${encodeURIComponent(tokenId.value)}`, { headers: { Authorization: `Bearer ${tokenId.value}:${secretKey.value}` } });
    newDestination.value = details.value.destination_url;
    localStorage.setItem('ulink_credentials', JSON.stringify({ token_id: tokenId.value, secret_key: secretKey.value }));
  } catch (e) { error.value = e.message; details.value = null; } finally { busy.value = false; }
};

const updateLink = async () => {
  busy.value = true;
  try {
    const result = await api('/api/links', { method: 'PUT', body: JSON.stringify({ token_id: tokenId.value, secret_key: secretKey.value, url: newDestination.value }) });
    details.value.destination_url = result.destination_url; notice.value = 'Destination updated. Your public URL stayed the same.';
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
};

const copy = async (text, label = 'Copied to clipboard.') => {
  await navigator.clipboard.writeText(text); notice.value = label;
};

const go = (next) => {
  if (page.value === next) { location.reload(); return; }
  page.value = next; const path = next === 'home' ? '/' : `/${next}`; history.pushState({}, '', path); error.value = ''; notice.value = '';
};

const adminHeaders = () => ({ Authorization: `Basic ${btoa(`${adminUser.value}:${adminPassword.value}`)}` });
const loadDomains = async () => {
  try {
    publicDomains.value = await api('/api/domains');
    const preferred = publicDomains.value.find(domain => domain.is_default) || publicDomains.value[0];
    selectedDomainId.value = preferred?.id ?? null;
  } catch (e) { error.value = e.message; }
};
const loadAdmin = async () => {
  busy.value = true;
  try {
    adminData.value = await api('/api/admin/dashboard', { headers: adminHeaders() });
    adminAuthenticated.value = true;
    sessionStorage.setItem('ulink_admin', JSON.stringify({ user: adminUser.value, password: adminPassword.value }));
  } catch (e) { error.value = e.message; adminAuthenticated.value = false; } finally { busy.value = false; }
};
const deleteLink = async (link) => {
  if (!confirm(`Permanently delete ${link.public_url}?`)) return;
  try { await api(`/api/admin/links/${link.id}`, { method: 'DELETE', headers: adminHeaders() }); await loadAdmin(); notice.value = 'Link deleted.'; }
  catch (e) { error.value = e.message; }
};
const openAdminLink = async (link) => {
  busy.value = true;
  try { adminLinkDetails.value = await api(`/api/admin/links/${link.id}`, { headers: adminHeaders() }); selectedVisitDetails.value = null; }
  catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const closeAdminLink = () => { adminLinkDetails.value = null; selectedVisitDetails.value = null; };
const addDomain = async () => {
  busy.value = true;
  try {
    await api('/api/admin/domains', { method: 'POST', headers: adminHeaders(), body: JSON.stringify({ label: newDomainLabel.value || null, base_url: newDomainUrl.value }) });
    newDomainLabel.value = ''; newDomainUrl.value = '';
    await loadAdmin(); await loadDomains(); notice.value = 'Public domain added.';
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
};
const updateDomain = async (domain, changes) => {
  try {
    await api(`/api/admin/domains/${domain.id}`, { method: 'PATCH', headers: adminHeaders(), body: JSON.stringify(changes) });
    await loadAdmin(); await loadDomains(); notice.value = 'Domain configuration updated.';
  } catch (e) { error.value = e.message; }
};
const deleteDomain = async (domain) => {
  if (!confirm(`Remove ${domain.base_url} from new-link choices? Existing links will keep using it.`)) return;
  try {
    await api(`/api/admin/domains/${domain.id}`, { method: 'DELETE', headers: adminHeaders() });
    await loadAdmin(); await loadDomains(); notice.value = 'Domain removed. Existing links were not changed.';
  } catch (e) { error.value = e.message; }
};
const beginDomainEdit = (domain) => {
  editingDomainId.value = domain.id;
  editDomainLabel.value = domain.label || '';
  editDomainUrl.value = domain.base_url;
};
const cancelDomainEdit = () => { editingDomainId.value = null; editDomainLabel.value = ''; editDomainUrl.value = ''; };
const saveDomainEdit = async (domain) => {
  await updateDomain(domain, { label: editDomainLabel.value || null, base_url: editDomainUrl.value });
  cancelDomainEdit();
};
const adminLogout = () => {
  sessionStorage.removeItem('ulink_admin');
  adminAuthenticated.value = false; adminData.value = null;
  adminUser.value = ''; adminPassword.value = '';
  adminLinkDetails.value = null;
  adminSection.value = 'dashboard';
};

const adminSectionMeta = computed(() => ({
  dashboard: { title: 'Dashboard', subtitle: 'Service health and activity at a glance.' },
  links: { title: 'Links', subtitle: 'Review and manage every anonymous ULink.' },
  domains: { title: 'Domains', subtitle: 'Configure public domains available during link creation.' },
}[adminSection.value]));
const selectAdminSection = (section) => { adminSection.value = section; adminSidebarOpen.value = false; };

const expiryLabel = computed(() => created.value ? new Date(created.value.expire_at).toLocaleDateString(undefined, { dateStyle: 'long' }) : '');

onMounted(() => {
  loadDomains();
  const saved = JSON.parse(localStorage.getItem('ulink_credentials') || 'null');
  if (saved) { tokenId.value = saved.token_id; secretKey.value = saved.secret_key; }
  const admin = JSON.parse(sessionStorage.getItem('ulink_admin') || 'null');
  if (admin) { adminUser.value = admin.user; adminPassword.value = admin.password; if (page.value === 'admin') loadAdmin(); }
  addEventListener('popstate', () => page.value = location.pathname === '/admin' ? 'admin' : location.pathname === '/manage' ? 'manage' : 'home');
});
</script>

<template>
  <div :class="['shell', { 'admin-shell': page === 'admin' }]">
    <header v-if="page !== 'admin'" class="nav wrap">
      <button class="brand" @click="go('home')"><span class="brand-mark">U</span><span>ULink</span></button>
      <nav><button :class="{ active: page === 'home' }" @click="go('home')">Create link</button><button :class="{ active: page === 'manage' }" @click="go('manage')">Manage link</button></nav>
      <span class="status"><i></i> Service online</span>
    </header>
    <header v-else-if="!adminAuthenticated" class="admin-nav">
      <div class="wrap admin-nav-inner"><div class="admin-brand"><span class="brand-mark">U</span><div><strong>ULink</strong><small>Administration portal</small></div></div><div class="admin-actions"><button class="secondary" @click="go('home')">View public portal</button></div></div>
    </header>

    <main v-if="page === 'home'" class="wrap hero-grid">
      <section class="hero-copy">
        <div class="eyebrow"><span>↻</span> BUILT FOR EPHEMERAL TUNNELS</div>
        <h1>One link.<br><em>Always current.</em></h1>
        <p class="lead">Keep a permanent public address while your Cloudflare Tunnel URL changes underneath it. Update the destination in seconds—your shared link never moves.</p>
        <div class="trust"><span>✓ No account</span><span>✓ Up to 1 year</span><span>✓ Private credentials</span></div>
      </section>

      <section class="card create-card">
        <template v-if="!created">
          <div class="card-head"><span class="step">01</span><div><h2>Create your ULink</h2><p>Point it at your current tunnel.</p></div></div>
          <form @submit.prevent="createLink">
            <label>Destination URL</label>
            <div class="url-field"><span>↗</span><input v-model="destination" type="url" required placeholder="https://example.trycloudflare.com"></div>
            <label>Public link domain</label>
            <select v-model="selectedDomainId">
              <option v-for="domain in publicDomains" :key="domain.id ?? 'current'" :value="domain.id">{{ domain.label || domain.base_url }} — {{ domain.base_url }}</option>
            </select>
            <label>Delivery mode</label>
            <div class="mode-options" role="radiogroup" aria-label="Delivery mode">
              <label :class="['mode-option', { selected: deliveryMode === 'redirect' }]"><input v-model="deliveryMode" type="radio" value="redirect"><span class="mode-icon">↗</span><span><strong>Redirect</strong><small>Sends visitors to the latest tunnel URL.</small></span><i>✓</i></label>
              <label :class="['mode-option', { selected: deliveryMode === 'proxy' }]"><input v-model="deliveryMode" type="radio" value="proxy"><span class="mode-icon">⇄</span><span><strong>Proxy</strong><small>ULink fetches and serves the upstream content.</small></span><i>✓</i></label>
            </div>
            <p v-if="deliveryMode === 'proxy'" class="mode-note">Proxy mode keeps the ULink in the address bar. Private network targets are blocked for security.</p>
            <div class="expiry-field"><label>Expires on</label><input v-model="expiry" type="date" required></div>
            <button class="primary" :disabled="busy">{{ busy ? 'Creating…' : 'Create persistent link' }} <span>→</span></button>
          </form>
          <p class="fine">Your secret is shown only once. Keep it somewhere safe.</p>
        </template>
        <template v-else>
          <div class="success-icon">✓</div><div class="center"><h2>Your link is live</h2><p>This address stays the same when the tunnel changes.</p></div>
          <label>Public ULink</label><div class="copy-row"><code>{{ created.url }}</code><button @click="copy(created.url, 'Public URL copied.')">Copy</button></div>
          <div class="credentials"><div><span>Token ID</span><code>{{ created.token_id }}</code></div><div><span>Secret key</span><code>{{ created.secret_key }}</code></div></div>
          <button class="secondary full" @click="copy(`${created.token_id}:${created.secret_key}`, 'Credentials copied.')">Copy management credentials</button>
          <p class="fine">Expires {{ expiryLabel }} · credentials saved in this browser</p>
          <button class="text-button" @click="created = null; destination = ''">Create another link</button>
        </template>
      </section>

      <section class="how"><div><span>01</span><h3>Create</h3><p>Get one stable ULink and private credentials.</p></div><b>→</b><div><span>02</span><h3>Share</h3><p>Use the public address everywhere.</p></div><b>→</b><div><span>03</span><h3>Update</h3><p>Swap in each fresh tunnel URL.</p></div></section>
    </main>

    <main v-else-if="page === 'manage'" class="wrap page">
      <div class="page-title"><div class="eyebrow">PRIVATE LINK CONSOLE</div><h1>Manage your link</h1><p>Use the credentials created with your anonymous link.</p></div>
      <section class="card manage-card">
        <form class="credential-form" @submit.prevent="inspect"><div><label>Token ID</label><input v-model="tokenId" required placeholder="Your token ID"></div><div><label>Secret key</label><input v-model="secretKey" required type="password" placeholder="Your secret key"></div><button class="primary" :disabled="busy">{{ busy ? 'Loading…' : 'Open link' }}</button></form>
      </section>
      <template v-if="details">
        <section class="stats"><div><span>Total hits</span><strong>{{ details.hits.total }}</strong></div><div><span>Failed hits</span><strong>{{ details.hits.failed }}</strong></div><div><span>Status</span><strong :class="details.expired ? 'bad' : 'good'">{{ details.expired ? 'Expired' : 'Active' }}</strong></div><div><span>Expires</span><strong class="small">{{ new Date(details.expire_at).toLocaleDateString() }}</strong></div></section>
        <section class="card update-card"><div><h2>Update destination</h2><p>The public address remains <code>{{ details.public_url }}</code> · {{ details.type === 'proxy' ? 'Proxy' : 'Redirect' }} mode</p></div><form @submit.prevent="updateLink"><input v-model="newDestination" required type="url"><button class="primary" :disabled="busy">Update URL</button></form></section>
        <section class="card"><div class="table-head"><div><h2>Recent visitors</h2><p>Latest 100 redirect attempts</p></div></div><div class="table-wrap"><table><thead><tr><th>IP address</th><th>Location</th><th>Browser</th><th>Device</th><th>Time</th><th>Result</th></tr></thead><tbody><tr v-for="visit in details.users" :key="visit.visited_at + visit.ip"><td><code>{{ visit.ip }}</code></td><td>{{ [visit.location.city, visit.location.region, visit.location.country].filter(Boolean).join(', ') || 'Unknown' }}</td><td>{{ visit.browser }}</td><td>{{ visit.device }}</td><td>{{ new Date(visit.visited_at).toLocaleString() }}</td><td><span :class="['tag', visit.successful ? 'good-bg' : 'bad-bg']">{{ visit.successful ? 'Redirected' : 'Failed' }}</span></td></tr><tr v-if="!details.users.length"><td colspan="6" class="empty">No visits yet.</td></tr></tbody></table></div></section>
      </template>
    </main>

    <main v-else-if="!adminAuthenticated" class="wrap page admin-page">
      <div class="page-title admin-title"><div class="eyebrow">SECURE OPERATIONS</div><h1>Welcome back</h1><p>Sign in with the administrator credentials configured on the server.</p></div>
      <section class="card login-card"><div class="login-icon">⌁</div><h2>Admin sign in</h2><p>Enter your secure environment credentials to continue.</p><form @submit.prevent="loadAdmin"><label>Username</label><input v-model="adminUser" autocomplete="username" required placeholder="Administrator username"><label>Password</label><input v-model="adminPassword" type="password" autocomplete="current-password" required placeholder="Administrator password"><button class="primary" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in securely' }}</button></form><small class="security-note">Credentials are transmitted only to this server and are not stored in the database.</small></section>
    </main>

    <main v-else class="admin-portal">
      <div v-if="adminSidebarOpen" class="sidebar-scrim" @click="adminSidebarOpen = false"></div>
      <aside :class="['admin-sidebar', { open: adminSidebarOpen }]">
        <div class="sidebar-brand"><span class="brand-mark">U</span><div><strong>ULink</strong><small>Admin console</small></div></div>
        <div class="sidebar-label">Workspace</div>
        <nav class="sidebar-nav">
          <button :class="{ active: adminSection === 'dashboard' }" @click="selectAdminSection('dashboard')"><span>⌂</span><div>Dashboard<small>Overview and activity</small></div></button>
          <button :class="{ active: adminSection === 'links' }" @click="selectAdminSection('links')"><span>↗</span><div>Links<small>Manage all ULinks</small></div><b>{{ adminData?.stats.total_links || 0 }}</b></button>
          <button :class="{ active: adminSection === 'domains' }" @click="selectAdminSection('domains')"><span>◎</span><div>Domains<small>Public URL origins</small></div><b>{{ adminData?.domains.length || 0 }}</b></button>
        </nav>
        <div class="sidebar-footer"><div class="admin-identity"><span>{{ adminUser.slice(0, 1).toUpperCase() }}</span><div><strong>{{ adminUser }}</strong><small>Administrator</small></div></div><button @click="go('home')">↗ <span>View public portal</span></button><button class="sidebar-logout" @click="adminLogout">⇥ <span>Sign out</span></button></div>
      </aside>

      <section class="admin-workspace">
        <header class="workspace-header"><button class="sidebar-toggle" aria-label="Open navigation" @click="adminSidebarOpen = true">☰</button><div><div class="workspace-kicker">Administration / {{ adminSectionMeta.title }}</div><h1>{{ adminSectionMeta.title }}</h1><p>{{ adminSectionMeta.subtitle }}</p></div><div class="workspace-actions"><span class="status"><i></i> Service online</span><button class="secondary" :disabled="busy" @click="loadAdmin">{{ busy ? 'Refreshing…' : 'Refresh data' }}</button></div></header>

        <div v-if="adminData" class="workspace-content">
          <template v-if="adminSection === 'dashboard'">
            <section class="stats admin-stats"><div><span>Total links</span><strong>{{ adminData.stats.total_links }}</strong><small>{{ adminData.stats.active_links }} currently active</small></div><div><span>Active</span><strong class="good">{{ adminData.stats.active_links }}</strong><small>Available to visitors</small></div><div><span>Expired</span><strong>{{ adminData.stats.expired_links }}</strong><small>Past expiration date</small></div><div><span>Total requests</span><strong>{{ adminData.stats.total_hits }}</strong><small>Across every link</small></div><div><span>Failed requests</span><strong :class="{ bad: adminData.stats.failed_hits }">{{ adminData.stats.failed_hits }}</strong><small>Requires attention</small></div></section>
            <section class="admin-overview-grid">
              <div class="card overview-card"><div class="table-head"><div><h2>Recent links</h2><p>Latest links created on the service</p></div><button class="text-action" @click="selectAdminSection('links')">View all →</button></div><div class="recent-list"><button v-for="link in adminData.links.data.slice(0, 5)" :key="link.id" @click="openAdminLink(link)"><span :class="['mode-dot', link.delivery_mode]"></span><div><strong>{{ link.public_url }}</strong><small>{{ link.destination_url }}</small></div><span>{{ link.total_hits }} hits</span><b>›</b></button><p v-if="!adminData.links.data.length" class="domain-empty">No links created yet.</p></div></div>
              <div class="card overview-card domain-summary"><div class="table-head"><div><h2>Domain status</h2><p>Configured public origins</p></div><button class="text-action" @click="selectAdminSection('domains')">Manage →</button></div><div class="summary-number">{{ adminData.domains.filter(domain => domain.is_active).length }}<span> active</span></div><div class="summary-track"><i :style="{ width: `${adminData.domains.length ? (adminData.domains.filter(domain => domain.is_active).length / adminData.domains.length) * 100 : 0}%` }"></i></div><p>{{ adminData.domains.length }} configured domain{{ adminData.domains.length === 1 ? '' : 's' }} in total.</p></div>
            </section>
          </template>

          <section v-else-if="adminSection === 'links'" class="card admin-table-card"><div class="table-head"><div><h2>All links</h2><p>Inspect traffic, destinations, and delivery modes.</p></div><span class="record-count">{{ adminData.links.total }} records</span></div><div class="table-wrap"><table><thead><tr><th>Public link</th><th>Destination</th><th>Mode</th><th>Hits</th><th>Expires</th><th></th></tr></thead><tbody><tr v-for="link in adminData.links.data" :key="link.id"><td><a :href="link.public_url" target="_blank">{{ link.public_url }}</a><small>{{ link.token_id }}</small></td><td class="truncate">{{ link.destination_url }}</td><td><span class="tag default-tag">{{ link.delivery_mode }}</span></td><td>{{ link.total_hits }} <small>{{ link.failed_hits }} failed</small></td><td>{{ new Date(link.expire_at).toLocaleDateString() }}</td><td><div class="row-actions"><button class="secondary" @click="openAdminLink(link)">View</button><button class="danger" @click="deleteLink(link)">Delete</button></div></td></tr></tbody></table></div></section>

          <section v-else class="card domains-card"><div class="table-head"><div><h2>Public domains</h2><p>Choose which domains and subdomains appear during link creation.</p></div><span class="record-count">{{ adminData.domains.length }} configured</span></div><form class="domain-form" @submit.prevent="addDomain"><input v-model="newDomainLabel" placeholder="Label (optional)"><input v-model="newDomainUrl" required placeholder="https://go.example.com"><button class="primary" :disabled="busy">Add domain</button></form><div v-if="adminData.domains.length" class="domain-list"><div v-for="domain in adminData.domains" :key="domain.id" class="domain-item"><form v-if="editingDomainId === domain.id" class="domain-edit-form" @submit.prevent="saveDomainEdit(domain)"><input v-model="editDomainLabel" placeholder="Domain label"><input v-model="editDomainUrl" required placeholder="https://go.example.com"><button class="primary" type="submit">Save changes</button><button class="secondary" type="button" @click="cancelDomainEdit">Cancel</button></form><template v-else><div><strong>{{ domain.label || 'Public domain' }}</strong><code>{{ domain.base_url }}</code></div><span :class="['tag', domain.is_active ? 'good-bg' : 'bad-bg']">{{ domain.is_active ? 'Active' : 'Disabled' }}</span><span v-if="domain.is_default" class="tag default-tag">Default</span><button v-else class="secondary" @click="updateDomain(domain, { is_default: true })">Make default</button><button class="secondary" @click="beginDomainEdit(domain)">Edit</button><button class="secondary" @click="updateDomain(domain, { is_active: !domain.is_active })">{{ domain.is_active ? 'Disable' : 'Enable' }}</button><button class="danger" @click="deleteDomain(domain)">Remove</button></template></div></div><p v-else class="domain-empty">No domains are configured.</p></section>
        </div>
        <div v-else class="workspace-loading">Loading administration data…</div>
      </section>
    </main>

    <div v-if="adminLinkDetails" class="modal-backdrop" @click.self="closeAdminLink">
      <section class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="link-detail-title">
        <header class="detail-header"><div><span class="eyebrow">LINK DETAILS</span><h2 id="link-detail-title">{{ adminLinkDetails.public_url }}</h2></div><button class="modal-close" aria-label="Close link details" @click="closeAdminLink">×</button></header>
        <div class="detail-content">
          <div class="detail-actions"><a :href="adminLinkDetails.public_url" target="_blank" class="primary">Open public link</a><button class="secondary" @click="copy(adminLinkDetails.public_url, 'Public URL copied.')">Copy URL</button></div>
          <div class="detail-grid"><div><span>Destination</span><code>{{ adminLinkDetails.destination_url }}</code></div><div><span>Token ID</span><code>{{ adminLinkDetails.token_id }}</code></div><div><span>Delivery mode</span><strong>{{ adminLinkDetails.delivery_mode }}</strong></div><div><span>Status</span><strong :class="adminLinkDetails.expired ? 'bad' : 'good'">{{ adminLinkDetails.expired ? 'Expired' : 'Active' }}</strong></div><div><span>Created</span><strong>{{ new Date(adminLinkDetails.created_at).toLocaleString() }}</strong></div><div><span>Expires</span><strong>{{ new Date(adminLinkDetails.expire_at).toLocaleString() }}</strong></div></div>
          <div class="detail-stats"><div><span>Total hits</span><strong>{{ adminLinkDetails.hits.total }}</strong></div><div><span>Failed hits</span><strong>{{ adminLinkDetails.hits.failed }}</strong></div><div><span>Successful</span><strong>{{ adminLinkDetails.hits.total - adminLinkDetails.hits.failed }}</strong></div></div>
          <div class="detail-visitors"><div class="table-head"><div><h3>Recent visitors</h3><p>Latest 100 requests and redirect attempts</p></div></div><div v-if="selectedVisitDetails" class="request-inspector"><header><div><strong>Request information</strong><span>{{ selectedVisitDetails.request_method }} {{ selectedVisitDetails.request_path }}</span></div><button @click="selectedVisitDetails = null">×</button></header><div class="request-info-grid"><div><span>Browser</span><strong>{{ selectedVisitDetails.browser }}</strong></div><div><span>Device</span><strong>{{ selectedVisitDetails.device }}</strong></div><div><span>Operating system</span><strong>{{ selectedVisitDetails.operating_system || 'Unknown' }}</strong></div><div><span>IP address</span><code>{{ selectedVisitDetails.ip }}</code></div><div><span>Language</span><code>{{ selectedVisitDetails.accept_language || 'Not provided' }}</code></div><div><span>Referrer</span><code>{{ selectedVisitDetails.referrer || 'Not provided' }}</code></div><div class="wide"><span>Full user agent</span><code>{{ selectedVisitDetails.user_agent || 'Not provided' }}</code></div><div class="wide"><span>Accept header</span><code>{{ selectedVisitDetails.accept || 'Not provided' }}</code></div></div><div v-if="Object.keys(selectedVisitDetails.client_hints || {}).length" class="client-hints"><span v-for="(value, name) in selectedVisitDetails.client_hints" :key="name"><b>{{ name.replaceAll('_', ' ') }}</b>{{ value }}</span></div><p class="privacy-safe">Cookies, authorization headers, and request bodies are intentionally never recorded.</p></div><div class="table-wrap"><table><thead><tr><th>IP address</th><th>Location</th><th>Browser</th><th>Device / OS</th><th>Time</th><th>Result</th><th></th></tr></thead><tbody><tr v-for="visit in adminLinkDetails.users" :key="visit.visited_at + visit.ip"><td><code>{{ visit.ip }}</code></td><td>{{ [visit.location.city, visit.location.region, visit.location.country].filter(Boolean).join(', ') || 'Unknown' }}</td><td>{{ visit.browser }}</td><td>{{ visit.device }}<small>{{ visit.operating_system || 'Unknown OS' }}</small></td><td>{{ new Date(visit.visited_at).toLocaleString() }}</td><td><span :class="['tag', visit.successful ? 'good-bg' : 'bad-bg']">{{ visit.successful ? 'Success' : visit.failure_reason || 'Failed' }}</span></td><td><button class="secondary inspect-button" @click="selectedVisitDetails = visit">Inspect</button></td></tr><tr v-if="!adminLinkDetails.users.length"><td colspan="7" class="empty">No visits recorded yet.</td></tr></tbody></table></div></div>
        </div>
      </section>
    </div>

    <div v-if="error" class="toast error">{{ error }} <button @click="error = ''">×</button></div>
    <div v-if="notice" class="toast">{{ notice }} <button @click="notice = ''">×</button></div>
  </div>
</template>
