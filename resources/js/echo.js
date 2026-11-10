import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// The server tells the page where Reverb is (so one built image works on any domain); anything it leaves
// empty falls back to the page's own address, then to the values compiled in for local development.
let runtime = {};
try {
    runtime = JSON.parse(document.querySelector('meta[name="jogger-reverb"]')?.content ?? '{}');
} catch (e) {
    runtime = {};
}

const scheme = runtime.scheme || import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '');
const port = runtime.port || import.meta.env.VITE_REVERB_PORT || window.location.port || (scheme === 'https' ? 443 : 80);

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: runtime.key || import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: runtime.host || import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
