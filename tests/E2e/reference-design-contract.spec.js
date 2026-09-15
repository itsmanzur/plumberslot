import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const pluginRoot = path.resolve( __dirname, '..', '..' );
const frontendReference = fs.readFileSync(
	path.join( pluginRoot, 'reference-file', 'tutorslot-frontend.html' ),
	'utf8'
);
const adminReference = fs.readFileSync(
	path.join( pluginRoot, 'reference-file', 'tutorslot-admin.html' ),
	'utf8'
);

function readBuiltCss( name ) {
	return fs.readFileSync(
		path.join( pluginRoot, 'assets', 'dist', `${ name }.css` ),
		'utf8'
	);
}

async function stylesOf( page, selectors ) {
	return page.evaluate( ( requestedSelectors ) => {
		const properties = [
			'backgroundColor',
			'borderRadius',
			'boxShadow',
			'columnGap',
			'color',
			'paddingBottom',
			'paddingLeft',
			'paddingRight',
			'paddingTop',
			'rowGap',
		];

		return Object.fromEntries(
			requestedSelectors.map( ( selector ) => {
				const style = window.getComputedStyle(
					document.querySelector( selector )
				);
				return [
					selector,
					Object.fromEntries(
						properties.map( ( property ) => [
							property,
							style[ property ],
						] )
					),
				];
			} )
		);
	}, selectors );
}

test( 'public colour, spacing, radius and shadow match the reference', async ( {
	page,
} ) => {
	expect( frontendReference ).toContain( '--ts-radius:14px' );
	expect( frontendReference ).toContain(
		'0 10px 30px -14px rgba(20,27,45,.22)'
	);

	await page.setContent( `
		<style>${ readBuiltCss( 'widget' ) }</style>
		<div class="tutorslot-widget tutorslot-root">
			<section class="ts-book ts-book--step">
				<header class="ts-book__hd">Steps</header>
				<div class="ts-book__body">
					<div class="ts-tz">Timezone</div>
					<div class="ts-book__subjects">
						<button class="ts-book__subject">English</button>
						<button class="ts-book__subject">Maths</button>
					</div>
					<div class="ts-book__slots">
						<button class="ts-slot ts-slot--open">10:00</button>
					</div>
					<dl class="ts-book__summary"><div><dt>Total</dt><dd>Free</dd></div></dl>
					<label class="ts-book__field">Name<input value="Learner"></label>
				</div>
				<footer class="ts-book__ft"><button class="ts-btn ts-btn--primary">Continue</button></footer>
			</section>
		</div>
	` );

	const styles = await stylesOf( page, [
		'.tutorslot-widget',
		'.ts-book--step',
		'.ts-book__hd',
		'.ts-book__body',
		'.ts-tz',
		'.ts-book__subjects',
		'.ts-book__subject',
		'.ts-book__slots',
		'.ts-slot',
		'.ts-book__summary',
		'.ts-book__field input',
		'.ts-book__ft',
		'.ts-btn',
	] );

	expect( styles[ '.tutorslot-widget' ].color ).toBe( 'rgb(60, 71, 89)' );
	expect( styles[ '.ts-book--step' ] ).toMatchObject( {
		backgroundColor: 'rgb(255, 255, 255)',
		borderRadius: '14px',
		boxShadow:
			'rgba(20, 27, 45, 0.05) 0px 1px 2px 0px, rgba(20, 27, 45, 0.22) 0px 10px 30px -14px',
	} );
	expect( styles[ '.ts-book__hd' ] ).toMatchObject( {
		paddingBottom: '18px',
		paddingLeft: '22px',
		paddingRight: '22px',
		paddingTop: '18px',
	} );
	expect( styles[ '.ts-book__body' ].paddingTop ).toBe( '22px' );
	expect( styles[ '.ts-book__body' ].paddingLeft ).toBe( '22px' );
	expect( styles[ '.ts-tz' ] ).toMatchObject( {
		backgroundColor: 'rgb(245, 250, 223)',
		borderRadius: '10px',
		paddingLeft: '14px',
		paddingTop: '10px',
	} );
	expect( styles[ '.ts-book__subjects' ].columnGap ).toBe( '12px' );
	expect( styles[ '.ts-book__subject' ] ).toMatchObject( {
		borderRadius: '12px',
		paddingLeft: '18px',
		paddingTop: '16px',
	} );
	expect( styles[ '.ts-book__slots' ].columnGap ).toBe( '9px' );
	expect( styles[ '.ts-slot' ] ).toMatchObject( {
		backgroundColor: 'rgb(220, 238, 122)',
		borderRadius: '10px',
		paddingLeft: '8px',
		paddingTop: '12px',
	} );
	expect( styles[ '.ts-book__summary' ] ).toMatchObject( {
		backgroundColor: 'rgb(248, 249, 251)',
		borderRadius: '12px',
		paddingLeft: '18px',
		paddingTop: '16px',
	} );
	expect( styles[ '.ts-book__field input' ].borderRadius ).toBe( '10px' );
	expect( styles[ '.ts-book__ft' ] ).toMatchObject( {
		paddingLeft: '22px',
		paddingTop: '16px',
	} );
	expect( styles[ '.ts-btn' ] ).toMatchObject( {
		backgroundColor: 'rgb(47, 75, 168)',
		borderRadius: '10px',
		paddingLeft: '20px',
		paddingTop: '13px',
	} );
} );

test( 'admin colour, spacing, radius and shadow match the reference', async ( {
	page,
} ) => {
	expect( adminReference ).toContain( '--r-sm:6px; --r:10px; --r-lg:16px' );
	expect( adminReference ).toContain( '0 8px 24px -12px rgba(20,27,45,.18)' );

	await page.setContent( `
		<style>${ readBuiltCss( 'admin' ) }</style>
		<div class="tutorslot-admin ts-admin tutorslot-root">
			<section class="ts-admin-card">
				<div class="ts-admin-card__header"><h2>Availability</h2></div>
				<label class="ts-admin-field"><span>Name</span><input value="Tutor"></label>
				<button class="ts-btn ts-btn--primary">Save</button>
			</section>
		</div>
	` );

	const styles = await stylesOf( page, [
		'.tutorslot-admin',
		'.ts-admin-card',
		'.ts-admin-card__header',
		'.ts-admin-field input',
		'.ts-btn',
	] );

	expect( styles[ '.tutorslot-admin' ].color ).toBe( 'rgb(60, 71, 89)' );
	expect( styles[ '.ts-admin-card' ] ).toMatchObject( {
		backgroundColor: 'rgb(255, 255, 255)',
		borderRadius: '16px',
		boxShadow:
			'rgba(20, 27, 45, 0.05) 0px 1px 2px 0px, rgba(20, 27, 45, 0.18) 0px 8px 24px -12px',
		paddingLeft: '22px',
		paddingTop: '20px',
	} );
	expect( styles[ '.ts-admin-card__header' ] ).toMatchObject( {
		paddingLeft: '22px',
		paddingTop: '16px',
	} );
	expect( styles[ '.ts-admin-field input' ] ).toMatchObject( {
		borderRadius: '10px',
		paddingLeft: '12px',
		paddingTop: '10px',
	} );
	expect( styles[ '.ts-btn' ] ).toMatchObject( {
		backgroundColor: 'rgb(47, 75, 168)',
		borderRadius: '8px',
		paddingLeft: '15px',
		paddingTop: '10px',
	} );
} );

test( 'parent dashboard cards match the public reference surface', async ( {
	page,
} ) => {
	await page.setContent( `
		<style>${ readBuiltCss( 'dashboard' ) }</style>
		<div class="tutorslot-dashboard ts-dash tutorslot-root">
			<div class="ts-dash__tabs"><button class="ts-dash__tab">Learner</button></div>
			<div class="ts-dash__grid">
				<section class="ts-dash__panel">
					<div class="ts-dash__lesson"><time>27 Jul</time><span>Chemistry</span><button>Open</button></div>
				</section>
				<aside class="ts-dash__side"><section class="ts-dash__panel">Credits</section></aside>
			</div>
		</div>
	` );

	const styles = await stylesOf( page, [
		'.tutorslot-dashboard',
		'.ts-dash__tabs',
		'.ts-dash__tab',
		'.ts-dash__grid',
		'.ts-dash__panel',
		'.ts-dash__lesson',
	] );

	expect( styles[ '.tutorslot-dashboard' ].color ).toBe( 'rgb(60, 71, 89)' );
	expect( styles[ '.ts-dash__tabs' ].columnGap ).toBe( '7px' );
	expect( styles[ '.ts-dash__tab' ] ).toMatchObject( {
		backgroundColor: 'rgb(255, 255, 255)',
		paddingLeft: '15px',
		paddingTop: '8px',
	} );
	expect( styles[ '.ts-dash__grid' ].columnGap ).toBe( '20px' );
	expect( styles[ '.ts-dash__panel' ] ).toMatchObject( {
		backgroundColor: 'rgb(255, 255, 255)',
		borderRadius: '14px',
		boxShadow:
			'rgba(20, 27, 45, 0.05) 0px 1px 2px 0px, rgba(20, 27, 45, 0.22) 0px 10px 30px -14px',
		paddingLeft: '20px',
		paddingTop: '20px',
	} );
	expect( styles[ '.ts-dash__lesson' ] ).toMatchObject( {
		columnGap: '14px',
		paddingTop: '15px',
	} );
} );
