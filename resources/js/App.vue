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
const publicDomains = ref([]);
const selectedDomainId = ref(null);
const newDomainLabel = ref('');
const newDomainUrl = ref('');

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
    created.value = await api('/api/links', { method: 'POST', body: JSON.stringify({ url: destination.value, expire_at: new Date(`${expiry.value}T23:59:59`).toISOString(), type: 'anonymous', domain_id: selectedDomainId.value }) });
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
  <div class="shell">
    <header class="nav wrap">
      <button class="brand" @click="go('home')"><span class="brand-mark">U</span><span>ULink</span></button>
      <nav><button :class="{ active: page === 'home' }" @click="go('home')">Create</button><button :class="{ active: page === 'manage' }" @click="go('manage')">Manage</button><button :class="{ active: page === 'admin' }" @click="go('admin')">Admin</button></nav>
      <span class="status"><i></i> Service online</span>
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
            <div class="field-row"><div><label>Expires on</label><input v-model="expiry" type="date" required></div><div class="fixed"><label>Link type</label><div class="pill-field">Anonymous <span>●</span></div></div></div>
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
        <section class="card update-card"><div><h2>Update destination</h2><p>The public address remains <code>{{ details.public_url }}</code></p></div><form @submit.prevent="updateLink"><input v-model="newDestination" required type="url"><button class="primary" :disabled="busy">Update URL</button></form></section>
        <section class="card"><div class="table-head"><div><h2>Recent visitors</h2><p>Latest 100 redirect attempts</p></div></div><div class="table-wrap"><table><thead><tr><th>IP address</th><th>Location</th><th>Browser</th><th>Device</th><th>Time</th><th>Result</th></tr></thead><tbody><tr v-for="visit in details.users" :key="visit.visited_at + visit.ip"><td><code>{{ visit.ip }}</code></td><td>{{ [visit.location.city, visit.location.region, visit.location.country].filter(Boolean).join(', ') || 'Unknown' }}</td><td>{{ visit.browser }}</td><td>{{ visit.device }}</td><td>{{ new Date(visit.visited_at).toLocaleString() }}</td><td><span :class="['tag', visit.successful ? 'good-bg' : 'bad-bg']">{{ visit.successful ? 'Redirected' : 'Failed' }}</span></td></tr><tr v-if="!details.users.length"><td colspan="6" class="empty">No visits yet.</td></tr></tbody></table></div></section>
      </template>
    </main>

    <main v-else class="wrap page admin-page">
      <div class="page-title"><div class="eyebrow">ENV-PROTECTED ACCESS</div><h1>Administration</h1><p>Credentials are configured on the server, never in the database.</p></div>
      <section v-if="!adminAuthenticated" class="card login-card"><h2>Admin sign in</h2><form @submit.prevent="loadAdmin"><label>Username</label><input v-model="adminUser" autocomplete="username" required><label>Password</label><input v-model="adminPassword" type="password" autocomplete="current-password" required><button class="primary" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button></form></section>
      <template v-else-if="adminData">
        <section class="stats admin-stats"><div><span>Total links</span><strong>{{ adminData.stats.total_links }}</strong></div><div><span>Active</span><strong>{{ adminData.stats.active_links }}</strong></div><div><span>Expired</span><strong>{{ adminData.stats.expired_links }}</strong></div><div><span>Total hits</span><strong>{{ adminData.stats.total_hits }}</strong></div><div><span>Failed hits</span><strong>{{ adminData.stats.failed_hits }}</strong></div></section>
        <section class="card domains-card">
          <div class="table-head"><div><h2>Public domains</h2><p>Choose which domains and subdomains appear during link creation.</p></div></div>
          <form class="domain-form" @submit.prevent="addDomain"><input v-model="newDomainLabel" placeholder="Label (optional)"><input v-model="newDomainUrl" required placeholder="https://go.example.com"><button class="primary" :disabled="busy">Add domain</button></form>
          <div v-if="adminData.domains.length" class="domain-list">
            <div v-for="domain in adminData.domains" :key="domain.id" class="domain-item">
              <div><strong>{{ domain.label || 'Public domain' }}</strong><code>{{ domain.base_url }}</code></div>
              <span :class="['tag', domain.is_active ? 'good-bg' : 'bad-bg']">{{ domain.is_active ? 'Active' : 'Disabled' }}</span>
              <span v-if="domain.is_default" class="tag default-tag">Default</span>
              <button v-else class="secondary" @click="updateDomain(domain, { is_default: true })">Make default</button>
              <button class="secondary" @click="updateDomain(domain, { is_active: !domain.is_active })">{{ domain.is_active ? 'Disable' : 'Enable' }}</button>
              <button class="danger" @click="deleteDomain(domain)">Remove</button>
            </div>
          </div>
          <p v-else class="domain-empty">No custom domains configured. New links currently use the main APP_URL domain.</p>
        </section>
        <section class="card"><div class="table-head"><div><h2>All links</h2><p>Newest anonymous links first</p></div><button class="secondary" @click="loadAdmin">Refresh</button></div><div class="table-wrap"><table><thead><tr><th>Public link</th><th>Destination</th><th>Hits</th><th>Expires</th><th></th></tr></thead><tbody><tr v-for="link in adminData.links.data" :key="link.id"><td><a :href="link.public_url" target="_blank">{{ link.public_url }}</a><small>{{ link.token_id }}</small></td><td class="truncate">{{ link.destination_url }}</td><td>{{ link.total_hits }} <small>({{ link.failed_hits }} failed)</small></td><td>{{ new Date(link.expire_at).toLocaleDateString() }}</td><td><button class="danger" @click="deleteLink(link)">Delete</button></td></tr></tbody></table></div></section>
      </template>
    </main>

    <div v-if="error" class="toast error">{{ error }} <button @click="error = ''">×</button></div>
    <div v-if="notice" class="toast">{{ notice }} <button @click="notice = ''">×</button></div>
    <footer class="wrap"><span>ULink / Updateable infrastructure links</span><span>Built for Cloudflare Tunnels</span></footer>
  </div>
</template>
