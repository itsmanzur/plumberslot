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
	}
}

async function replaceText( locator, value ) {
	await expect( locator ).toBeVisible();
	await locator.evaluate( ( element, text ) => {
		element.textContent = text;
	}, value );
}

test( 'parent dashboard matches its visual baseline', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 120000 );
	test.skip(
		'1' !== process.env.TUTORSLOT_E2E_VISUAL_READY,
		'Set TUTORSLOT_E2E_VISUAL_READY=1 to allow isolated visual fixture rows.'
	);
	const fixtureKey = testInfo.project.name.includes( 'mobile' )
		? 'mob-parent'
		: 'desk-parent';
	const seed = await fixture( 'seed-parent-dashboard', fixtureKey );

	try {
		await logIn( page, seed.parentLogin, seed.password );
		const responses = [
			page.waitForResponse( ( response ) =>
				response.url().includes( '/relations/children' )
			),
			page.waitForResponse(
				( response ) =>
					response.url().includes( '/bookings?' ) &&
					response.url().includes( 'scope=family' )
			),
			page.waitForResponse( ( response ) => {
				const url = new URL( response.url() );
				return url.pathname.endsWith( '/tutorslot/v1/credits' );
			} ),
			page.waitForResponse( ( response ) =>
				response.url().includes( '/credits/packages' )
			),
		];
		await page.goto( seed.pagePath );
		const loaded = await Promise.all( responses );
		expect( loaded.map( ( response ) => response.status() ) ).toEqual( [
			200, 200, 200, 200,
		] );
		await dismissConsent( page );

		const dashboard = page.locator( '.tutorslot-dashboard.tutorslot-root' );
		await expect(
			dashboard.getByRole( 'heading', {
				name: "Your family's lessons",
			} )
		).toBeVisible();
		await expect(
			dashboard.getByRole( 'button', { name: /Alice/ } )
		).toBeVisible();
		await expect( dashboard.locator( '.ts-dash__lesson' ) ).toHaveCount(
			1
		);
		await expect( dashboard.locator( '.ts-credit' ) ).toBeVisible();

		const lesson = dashboard.locator( '.ts-dash__lesson' );
		await replaceText( lesson.locator( '.ts-dash__when small' ), 'MON' );
		await replaceText( lesson.locator( '.ts-dash__when b' ), '10' );
		await replaceText(
			lesson.locator( '.ts-dash__what small' ),
			'10:00 AM–11:00 AM'
		);
		await replaceText(
			dashboard.locator( '.ts-dash__side .ts-dash__meta' ).first(),
			'Expires 2/6/2027. Unused credits roll over once if you buy again before then.'
		);
		await replaceText(
			dashboard.locator( '.ts-dash__note b' ),
			'8/10/2026 — '
		);
		await page
			.locator( '.tz-mobile-bottom-nav, .ts-live' )
			.evaluateAll( ( elements ) =>
				elements.forEach( ( element ) => element.remove() )
			);
		await page.evaluate( () => document.fonts.ready );

		await expect( dashboard ).toHaveScreenshot( 'parent-dashboard.png', {
			animations: 'disabled',
			caret: 'hide',
			maxDiffPixelRatio: 0.005,
		} );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
