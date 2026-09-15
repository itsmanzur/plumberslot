import { test, expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { promisify } from 'node:util';

const execute = promisify( execFile );
const fixtureScript = path.resolve( __dirname, 'fixtures', 'booking-race.php' );
const FIRST_RENDER_BUDGET_MS = 1000;
const SAMPLE_COUNT = 5;

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

function percentile( values, percentileValue ) {
	const sorted = [ ...values ].sort( ( left, right ) => left - right );
	const index = Math.max(
		0,
		Math.ceil( ( percentileValue / 100 ) * sorted.length ) - 1
	);

	return sorted[ index ];
}

async function installFirstRenderObserver( page ) {
	await page.addInitScript( () => {
		window.__tutorslotAdminFirstRender = 0;

		const markReady = () => {
			if (
				window.__tutorslotAdminFirstRender > 0 ||
				! document.querySelector(
					'#tutorslot-admin-root .ts-dashboard__today'
				)
			) {
				return;
			}

			window.__tutorslotAdminFirstRender = performance.now();
		};
		const observer = new window.MutationObserver( () => {
			markReady();
			if ( window.__tutorslotAdminFirstRender > 0 ) {
				observer.disconnect();
			}
		} );

		observer.observe( document, { childList: true, subtree: true } );
		document.addEventListener( 'DOMContentLoaded', markReady, {
			once: true,
		} );
	} );
}

async function readMetrics( page ) {
	return page.evaluate( () => {
		const navigation = performance.getEntriesByType( 'navigation' )[ 0 ];
		const domContentLoaded = navigation?.domContentLoadedEventEnd || 0;
		const firstRender = window.__tutorslotAdminFirstRender || 0;
		const mountStart =
			performance
				.getEntriesByName( 'tutorslot-admin-mount-start' )
				.at( -1 )?.startTime || 0;
		const responseStart = navigation?.responseStart || 0;

		return {
			appFirstRender:
				mountStart > 0 && firstRender > mountStart
					? firstRender - mountStart
					: 0,
			domContentLoaded,
			firstRender,
			mountStart,
			responseToRender:
				responseStart > 0 && firstRender > responseStart
					? firstRender - responseStart
					: 0,
			responseStart,
		};
	} );
}

test( 'admin dashboard first meaningful render p75 stays under one second', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 120000 );
	test.skip(
		'1' !== process.env.TUTORSLOT_E2E_PERF_READY,
		'Set TUTORSLOT_E2E_PERF_READY=1 to run the Local-site performance gate.'
	);

	const fixtureKey = testInfo.project.name.includes( 'mobile' )
		? 'mob-admin-perf'
		: 'desk-admin-perf';
	const seed = await fixture( 'seed-lifecycle', fixtureKey );

	try {
		await installFirstRenderObserver( page );
		await logIn( page, seed.tutorLogin, seed.password );

		const root = page.locator( '#tutorslot-admin-root' );
		await page.goto(
			'/wp-admin/admin.php?page=tutorslot&tutorslot_perf_warmup=1'
		);
		await expect( root.locator( '.ts-dashboard__today' ) ).toBeVisible( {
			timeout: 15000,
		} );

		const devtools = await page.context().newCDPSession( page );
		await devtools.send( 'Network.enable' );
		await devtools.send( 'Network.setCacheDisabled', {
			cacheDisabled: true,
		} );

		const samples = [];
		for ( let sample = 0; sample < SAMPLE_COUNT; sample++ ) {
			await devtools.send( 'Network.clearBrowserCache' );

			await page.goto(
				`/wp-admin/admin.php?page=tutorslot&tutorslot_perf_sample=${ sample }-${ Date.now() }`,
				{ waitUntil: 'domcontentloaded' }
			);
			await expect( root.locator( '.ts-dashboard__today' ) ).toBeVisible(
				{ timeout: 15000 }
			);
			expect(
				await page.evaluate( () =>
					Boolean( window.tutorslotAdmin?.initialDashboard )
				)
			).toBe( true );
			samples.push( await readMetrics( page ) );
		}

		const values = samples.map( ( sample ) => sample.appFirstRender );
		const p75 = percentile( values, 75 );
		process.stdout.write(
			`\n${
				testInfo.project.name
			} admin app first-render p75: ${ p75.toFixed(
				1
			) }ms; samples: ${ values
				.map( ( value ) => value.toFixed( 1 ) )
				.join( ', ' ) }\n`
		);
		testInfo.annotations.push( {
			description: `p75=${ p75.toFixed( 1 ) }ms; samples=${ values
				.map( ( value ) => value.toFixed( 1 ) )
				.join( ',' ) }`,
			type: 'admin-first-render',
		} );
		await testInfo.attach( 'admin-first-render.json', {
			body: JSON.stringify(
				{
					budgetMs: FIRST_RENDER_BUDGET_MS,
					p75,
					project: testInfo.project.name,
					samples,
				},
				null,
				2
			),
			contentType: 'application/json',
		} );

		expect(
			values.every( ( value ) => value > 0 ),
			`Missing dashboard app-render timing: ${ JSON.stringify(
				samples
			) }`
		).toBe( true );
		expect(
			p75,
			`Admin first render p75 ${ p75.toFixed(
				1
			) }ms exceeded ${ FIRST_RENDER_BUDGET_MS }ms. Samples: ${ values
				.map( ( value ) => value.toFixed( 1 ) )
				.join( ', ' ) }`
		).toBeLessThan( FIRST_RENDER_BUDGET_MS );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
