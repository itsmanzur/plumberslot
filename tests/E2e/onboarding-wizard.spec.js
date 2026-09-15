import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );

async function fixture( action, fixtureKey ) {
	const phpArgs = [ fixtureScript, action ];
	const phpBinary = process.env.PLUMBERSLOT_E2E_PHP_BINARY || 'php';

	if ( 'win32' === process.platform ) {
		phpArgs.unshift(
			'-n',
			'-d',
			`extension_dir=${ path.join( path.dirname( phpBinary ), 'ext' ) }`,
			'-d',
			'extension=php_mysqli.dll'
		);
	}

	const { stdout } = await execute( phpBinary, phpArgs, {
		env: {
			...process.env,
			PLUMBERSLOT_E2E_FIXTURE_KEY: fixtureKey,
		},
		timeout: 30000,
		windowsHide: true,
	} );
	const line = stdout
		.trim()
		.split( /\r?\n/ )
		.reverse()
		.find( ( candidate ) => candidate.trim().startsWith( '{' ) );

	if ( ! line ) {
		throw new Error( 'Fixture returned no JSON response.' );
	}

	return JSON.parse( line );
}

async function logIn( page, login, password ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( login );
	await page.locator( '#user_pass' ).fill( password );
	await Promise.all( [
		page.waitForURL( ( url ) => ! url.pathname.endsWith( 'wp-login.php' ) ),
		page.locator( '#wp-submit' ).click(),
	] );
}

function prepareOnboardingTest( testInfo ) {
	testInfo.setTimeout( 90000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_ONBOARDING_READY,
		'Set PLUMBERSLOT_E2E_ONBOARDING_READY=1 to allow isolated onboarding fixture rows.'
	);

	return `${ testInfo.project.name }-onboarding`.replace(
		/[^a-z0-9_-]/g,
		'-'
	);
}

test( 'manager completes the four-step onboarding wizard', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareOnboardingTest( testInfo );
	const seed = await fixture( 'seed-onboarding', fixtureKey );

	try {
		await logIn( page, seed.technicianLogin, seed.password );
		const statusResponse = page.waitForResponse(
			( response ) =>
				response.url().endsWith( '/plumberslot/v1/setup' ) &&
				'GET' === response.request().method()
		);
		await page.goto( '/wp-admin/admin.php?page=plumberslot-setup' );
		expect( ( await statusResponse ).status() ).toBe( 200 );

		await expect(
			page.getByRole( 'heading', { name: 'Who teaches here?' } )
		).toBeVisible();
		await page.getByRole( 'button', { name: /Coaching centre/ } ).click();
		await page.getByRole( 'button', { name: 'Continue →' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Services you teach' } )
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Physics', exact: true } )
			.click();
		await expect( page.getByText( 'Selected: Physics' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Continue →' } ).click();

		await expect(
			page.getByRole( 'heading', {
				name: 'Paint the hours customers can book',
			} )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Weekend mornings' } ).click();
		await page.getByRole( 'button', { name: 'Continue →' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Payments' } )
		).toBeVisible();
		const payments = page.getByRole( 'switch', {
			name: 'Enable online payments',
		} );
		await expect( payments ).toHaveAttribute( 'aria-checked', 'true' );
		await payments.click();
		await expect( payments ).toHaveAttribute( 'aria-checked', 'false' );

		const completionResponse = page.waitForResponse(
			( response ) =>
				response.url().endsWith( '/plumberslot/v1/setup' ) &&
				'POST' === response.request().method()
		);
		await page.getByRole( 'button', { name: 'Finish' } ).click();
		const response = await completionResponse;
		expect( response.status() ).toBe( 200 );
		const payload = await response.json();
		expect( payload.completed ).toBe( true );
		expect( payload.setup_mode ).toBe( 'centre' );

		await expect(
			page.getByRole( 'heading', { name: 'You are bookable' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Invite technicians' } )
		).toBeVisible();
		await page.getByText( 'WordPress shortcode' ).click();
		await expect(
			page.getByText(
				`[plumberslot technician="${ seed.technicianSlug }"]`
			)
		).toBeVisible();

		const database = await fixture( 'inspect-onboarding', fixtureKey );
		expect( database.completed ).toBe( '1' );
		expect( database.technician.status ).toBe( 'active' );
		expect( database.services ).toContain( 'Physics' );
		expect( database.availabilityCount ).toBeGreaterThan( 0 );
		expect( database.setupMode ).toBe( 'centre' );
		expect( database.paymentsEnabled ).toBe( false );
		expect( database.pageContent ).toBe(
			`[plumberslot technician="${ seed.technicianSlug }"]`
		);
		expect( database.auditCount ).toBe( 1 );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
