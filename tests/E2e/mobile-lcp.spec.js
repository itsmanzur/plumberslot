import { test, expect } from '@playwright/test';

const LCP_BUDGET_MS = 1500;
const SAMPLE_COUNT = 5;

test.use( {
	serviceWorkers: 'block',
	viewport: { width: 390, height: 844 },
} );

function percentile( values, percentileValue ) {
	const sorted = [ ...values ].sort( ( left, right ) => left - right );
	const index = Math.max(
		0,
		Math.ceil( ( percentileValue / 100 ) * sorted.length ) - 1
	);
	return sorted[ index ];
}

async function installLcpObserver( page ) {
	await page.addInitScript( () => {
		window.__plumberslotLcpEntries = [];
		new PerformanceObserver( ( list ) => {
			for ( const entry of list.getEntries() ) {
				const element = entry.element;
				window.__plumberslotLcpEntries.push( {
					element: element
						? {
								className: String( element.className || '' ),
								id: element.id || '',
								tagName: element.tagName.toLowerCase(),
								text: ( element.textContent || '' )
									.trim()
									.slice( 0, 100 ),
						  }
						: null,
					size: entry.size,
					startTime: entry.startTime,
					url: entry.url || '',
				} );
			}
		} ).observe( { type: 'largest-contentful-paint', buffered: true } );
	} );
}

async function readMetrics( page ) {
	return page.evaluate( () => {
		const entries = window.__plumberslotLcpEntries || [];
		const navigation = performance.getEntriesByType( 'navigation' )[ 0 ];
		const lastEntry = entries.at( -1 );

		return {
			domContentLoaded: navigation?.domContentLoadedEventEnd || 0,
			element: lastEntry?.element || null,
			lcp: lastEntry?.startTime || 0,
			load: navigation?.loadEventEnd || 0,
			responseStart: navigation?.responseStart || 0,
			sampleCount: entries.length,
		};
	} );
}

test( 'mobile booking page p75 LCP stays under 1.5 seconds', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 120000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_PERF_READY,
		'Set PLUMBERSLOT_E2E_PERF_READY=1 to run the Local-site performance gate.'
	);

	await installLcpObserver( page );
	const devtools = await page.context().newCDPSession( page );
	await devtools.send( 'Network.enable' );
	await devtools.send( 'Network.setCacheDisabled', { cacheDisabled: true } );

	// Warm PHP, MySQL, and opcode caches before measuring browser cold loads.
	await page.goto( '/book/?plumberslot_lcp_warmup=1', {
		waitUntil: 'load',
	} );
	await expect(
		page.locator( '.plumberslot-widget.plumberslot-root' )
	).toBeVisible();

	const samples = [];
	for ( let sample = 0; sample < SAMPLE_COUNT; sample++ ) {
		await devtools.send( 'Network.clearBrowserCache' );
		await page.goto(
			`/book/?plumberslot_lcp_sample=${ sample }-${ Date.now() }`,
			{ waitUntil: 'load' }
		);
		await expect(
			page.locator( '.plumberslot-widget.plumberslot-root' )
		).toBeVisible();
		await page.waitForTimeout( 1000 );
		samples.push( await readMetrics( page ) );
	}

	const lcpValues = samples.map( ( sample ) => sample.lcp );
	const p75 = percentile( lcpValues, 75 );
	testInfo.annotations.push( {
		description: `p75=${ p75.toFixed( 1 ) }ms; samples=${ lcpValues
			.map( ( value ) => value.toFixed( 1 ) )
			.join( ',' ) }`,
		type: 'mobile-lcp',
	} );
	await testInfo.attach( 'mobile-lcp.json', {
		body: JSON.stringify(
			{
				budgetMs: LCP_BUDGET_MS,
				p75,
				samples,
				viewport: { height: 844, width: 390 },
			},
			null,
			2
		),
		contentType: 'application/json',
	} );

	expect(
		lcpValues.every( ( value ) => value > 0 ),
		`Missing LCP entry: ${ JSON.stringify( samples ) }`
	).toBe( true );
	expect(
		p75,
		`p75 LCP ${ p75.toFixed(
			1
		) }ms exceeded ${ LCP_BUDGET_MS }ms. Samples: ${ lcpValues
			.map( ( value ) => value.toFixed( 1 ) )
			.join( ', ' ) }`
	).toBeLessThan( LCP_BUDGET_MS );
} );
