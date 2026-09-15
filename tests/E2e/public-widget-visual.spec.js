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

test( 'public booking widget matches its visual baseline', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 90000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_VISUAL_READY,
		'Set PLUMBERSLOT_E2E_VISUAL_READY=1 to allow isolated visual fixture rows.'
	);
	const fixtureKey = testInfo.project.name.includes( 'mobile' )
		? 'mob-widget'
		: 'desk-widget';
	const seed = await fixture( 'seed', fixtureKey );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const tutorResponse = page.waitForResponse( ( response ) =>
			response.url().includes( `/public/tutors/${ seed.tutorId }` )
		);
		await page.goto( seed.pagePath );
		expect( ( await tutorResponse ).status() ).toBe( 200 );
		const consentButton = page.locator(
			'#consentaro-banner [data-consentaro-action="accept-all"]'
		);
		if ( await consentButton.isVisible() ) {
			await consentButton.click();
			await expect( page.locator( '#consentaro-banner' ) ).toBeHidden();
		}

		const widget = page.locator( '.plumberslot-widget.plumberslot-root' );
		await expect(
			widget.getByRole( 'heading', {
				name: 'What do you want to work on?',
			} )
		).toBeVisible();
		const subject = widget.getByRole( 'option', { name: /English E2E/ } );
		await subject.click();
		await expect( subject ).toHaveAttribute( 'aria-selected', 'true' );
		await expect(
			widget.getByRole( 'button', { name: 'Choose a time →' } )
		).toBeEnabled();
		await subject.evaluate( ( element ) => element.blur() );
		await page.mouse.move( 0, 0 );
		await page.evaluate( () => document.fonts.ready );

		await expect( widget ).toHaveScreenshot( 'public-booking-widget.png', {
			animations: 'disabled',
			caret: 'hide',
			maxDiffPixelRatio: 0.005,
		} );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
