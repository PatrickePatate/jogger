<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /** Starts ChromeDriver unless one is already running (set DUSK_DRIVER_URL to use a remote one). */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail() && ! getenv('DUSK_DRIVER_URL')) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments([
            '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage', '--window-size=1400,1000',
            '--hide-scrollbars', '--force-device-scale-factor=1',
        ]);

        // Playwright's Chromium (or any non-default Chrome) when there is no system Chrome.
        if ($binary = env('DUSK_CHROME_BINARY')) {
            $options->setBinary($binary);
        }

        return RemoteWebDriver::create(
            getenv('DUSK_DRIVER_URL') ?: 'http://localhost:9515',
            DesiredCapabilities::chrome()
                ->setCapability(ChromeOptions::CAPABILITY, $options)
                ->setCapability('goog:loggingPrefs', ['browser' => 'ALL']),
        );
    }

    /** Fails when the browser console reports a Content-Security-Policy violation. */
    protected function assertNoCspViolations(Browser $browser): void
    {
        $violations = Collection::make($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry) => stripos($entry['message'], 'Content Security Policy') !== false
                || stripos($entry['message'], 'Content-Security-Policy') !== false)
            ->pluck('message');

        $this->assertTrue($violations->isEmpty(), "CSP violations in the browser console:\n".$violations->implode("\n"));
    }
}
