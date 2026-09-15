import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );

test.use( { viewport: { width: 390, height: 844 } } );

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
	}
}

function prepareTest( testInfo, state ) {
	testInfo.setTimeout( 150000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_VISUAL_READY,
		'Set PLUMBERSLOT_E2E_VISUAL_READY=1 to allow isolated responsive fixture rows.'
	);
	const project = testInfo.project.name.includes( 'mobile' ) ? 'mob' : 'desk';

	return `${ project }-overflow-${ state }`;
}

async function expectNoHorizontalOverflow( page, rootSelector, state ) {
	const metrics = await page.evaluate( ( selector ) => {
		const html = document.documentElement;
		const body = document.body;
		const root = document.querySelector( selector );
		const rect = root?.getBoundingClientRect();

		return {
			viewport: html.clientWidth,
			documentWidth: Math.max( html.scrollWidth, body?.scrollWidth || 0 ),
			rootFound: Boolean( root ),
			rootLeft: rect?.left ?? 0,
			rootRight: rect?.right ?? 0,
		};
	}, rootSelector );

	expect( metrics.rootFound, `${ state }: PlumberSlot root is missing.` ).toBe(
		true
	);
	expect(
		metrics.documentWidth,
		`${ state }: document is ${ metrics.documentWidth }px wide at a ${ metrics.viewport }px viewport.`
	).toBeLessThanOrEqual( metrics.viewport + 1 );
	expect(
		metrics.rootLeft,
		`${ state }: PlumberSlot root starts outside the viewport.`
	).toBeGreaterThanOrEqual( -1 );
	expect(
		metrics.rootRight,
		`${ state }: PlumberSlot root ends outside the viewport.`
	).toBeLessThanOrEqual( metrics.viewport + 1 );
}

test( 'public booking flow contains every state at 390px', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareTest( testInfo, 'booking' );
	const seed = await fixture( 'seed', fixtureKey );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const technicianResponse = page.waitForResponse( ( response ) =>
			response.url().includes( `/public/technicians/${ seed.technicianId }` )
		);
		await goTo( page, seed.pagePath );
		expect( ( await technicianResponse ).status() ).toBe( 200 );
		await dismissConsent( page );

		const widget = page.locator( '.plumberslot-widget.plumberslot-root' );
		await expect(
			widget.getByRole( 'heading', {
				name: 'What do you want to work on?',
			} )
		).toBeVisible();
		await expectNoHorizontalOverflow(
			page,
			'.plumberslot-widget.plumberslot-root',
			'booking service'
		);

		await widget.getByRole( 'option', { name: /English E2E/ } ).click();
		const slotsResponse = page.waitForResponse( ( response ) =>
			response.url().includes( '/wp-json/plumberslot/v1/slots?' )
		);
		await widget.getByRole( 'button', { name: 'Choose a time →' } ).click();
		expect( ( await slotsResponse ).status() ).toBe( 200 );
		await expect(
			widget.getByRole( 'heading', { name: 'When works for you?' } )
		).toBeVisible();
		await expectNoHorizontalOverflow(
			page,
			'.plumberslot-widget.plumberslot-root',
			'booking time'
		);

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
		await expectNoHorizontalOverflow(
			page,
			'.plumberslot-widget.plumberslot-root',
			'booking confirm'
		);

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
		await expectNoHorizontalOverflow(
			page,
			'.plumberslot-widget.plumberslot-root',
			'booking completion'
		);
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );

test( 'payment recovery is contained at 390px', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareTest( testInfo, 'payment' );
	const seed = await fixture( 'seed-payment', fixtureKey, 'failure' );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const bookingResponse = page.waitForResponse( ( response ) =>
			response.url().includes( `/bookings/${ seed.bookingId }` )
		);
		await goTo(
			page,
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
		await expectNoHorizontalOverflow(
			page,
			'.plumberslot-widget.plumberslot-root',
			'payment recovery'
		);
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );

test( 'availability timetable is contained at 390px', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareTest( testInfo, 'availability' );
	const seed = await fixture( 'seed-lifecycle', fixtureKey );

	try {
		await logIn( page, seed.technicianLogin, seed.password );
		const availabilityResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( `/availability/${ seed.technicianId }` ) &&
				'GET' === response.request().method()
		);
		await goTo( page, '/wp-admin/admin.php?page=plumberslot-availability' );
		expect( ( await availabilityResponse ).status() ).toBe( 200 );
		const root = page.locator( '#plumberslot-admin-root' );
		await expect( root.locator( '.ts-tt' ) ).toBeVisible();
		await expectNoHorizontalOverflow(
			page,
			'#plumberslot-admin-root',
			'availability timetable'
		);
		const timetable = await root
			.locator( '.ts-tt-wrap' )
			.evaluate( ( element ) => ( {
				clientWidth: element.clientWidth,
				scrollWidth: element.scrollWidth,
				overflowX:
					element.ownerDocument.defaultView.getComputedStyle(
						element
					).overflowX,
			} ) );
		expect( timetable.scrollWidth ).toBeGreaterThanOrEqual(
			timetable.clientWidth
		);
		expect( [ 'auto', 'scroll' ] ).toContain( timetable.overflowX );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
