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

async function goTo( page, url ) {
	let lastError;

	for ( let attempt = 0; attempt < 3; attempt++ ) {
		try {
			return await page.goto( url );
		} catch ( error ) {
			lastError = error;
			if (
				2 === attempt ||
				! /ERR_CONNECTION_(?:REFUSED|RESET)/.test( error.message )
			) {
				throw error;
			}
			await page.waitForTimeout( 1500 );
		}
	}

	throw lastError;
}

async function logIn( page, login, password ) {
	await goTo( page, '/wp-login.php' );
	await page.locator( '#user_login' ).fill( login );
	await page.locator( '#user_pass' ).fill( password );
	await Promise.all( [
		page.waitForURL( ( url ) => ! url.pathname.endsWith( 'wp-login.php' ) ),
		page.locator( '#wp-submit' ).click(),
	] );
}

async function isolateWidget( page ) {
	await page
		.locator(
			'#consentaro-banner, #consentaro-banner-modal, .tz-mobile-bottom-nav'
		)
		.evaluateAll( ( elements ) =>
			elements.forEach( ( element ) => element.remove() )
		);
}

async function tabTo( page, target, label, limit = 120 ) {
	await expect( target, `${ label } is not available.` ).toBeVisible();

	for ( let presses = 0; presses < limit; presses++ ) {
		await page.keyboard.press( 'Tab' );
		if (
			await target.evaluate(
				( element ) => element === element.ownerDocument.activeElement
			)
		) {
			return;
		}
	}

	throw new Error( `${ label } was not reached by keyboard.` );
}

async function expectVisibleFocusRing( target, label ) {
	await expect( target, `${ label } is not focused.` ).toBeFocused();
	const focus = await target.evaluate( ( element ) => {
		const style =
			element.ownerDocument.defaultView.getComputedStyle( element );
		return {
			color: style.outlineColor,
			offset: Number.parseFloat( style.outlineOffset ),
			style: style.outlineStyle,
			width: Number.parseFloat( style.outlineWidth ),
		};
	} );

	expect( focus.style, `${ label } outline style` ).not.toBe( 'none' );
	expect( focus.width, `${ label } outline width` ).toBeGreaterThanOrEqual(
		2
	);
	expect( focus.offset, `${ label } outline offset` ).toBeGreaterThanOrEqual(
		1
	);
	expect( focus.color, `${ label } outline color` ).not.toMatch(
		/^(?:transparent|rgba\([^)]*,\s*0\))$/
	);
}

test( 'booking controls retain visible keyboard focus rings', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 150000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_HAPPY_READY,
		'Set PLUMBERSLOT_E2E_HAPPY_READY=1 to allow isolated focus-ring fixture rows.'
	);
	const project = testInfo.project.name.includes( 'mobile' ) ? 'mob' : 'desk';
	const fixtureKey = `${ project }-focus-ring`;
	const seed = await fixture( 'seed', fixtureKey );
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const technicianResponse = page.waitForResponse( ( response ) =>
			response
				.url()
				.includes( `/public/technicians/${ seed.technicianId }` )
		);
		await goTo( page, seed.pagePath );
		expect( ( await technicianResponse ).status() ).toBe( 200 );
		await isolateWidget( page );

		const widget = page.locator( '.plumberslot-widget.plumberslot-root' );
		const serviceList = widget.getByRole( 'listbox', { name: 'Services' } );
		const firstService = serviceList.getByRole( 'option' ).first();
		await tabTo( page, firstService, 'Service option' );
		await expectVisibleFocusRing( firstService, 'Service option' );
		await page.keyboard.press( 'ArrowRight' );
		const selectedService = serviceList.locator( '[aria-selected="true"]' );
		await expectVisibleFocusRing(
			selectedService,
			'Arrow-selected service option'
		);

		const chooseTime = widget.getByRole( 'button', {
			name: 'Choose a time →',
		} );
		await tabTo( page, chooseTime, 'Choose a time button' );
		await expectVisibleFocusRing( chooseTime, 'Choose a time button' );
		const slotsResponse = page.waitForResponse( ( response ) =>
			response.url().includes( '/wp-json/plumberslot/v1/slots?' )
		);
		await page.keyboard.press( 'Enter' );
		expect( ( await slotsResponse ).status() ).toBe( 200 );

		const timezone = widget.getByRole( 'button', { name: 'Change' } );
		await tabTo( page, timezone, 'Timezone button' );
		await expectVisibleFocusRing( timezone, 'Timezone button' );
		const openDay = widget.locator( '.ts-day:not(:disabled)' ).first();
		await tabTo( page, openDay, 'Available day' );
		await expectVisibleFocusRing( openDay, 'Available day' );

		const openTimes = widget.getByRole( 'listbox', { name: 'Open times' } );
		const firstOpenTime = openTimes
			.locator( '[role="option"]:not([disabled])' )
			.first();
		await tabTo( page, firstOpenTime, 'Open time' );
		await expectVisibleFocusRing( firstOpenTime, 'Open time' );
		await page.keyboard.press( 'ArrowRight' );
		const selectedTime = openTimes.locator( '[aria-selected="true"]' );
		await expectVisibleFocusRing(
			selectedTime,
			'Arrow-selected open time'
		);

		const continueButton = widget.getByRole( 'button', {
			name: 'Continue →',
		} );
		await tabTo( page, continueButton, 'Continue button' );
		await expectVisibleFocusRing( continueButton, 'Continue button' );
		const holdResponse = page.waitForResponse(
			( response ) =>
				response
					.url()
					.includes( '/wp-json/plumberslot/v1/bookings/hold' ) &&
				'POST' === response.request().method()
		);
		await page.keyboard.press( 'Enter' );
		expect( ( await holdResponse ).status() ).toBe( 200 );

		const notes = widget.getByPlaceholder(
			'Anything helpful before the appointment'
		);
		await tabTo( page, notes, 'Appointment note field' );
		await expectVisibleFocusRing( notes, 'Appointment note field' );
		const weeklyCourse = widget.getByText( 'Book as a weekly course', {
			exact: true,
		} );
		await tabTo( page, weeklyCourse, 'Weekly-course summary' );
		await expectVisibleFocusRing( weeklyCourse, 'Weekly-course summary' );

		const backButton = widget.getByRole( 'button', { name: '← Back' } );
		await tabTo( page, backButton, 'Back button' );
		await expectVisibleFocusRing( backButton, 'Back button' );
		const confirmButton = widget.getByRole( 'button', {
			name: 'Confirm booking',
		} );
		await tabTo( page, confirmButton, 'Confirm booking button' );
		await expectVisibleFocusRing( confirmButton, 'Confirm booking button' );
		expect( pageErrors ).toEqual( [] );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
