import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );

async function fixture( action, projectName ) {
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
			PLUMBERSLOT_E2E_FIXTURE_KEY: projectName,
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

test( 'customer completes the public booking flow', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 90000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_HAPPY_READY,
		'Set PLUMBERSLOT_E2E_HAPPY_READY=1 to allow isolated happy-path fixture rows.'
	);

	const fixtureKey = testInfo.project.name.replace( /[^a-z0-9_-]/g, '-' );
	const seed = await fixture( 'seed', fixtureKey );
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const technicianResponsePromise = page.waitForResponse( ( response ) =>
			response.url().includes( `/public/technicians/${ seed.technicianId }` )
		);
		await page.goto( seed.pagePath );
		const technicianResponse = await technicianResponsePromise;
		expect( technicianResponse.status() ).toBe( 200 );

		const widget = page.locator( '.plumberslot-widget.plumberslot-root' );
		await expect( widget ).toBeVisible();
		await expect(
			widget.getByRole( 'heading', {
				name: 'What do you want to work on?',
			} )
		).toBeVisible();

		await widget.getByRole( 'option', { name: /English E2E/ } ).click();
		const slotsResponsePromise = page.waitForResponse( ( response ) =>
			response.url().includes( '/wp-json/plumberslot/v1/slots?' )
		);
		await widget.getByRole( 'button', { name: 'Choose a time →' } ).click();
		const slotsResponse = await slotsResponsePromise;
		expect( slotsResponse.status() ).toBe( 200 );
		const slotsPayload = await slotsResponse.json();
		expect(
			slotsPayload.slots.filter( ( slot ) => 'open' === slot.state )
				.length
		).toBeGreaterThan( 0 );

		await expect(
			widget.getByRole( 'heading', { name: 'When works for you?' } )
		).toBeVisible();
		const openTimes = widget.getByRole( 'listbox', { name: 'Open times' } );
		const firstOpenTime = openTimes
			.locator( '[role="option"]:not([disabled])' )
			.first();
		await expect( firstOpenTime ).toBeVisible( { timeout: 15000 } );
		await firstOpenTime.click();
		await widget.getByRole( 'button', { name: 'Continue →' } ).click();

		await expect(
			widget.getByRole( 'heading', { name: 'Confirm and book' } )
		).toBeVisible();
		await expect( widget.getByText( /^Held for / ) ).toBeVisible();
		await widget
			.getByPlaceholder( 'Anything helpful before the appointment' )
			.fill( 'Playwright happy-path booking.' );

		const bookingResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/wp-json/plumberslot/v1/bookings' ) &&
				'POST' === response.request().method()
		);
		await widget.getByRole( 'button', { name: 'Confirm booking' } ).click();
		const response = await bookingResponse;
		expect( response.status() ).toBe( 201 );
		const payload = await response.json();
		expect( payload.id ).toBeGreaterThan( 0 );

		await expect(
			widget.getByText( /Booking confirmed/ ).first()
		).toBeVisible();
		await expect(
			widget.getByRole( 'heading', { name: /Booked\./ } )
		).toBeFocused();
		await expect(
			widget.getByText( /English E2E with Farhana E2E/ )
		).toBeVisible();
		await expect( widget.getByText( /^TS-\d{6,}$/ ).first() ).toBeVisible();

		const database = await fixture( 'inspect', fixtureKey );
		expect( database.bookingCount ).toBe( 1 );
		expect( Number( database.booking.id ) ).toBe( payload.id );
		expect( Number( database.booking.service_id ) ).toBe( seed.serviceId );
		expect( database.booking.status ).toBe( 'confirmed' );
		expect( pageErrors ).toEqual( [] );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
