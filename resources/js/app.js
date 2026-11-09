import './echo';

// Alpine (with focus/collapse plugins) is injected and started by Livewire.
// Components keep their Alpine logic inline so load order never matters.

window.joggerTheme = {
    // 'light' | 'dark' | 'system'
    get() {
        try {
            return localStorage.getItem('theme') || 'system';
        } catch (e) {
            return 'system';
        }
    },
    apply(mode) {
        const dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
    },
    set(mode) {
        try {
            localStorage.setItem('theme', mode);
        } catch (e) {
            // storage unavailable (private mode): theme still applies for this page view
        }
        this.apply(mode);
    },
};

window.joggerTheme.apply(window.joggerTheme.get());

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (window.joggerTheme.get() === 'system') {
        window.joggerTheme.apply('system');
    }
});

/** Show a toast from anywhere: toast({ type: 'success', message: 'Saved' }) */
window.toast = (detail) => window.dispatchEvent(new CustomEvent('toast', { detail }));
