<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import {
    ArrowDown,
    ArrowUp,
    CalendarDays,
    Clock3,
    Download,
    ListMusic,
    LoaderCircle,
    LogOut,
    Play,
    Plus,
    Radio,
    RefreshCw,
    Scissors,
    Trash2,
} from '@lucide/vue';

const pad = (value) => String(value).padStart(2, '0');
const localDate = (value = new Date()) => `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
const secondsFromTime = (value) => {
    const [hours = 0, minutes = 0, seconds = 0] = String(value).split(':').map(Number);
    return hours * 3600 + minutes * 60 + seconds;
};
const timeFromSeconds = (value) => {
    const bounded = Math.max(0, Math.min(86399, Math.floor(value)));
    return `${pad(Math.floor(bounded / 3600))}:${pad(Math.floor((bounded % 3600) / 60))}:${pad(bounded % 60)}`;
};
const timeFromIso = (value, fallback) => value?.match(/T(\d{2}:\d{2}:\d{2})/)?.[1] || fallback;
const formatDuration = (seconds) => {
    const total = Math.max(0, Math.round(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const remaining = total % 60;

    if (hours) return `${hours} h ${minutes} min`;
    if (minutes) return `${minutes} min ${remaining} s`;
    return `${remaining} s`;
};

const token = ref(localStorage.getItem('melodia_api_token') || '');
const username = ref('admin');
const password = ref('');
const currentUser = ref(null);
const date = ref(localDate());
const hours = ref([]);
const selectedHour = ref(null);
const start = ref('');
const end = ref('');
const clips = ref([]);
const outputFormat = ref('mp3');
const previewUrl = ref('');
const previewBaseSeconds = ref(0);
const audioPlayer = ref(null);
const authenticating = ref(false);
const loadingHours = ref(false);
const loadingPreview = ref(false);
const exporting = ref(false);
const error = ref('');
const notice = ref('');
let refreshTimer = null;

const api = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } });

const selectedSlot = computed(() => hours.value.find((item) => item.hour === selectedHour.value) || null);
const rangeDuration = computed(() => Math.max(0, secondsFromTime(end.value) - secondsFromTime(start.value)));
const totalDuration = computed(() => clips.value.reduce(
    (total, clip) => total + Math.max(0, secondsFromTime(clip.end) - secondsFromTime(clip.start)),
    0,
));
const availableCount = computed(() => hours.value.filter((item) => item.available).length);
const currentHour = computed(() => (date.value === localDate() ? new Date().getHours() : -1));

function applyToken() {
    if (token.value) {
        api.defaults.headers.common.Authorization = `Bearer ${token.value}`;
        localStorage.setItem('melodia_api_token', token.value);
    } else {
        delete api.defaults.headers.common.Authorization;
        localStorage.removeItem('melodia_api_token');
    }
}

function revokePreview() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = '';
}

async function errorMessage(exception, fallback) {
    const data = exception.response?.data;
    if (data instanceof Blob) {
        try {
            const parsed = JSON.parse(await data.text());
            return parsed.details || parsed.error || parsed.message || fallback;
        } catch {
            return fallback;
        }
    }

    return data?.details || data?.error || data?.message || exception.message || fallback;
}

const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

async function processExport(ranges, format) {
    const created = await api.post('/exports', { ranges, format });
    const jobId = created.data.id;

    for (let attempt = 0; attempt < 600; attempt += 1) {
        await wait(3000);
        const response = await api.get(`/exports/${jobId}`);

        if (response.data.status === 'completed') {
            return jobId;
        }

        if (response.data.status === 'failed') {
            throw new Error(response.data.error || 'No se pudo procesar el audio.');
        }
    }

    throw new Error('La exportacion esta tomando mas tiempo del esperado.');
}

async function downloadJob(jobId) {
    return api.get(`/exports/${jobId}/download`, { responseType: 'blob' });
}

async function login() {
    authenticating.value = true;
    error.value = '';

    try {
        const response = await api.post('/login', { username: username.value, password: password.value });
        token.value = response.data.token;
        currentUser.value = response.data.user;
        password.value = '';
        applyToken();
        await loadHours();
        startPolling();
    } catch (exception) {
        error.value = await errorMessage(exception, 'No se pudo iniciar sesion.');
    } finally {
        authenticating.value = false;
    }
}

async function logout() {
    stopPolling();
    applyToken();
    try { await api.post('/logout'); } catch { /* The local session still closes. */ }
    token.value = '';
    currentUser.value = null;
    hours.value = [];
    clips.value = [];
    revokePreview();
    applyToken();
}

async function loadProfile() {
    applyToken();
    try {
        currentUser.value = (await api.get('/me')).data.user;
    } catch {
        await logout();
    }
}

async function loadHours(silent = false) {
    applyToken();
    if (!silent) loadingHours.value = true;
    error.value = '';

    try {
        const response = await api.get('/hours', { params: { date: date.value } });
        hours.value = response.data.hours;

        if (selectedHour.value === null || !hours.value.some((item) => item.hour === selectedHour.value && item.available)) {
            const preferred = hours.value.find((item) => item.hour === currentHour.value && item.available)
                || [...hours.value].reverse().find((item) => item.available);
            selectHour(preferred || null);
        }
    } catch (exception) {
        error.value = await errorMessage(exception, 'No se pudieron cargar las grabaciones.');
    } finally {
        loadingHours.value = false;
    }
}

function selectHour(slot) {
    if (!slot?.available) return;
    selectedHour.value = slot.hour;
    const hourStart = `${pad(slot.hour)}:00:00`;
    const hourEnd = `${pad(slot.hour)}:59:59`;
    start.value = timeFromIso(slot.starts_at, hourStart);
    const detectedEnd = timeFromIso(slot.ends_at, hourEnd);
    end.value = secondsFromTime(detectedEnd) <= secondsFromTime(start.value) ? hourEnd : detectedEnd;
    revokePreview();
    notice.value = '';
}

async function loadPreview() {
    if (!rangeDuration.value) return;
    loadingPreview.value = true;
    error.value = '';
    revokePreview();

    try {
        const ranges = [{ date: date.value, start: start.value, end: end.value }];
        const jobId = await processExport(ranges, 'mp3');
        const response = await downloadJob(jobId);
        previewBaseSeconds.value = secondsFromTime(start.value);
        previewUrl.value = URL.createObjectURL(response.data);
        await nextTick();
        audioPlayer.value?.play().catch(() => {});
    } catch (exception) {
        error.value = await errorMessage(exception, 'No se pudo preparar la reproduccion.');
    } finally {
        loadingPreview.value = false;
    }
}

function markAtPlayer(field) {
    if (!audioPlayer.value || !previewUrl.value) return;
    const value = timeFromSeconds(previewBaseSeconds.value + audioPlayer.value.currentTime);
    if (field === 'start') start.value = value;
    else end.value = value;
}

function addClip() {
    if (!rangeDuration.value) {
        error.value = 'La hora final debe ser posterior a la inicial.';
        return;
    }

    clips.value.push({
        id: `${Date.now()}-${Math.random()}`,
        date: date.value,
        start: start.value,
        end: end.value,
    });
    error.value = '';
    notice.value = 'Fragmento agregado a la lista.';
}

function moveClip(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= clips.value.length) return;
    const updated = [...clips.value];
    [updated[index], updated[target]] = [updated[target], updated[index]];
    clips.value = updated;
}

async function downloadExport() {
    if (!clips.value.length) return;
    exporting.value = true;
    error.value = '';
    notice.value = '';

    try {
        const ranges = clips.value.map(({ date: clipDate, start: clipStart, end: clipEnd }) => ({
                date: clipDate,
                start: clipStart,
                end: clipEnd,
            }));
        const jobId = await processExport(ranges, outputFormat.value);
        const response = await downloadJob(jobId);
        const url = URL.createObjectURL(response.data);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `melodia_${date.value}.${outputFormat.value}`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        notice.value = 'La descarga esta lista en tu dispositivo.';
    } catch (exception) {
        error.value = await errorMessage(exception, 'No se pudo generar la descarga.');
    } finally {
        exporting.value = false;
    }
}

function startPolling() {
    stopPolling();
    refreshTimer = window.setInterval(() => {
        if (token.value && date.value === localDate()) loadHours(true);
    }, 15000);
}

function stopPolling() {
    if (refreshTimer) window.clearInterval(refreshTimer);
    refreshTimer = null;
}

onMounted(async () => {
    applyToken();
    if (token.value) {
        await Promise.all([loadProfile(), loadHours()]);
        startPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();
    revokePreview();
});
</script>

<template>
    <main class="min-h-screen bg-[#f3f5f6] text-[#172027]">
        <header class="border-b border-[#d7dde0] bg-white">
            <div class="mx-auto flex min-h-16 max-w-[1440px] items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-md bg-[#176b72] text-white"><Radio :size="19" /></span>
                    <div>
                        <p class="text-sm font-bold text-[#172027]">Radio Melodia</p>
                        <p class="text-xs text-[#687780]">Archivo de emisiones</p>
                    </div>
                </div>

                <div v-if="token" class="flex items-center gap-2">
                    <span class="hidden text-sm text-[#52616a] sm:inline">{{ currentUser?.username || currentUser?.email }}</span>
                    <button class="icon-button" title="Actualizar grabaciones" :disabled="loadingHours" @click="loadHours()">
                        <RefreshCw :size="18" :class="{ 'animate-spin': loadingHours }" />
                    </button>
                    <button class="icon-button" title="Cerrar sesion" @click="logout"><LogOut :size="18" /></button>
                </div>
            </div>
        </header>

        <div v-if="!token" class="mx-auto grid min-h-[calc(100vh-65px)] max-w-md place-items-center px-4">
            <form class="w-full rounded-md border border-[#d7dde0] bg-white p-6 shadow-sm" @submit.prevent="login">
                <h1 class="text-xl font-bold">Acceder</h1>
                <div class="mt-5 space-y-4">
                    <label class="field-label">Usuario<input v-model="username" class="field" autocomplete="username" required></label>
                    <label class="field-label">Contrasena<input v-model="password" class="field" type="password" autocomplete="current-password" required></label>
                    <p v-if="error" class="alert-error">{{ error }}</p>
                    <button class="primary-button w-full" :disabled="authenticating">
                        <LoaderCircle v-if="authenticating" :size="17" class="animate-spin" />
                        {{ authenticating ? 'Ingresando...' : 'Ingresar' }}
                    </button>
                </div>
            </form>
        </div>

        <div v-else class="mx-auto max-w-[1440px] px-4 py-5 sm:px-6 lg:px-8">
            <div v-if="error || notice" class="mb-4 space-y-2">
                <p v-if="error" class="alert-error">{{ error }}</p>
                <p v-if="notice" class="alert-success">{{ notice }}</p>
            </div>

            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Grabaciones del dia</h1>
                    <p class="mt-1 text-sm text-[#687780]">{{ availableCount }} de 24 horas disponibles</p>
                </div>
                <label class="field-label w-full sm:w-48">
                    <span class="flex items-center gap-2"><CalendarDays :size="15" /> Fecha</span>
                    <input v-model="date" type="date" class="field" @change="selectedHour = null; revokePreview(); loadHours()">
                </label>
            </div>

            <section class="hour-grid" aria-label="Grabaciones por hora">
                <button
                    v-for="slot in hours"
                    :key="slot.hour"
                    class="hour-slot"
                    :class="{ selected: selectedHour === slot.hour, unavailable: !slot.available }"
                    :disabled="!slot.available"
                    @click="selectHour(slot)"
                >
                    <span class="flex items-center justify-between gap-2">
                        <strong>{{ pad(slot.hour) }}:00</strong>
                        <span v-if="slot.hour === currentHour" class="live-badge">EN VIVO</span>
                    </span>
                    <span class="mt-2 block text-xs" :class="slot.available ? 'text-[#587078]' : 'text-[#9aa5aa]'">
                        {{ slot.available ? formatDuration(slot.coverage_seconds) : 'Sin audio' }}
                    </span>
                </button>
            </section>

            <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
                <section class="panel min-w-0">
                    <div class="panel-heading">
                        <div class="flex items-center gap-2"><Play :size="17" /><h2>Editor de audio</h2></div>
                        <span v-if="selectedSlot" class="text-xs font-medium text-[#687780]">{{ selectedSlot.label }}</span>
                    </div>

                    <div v-if="selectedSlot" class="p-4 sm:p-5">
                        <div class="waveform" aria-hidden="true">
                            <span v-for="(height, index) in [28,52,38,74,46,82,34,66,44,90,56,72,32,62,48,84,40,70,52,78,36,64,46,86,54,68,30,76,42,60,50,80]" :key="index" :style="{ height: `${height}%` }"></span>
                        </div>

                        <audio ref="audioPlayer" class="mt-4 w-full" controls :src="previewUrl || undefined"></audio>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <label class="field-label">Inicio<input v-model="start" class="field" type="time" step="1"></label>
                            <label class="field-label">Fin<input v-model="end" class="field" type="time" step="1"></label>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button class="secondary-button" :disabled="loadingPreview || !rangeDuration" @click="loadPreview">
                                <LoaderCircle v-if="loadingPreview" :size="16" class="animate-spin" />
                                <Play v-else :size="16" />
                                {{ loadingPreview ? 'Preparando...' : 'Cargar audio' }}
                            </button>
                            <button class="secondary-button" :disabled="!previewUrl" @click="markAtPlayer('start')"><Clock3 :size="16" /> Marcar inicio</button>
                            <button class="secondary-button" :disabled="!previewUrl" @click="markAtPlayer('end')"><Clock3 :size="16" /> Marcar fin</button>
                        </div>

                        <div class="mt-5 flex flex-col gap-3 border-t border-[#e2e6e8] pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <span class="text-sm text-[#687780]">Duracion <strong class="text-[#172027]">{{ formatDuration(rangeDuration) }}</strong></span>
                            <button class="primary-button" :disabled="!rangeDuration" @click="addClip"><Plus :size="17" /> Agregar fragmento</button>
                        </div>
                    </div>

                    <div v-else class="grid min-h-72 place-items-center p-6 text-center text-sm text-[#687780]">
                        <div><Clock3 class="mx-auto mb-3" :size="28" /><p>Selecciona una hora disponible.</p></div>
                    </div>
                </section>

                <aside class="panel self-start">
                    <div class="panel-heading">
                        <div class="flex items-center gap-2"><ListMusic :size="17" /><h2>Lista de cortes</h2></div>
                        <span class="counter">{{ clips.length }}</span>
                    </div>

                    <div v-if="clips.length" class="divide-y divide-[#e2e6e8]">
                        <div v-for="(clip, index) in clips" :key="clip.id" class="flex items-center gap-3 p-3">
                            <span class="grid size-8 shrink-0 place-items-center rounded bg-[#edf4f4] text-xs font-bold text-[#176b72]">{{ index + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">{{ clip.start }} - {{ clip.end }}</p>
                                <p class="mt-0.5 text-xs text-[#687780]">{{ clip.date }} · {{ formatDuration(secondsFromTime(clip.end) - secondsFromTime(clip.start)) }}</p>
                            </div>
                            <div class="flex shrink-0">
                                <button class="mini-icon" title="Subir" :disabled="index === 0" @click="moveClip(index, -1)"><ArrowUp :size="15" /></button>
                                <button class="mini-icon" title="Bajar" :disabled="index === clips.length - 1" @click="moveClip(index, 1)"><ArrowDown :size="15" /></button>
                                <button class="mini-icon danger" title="Eliminar" @click="clips.splice(index, 1)"><Trash2 :size="15" /></button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="grid min-h-40 place-items-center px-4 text-center text-sm text-[#7b898f]">
                        <div><Scissors class="mx-auto mb-2" :size="25" /><p>Aun no hay fragmentos.</p></div>
                    </div>

                    <div class="border-t border-[#dfe4e6] bg-[#f8faf9] p-4">
                        <div class="mb-4 flex items-center justify-between text-sm">
                            <span class="text-[#687780]">Duracion total</span>
                            <strong>{{ formatDuration(totalDuration) }}</strong>
                        </div>
                        <div class="mb-3 grid grid-cols-2 rounded-md border border-[#cbd4d7] bg-white p-1">
                            <button class="format-option" :class="{ active: outputFormat === 'mp3' }" @click="outputFormat = 'mp3'">MP3</button>
                            <button class="format-option" :class="{ active: outputFormat === 'wav' }" @click="outputFormat = 'wav'">WAV</button>
                        </div>
                        <button class="download-button" :disabled="!clips.length || exporting" @click="downloadExport">
                            <LoaderCircle v-if="exporting" :size="18" class="animate-spin" />
                            <Download v-else :size="18" />
                            {{ exporting ? 'Uniendo audio...' : `Descargar ${outputFormat.toUpperCase()}` }}
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</template>
