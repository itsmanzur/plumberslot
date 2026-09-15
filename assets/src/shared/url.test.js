import { safeUrl } from './url';

const BASE = 'https://example.test/book/';

describe( 'safeUrl', () => {
	test.each( [
		'javascript:alert(1)',
		'data:text/html,<script>alert(1)</script>',
		'vbscript:msgbox(1)',
		'https://user:secret@example.test/book/',
		'',
	] )( 'rejects executable, credentialed, or empty target %s', ( value ) => {
		expect( safeUrl( value, { base: BASE } ) ).toBe( '' );
	} );

	test( 'normalizes relative same-origin targets', () => {
		expect( safeUrl( '/technician-dashboard/', { base: BASE } ) ).toBe(
			'https://example.test/technician-dashboard/'
		);
	} );

	test( 'allows an external HTTPS payment target by default', () => {
		expect(
			safeUrl( 'https://checkout.example/pay', { base: BASE } )
		).toBe( 'https://checkout.example/pay' );
	} );

	test( 'rejects a cross-origin target under the same-origin policy', () => {
		expect(
			safeUrl( '//evil.example/steal', { base: BASE, sameOrigin: true } )
		).toBe( '' );
	} );
} );
