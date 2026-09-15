import { h, render } from 'preact';
import { DoneView } from './views/DoneView';
import { ProfileView } from './views/ProfileView';

const PAYLOAD =
	'<img src=x onerror="window.__plumberslotXss=1"><script>window.__plumberslotXss=1</script>';

let root;

beforeEach( () => {
	root = document.createElement( 'div' );
	document.body.appendChild( root );
	window.__plumberslotXss = 0;
} );

afterEach( () => {
	render( null, root );
	root.remove();
	delete window.__plumberslotXss;
} );

test( 'renders stored technician, service, and review payloads as text', () => {
	render(
		h( ProfileView, {
			technician: {
				initials: 'TS',
				display_name: PAYLOAD,
				from_price_minor: 0,
				currency: 'USD',
				rating: 5,
				lesson_count: 1,
				years_teaching: 1,
				response_time: PAYLOAD,
				languages: [ PAYLOAD ],
				services: [ { id: 1, name: PAYLOAD } ],
				bio: PAYLOAD,
				reviews: [
					{
						id: 1,
						author: PAYLOAD,
						rating: 5,
						role: 'Parent',
						body: PAYLOAD,
					},
				],
				default_duration: 60,
				meeting_provider: 'Online',
			},
			onBook: () => {},
		} ),
		root
	);

	expect( root.querySelector( 'script, img' ) ).toBeNull();
	expect( root.textContent ).toContain( PAYLOAD );
	expect( window.__plumberslotXss ).toBe( 0 );
} );

test( 'does not expose an executable meeting URL', () => {
	render(
		h( DoneView, {
			technician: {
				display_name: 'Technician',
				default_duration: 60,
				meeting_provider: 'Online',
				currency: 'USD',
			},
			service: { name: 'Math', duration_min: 60 },
			booking: {
				id: 1,
				start_utc: '2030-01-01T10:00:00Z',
				end_utc: '2030-01-01T11:00:00Z',
				join_url: 'javascript:window.__plumberslotXss=1',
				price_minor: 0,
				currency: 'USD',
				payment: 'free',
				status: 'confirmed',
			},
			timezone: 'UTC',
		} ),
		root
	);

	expect( root.querySelector( 'a[href^="javascript:"]' ) ).toBeNull();
	expect( root.textContent ).not.toContain( 'Join lesson' );
	expect( window.__plumberslotXss ).toBe( 0 );
} );
