import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );

test.use( { reducedMotion: 'reduce' } );

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

async function expectReducedMotion( root, state ) {
	const result = await root.evaluate( ( rootElement ) => {
		const windowObject = rootElement.ownerDocument.defaultView;
		const toMilliseconds = ( value ) => {
			const trimmed = value.trim();
			return trimmed.endsWith( 'ms' )
				? Number.parseFloat( trimmed )
				: Number.parseFloat( trimmed ) * 1000;
		};
		const values = ( value ) => value.split( ',' ).map( toMilliseconds );
		const violations = [];
		const elements = [
			rootElement,
			...rootElement.querySelectorAll( '*' ),
		];

		for ( const element of elements ) {
			for ( const pseudo of [ null, '::before', '::after' ] ) {
				const style = windowObject.getComputedStyle( element, pseudo );
				const name = `${ element.tagName.toLowerCase() }${
					element.className
						? `.${ String( element.className )
								.trim()
								.split( /\s+/ )
								.join( '.' ) }`
						: ''
				}${ pseudo || '' }`;
				const iterations = style.animationIterationCount
					.split( ',' )
					.map( ( value ) => value.trim() );
				const hasViolation =
					values( style.animationDelay ).some(
						( value ) => Math.abs( value ) > 0.011
					) ||
					values( style.animationDuration ).some(
						( value ) => value > 0.011
					) ||
					iterations.some(
						( value ) =>
							'infinite' === value ||
							Number.parseFloat( value ) > 1
					) ||
					values( style.transitionDelay ).some(
						( value ) => Math.abs( value ) > 0.011
					) ||
					values( style.transitionDuration ).some(
						( value ) => value > 0.011
					) ||
					'auto' !== style.scrollBehavior;

				if ( hasViolation ) {
					violations.push( {
						animationDelay: style.animationDelay,
						animationDuration: style.animationDuration,
						animationIterationCount: style.animationIterationCount,
						name,
						scrollBehavior: style.scrollBehavior,
						transitionDelay: style.transitionDelay,
						transitionDuration: style.transitionDuration,
					} );
				}
			}
		}

		return {
			mediaMatches: windowObject.matchMedia(
				'(prefers-reduced-motion: reduce)'
			).matches,
			violations: violations.slice( 0, 10 ),
		};
	} );

	expect( result.mediaMatches, `${ state }: media query is inactive.` ).toBe(
		true
	);
	expect(
		result.violations,
		`${ state }: motion was not reduced: ${ JSON.stringify(
			result.violations
		) }`
	).toEqual( [] );
}

test( 'booking states respect reduced-motion preference', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 150000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_HAPPY_READY,
		'Set PLUMBERSLOT_E2E_HAPPY_READY=1 to allow isolated reduced-motion fixture rows.'
	);
	const project = testInfo.project.name.includes( 'mobile' ) ? 'mob' : 'desk';
	const fixtureKey = `${ project }-reduced-motion`;
	const seed = await fixture( 'seed', fixtureKey );
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	try {
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await logIn( page, seed.aliceLogin, seed.password );
		const technicianResponse = page.waitForResponse( ( response ) =>
			response.url().includes( `/public/technicians/${ seed.technicianId }` )
		);
		await goTo( page, seed.pagePath );
		expect( ( await technicianResponse ).status() ).toBe( 200 );
		await isolateWidget( page );
		const widget = page.locator( '.plumberslot-widget.plumberslot-root' );

		await expectReducedMotion( widget, 'Service step' );
		await widget.getByRole( 'option', { name: /English E2E/ } ).click();
		await page.route(
			'**/wp-json/plumberslot/v1/slots?**',
			async ( route ) => {
				await page.waitForTimeout( 750 );
				await route.continue();
			}
		);
		const slotsResponse = page.waitForResponse( ( response ) =>
			response.url().includes( '/wp-json/plumberslot/v1/slots?' )
		);
		await widget.getByRole( 'button', { name: 'Choose a time →' } ).click();
		await expect( widget.getByText( 'Loading times…' ) ).toBeVisible();
		await expectReducedMotion( widget, 'Time loading state' );
		expect( ( await slotsResponse ).status() ).toBe( 200 );
		await page.unroute( '**/wp-json/plumberslot/v1/slots?**' );
		await expect(
			widget
				.getByRole( 'listbox', { name: 'Open times' } )
				.locator( '[role="option"]:not([disabled])' )
				.first()
		).toBeVisible();
		await expectReducedMotion( widget, 'Time ready state' );

		const firstOpenTime = widget
			.getByRole( 'listbox', { name: 'Open times' } )
			.locator( '[role="option"]:not([disabled])' )
			.first();
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
		await expectReducedMotion( widget, 'Confirm step' );
		expect( pageErrors ).toEqual( [] );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
