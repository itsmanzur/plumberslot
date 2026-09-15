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

test( 'availability timetable matches its visual baseline', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 90000 );
	test.skip(
		'1' !== process.env.TUTORSLOT_E2E_VISUAL_READY,
		'Set TUTORSLOT_E2E_VISUAL_READY=1 to allow isolated visual fixture rows.'
	);
	const fixtureKey = testInfo.project.name.includes( 'mobile' )
		? 'mob-availability'
		: 'desk-availability';
	const seed = await fixture( 'seed-lifecycle', fixtureKey );

	try {
		await logIn( page, seed.tutorLogin, seed.password );
		const availabilityResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( `/availability/${ seed.tutorId }` ) &&
				'GET' === response.request().method()
		);
		await page.goto( '/wp-admin/admin.php?page=tutorslot-availability' );
		expect( ( await availabilityResponse ).status() ).toBe( 200 );

		const root = page.locator( '#tutorslot-admin-root' );
		await expect(
			root.getByRole( 'heading', {
				name: 'When can students book you?',
			} )
		).toBeVisible();
		await expect( root.locator( '.ts-tt' ) ).toBeVisible();
		await page.evaluate( () => document.fonts.ready );

		await expect( root ).toHaveScreenshot( 'availability-timetable.png', {
			animations: 'disabled',
			caret: 'hide',
			maxDiffPixelRatio: 0.005,
		} );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
