<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import {
    Archive,
    CheckCircle2,
    Cloud,
    Database,
    HardDrive,
    LoaderCircle,
    LockKeyhole,
    LogOut,
    RefreshCw,
    Server,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';

const token = ref(localStorage.getItem('melodia_developer_token') || '');
const username = ref('');
const password = ref('');
const user = ref(null);
const overview = ref(null);
const jobs = ref([]);
const driveSettings = ref({ folder_id: '', upload_chunk_mb: 8, credentials_configured: false, service_account_email: null });
const credentialsFile = ref(null);
const selectedDate = ref('');
const deleteAfterUpload = ref(false);
const loading = ref(false);
const authenticating = ref(false);
const testingDrive = ref(false);
const savingSettings = ref(false);
const queueing = ref(false);
const error = ref('');
const notice = ref('');
let refreshTimer = null;

const api = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } });
const diskUsed = computed(() => overview.value
    ? Math.max(0, overview.value.disk_total_bytes - overview.value.disk_free_bytes)
    : 0);
const diskPercent = computed(() => overview.value?.disk_total_bytes
    ? Math.round((diskUsed.value / overview.value.disk_total_bytes) * 100)
    : 0);

function applyToken() {
    if (token.value) api.defaults.headers.common.Authorization = `Bearer ${token.value}`;
    else delete api.defaults.headers.common.Authorization;
}

function formatBytes(value) {
    const bytes = Number(value || 0);
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / (1024 ** index)).toFixed(index > 2 ? 2 : 1)} ${units[index]}`;
}

function message(exception, fallback) {
    return exception.response?.data?.message || exception.response?.data?.error || fallback;
}

function statusLabel(status) {
    return ({ queued: 'En cola', processing: 'Procesando', completed: 'Completado', failed: 'Fallido' })[status] || status;
}

async function login() {
    authenticating.value = true;
    error.value = '';
    try {
        const response = await api.post('/login', { username: username.value, password: password.value });
        if (response.data.user.role !== 'developer') {
            api.defaults.headers.common.Authorization = `Bearer ${response.data.token}`;
            try { await api.post('/logout'); } catch { /* The temporary token will expire normally. */ }
            delete api.defaults.headers.common.Authorization;
            throw new Error('Esta cuenta no tiene acceso de desarrollador.');
        }
        token.value = response.data.token;
        user.value = response.data.user;
        localStorage.setItem('melodia_developer_token', token.value);
        applyToken();
        password.value = '';
        await refresh();
        startPolling();
    } catch (exception) {
        token.value = '';
        localStorage.removeItem('melodia_developer_token');
        error.value = message(exception, exception.message || 'No se pudo iniciar sesion.');
    } finally {
        authenticating.value = false;
    }
}

async function verifySession() {
    applyToken();
    try {
        const response = await api.get('/me');
        if (response.data.user.role !== 'developer') throw new Error('forbidden');
        user.value = response.data.user;
        await refresh();
        startPolling();
    } catch {
        logout(false);
    }
}

async function logout(request = true) {
    stopPolling();
    if (request) {
        try { await api.post('/logout'); } catch { /* Local logout still applies. */ }
    }
    token.value = '';
    user.value = null;
    overview.value = null;
    jobs.value = [];
    localStorage.removeItem('melodia_developer_token');
    applyToken();
}

async function refresh(silent = false) {
    if (!silent) loading.value = true;
    try {
        const [overviewResponse, jobsResponse] = await Promise.all([
            api.get('/developer/overview'),
            api.get('/developer/archives'),
        ]);
        overview.value = overviewResponse.data;
        jobs.value = jobsResponse.data.jobs;
        if (!silent) driveSettings.value = (await api.get('/developer/settings')).data;
        if (!selectedDate.value && overview.value.dates.length) selectedDate.value = overview.value.dates[0].date;
    } catch (exception) {
        error.value = message(exception, 'No se pudo actualizar el panel.');
    } finally {
        loading.value = false;
    }
}

async function saveDriveSettings() {
    savingSettings.value = true;
    error.value = '';
    notice.value = '';

    const form = new FormData();
    form.append('folder_id', driveSettings.value.folder_id);
    form.append('upload_chunk_mb', driveSettings.value.upload_chunk_mb);
    if (credentialsFile.value) form.append('credentials', credentialsFile.value);

    try {
        const response = await api.post('/developer/settings', form);
        driveSettings.value = response.data;
        credentialsFile.value = null;
        notice.value = 'Configuracion de Google Drive guardada.';
        await refresh(true);
    } catch (exception) {
        error.value = message(exception, 'No se pudo guardar la configuracion.');
    } finally {
        savingSettings.value = false;
    }
}

async function testDrive() {
    testingDrive.value = true;
    error.value = '';
    notice.value = '';
    try {
        const response = await api.post('/developer/drive/test');
        notice.value = `Conexion correcta con ${response.data.folder_name}.`;
    } catch (exception) {
        error.value = message(exception, 'No se pudo conectar con Google Drive.');
    } finally {
        testingDrive.value = false;
    }
}

async function queueArchive() {
    if (!selectedDate.value) return;
    queueing.value = true;
    error.value = '';
    notice.value = '';
    try {
        await api.post('/developer/archives', {
            date: selectedDate.value,
            delete_after_upload: deleteAfterUpload.value,
        });
        notice.value = `El respaldo de ${selectedDate.value} fue agregado a la cola.`;
        deleteAfterUpload.value = false;
        await refresh(true);
    } catch (exception) {
        error.value = message(exception, 'No se pudo crear el respaldo.');
    } finally {
        queueing.value = false;
    }
}

function startPolling() {
    stopPolling();
    refreshTimer = window.setInterval(() => refresh(true), 10000);
}

function stopPolling() {
    if (refreshTimer) window.clearInterval(refreshTimer);
    refreshTimer = null;
}

onMounted(() => {
    if (token.value) verifySession();
});
onBeforeUnmount(stopPolling);
</script>

<template>
    <main class="min-h-screen bg-[#f3f5f6] text-[#172027]">
        <header class="border-b border-[#d7dde0] bg-[#172027] text-white">
            <div class="mx-auto flex min-h-16 max-w-[1280px] items-center justify-between gap-4 px-4 sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-md bg-[#2b7b80]"><ShieldCheck :size="19" /></span>
                    <div><p class="text-sm font-bold">Consola del sistema</p><p class="text-xs text-[#b9c5c9]">Radio Melodia</p></div>
                </div>
                <div v-if="user" class="flex items-center gap-2">
                    <span class="hidden text-xs text-[#cbd5d8] sm:inline">{{ user.email }}</span>
                    <button class="admin-icon" title="Actualizar" :disabled="loading" @click="refresh()"><RefreshCw :size="17" :class="{ 'animate-spin': loading }" /></button>
                    <button class="admin-icon" title="Cerrar sesion" @click="logout()"><LogOut :size="17" /></button>
                </div>
            </div>
        </header>

        <div v-if="!user" class="mx-auto grid min-h-[calc(100vh-65px)] max-w-md place-items-center px-4">
            <form class="panel w-full p-6" @submit.prevent="login">
                <LockKeyhole :size="28" class="mb-4 text-[#176b72]" />
                <h1 class="text-xl font-bold">Acceso de desarrollador</h1>
                <div class="mt-5 space-y-4">
                    <label class="field-label">Usuario<input v-model="username" class="field" required autocomplete="username"></label>
                    <label class="field-label">Contrasena<input v-model="password" class="field" type="password" required autocomplete="current-password"></label>
                    <p v-if="error" class="alert-error">{{ error }}</p>
                    <button class="primary-button w-full" :disabled="authenticating"><LoaderCircle v-if="authenticating" :size="17" class="animate-spin" />{{ authenticating ? 'Verificando...' : 'Ingresar' }}</button>
                </div>
            </form>
        </div>

        <div v-else class="mx-auto max-w-[1280px] px-4 py-6 sm:px-6">
            <div v-if="error || notice" class="mb-4 space-y-2">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <p v-if="notice" class="alert-success">{{ notice }}</p>
            </div>

            <div class="mb-5"><h1 class="text-2xl font-bold">Almacenamiento</h1><p class="mt-1 text-sm text-[#687780]">Estado de grabaciones y respaldos externos</p></div>

            <template v-if="overview">
                <section class="metric-grid">
                    <div class="metric"><HardDrive :size="19" /><span>Uso del disco</span><strong>{{ diskPercent }}%</strong><small>{{ formatBytes(diskUsed) }} de {{ formatBytes(overview.disk_total_bytes) }}</small></div>
                    <div class="metric"><Database :size="19" /><span>Grabaciones</span><strong>{{ formatBytes(overview.total_bytes) }}</strong><small>{{ overview.total_files.toLocaleString() }} archivos MP3</small></div>
                    <div class="metric"><Archive :size="19" /><span>Dias locales</span><strong>{{ overview.days }}</strong><small>{{ formatBytes(overview.disk_free_bytes) }} libres</small></div>
                    <div class="metric"><Cloud :size="19" /><span>Google Drive</span><strong class="text-base">{{ overview.drive.configured ? 'Configurado' : 'Pendiente' }}</strong><small>{{ overview.drive.account || 'Sin cuenta de servicio' }}</small></div>
                </section>

                <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <section class="panel min-w-0">
                        <div class="panel-heading"><div class="flex items-center gap-2"><Server :size="17" /><h2>Grabaciones en la VPS</h2></div><span class="counter">{{ overview.dates.length }}</span></div>
                        <div class="overflow-x-auto">
                            <table class="admin-table">
                                <thead><tr><th>Fecha UTC</th><th>Archivos</th><th>Tamano</th><th></th></tr></thead>
                                <tbody>
                                    <tr v-for="item in overview.dates" :key="item.date">
                                        <td class="font-semibold">{{ item.date }}</td><td>{{ item.files.toLocaleString() }}</td><td>{{ formatBytes(item.bytes) }}</td>
                                        <td class="text-right"><button class="secondary-button" @click="selectedDate = item.date">Seleccionar</button></td>
                                    </tr>
                                    <tr v-if="!overview.dates.length"><td colspan="4" class="py-10 text-center text-[#75848a]">No hay dias completos para archivar.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <aside class="space-y-5">
                        <section class="panel">
                            <div class="panel-heading"><div class="flex items-center gap-2"><Cloud :size="17" /><h2>Google Drive</h2></div><CheckCircle2 v-if="overview.drive.configured" :size="18" class="text-[#287246]" /></div>
                            <form class="space-y-4 p-4" @submit.prevent="saveDriveSettings">
                                <label class="field-label">ID de carpeta en unidad compartida<input v-model.trim="driveSettings.folder_id" class="field" required placeholder="1AbC..."></label>
                                <label class="field-label">Credencial de cuenta de servicio
                                    <input class="file-field" type="file" accept="application/json,.json" :required="!driveSettings.credentials_configured" @change="credentialsFile = $event.target.files[0] || null">
                                    <span v-if="driveSettings.service_account_email" class="mt-1 block break-all text-[11px] font-normal text-[#687780]">{{ driveSettings.service_account_email }}</span>
                                </label>
                                <label class="field-label">Bloque de carga
                                    <select v-model.number="driveSettings.upload_chunk_mb" class="field">
                                        <option :value="4">4 MB</option><option :value="8">8 MB</option><option :value="16">16 MB</option><option :value="32">32 MB</option><option :value="64">64 MB</option>
                                    </select>
                                </label>
                                <button class="primary-button w-full" :disabled="savingSettings"><LoaderCircle v-if="savingSettings" :size="16" class="animate-spin" /><Cloud v-else :size="16" />Guardar configuracion</button>
                                <button class="secondary-button w-full" type="button" :disabled="!overview.drive.configured || testingDrive" @click="testDrive"><LoaderCircle v-if="testingDrive" :size="16" class="animate-spin" /><CheckCircle2 v-else :size="16" />Probar conexion</button>
                            </form>
                        </section>

                        <section class="panel">
                            <div class="panel-heading"><div class="flex items-center gap-2"><Archive :size="17" /><h2>Crear respaldo</h2></div></div>
                            <div class="p-4">
                                <label class="field-label">Dia<select v-model="selectedDate" class="field"><option v-for="item in overview.dates" :key="item.date" :value="item.date">{{ item.date }} - {{ formatBytes(item.bytes) }}</option></select></label>
                                <label class="mt-4 flex items-start gap-3 text-sm"><input v-model="deleteAfterUpload" type="checkbox" class="mt-1 size-4 accent-[#176b72]"><span><strong>Liberar espacio al terminar</strong><small class="mt-1 block text-xs text-[#687780]">El dia local se elimina solo cuando Drive confirma la carga.</small></span></label>
                                <p v-if="deleteAfterUpload" class="mt-3 flex gap-2 rounded-md bg-[#fff4e6] p-3 text-xs text-[#7a4a13]"><TriangleAlert :size="16" class="shrink-0" />Esta accion elimina las grabaciones originales de la VPS.</p>
                                <button class="primary-button mt-4 w-full" :disabled="!selectedDate || !overview.drive.configured || queueing" @click="queueArchive"><LoaderCircle v-if="queueing" :size="16" class="animate-spin" /><Archive v-else :size="16" />Agregar a la cola</button>
                            </div>
                        </section>
                    </aside>
                </div>

                <section class="panel mt-5">
                    <div class="panel-heading"><div class="flex items-center gap-2"><Archive :size="17" /><h2>Actividad de respaldos</h2></div></div>
                    <div class="overflow-x-auto"><table class="admin-table"><thead><tr><th>Fecha</th><th>Estado</th><th>Destino</th><th>Creado</th></tr></thead><tbody>
                        <tr v-for="job in jobs" :key="job.id"><td class="font-semibold">{{ job.date }}</td><td><span class="job-status" :class="job.status">{{ statusLabel(job.status) }}</span><p v-if="job.error" class="mt-1 max-w-md text-xs text-[#a33a32]">{{ job.error }}</p></td><td>{{ job.remote_name || 'Google Drive' }}<small v-if="job.delete_after_upload" class="block text-[#a05b20]">Libera espacio</small></td><td class="text-xs text-[#687780]">{{ new Date(job.created_at).toLocaleString() }}</td></tr>
                        <tr v-if="!jobs.length"><td colspan="4" class="py-8 text-center text-[#75848a]">Aun no se han creado respaldos.</td></tr>
                    </tbody></table></div>
                </section>
            </template>
        </div>
    </main>
</template>
