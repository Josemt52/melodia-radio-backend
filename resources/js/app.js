import './bootstrap';
import { createApp } from 'vue';
import App from './App.vue';
import DeveloperPanel from './DeveloperPanel.vue';

const rootComponent = document.body.dataset.panel === 'developer' ? DeveloperPanel : App;
createApp(rootComponent).mount('#app');
