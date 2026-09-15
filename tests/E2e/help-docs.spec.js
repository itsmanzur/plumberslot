import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const pluginRoot = path.join( __dirname, '../..' );
const distJs = path.join( pluginRoot, 'assets/dist/admin.js' );
const distCss = path.join( pluginRoot, 'assets/dist/admin.css' );
const adminMenu = path.join( pluginRoot, 'src/Admin/AdminMenu.php' );

test( 'help page and external video-link contract ship in the release footprint', async () => {
	const source = fs.readFileSync( distJs, 'utf8' );
	const css = fs.readFileSync( distCss, 'utf8' );
	const php = fs.readFileSync( adminMenu, 'utf8' );

	for ( const marker of [
		'plumberslot-help',
		'Teaching time, without scheduling chaos.',
		'Plain-English guides',
		'Your first four steps',
		'Video link coming soon',
	] ) {
		expect( source, marker ).toContain( marker );
	}

	expect( css ).toContain( '.ts-docs__hero' );
	expect( css ).toContain( '.ts-docs__video-link' );
	expect( php ).toContain( 'plumberslot_help_video_url' );
	expect( php ).toContain( "'videoUrl'" );
	expect( fs.existsSync( path.join( pluginRoot, 'assets/docs' ) ) ).toBe(
		false
	);
} );

test( 'help page shell does not overflow a 390px viewport', async ( {
	page,
} ) => {
	const css = fs.readFileSync( distCss, 'utf8' );
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.setContent( `
		<style>${ css }</style>
		<main class="plumberslot-admin ts-admin">
			<section class="ts-docs">
				<section class="ts-docs__hero">
					<div class="ts-docs__hero-copy">
						<h1>Teaching time, without scheduling chaos.</h1>
						<p class="ts-docs__lead">A plain-English guide for technicians.</p>
					</div>
				</section>
				<nav class="ts-docs__nav"><a href="#guide">Feature guides</a></nav>
				<section class="ts-docs__section" id="guide">
					<div class="ts-docs__usp-grid"><article class="ts-docs__usp">Technician-first availability</article></div>
					<div class="ts-docs__shortcode"><code>[plumberslot technician="your-technician-slug"]</code></div>
				</section>
			</section>
		</main>
	` );

	const width = await page.evaluate( () => ( {
		document: document.documentElement.scrollWidth,
		viewport: window.innerWidth,
	} ) );

	expect( width.document ).toBeLessThanOrEqual( width.viewport );
} );
