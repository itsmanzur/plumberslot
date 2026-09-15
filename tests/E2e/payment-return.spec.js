import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );

async function fixture( action, fixtureKey, ...args ) {
	const phpArgs = [ fixtureScript, action, ...args ];
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

function paymentUrl( seed, outcome ) {
	return `${ seed.pagePath }&plumberslot_pay=${ outcome }&booking=${ seed.bookingId }`;
}

async function openPaymentReturn( page, seed, outcome ) {
	const bookingResponsePromise = page.waitForResponse(
		( response ) =>
			response.url().includes( `/bookings/${ seed.bookingId }` ) &&
			'GET' === response.request().method()
	);
	await page.goto( paymentUrl( seed, outcome ) );
	const response = await bookingResponsePromise;
	expect( response.status() ).toBe( 200 );

	return page.locator( '.plumberslot-widget.plumberslot-root' );
}

function preparePaymentTest( testInfo, state ) {
	testInfo.setTimeout( 90000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_PAYMENT_READY,
		'Set PLUMBERSLOT_E2E_PAYMENT_READY=1 to allow isolated payment fixture rows.'
	);

	return `${ testInfo.project.name }-${ state }`.replace(
		/[^a-z0-9_-]/g,
		'-'
	);
}

test( 'verified payment success renders confirmed completion', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = preparePaymentTest( testInfo, 'success' );
	const seed = await fixture( 'seed-payment', fixtureKey, 'success' );
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const widget = await openPaymentReturn( page, seed, 'success' );
		const heading = widget.getByRole( 'heading', {
			name: /Payment confirmed\./,
		} );

		await expect( heading ).toBeVisible();
		await expect( heading ).toBeFocused();
		await expect(
			widget.getByText( /English E2E with Farhana E2E/ )
		).toBeVisible();
		await expect( widget.getByText( /^TS-\d{6,}$/ ).first() ).toBeVisible();

		const database = await fixture( 'inspect', fixtureKey );
		expect( database.bookingCount ).toBe( 1 );
		expect( Number( database.booking.id ) ).toBe( seed.bookingId );
		expect( database.booking.status ).toBe( 'confirmed' );
		expect( database.booking.payment_ref ).toContain(
			'plumberslot-e2e-paid-'
		);
		expect( pageErrors ).toEqual( [] );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );

test( 'cancelled checkout renders recovery path and clears return flags', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = preparePaymentTest( testInfo, 'cancel' );
	const seed = await fixture( 'seed-payment', fixtureKey, 'cancel' );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const widget = await openPaymentReturn( page, seed, 'cancel' );
		const heading = widget.getByRole( 'heading', {
			name: 'Your payment was not completed',
		} );

		await expect( heading ).toBeVisible();
		await expect( heading ).toBeFocused();
		await expect(
			widget.getByText( 'No confirmed charge:' )
		).toBeVisible();
		await expect(
			widget.getByText( 'Cancelled', { exact: true } )
		).toBeVisible();

		await Promise.all( [
			page.waitForURL(
				( url ) =>
					! url.searchParams.has( 'plumberslot_pay' ) &&
					! url.searchParams.has( 'booking' )
			),
			widget.getByRole( 'button', { name: 'Return to booking' } ).click(),
		] );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );

test( 'failed payment renders retry-safe failure state', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = preparePaymentTest( testInfo, 'failure' );
	const seed = await fixture( 'seed-payment', fixtureKey, 'failure' );
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const widget = await openPaymentReturn( page, seed, 'success' );
		const heading = widget.getByRole( 'heading', {
			name: 'We could not complete the payment',
		} );

		await expect( heading ).toBeVisible();
		await expect( heading ).toBeFocused();
		await expect( widget.getByText( 'Payment failed:' ) ).toBeVisible();
		await expect(
			widget.getByText( 'Failed', { exact: true } )
		).toBeVisible();

		const database = await fixture( 'inspect', fixtureKey );
		expect( database.bookingCount ).toBe( 1 );
		expect( database.booking.status ).toBe( 'payment_failed' );
		expect( pageErrors ).toEqual( [] );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
