import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );

async function fixture( action, fixtureKey ) {
	const phpArgs = [ fixtureScript, action ];
	const phpBinary = process.env.TUTORSLOT_E2E_PHP_BINARY || 'php';

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
			TUTORSLOT_E2E_FIXTURE_KEY: fixtureKey,
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
	await page.evaluate( () => {
		window.tutorSlotPointerActions = 0;
		document
			.querySelector( '.tutorslot-widget.tutorslot-root' )
			?.addEventListener( 'pointerdown', () => {
				window.tutorSlotPointerActions += 1;
			} );
	} );
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

	const active = await page.evaluate( () => {
		const activeElement = document.body.ownerDocument.activeElement;
		return {
			tag: activeElement?.tagName || '',
			text: activeElement?.textContent?.trim().slice( 0, 80 ) || '',
		};
	} );
	throw new Error(
		`${ label } was not reached after ${ limit } Tab presses; focus stopped on ${ active.tag }: ${ active.text }`
	);
}

test( 'student completes booking with keyboard only', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 150000 );
	test.skip(
		'1' !== process.env.TUTORSLOT_E2E_HAPPY_READY,
		'Set TUTORSLOT_E2E_HAPPY_READY=1 to allow isolated keyboard-flow fixture rows.'
	);
	const project = testInfo.project.name.includes( 'mobile' ) ? 'mob' : 'desk';
	const fixtureKey = `${ project }-keyboard-booking`;
	const seed = await fixture( 'seed', fixtureKey );
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const tutorResponse = page.waitForResponse( ( response ) =>
			response.url().includes( `/public/tutors/${ seed.tutorId }` )
		);
		await goTo( page, seed.pagePath );
		expect( ( await tutorResponse ).status() ).toBe( 200 );
		await isolateWidget( page );

		const widget = page.locator( '.tutorslot-widget.tutorslot-root' );
		const firstSubject = widget
			.getByRole( 'listbox', { name: 'Subjects' } )
			.getByRole( 'option' )
			.first();
		await tabTo( page, firstSubject, 'first subject option' );
		await page.keyboard.press( 'ArrowRight' );
		await expect(
			widget
				.getByRole( 'listbox', { name: 'Subjects' } )
				.locator( '[aria-selected="true"]' )
		).toHaveCount( 1 );

		const chooseTime = widget.getByRole( 'button', {
			name: 'Choose a time →',
		} );
		await tabTo( page, chooseTime, 'Choose a time button' );
		const slotsResponse = page.waitForResponse( ( response ) =>
			response.url().includes( '/wp-json/tutorslot/v1/slots?' )
		);
		await page.keyboard.press( 'Enter' );
		expect( ( await slotsResponse ).status() ).toBe( 200 );
		await expect(
			widget.getByRole( 'heading', { name: 'When works for you?' } )
		).toBeVisible();

		const openTimes = widget.getByRole( 'listbox', { name: 'Open times' } );
		const firstOpenTime = openTimes
			.locator( '[role="option"]:not([disabled])' )
			.first();
		await tabTo( page, firstOpenTime, 'first open time' );
		await page.keyboard.press( 'ArrowRight' );
		await expect(
			openTimes.locator( '[aria-selected="true"]' )
		).toHaveCount( 1 );
		expect(
			await openTimes.evaluate( ( element ) =>
				element.contains( element.ownerDocument.activeElement )
			)
		).toBe( true );

		const continueButton = widget.getByRole( 'button', {
			name: 'Continue →',
		} );
		await tabTo( page, continueButton, 'Continue button' );
		const holdResponse = page.waitForResponse(
			( response ) =>
				response
					.url()
					.includes( '/wp-json/tutorslot/v1/bookings/hold' ) &&
				'POST' === response.request().method()
		);
		await page.keyboard.press( 'Enter' );
		expect( ( await holdResponse ).status() ).toBe( 200 );
		await expect(
			widget.getByRole( 'heading', { name: 'Confirm and book' } )
		).toBeVisible();

		const notes = widget.getByPlaceholder(
			'Anything helpful before the lesson'
		);
		await tabTo( page, notes, 'lesson note field' );
		await page.keyboard.type( 'Keyboard-only E2E booking.' );
		await expect( notes ).toHaveValue( 'Keyboard-only E2E booking.' );

		const confirmButton = widget.getByRole( 'button', {
			name: 'Confirm booking',
		} );
		await tabTo( page, confirmButton, 'Confirm booking button' );
		const bookingResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/wp-json/tutorslot/v1/bookings' ) &&
				'POST' === response.request().method()
		);
		await page.keyboard.press( 'Enter' );
		const response = await bookingResponse;
		expect( response.status() ).toBe( 201 );
		const payload = await response.json();
		await expect(
			widget.getByRole( 'heading', { name: /^Booked\./ } )
		).toBeFocused();
		expect(
			await page.evaluate( () => window.tutorSlotPointerActions )
		).toBe( 0 );

		const database = await fixture( 'inspect', fixtureKey );
		expect( database.bookingCount ).toBe( 1 );
		expect( Number( database.booking.id ) ).toBe( payload.id );
		expect( database.booking.status ).toBe( 'confirmed' );
		expect( pageErrors ).toEqual( [] );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
