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

function prepareLifecycleTest( testInfo, state ) {
	testInfo.setTimeout( 90000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_LIFECYCLE_READY,
		'Set PLUMBERSLOT_E2E_LIFECYCLE_READY=1 to allow isolated lifecycle fixture rows.'
	);

	return `${ testInfo.project.name }-${ state }`.replace(
		/[^a-z0-9_-]/g,
		'-'
	);
}

async function openManagedBooking( page, seed ) {
	await logIn( page, seed.tutorLogin, seed.password );
	const bookingsResponse = page.waitForResponse(
		( response ) =>
			response.url().includes( '/bookings?' ) &&
			'GET' === response.request().method()
	);
	await page.goto( '/wp-admin/admin.php?page=plumberslot-bookings' );
	expect( ( await bookingsResponse ).status() ).toBe( 200 );

	const row = page.locator( '.ts-admin-table tbody tr' ).filter( {
		hasText: 'English E2E',
	} );
	await expect( row ).toHaveCount( 1 );
	await row.getByRole( 'button', { name: 'Open booking' } ).click();

	const dialog = page.getByRole( 'dialog', {
		name: `Booking #${ seed.bookingId }`,
	} );
	await expect( dialog ).toBeVisible();

	return dialog;
}

test( 'manager reschedules a confirmed booking into a new open slot', async ( {
	page,
}, testInfo ) => {
	const fixtureKey = prepareLifecycleTest( testInfo, 'reschedule' );
	const seed = await fixture( 'seed-lifecycle', fixtureKey );

	try {
		const detail = await openManagedBooking( page, seed );
		const slotsResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/slots?' ) &&
				response
					.url()
					.includes( `exclude_booking=${ seed.bookingId }` ) &&
				'GET' === response.request().method()
		);
		await detail.getByRole( 'button', { name: 'Reschedule' } ).click();
		expect( ( await slotsResponse ).status() ).toBe( 200 );

		const moveDialog = page
			.locator( '.ts-modal-root:not([hidden])' )
			.getByRole( 'dialog' );
		await expect( moveDialog ).toBeVisible();
		await expect(
			moveDialog.getByRole( 'heading', { name: /Move .* lesson/ } )
		).toBeVisible();
		const openSlots = moveDialog.locator( '.ts-slots button' );
		await expect( openSlots ).toHaveCount( 3 );
		await openSlots.last().click();

		const moveResponse = page.waitForResponse(
			( response ) =>
				response
					.url()
					.includes( `/bookings/${ seed.bookingId }/reschedule` ) &&
				'POST' === response.request().method()
		);
		await moveDialog
			.getByRole( 'button', { name: 'Save new time' } )
			.click();
		expect( ( await moveResponse ).status() ).toBe( 200 );
		await expect( moveDialog ).toBeHidden();
		await expect( page.getByText( 'Booking rescheduled.' ) ).toBeVisible();

		const database = await fixture( 'inspect', fixtureKey );
		expect( database.bookingCount ).toBe( 2 );
		expect( database.bookings ).toHaveLength( 2 );
		expect( Number( database.bookings[ 0 ].id ) ).toBe( seed.bookingId );
		expect( database.bookings[ 0 ].status ).toBe( 'moved' );
		expect( database.bookings[ 1 ].status ).toBe( 'confirmed' );
		expect( database.bookings[ 1 ].start_utc ).not.toBe( seed.startSql );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );

test( 'manager cancels a confirmed booking', async ( { page }, testInfo ) => {
	const fixtureKey = prepareLifecycleTest( testInfo, 'cancel' );
	const seed = await fixture( 'seed-lifecycle', fixtureKey );

	try {
		const detail = await openManagedBooking( page, seed );
		const cancelResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( `/bookings/${ seed.bookingId }` ) &&
				'DELETE' === response.request().method()
		);
		await detail.getByRole( 'button', { name: 'Cancel booking' } ).click();
		expect( ( await cancelResponse ).status() ).toBe( 200 );
		await expect( detail ).toBeHidden();
		await expect( page.getByText( 'Booking cancelled.' ) ).toBeVisible();

		const database = await fixture( 'inspect', fixtureKey );
		expect( database.bookingCount ).toBe( 1 );
		expect( Number( database.booking.id ) ).toBe( seed.bookingId );
		expect( database.booking.status ).toBe( 'cancelled' );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
