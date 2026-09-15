import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );
const FRAME_BUDGET_MS = 16;
const MINIMUM_MEASURES = 15;

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

function percentile( values, percentileValue ) {
	const sorted = [ ...values ].sort( ( left, right ) => left - right );
	const index = Math.max(
		0,
		Math.ceil( ( percentileValue / 100 ) * sorted.length ) - 1
	);

	return sorted[ index ];
}

test( 'availability timetable interaction work stays under 16ms per frame', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 120000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_PERF_READY,
		'Set PLUMBERSLOT_E2E_PERF_READY=1 to run the Local-site performance gate.'
	);

	const fixtureKey = testInfo.project.name.includes( 'mobile' )
		? 'mob-timetable-perf'
		: 'desk-timetable-perf';
	const seed = await fixture( 'seed-lifecycle', fixtureKey );

	try {
		await logIn( page, seed.technicianLogin, seed.password );
		await page.goto( '/wp-admin/admin.php?page=plumberslot-availability' );

		const root = page.locator( '#plumberslot-admin-root' );
		const grid = root.getByRole( 'grid', {
			name: 'Weekly availability timetable',
		} );
		await expect( grid ).toBeVisible( { timeout: 15000 } );
		const cells = grid.locator( '.ts-tt__cell[aria-disabled="false"]' );
		expect( await cells.count() ).toBeGreaterThan( MINIMUM_MEASURES );

		await page.evaluate( () => {
			performance.clearMeasures( 'plumberslot-timetable-interaction' );
		} );

		await cells.first().hover();
		await page.mouse.down();
		try {
			for ( let index = 1; index <= MINIMUM_MEASURES; index++ ) {
				await cells.nth( index ).hover();
				await page.waitForFunction(
					( expected ) =>
						performance.getEntriesByName(
							'plumberslot-timetable-interaction'
						).length >= expected,
					index + 1
				);
			}
		} finally {
			await page.mouse.up();
		}

		await expect( root.getByText( 'Unsaved changes' ) ).toBeVisible();
		const measures = await page.evaluate( () =>
			performance
				.getEntriesByName( 'plumberslot-timetable-interaction' )
				.map( ( entry ) => ( {
					duration: entry.duration,
					startTime: entry.startTime,
				} ) )
		);
		const values = measures.map( ( measure ) => measure.duration );
		const p75 = percentile( values, 75 );
		const slowest = Math.max( ...values );
		process.stdout.write(
			`\n${
				testInfo.project.name
			} timetable interaction p75: ${ p75.toFixed(
				3
			) }ms; max: ${ slowest.toFixed( 3 ) }ms; measures: ${
				values.length
			}\n`
		);
		await testInfo.attach( 'timetable-interaction.json', {
			body: JSON.stringify(
				{
					budgetMs: FRAME_BUDGET_MS,
					measures,
					p75,
					project: testInfo.project.name,
				},
				null,
				2
			),
			contentType: 'application/json',
		} );

		expect( values.length ).toBeGreaterThanOrEqual( MINIMUM_MEASURES );
		expect(
			slowest,
			`Slowest timetable commit ${ slowest.toFixed(
				3
			) }ms exceeded ${ FRAME_BUDGET_MS }ms.`
		).toBeLessThan( FRAME_BUDGET_MS );
		expect(
			p75,
			`Timetable interaction p75 ${ p75.toFixed(
				3
			) }ms exceeded ${ FRAME_BUDGET_MS }ms. Durations: ${ values
				.map( ( value ) => value.toFixed( 3 ) )
				.join( ', ' ) }`
		).toBeLessThan( FRAME_BUDGET_MS );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
