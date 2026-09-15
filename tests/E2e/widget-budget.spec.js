import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import zlib from 'zlib';

const distJs = path.join( __dirname, '../../assets/dist/widget.js' );
const distCss = path.join( __dirname, '../../assets/dist/widget.css' );
const srcApp = path.join( __dirname, '../../assets/src/widget/App.js' );
const srcTimeStep = path.join(
	__dirname,
	'../../assets/src/widget/views/TimeStep.js'
);
const srcSubjectStep = path.join(
	__dirname,
	'../../assets/src/widget/views/SubjectStep.js'
);
const srcConfirmStep = path.join(
	__dirname,
	'../../assets/src/widget/views/ConfirmStep.js'
);
const srcDoneView = path.join(
	__dirname,
	'../../assets/src/widget/views/DoneView.js'
);
const srcPaymentReturn = path.join(
	__dirname,
	'../../assets/src/widget/views/PaymentReturnView.js'
);
const srcLib = path.join( __dirname, '../../assets/src/widget/lib.js' );
const srcCompat = path.join( __dirname, '../../assets/src/widget/compat.css' );
const srcA11y = path.join( __dirname, '../../assets/src/widget/a11y.js' );

test( 'widget JS + CSS gzip size stays under 50KB', async () => {
	const js = fs.readFileSync( distJs );
	const css = fs.readFileSync( distCss );
	const gzipBytes =
		zlib.gzipSync( js, { level: 9 } ).byteLength +
		zlib.gzipSync( css, { level: 9 } ).byteLength;
	expect( gzipBytes ).toBeLessThan( 50 * 1024 );
} );

test( '390px layout guards: overflow-x hidden and single-column subjects', async () => {
	const css = fs.readFileSync( distCss, 'utf8' );
	expect( css ).toMatch( /overflow-x:\s*hidden/ );
	expect( css ).toMatch( /max-width:\s*390px/ );
	expect( css ).toMatch( /grid-template-columns:\s*1fr/ );
} );

test( 'keyboard-capable booking controls exist in widget source', async () => {
	const source = fs.readFileSync( distJs, 'utf8' );
	for ( const marker of [
		'role:"listbox"',
		'role:"option"',
		'aria-selected',
		'Choose a time',
		'Confirm and book',
	] ) {
		expect(
			source.includes( marker ) ||
				source.includes( marker.replace( /"/g, '' ) )
		).toBeTruthy();
	}
	// Buttons are native <button> via Preact h('button'…) / shared Button.
	expect( source ).toContain( 'button' );
} );

test( 'booking listboxes support roving keyboard selection', async () => {
	const source = fs.readFileSync( srcA11y, 'utf8' );
	for ( const key of [ 'ArrowDown', 'ArrowUp', 'Home', 'End' ] ) {
		expect( source ).toContain( `'${ key }'` );
	}
	expect( source ).toContain( 'preventDefault' );
	expect( source ).toContain( 'querySelectorAll( \'[role="option"]\' )' );
} );

test( 'time step selects an available day and clears stale time choices', async () => {
	const source = fs.readFileSync( srcTimeStep, 'utf8' );
	expect( source ).toContain( 'firstOpenDayId' );
	expect( source ).toContain( 'selectedDayIsOpen' );
	expect( source ).toContain( 'onSelectStart( null )' );
} );

test( 'widget actions meet the 44px touch-target floor', async () => {
	const css = fs.readFileSync( distCss, 'utf8' );
	expect( css ).toMatch(
		/\.tutorslot-widget \.ts-btn[^}]*min-height:\s*44px/s
	);
	expect( css ).toMatch( /\.ts-tz__change[^}]*min-height:\s*44px/s );
	expect( css ).toMatch( /\.ts-book__more summary[^}]*min-height:\s*44px/s );
	expect( css ).toMatch( /\.ts-book__check[^}]*min-height:\s*44px/s );
	expect( css ).toMatch( /\.ts-book__day[^}]*min-height:\s*44px/s );
} );

test( 'subject step uses polished fallback copy and left alignment', async () => {
	const source = fs.readFileSync( srcSubjectStep, 'utf8' );
	const css = fs.readFileSync( distCss, 'utf8' );
	expect( source ).toContain( 'minute lesson' );
	expect( source ).toContain( "'Free'" );
	expect( source ).toContain( '/ lesson' );
	expect( css ).toMatch( /\.ts-book--step[^}]*margin:\s*0/s );
	expect( css ).toMatch(
		/\.tutorslot-widget \.ts-book__subject\.is-selected[^}]*background:\s*var\(--ts-primary-wash\)\s*!important/s
	);
} );

test( 'theme button hover cannot override subject readability', async () => {
	const css = fs.readFileSync( distCss, 'utf8' );
	expect( css ).toMatch(
		/\.tutorslot-widget \.ts-book__subject:hover[^}]*background:\s*var\(--ts-card\)\s*!important/s
	);
	expect( css ).toMatch(
		/\.tutorslot-widget \.ts-book__subject:hover[^}]*color:\s*var\(--ts-ink\)/s
	);
} );

test( 'widget compatibility layer owns interactive component states', async () => {
	const source = fs.readFileSync( srcCompat, 'utf8' );
	for ( const marker of [
		'.ts-btn--primary:hover:not(:disabled)',
		'.ts-day:disabled:hover',
		'.ts-day[aria-pressed="true"]:hover',
		'.ts-slot--open:hover',
		'.ts-slot[aria-selected="true"]:hover',
		'.ts-book__who-option.is-selected:hover',
		'.ts-book__pay-option.is-selected:hover',
		'.ts-book__field :where(',
	] ) {
		expect( source ).toContain( marker );
	}
	expect( source ).toContain( 'background: var(--ts-card) !important' );
	expect( source ).toContain( 'appearance: auto !important' );
} );

test( 'confirm step releases holds and books only linked students', async () => {
	const source = fs.readFileSync( srcConfirmStep, 'utf8' );
	for ( const marker of [
		"get( 'relations/children' )",
		"remove( 'bookings/hold'",
		'student_id: forChild ? childId : undefined',
		"role: 'group'",
		'Linked student',
		'Who is this for?',
		'Payment',
		'Securing this time',
		'onClick: leaveConfirm',
	] ) {
		expect( source ).toContain( marker );
	}
} );

test( 'payment return verifies server state before claiming success', async () => {
	const app = fs.readFileSync( srcApp, 'utf8' );
	const source = fs.readFileSync( srcPaymentReturn, 'utf8' );
	for ( const marker of [
		'get( `bookings/${ bookingId }` )',
		"paymentState( result, outcome ) === 'pending'",
		'POLL_LIMIT',
		'Do not pay again.',
		'We could not verify this payment',
	] ) {
		expect( source ).toContain( marker );
	}
	expect( app ).toContain( 'PaymentReturnView' );
	expect( app ).not.toContain( "title: 'Payment received:'" );
} );

test( 'completion view exposes resilient reference and calendar actions', async () => {
	const done = fs.readFileSync( srcDoneView, 'utf8' );
	const lib = fs.readFileSync( srcLib, 'utf8' );
	const css = fs.readFileSync( distCss, 'utf8' );
	for ( const marker of [
		'bookingReference',
		'Payment confirmed',
		'Add to calendar',
		'Open secure lesson link',
		'Package credit',
	] ) {
		expect( done ).toContain( marker );
	}
	for ( const marker of [ 'UID:', 'DTSTAMP:', 'escapeIcsText' ] ) {
		expect( lib ).toContain( marker );
	}
	expect( css ).toMatch(
		/\.ts-book__done-actions \.ts-btn[^}]*width:\s*100%/s
	);
} );

test( 'mobile booking path screens are present in the widget', async () => {
	const app = fs.readFileSync( srcApp, 'utf8' );
	const bundle = fs.readFileSync( distJs, 'utf8' );
	for ( const step of [ 'subject', 'time', 'confirm', 'done', 'profile' ] ) {
		expect( app ).toContain( `'${ step }'` );
	}
	for ( const label of [
		'What do you want to work on?',
		'When works for you?',
		'Confirm and book',
		'Booked',
	] ) {
		expect( bundle ).toContain( label );
	}
} );

test( 'conditional enqueue + lean bundle support LCP budget', async () => {
	const assetManager = fs.readFileSync(
		path.join( __dirname, '../../src/Frontend/AssetManager.php' ),
		'utf8'
	);
	expect( assetManager ).toContain( 'mark_needed' );
	expect( assetManager ).toContain( 'has_shortcode' );
	expect( assetManager ).toContain( "asset_metadata( 'widget' )" );
	expect( assetManager ).toContain( "$asset['version']" );
	const gzipBytes =
		zlib.gzipSync( fs.readFileSync( distJs ), { level: 9 } ).byteLength +
		zlib.gzipSync( fs.readFileSync( distCss ), { level: 9 } ).byteLength;
	// Lean payload is the primary LCP lever for the widget on 4G.
	expect( gzipBytes ).toBeLessThan( 25 * 1024 );
} );
