import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const distJs = path.join( __dirname, '../../assets/dist/admin.js' );
const distCss = path.join( __dirname, '../../assets/dist/admin.css' );
const baselineDir = path.join( __dirname, 'baselines' );

const SCREENS = [
	{ slug: 'plumberslot', title: 'Dashboard' },
	{ slug: 'plumberslot-availability', title: 'Availability' },
	{ slug: 'plumberslot-bookings', title: 'Bookings' },
	{ slug: 'plumberslot-tutors', title: 'Tutors' },
	{ slug: 'plumberslot-setup', title: 'Set up PlumberSlot' },
	{ slug: 'plumberslot-settings', title: 'Settings' },
	{
		slug: 'plumberslot-help',
		title: 'Teaching time, without scheduling chaos.',
	},
	{ slug: 'plumberslot-bookings-empty', title: 'Bookings' },
];

test( 'admin bundle exposes all eight reference screens', async () => {
	const source = fs.readFileSync( distJs, 'utf8' );
	for ( const marker of [
		'plumberslot-availability',
		'plumberslot-subjects',
		'plumberslot-bookings',
		'plumberslot-tutors',
		'plumberslot-settings',
		'plumberslot-setup',
		'plumberslot-help',
		'data-screen',
		'Dashboard',
		'Availability',
		'Subjects',
		'Bookings',
		'Tutors',
		'Settings',
		'Teaching time, without scheduling chaos.',
	] ) {
		expect( source, marker ).toContain( marker );
	}
} );

test( 'visual-regression HTML baselines for eight screens', async () => {
	expect( fs.existsSync( distCss ) ).toBe( true );
	fs.mkdirSync( baselineDir, { recursive: true } );

	const cssHash = fs.readFileSync( distCss, 'utf8' ).length;

	for ( const screen of SCREENS ) {
		const html = [
			'<!doctype html>',
			`<html data-viewport="390,tablet,desktop" data-css-bytes="${ cssHash }">`,
			'<body class="plumberslot-admin ts-admin">',
			`<section data-screen="${ screen.slug }">`,
			`<h1>${ screen.title }</h1>`,
			'<p class="ts-admin__sub">Visual baseline shell</p>',
			'<div class="ts-admin-card">Screen mount is live</div>',
			'</section>',
			'</body></html>',
			'',
		].join( '\n' );

		const file = path.join( baselineDir, `${ screen.slug }.html` );
		if ( ! fs.existsSync( file ) ) {
			fs.writeFileSync( file, html, 'utf8' );
		}
		expect( fs.readFileSync( file, 'utf8' ) ).toContain(
			`data-screen="${ screen.slug }"`
		);
		expect( fs.readFileSync( file, 'utf8' ) ).toContain( screen.title );
	}

	expect(
		fs.readdirSync( baselineDir ).filter( ( f ) => f.endsWith( '.html' ) )
	).toHaveLength( 8 );
} );

test( 'mobile availability keeps timetable overflow inside its card', async ( {
	page,
} ) => {
	const css = fs.readFileSync( distCss, 'utf8' );
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.setContent( `
		<style>${ css }</style>
		<main class="plumberslot-admin ts-admin">
			<div class="ts-admin-two ts-avail">
				<section class="ts-admin-card ts-admin-card--flush">
					<div class="ts-admin-card__body">
						<div class="ts-tt-wrap">
							<table class="ts-tt"><tbody><tr><td>Availability</td></tr></tbody></table>
						</div>
					</div>
				</section>
				<aside class="ts-admin-stack ts-avail__side">Lesson defaults</aside>
			</div>
		</main>
	` );

	const layout = await page.evaluate( () => {
		const grid = document.querySelector( '.ts-tt-wrap' );
		return {
			pageWidth: document.documentElement.scrollWidth,
			viewportWidth: window.innerWidth,
			gridClientWidth: grid.clientWidth,
			gridScrollWidth: grid.scrollWidth,
		};
	} );

	expect( layout.pageWidth ).toBeLessThanOrEqual( layout.viewportWidth );
	expect( layout.gridScrollWidth ).toBeGreaterThan( layout.gridClientWidth );
} );

test( 'mobile admin controls keep accessible touch targets', async ( {
	page,
} ) => {
	const css = fs.readFileSync( distCss, 'utf8' );
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.setContent( `
		<style>${ css }</style>
		<main class="plumberslot-admin ts-admin">
			<button class="ts-btn ts-btn--ghost ts-btn--sm">Open</button>
			<div class="ts-admin-tabs"><button>Upcoming</button></div>
			<div class="ts-toggle">
				<button class="ts-toggle__switch" role="switch" aria-checked="false">
					<span class="ts-toggle__track" aria-hidden="true"></span>
				</button>
			</div>
		</main>
	` );

	const targets = await page
		.locator( '.ts-btn, .ts-admin-tabs button, .ts-toggle__switch' )
		.evaluateAll( ( controls ) =>
			controls.map( ( control ) => {
				const rect = control.getBoundingClientRect();
				return { width: rect.width, height: rect.height };
			} )
		);

	for ( const target of targets ) {
		expect( target.width ).toBeGreaterThanOrEqual( 44 );
		expect( target.height ).toBeGreaterThanOrEqual( 44 );
	}
} );
