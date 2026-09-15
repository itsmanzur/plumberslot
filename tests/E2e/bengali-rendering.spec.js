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
			'#consentaro-banner, #consentaro-banner-modal, .tz-mobile-bottom-nav, .ts-live'
		)
		.evaluateAll( ( elements ) =>
			elements.forEach( ( element ) => element.remove() )
		);
}

async function replaceText( locator, value ) {
	await expect( locator ).toBeVisible();
	await locator.evaluate( ( element, text ) => {
		element.textContent = text;
	}, value );
}

async function expectBengaliGlyphs( locator ) {
	const metrics = await locator.evaluate( ( element ) => {
		const style =
			element.ownerDocument.defaultView.getComputedStyle( element );
		const canvas = element.ownerDocument.createElement( 'canvas' );
		const context = canvas.getContext( '2d' );
		context.font = `${ style.fontWeight } ${ style.fontSize } ${ style.fontFamily }`;
		const graphemes = [
			...new Intl.Segmenter( 'bn', { granularity: 'grapheme' } ).segment(
				'বাংলাভাষাওসাহিত্য'
			),
		].map( ( part ) => part.segment );
		const widths = new Set(
			graphemes.map( ( glyph ) =>
				context.measureText( glyph ).width.toFixed( 1 )
			)
		);
		const box = element.getBoundingClientRect();

		return {
			clientHeight: element.clientHeight,
			clientWidth: element.clientWidth,
			fontFamily: style.fontFamily,
			glyphWidthCount: widths.size,
			hasReplacementCharacter: element.textContent.includes( '\uFFFD' ),
			height: box.height,
			scrollHeight: element.scrollHeight,
			scrollWidth: element.scrollWidth,
			width: box.width,
		};
	} );

	expect( metrics.fontFamily ).toContain( 'Noto Sans Bengali' );
	expect( metrics.fontFamily ).toContain( 'Nirmala UI' );
	expect( metrics.fontFamily ).toContain( 'Bangla Sangam MN' );
	expect( metrics.glyphWidthCount ).toBeGreaterThan( 3 );
	expect( metrics.hasReplacementCharacter ).toBe( false );
	expect( metrics.width ).toBeGreaterThan( 0 );
	expect( metrics.height ).toBeGreaterThan( 0 );
	expect( metrics.scrollWidth ).toBeLessThanOrEqual(
		metrics.clientWidth + 1
	);
	expect( metrics.scrollHeight ).toBeLessThanOrEqual(
		metrics.clientHeight + 1
	);
}

test( 'Bengali text renders without missing or clipped glyphs', async ( {
	page,
}, testInfo ) => {
	testInfo.setTimeout( 150000 );
	test.skip(
		'1' !== process.env.PLUMBERSLOT_E2E_VISUAL_READY,
		'Set PLUMBERSLOT_E2E_VISUAL_READY=1 to allow isolated Bengali visual fixture rows.'
	);
	const project = testInfo.project.name.includes( 'mobile' ) ? 'mob' : 'desk';
	const fixtureKey = `${ project }-bengali`;
	const seed = await fixture( 'seed-bengali', fixtureKey );

	try {
		await logIn( page, seed.aliceLogin, seed.password );
		const tutorResponse = page.waitForResponse( ( response ) =>
			response.url().includes( `/public/tutors/${ seed.tutorId }` )
		);
		await goTo( page, seed.pagePath );
		expect( ( await tutorResponse ).status() ).toBe( 200 );
		await isolateWidget( page );
		await page.evaluate( () => document.fonts.ready );

		const widget = page.locator( '.plumberslot-widget.plumberslot-root' );
		const subject = widget.getByRole( 'option', {
			name: /বাংলা ভাষা ও সাহিত্য/,
		} );
		await expect( subject ).toContainText( 'প্রাথমিক · জাতীয় শিক্ষাক্রম' );
		await expectBengaliGlyphs( subject );
		await expect( widget ).toHaveScreenshot( 'bengali-subject.png', {
			animations: 'disabled',
			caret: 'hide',
			maxDiffPixelRatio: 0.005,
		} );

		await subject.click();
		const slotsResponse = page.waitForResponse( ( response ) =>
			response.url().includes( '/wp-json/plumberslot/v1/slots?' )
		);
		await widget.getByRole( 'button', { name: 'Choose a time →' } ).click();
		expect( ( await slotsResponse ).status() ).toBe( 200 );
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

		await widget
			.getByPlaceholder( 'Anything helpful before the lesson' )
			.fill( 'আজ আমরা বাংলা ব্যাকরণ ও যুক্তাক্ষর অনুশীলন করব।' );
		await replaceText(
			widget.getByText( /^Held for / ),
			'সময়টি সাময়িকভাবে সংরক্ষিত'
		);
		await replaceText(
			widget
				.locator( '.ts-book__summary > div' )
				.filter( { hasText: 'When' } )
				.locator( 'dd' ),
			'সোমবার, ১০ আগস্ট · সকাল ১০:০০'
		);
		const summary = widget.locator( '.ts-book__summary' );
		await expectBengaliGlyphs( summary );
		await page
			.locator( '.ts-live' )
			.evaluateAll( ( elements ) =>
				elements.forEach( ( element ) => element.remove() )
			);
		await page.evaluate( () => document.fonts.ready );
		await expect( widget ).toHaveScreenshot( 'bengali-confirm.png', {
			animations: 'disabled',
			caret: 'hide',
			maxDiffPixelRatio: 0.005,
		} );
	} finally {
		await fixture( 'cleanup', fixtureKey );
	}
} );
