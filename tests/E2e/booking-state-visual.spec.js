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

async function dismissConsent( page ) {
	const consentButton = page.locator(
		'#consentaro-banner [data-consentaro-action="accept-all"]'
	);
	if ( await consentButton.isVisible() ) {
		await consentButton.click();
		await page
			.locator( '#consentaro-banner, #consentaro-banner-modal' )
			.evaluateAll( ( elements ) =>
				elements.forEach( ( element ) => element.remove() )
			);
		await expect( page.locator( '#consentaro-banner' ) ).toBeHidden();
	}
}

function prepareVisualTest( testInfo, state ) {
	testInfo.setTimeout( 120000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_VISUAL_READY,
		'Set PLUMBERSLOT_E2E_VISUAL_READY=1 to allow isolated visual fixture rows.'
	);
	const viewport = testInfo.project.name.includes( 'mobile' )
		? 'mob'
		: 'desk';

	return `${ viewport }-${ state }`;
}

async function freezeCountdown( page ) {
	await page.addInitScript( () => {
		const nativeSetInterval = window.setInterval.bind( window );
		window.setInterval = ( callback, delay, ...args ) => {
			if ( 1000 === delay ) {
				return 0;
			}
			return nativeSetInterval( callback, delay, ...args );
		};
	} );
}

async function openConfirmStep( page, seed ) {
	await logIn( page, seed.aliceLogin, seed.password );
	const technicianResponse = page.waitForResponse( ( response ) =>
		response.url().includes( `/public/technicians/${ seed.technicianId }` )
	);
	await page.goto( seed.pagePath );
	expect( ( await technicianResponse ).status() ).toBe( 200 );
	await dismissConsent( page );

	const widget = page.locator( '.plumberslot-widget.plumberslot-root' );
	await widget.getByRole( 'option', { name: /English E2E/ } ).click();
	const slotsResponse = page.waitForResponse( ( response ) =>
		response.url().includes( '/wp-json/plumberslot/v1/slots?' )
	);
	await widget.getByRole( 'button', { name: 'Choose a time →' } ).click();
	expect( ( await slotsResponse ).status() ).toBe( 200 );

	const firstOpenTime = widget
		.getByRole( 'listbox', { name: 'Open times' } )
		.locator( '[role="option"]:not([disabled])' )
		.first();
	await expect( firstOpenTime ).toBeVisible();
	await firstOpenTime.click();
	const holdResponse = page.waitForResponse(
		( response ) =>
			response
				.url()
				.includes( '/wp-json/plumberslot/v1/bookings/hold' ) &&
			'POST' === response.request().method()
	);
	await widget.getByRole( 'button', { name: 'Continue →' } ).click();
	expect( ( await holdResponse ).status() ).toBe( 200 );
	await expect(
		widget.getByRole( 'heading', { name: 'Confirm and book' } )
	).toBeVisible();
	await expect( widget.getByText( /^Held for / ) ).toBeVisible();

	return widget;
}

async function replaceText( locator, value ) {
	await expect( locator ).toBeVisible();
	await locator.evaluate( ( element, text ) => {
		element.textContent = text;
	}, value );
}

async function replaceValueRow( root, label, value ) {
	const row = root
		.locator( ':scope > div' )
		.filter( { hasText: label } )
		.first();
	await replaceText( row.locator( 'dd' ), value );
}

async function snapshot( widget, name ) {
	const page = widget.page();
	await page
		.locator( '.tz-mobile-bottom-nav, .ts-live' )
		.evaluateAll( ( elements ) =>
			elements.forEach( ( element ) => element.remove() )
		);
	await page.evaluate( () => document.fonts.ready );
	await expect( widget ).toHaveScreenshot( name, {
		animations: 'disabled',
		caret: 'hide',
		maxDiffPixelRatio: 0.005,
	} );
}

test( 'booking confirm state matches its visual baseline', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareVisualTest( testInfo, 'confirm' );
	const seed = await fixture( 'seed', fixtureKey );

	try {
		await freezeCountdown( page );
		const widget = await openConfirmStep( page, seed );
		await replaceValueRow(
			widget.locator( '.ts-book__summary' ),
			'When',
			'Monday, 10 August 2026 at 10:00 AM'
		);
		await snapshot( widget, 'booking-confirm.png' );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );

test( 'payment failure state matches its visual baseline', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareVisualTest( testInfo, 'payment' );
	const seed = await fixture( 'seed-payment', fixtureKey, 'failure' );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const bookingResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( `/bookings/${ seed.bookingId }` ) &&
				'GET' === response.request().method()
		);
		await page.goto(
			`${ seed.pagePath }&plumberslot_pay=success&booking=${ seed.bookingId }`
		);
		expect( ( await bookingResponse ).status() ).toBe( 200 );
		await dismissConsent( page );

		const widget = page.locator( '.plumberslot-widget.plumberslot-root' );
		await expect(
			widget.getByRole( 'heading', {
				name: 'We could not complete the payment',
			} )
		).toBeVisible();
		const details = widget.locator( '.ts-book__result-details' );
		await replaceValueRow( details, 'Booking', 'TS-000000' );
		await replaceValueRow(
			details,
			'When',
			'Monday, 10 August 2026 at 10:00 AM'
		);
		await snapshot( widget, 'payment-failure.png' );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );

test( 'booking completion state matches its visual baseline', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareVisualTest( testInfo, 'complete' );
	const seed = await fixture( 'seed', fixtureKey );

	try {
		await freezeCountdown( page );
		const widget = await openConfirmStep( page, seed );
		const bookingResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/wp-json/plumberslot/v1/bookings' ) &&
				'POST' === response.request().method()
		);
		await widget.getByRole( 'button', { name: 'Confirm booking' } ).click();
		expect( ( await bookingResponse ).status() ).toBe( 201 );
		await expect(
			widget.getByRole( 'heading', { name: /^Booked\./ } )
		).toBeVisible();

		await replaceText(
			widget.locator( '.ts-book__result-kicker .plumberslot-mono' ),
			'TS-000000'
		);
		await replaceText(
			widget.getByRole( 'heading', { name: /^Booked\./ } ),
			'Booked. You are set for Monday.'
		);
		await replaceText(
			widget.locator( '.ts-book__cal-top .plumberslot-mono' ),
			'10 AUG'
		);
		const details = widget.locator( '.ts-book__cal-mid .ts-book__kv' );
		await replaceValueRow( details, 'Booking', 'TS-000000' );
		await replaceValueRow( details, 'Starts', '10:00 AM UTC' );
		const deadline = widget.getByText( /^Need a different time\?/ );
		if ( await deadline.isVisible() ) {
			await replaceText(
				deadline,
				'Need a different time? You can move this appointment yourself until Sunday, 9 August 2026 at 10:00 AM.'
			);
		}
		await snapshot( widget, 'booking-completion.png' );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
