import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import { SearchAddon } from '@xterm/addon-search';
import '@xterm/xterm/css/xterm.css';

const WINDOW = 512 * 1024; // bytes of scrollback fetched per request
const MAX_LINES = 50000;

/**
 * Alpine component for a job's log: loads what is stored, then follows the live stream.
 * Stored text and live chunks are merged by sequence number: the scrollback response says which `seq` it ends at,
 * and only chunks after that are written, so a reload or a reconnect never repeats or skips lines.
 */
window.logTerminal = ({ url, jobId }) => ({
    query: '',
    status: 'Loading…',
    earlier: false,
    firstOffset: 0,
    nextOffset: 0,
    lastSeq: 0,
    lastPart: -1,
    finalized: false,
    buffered: [],
    loading: true,

    init() {
        this.term = new Terminal({
            convertEol: true,
            disableStdin: true,
            scrollback: MAX_LINES,
            fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            fontSize: 13,
            theme: { background: '#0f172a', foreground: '#e2e8f0', cursor: '#0f172a', selectionBackground: '#334155' },
        });
        this.fit = new FitAddon();
        this.search = new SearchAddon();
        this.term.loadAddon(this.fit);
        this.term.loadAddon(this.search);
        this.term.open(this.$refs.terminal);
        this.fit.fit();

        this.onResize = () => this.fit.fit();
        window.addEventListener('resize', this.onResize);

        // Subscribe first and hold chunks back until the stored part is in, so nothing falls between the two.
        this.channel = window.Echo?.private(`job.${jobId}`).listen('.log.chunk', (e) => this.receive(e));
        window.Echo?.connector?.pusher?.connection?.bind('connected', () => {
            if (!this.loading && !this.finalized) this.catchUp();
        });

        this.load(-WINDOW);
    },

    destroy() {
        window.removeEventListener('resize', this.onResize);
        window.Echo?.leave(`job.${jobId}`);
        this.term?.dispose();
    },

    async fetchWindow(offset, limit = WINDOW) {
        const response = await fetch(`${url}?offset=${offset}&limit=${limit}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    },

    async load(offset) {
        try {
            const page = await this.fetchWindow(offset);
            this.term.write(page.data);
            this.firstOffset = page.offset;
            this.nextOffset = page.next_offset;
            this.earlier = page.offset > 0;
            this.apply(page);
        } catch (e) {
            this.status = 'Could not load the log.';
        } finally {
            this.loading = false;
            this.buffered.splice(0).forEach((e) => this.receive(e));
        }
    },

    /** After a reconnect: fetch what was stored since, by offset, then resume the stream. */
    async catchUp() {
        this.loading = true;
        try {
            let page;
            do {
                page = await this.fetchWindow(this.nextOffset);
                this.term.write(page.data);
                this.nextOffset = page.next_offset;
                this.apply(page);
            } while (page.next_offset < page.size);
        } catch (e) {
            // The next reconnect tries again.
        } finally {
            this.loading = false;
            this.buffered.splice(0).forEach((e) => this.receive(e));
        }
    },

    apply(page) {
        this.lastSeq = Math.max(this.lastSeq, page.last_seq);
        this.lastPart = Number.MAX_SAFE_INTEGER; // everything of last_seq is in the stored text
        this.finalized = page.finalized;
        this.status = page.size === 0 ? (page.finalized ? 'No output.' : 'Waiting for output…')
            : (page.finalized ? 'Complete' : 'Live') + (page.truncated ? ' · truncated' : '');
    },

    receive(e) {
        if (this.loading) {
            this.buffered.push(e);
            return;
        }
        const isNew = e.seq > this.lastSeq || (e.seq === this.lastSeq && e.part > this.lastPart);
        if (!isNew) return;

        this.term.write(e.data);
        this.nextOffset += new TextEncoder().encode(e.data).length;
        this.lastSeq = e.seq;
        this.lastPart = e.part;
        this.status = 'Live';
    },

    /** Older text cannot be inserted above what a terminal already shows, so the view is rebuilt from further back. */
    async loadEarlier() {
        const from = Math.max(0, this.firstOffset - WINDOW);
        this.loading = true;
        try {
            this.term.reset();
            let offset = from;
            let page;
            do {
                page = await this.fetchWindow(offset);
                if (offset === from) this.firstOffset = page.offset;
                this.term.write(page.data);
                offset = page.next_offset;
            } while (offset < page.size && page.data.length > 0);
            this.nextOffset = offset;
            this.earlier = this.firstOffset > 0;
            this.apply(page);
        } catch (e) {
            this.status = 'Could not load earlier lines.';
        } finally {
            this.loading = false;
            this.buffered.splice(0).forEach((e) => this.receive(e));
        }
    },

    find(backwards) {
        if (this.query === '') return;
        const found = backwards ? this.search.findPrevious(this.query) : this.search.findNext(this.query);
        if (!found) this.status = 'No match';
    },
});
