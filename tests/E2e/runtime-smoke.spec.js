import { test, expect } from '@playwright/test';

test( 'WordPress and the PlumberSlot REST route boot without a fatal error', async ( {
	request,
} ) => {
	const home = await request.get( '/' );
	expect( home.status() ).toBe( 200 );
	expect( await home.text() ).not.toContain( 'Fatal error' );

	const slots = await request.get( '/wp-json/plumberslot/v1/slots' );
	expect( slots.status() ).toBe( 400 );

	const payload = await slots.json();
	expect( payload.code ).toBe( 'rest_missing_callback_param' );
	expect( payload.data.params ).toEqual(
		expect.arrayContaining( [ 'tutor_id', 'from', 'to' ] )
	);
} );

test( 'compiled admin and widget assets are publicly readable', async ( {
	request,
} ) => {
	for ( const asset of [
		'/wp-content/plugins/plumberslot/assets/dist/admin.js',
		'/wp-content/plugins/plumberslot/assets/dist/admin.css',
		'/wp-content/plugins/plumberslot/assets/dist/widget.js',
		'/wp-content/plugins/plumberslot/assets/dist/widget.css',
		'/wp-content/plugins/plumberslot/assets/dist/block.js',
	] ) {
		const response = await request.get( asset );
		expect( response.status(), asset ).toBe( 200 );
	}
} );
