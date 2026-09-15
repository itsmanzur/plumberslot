const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.TUTORSLOT_E2E_URL || 'http://themezur.local';
const serverCommand = process.env.TUTORSLOT_E2E_SERVER_COMMAND;
const browserPath = process.env.TUTORSLOT_E2E_BROWSER_PATH;
const launchOptions = browserPath ? { executablePath: browserPath } : {};

module.exports = defineConfig( {
	testDir: './tests/E2e',
	outputDir: 'test-results',
	reporter: [ [ 'list' ], [ 'html', { outputFolder: 'playwright-report', open: 'never' } ] ],
	use: {
		baseURL,
		launchOptions,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	webServer: serverCommand
		? {
				command: serverCommand,
				url: baseURL,
				reuseExistingServer: ! process.env.CI,
				timeout: 120000,
		  }
		: undefined,
	projects: [
		{
			name: 'chromium-desktop',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		{
			name: 'chromium-mobile',
			use: {
				...devices[ 'Pixel 5' ],
				viewport: { width: 390, height: 844 },
			},
		},
	],
} );
