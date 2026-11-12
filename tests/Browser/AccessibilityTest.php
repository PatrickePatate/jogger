<?php

namespace Tests\Browser;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class AccessibilityTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const AXE = 'node_modules/axe-core/axe.min.js';

    /** @var array<string, string> page name => path */
    private array $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $installation = Installation::factory()->create();
        $repository = Repository::factory()->create(['installation_id' => $installation->id, 'full_name' => 'acme/api']);
        $pool = RunnerPool::factory()->create(['installation_id' => $installation->id, 'name' => 'pool-a11y']);
        $host = Host::factory()->create(['name' => 'host-a11y']);
        $runner = Runner::factory()->create(['pool_id' => $pool->id, 'host_id' => $host->id]);

        $job = WorkflowJob::factory()->completed()->create([
            'repository_id' => $repository->id, 'pool_id' => $pool->id, 'runner_id' => $runner->id, 'job_name' => 'a11y-done',
        ]);
        // One row per remaining status so every pill tone is scanned.
        WorkflowJob::factory()->create(['repository_id' => $repository->id, 'job_name' => 'a11y-queued']);
        WorkflowJob::factory()->running()->create(['repository_id' => $repository->id, 'pool_id' => $pool->id, 'job_name' => 'a11y-running']);
        WorkflowJob::factory()->completed(JobConclusion::Failure)->create(['repository_id' => $repository->id, 'job_name' => 'a11y-failed']);

        $this->pages = [
            'Overview' => '/',
            'Jobs' => '/jobs',
            'Job detail' => "/jobs/{$job->id}",
            'Pools' => '/pools',
            'Hosts' => '/hosts',
            'Settings' => '/admin/settings',
            'Settings: security' => '/settings/security',
        ];
    }

    #[Test]
    public function pages_have_no_serious_or_critical_axe_violations_in_either_theme(): void
    {
        $this->assertFileExists(base_path(self::AXE), 'axe-core is missing: run `npm ci`.');
        $axe = file_get_contents(base_path(self::AXE));

        $this->browse(function (Browser $browser) use ($axe) {
            $browser->loginAs(User::factory()->admin()->create());
            $browser->driver->manage()->timeouts()->setScriptTimeout(30);
            $failures = [];

            foreach (['light', 'dark'] as $theme) {
                foreach ($this->pages as $name => $path) {
                    $browser->visit($path)->script("localStorage.setItem('theme', '{$theme}')");
                    $browser->visit($path)->waitFor('main')->pause(400); // Alpine/Livewire settle, transitions end

                    $this->assertSame($theme === 'dark', $browser->script('return document.documentElement.classList.contains("dark")')[0], "The {$theme} theme was not applied.");
                    array_push($failures, ...$this->axeViolations($browser, $axe, "[{$theme}] {$name} ({$path})"));
                }

                // One open-menu state: the account dropdown (role=menu and its items only exist for axe once visible).
                $browser->click('header button[aria-haspopup="menu"]')->waitFor('[role="menu"]')->pause(300);
                $this->assertSame('true', $browser->attribute('header button[aria-haspopup="menu"]', 'aria-expanded'));
                array_push($failures, ...$this->axeViolations($browser, $axe, "[{$theme}] account menu open"));
            }

            $browser->script("localStorage.removeItem('theme')");
            $this->assertTrue($failures === [], "axe-core serious/critical violations:\n".implode("\n", $failures));
        });
    }

    #[Test]
    public function the_skip_link_is_the_first_focusable_element_and_moves_focus_to_main(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(User::factory()->admin()->create());

            foreach ($this->pages as $name => $path) {
                $browser->visit($path)->waitFor('main');
                $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::TAB);

                $this->assertSame(
                    ['A', '#main', 'Skip to content'],
                    $browser->script('const a = document.activeElement; return [a.tagName, a.getAttribute("href"), a.textContent.trim()];')[0],
                    "{$name}: the first Tab stop is not the skip link.",
                );
                $this->assertTrue(
                    $browser->script('const r = document.activeElement.getBoundingClientRect(); return r.width > 1 && r.height > 1 && r.left >= 0 && r.top >= 0;')[0],
                    "{$name}: the focused skip link is not visible.",
                );

                $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
                $browser->pause(100);

                $this->assertTrue(
                    $browser->script('return document.activeElement === document.querySelector("main#main");')[0],
                    "{$name}: activating the skip link did not move focus to <main>.",
                );
            }
        });
    }

    #[Test]
    public function pages_fit_a_360px_viewport_and_tables_stack_or_scroll_in_their_own_container(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(User::factory()->admin()->create());
            // Chrome clamps real window widths near 500 px, so the viewport is emulated instead.
            $this->emulateViewport($browser, 360, 800);

            try {
                foreach ($this->pages as $name => $path) {
                    $browser->visit($path)->waitFor('main')->pause(300);

                    $result = $browser->script(<<<'JS'
                        const doc = document.documentElement;
                        const scrolls = (el) => { for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { if (['auto', 'scroll'].includes(getComputedStyle(p).overflowX)) return true; } return false; };
                        const tables = [...document.querySelectorAll('table')].filter((t) => t.getClientRects().length).map((t) => {
                            const row = t.querySelector('tbody tr');
                            const stacked = t.classList.contains('table-stack') && (!row || getComputedStyle(row).display === 'block');
                            return { stacked, scrolls: scrolls(t), fits: t.getBoundingClientRect().right <= doc.clientWidth + 1 };
                        });
                        // Widest offenders, to make a failure actionable.
                        const wide = [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right > doc.clientWidth + 1 && !scrolls(e) && getComputedStyle(e).position !== 'fixed')
                            .slice(0, 5).map((e) => e.tagName.toLowerCase() + '.' + String(e.className.baseVal ?? e.className).split(' ').slice(0, 4).join('.'));
                        return { inner: window.innerWidth, scrollWidth: doc.scrollWidth, clientWidth: doc.clientWidth, tables, wide };
                    JS)[0];

                    $this->assertSame(360, $result['inner'], "{$name}: the viewport is not 360 px wide.");
                    $this->assertLessThanOrEqual(
                        $result['clientWidth'], $result['scrollWidth'],
                        "{$name}: the page overflows horizontally at 360 px. Offenders: ".implode(', ', $result['wide']),
                    );
                    foreach ($result['tables'] as $i => $table) {
                        $this->assertTrue(
                            $table['stacked'] || $table['scrolls'],
                            "{$name}: table #{$i} neither stacks into cards nor scrolls inside its own container.",
                        );
                        if ($table['stacked']) {
                            $this->assertTrue($table['fits'], "{$name}: stacked table #{$i} is wider than the viewport.");
                        }
                    }
                }
            } finally {
                $browser->driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', ['cmd' => 'Emulation.clearDeviceMetricsOverride', 'params' => (object) []]);
            }
        });
    }

    /**
     * Runs axe-core (injected from node_modules by the driver: no CDN, the page's CSP is untouched).
     *
     * @return list<string> one line per failing node: rule id, impact, target and axe's summary
     */
    private function axeViolations(Browser $browser, string $axe, string $where): array
    {
        $browser->driver->executeScript($axe);
        $violations = $browser->driver->executeAsyncScript(<<<'JS'
            const done = arguments[arguments.length - 1];
            axe.run(document, { resultTypes: ['violations'] }).then(
                (r) => done(r.violations
                    .filter((v) => ['serious', 'critical'].includes(v.impact))
                    .map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.map((n) => n.target.join(' ') + ' :: ' + (n.failureSummary || '').replace(/\s+/g, ' ')) }))),
                (e) => done([{ id: 'axe-error', impact: String(e), nodes: [] }]),
            );
        JS);

        $lines = [];
        foreach ($violations as $violation) {
            foreach ($violation['nodes'] ?: ['(no node)'] as $node) {
                $lines[] = "{$where}: {$violation['id']} [{$violation['impact']}] {$node}";
            }
        }

        return $lines;
    }

    private function emulateViewport(Browser $browser, int $width, int $height): void
    {
        $browser->driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
            'cmd' => 'Emulation.setDeviceMetricsOverride',
            'params' => ['width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => false],
        ]);
    }
}
