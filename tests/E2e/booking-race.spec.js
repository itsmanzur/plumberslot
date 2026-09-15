// Two authenticated browser sessions, one live REST endpoint, one slot.
import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );

test.describe.configure( { mode: 'serial' } );

async function fixture( action, ...args ) {
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
		env: process.env,
		timeout: 30000,
		windowsHide: true,
	} );

	return parseFixtureResponse( stdout );
}

function parseFixtureResponse( stdout ) {
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

async function loginAndOpenFixture( context, login, password, pagePath ) {
	const page = await context.newPage();

	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( login );
	await page.locator( '#user_pass' ).fill( password );
	await Promise.all( [
		page.waitForURL( ( url ) => ! url.pathname.endsWith( 'wp-login.php' ) ),
		page.locator( '#wp-submit' ).click(),
	] );

	await page.goto( pagePath );
	await expect(
		page.locator( '.plumberslot-widget.plumberslot-root' )
	).toBeVisible();

	const nonce = await page.evaluate( () => window.plumberslotWidget?.nonce );
	expect( nonce ).toBeTruthy();

	return { page, nonce };
}

async function createBooking( page, nonce, seed, start = seed.start ) {
	return page.evaluate(
		async ( request ) => {
			const response = await fetch( '/wp-json/plumberslot/v1/bookings', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': request.nonce,
				},
				body: JSON.stringify( {
					technician_id: request.technicianId,
					start: request.start,
					timezone: 'UTC',
				} ),
			} );

			return {
				status: response.status,
				body: await response.json(),
			};
		},
		{ nonce, technicianId: seed.technicianId, start }
	);
}

async function runRace( browser, bobOffsetMinutes ) {
	const seed = await fixture( 'seed' );
	const aliceContext = await browser.newContext();
	const bobContext = await browser.newContext();

	try {
		const [ alice, bob ] = await Promise.all( [
			loginAndOpenFixture(
				aliceContext,
				seed.aliceLogin,
				seed.password,
				seed.pagePath
			),
			loginAndOpenFixture(
				bobContext,
				seed.bobLogin,
				seed.password,
				seed.pagePath
			),
		] );

		const bobStart = new Date(
			Date.parse( seed.start ) + bobOffsetMinutes * 60 * 1000
		).toISOString();
		const results = await Promise.all( [
			createBooking( alice.page, alice.nonce, seed ),
			createBooking( bob.page, bob.nonce, seed, bobStart ),
		] );
		const statuses = results.map( ( result ) => result.status ).sort();

		expect( statuses ).toEqual( [ 201, 409 ] );
		expect(
			results.find( ( result ) => 409 === result.status )?.body.code
		).toBe( 'plumberslot_slot_taken' );

		const database = await fixture( 'inspect' );
		expect( database.bookingCount ).toBe( 1 );
	} finally {
		await Promise.allSettled( [
			aliceContext.close(),
			bobContext.close(),
		] );
		await fixture( 'cleanup' );
	}
}

function skipUnlessRaceIsReady( testInfo ) {
	testInfo.setTimeout( 90000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_RACE_READY,
		'Set PLUMBERSLOT_E2E_RACE_READY=1 to allow the isolated fixture rows.'
	);
	test.skip(
		'chromium-desktop' !== testInfo.project.name,
		'The two-context race only needs to run once.'
	);
}

test( 'two customers race for one slot: one books and one receives 409', async ( {
	browser,
}, testInfo ) => {
	skipUnlessRaceIsReady( testInfo );
	await runRace( browser, 0 );
} );

test( 'two customers race for overlapping starts: one books and one receives 409', async ( {
	browser,
}, testInfo ) => {
	skipUnlessRaceIsReady( testInfo );
	await runRace( browser, 30 );
} );
