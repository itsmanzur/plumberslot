import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	CreditMeter,
	EmptyState,
	ErrorState,
	Skeleton,
	StatusChip,
	announce,
	navigateTo,
} from '../shared';
import { get, getBoot, post } from './api';

export function ParentDashboard() {
	const boot = getBoot();
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ children, setChildren ] = useState( [] );
	const [ childId, setChildId ] = useState( 0 );
	const [ bookings, setBookings ] = useState( [] );
	const [ packages, setPackages ] = useState( [] );
	const [ catalog, setCatalog ] = useState( null );
	const [ inviteEmail, setInviteEmail ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const load = async () => {
		setError( '' );
		try {
			const [ kids, family, credits, pack ] = await Promise.all( [
				get( 'relations/children' ),
				get( 'bookings', { scope: 'family', per_page: 50 } ),
				get( 'credits' ),
				get( 'credits/packages' ),
			] );
			const list = kids.items || [];
			setChildren( list );
			setChildId( ( prev ) => prev || list[ 0 ]?.student_id || 0 );
			setBookings( family.bookings || family.items || [] );
			setPackages( credits.items || [] );
			setCatalog( pack );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load dashboard.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		if ( ! boot.loggedIn ) {
			setStatus( 'login' );
			return;
		}
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const filtered = useMemo( () => {
		if ( ! childId ) {
			return bookings;
		}
		return bookings.filter( ( b ) => b.student_id === childId );
	}, [ bookings, childId ] );

	const meter = useMemo( () => {
		const active = packages.filter(
			( p ) =>
				p.remaining > 0 &&
				( ! p.expires_at || Date.parse( p.expires_at ) > Date.now() )
		);
		const total = active.reduce( ( s, p ) => s + p.total, 0 );
		const used = active.reduce( ( s, p ) => s + p.used, 0 );
		const expires = active
			.map( ( p ) => p.expires_at )
			.filter( Boolean )
			.sort()[ 0 ];
		return { total, used, expires };
	}, [ packages ] );

	const notes = useMemo(
		() =>
			filtered
				.filter( ( b ) => b.notes && String( b.notes ).trim() )
				.slice( 0, 5 ),
		[ filtered ]
	);

	const invite = async () => {
		setBusy( true );
		try {
			await post( 'relations/invite', { student_email: inviteEmail } );
			announce( 'Invitation sent.' );
			setInviteEmail( '' );
			await load();
		} catch ( err ) {
			setError( err.message );
		} finally {
			setBusy( false );
		}
	};

	const buyCredits = async () => {
		setBusy( true );
		try {
			await post( 'credits', {
				total: catalog?.default_total || 10,
			} );
			announce( 'Package purchased.' );
			await load();
		} catch ( err ) {
			setError( err.message );
		} finally {
			setBusy( false );
		}
	};

	if ( status === 'login' ) {
		return h(
			'div',
			{ class: 'ts-dash' },
			h(
				Callout,
				{ title: 'Sign in:' },
				'Parents need an account to manage family lessons.'
			),
			h(
				Button,
				{
					onClick: () =>
						navigateTo( boot.loginUrl, { sameOrigin: true } ),
				},
				'Sign in'
			)
		);
	}

	if ( status === 'loading' ) {
		return h( 'div', { class: 'ts-dash' }, h( Skeleton, { lines: 6 } ) );
	}

	if ( status === 'error' ) {
		return h(
			'div',
			{ class: 'ts-dash' },
			h( ErrorState, {
				title: 'Dashboard unavailable',
				description: error,
			} )
		);
	}

	return h(
		'div',
		{ class: 'ts-dash' },
		h(
			'header',
			{ class: 'ts-dash__hero' },
			h(
				'p',
				{ class: 'ts-dash__eyebrow' },
				`Signed in as ${ boot.user?.name || '' }`
			),
			h( 'h1', null, "Your family's lessons" )
		),
		error ? h( Callout, { tone: 'warn', title: 'Notice:' }, error ) : null,
		h(
			'div',
			{ class: 'ts-dash__tabs', role: 'tablist' },
			children.map( ( child ) =>
				h(
					'button',
					{
						key: child.student_id,
						type: 'button',
						class:
							'ts-dash__tab' +
							( child.student_id === childId
								? ' is-active'
								: '' ),
						onClick: () => setChildId( child.student_id ),
					},
					h( 'span', { class: 'ts-dash__dot' }, child.initials ),
					child.student_name
				)
			),
			h(
				'div',
				{ class: 'ts-dash__invite' },
				h( 'input', {
					type: 'email',
					placeholder: 'Child email',
					value: inviteEmail,
					onInput: ( e ) => setInviteEmail( e.target.value ),
				} ),
				h(
					Button,
					{
						variant: 'ghost',
						disabled: busy || ! inviteEmail,
						onClick: invite,
					},
					'+ Add a child'
				)
			)
		),
		h(
			'div',
			{ class: 'ts-dash__grid' },
			h(
				'section',
				{ class: 'ts-dash__panel' },
				h(
					'div',
					{ class: 'ts-dash__panel-hd' },
					h( 'h2', null, 'Coming up' ),
					h(
						Button,
						{
							variant: 'secondary',
							onClick: () =>
								navigateTo(
									boot.bookingUrl || boot.dashboardUrl || '/',
									{ sameOrigin: true }
								),
						},
						'Book another lesson'
					)
				),
				filtered.length === 0
					? h( EmptyState, {
							title: 'No lessons yet',
							description:
								'Book a lesson for this child to see it here.',
					  } )
					: filtered.map( ( row ) =>
							h(
								'article',
								{ key: row.id, class: 'ts-dash__lesson' },
								h(
									'div',
									{ class: 'ts-dash__when' },
									h(
										'small',
										null,
										weekday( row.start_utc )
									),
									h( 'b', null, dayNum( row.start_utc ) )
								),
								h(
									'div',
									{ class: 'ts-dash__what' },
									h(
										'b',
										null,
										`${ row.subject } · ${
											row.tutor || ''
										}`
									),
									h(
										'small',
										{ class: 'ts-mono' },
										[
											timeRange(
												row.start_utc,
												row.end_utc
											),
											row.series_label,
										]
											.filter( Boolean )
											.join( ' · ' )
									)
								),
								h(
									StatusChip,
									{ tone: payTone( row.payment ) },
									row.payment
								)
							)
					  )
			),
			h(
				'aside',
				{ class: 'ts-dash__side' },
				h(
					'section',
					{ class: 'ts-dash__panel' },
					h( CreditMeter, {
						used: meter.used,
						total: meter.total || catalog?.default_total || 0,
						label: 'Lesson credits',
						variant: 'pips',
					} ),
					meter.expires
						? h(
								'p',
								{ class: 'ts-dash__meta' },
								`Expires ${ new Date(
									meter.expires
								).toLocaleDateString() }. Unused credits roll over once if you buy again before then.`
						  )
						: null,
					h(
						Button,
						{
							variant: 'secondary',
							disabled: busy,
							onClick: buyCredits,
						},
						`Buy ${ catalog?.default_total || 10 } more`
					)
				),
				h(
					'section',
					{ class: 'ts-dash__panel' },
					h( 'h3', null, 'After each lesson' ),
					h(
						'p',
						{ class: 'ts-dash__meta' },
						'Tutors leave a short note so you know what was covered.'
					),
					notes.length === 0
						? h( 'p', { class: 'ts-dash__meta' }, 'No notes yet.' )
						: notes.map( ( n ) =>
								h(
									'div',
									{ key: n.id, class: 'ts-dash__note' },
									h(
										'b',
										null,
										`${ dayLabel( n.start_utc ) } — `
									),
									n.notes
								)
						  )
				)
			)
		)
	);
}

function weekday( iso ) {
	return new Date(
		iso.includes( 'T' ) ? iso : iso.replace( ' ', 'T' ) + 'Z'
	).toLocaleDateString( undefined, { weekday: 'short' } );
}

function dayNum( iso ) {
	return new Date(
		iso.includes( 'T' ) ? iso : iso.replace( ' ', 'T' ) + 'Z'
	).getDate();
}

function dayLabel( iso ) {
	return new Date(
		iso.includes( 'T' ) ? iso : iso.replace( ' ', 'T' ) + 'Z'
	).toLocaleDateString();
}

function timeRange( start, end ) {
	const s = new Date(
		start.includes( 'T' ) ? start : start.replace( ' ', 'T' ) + 'Z'
	);
	const e = end
		? new Date( end.includes( 'T' ) ? end : end.replace( ' ', 'T' ) + 'Z' )
		: null;
	const opts = { hour: '2-digit', minute: '2-digit' };
	return e
		? `${ s.toLocaleTimeString( undefined, opts ) }–${ e.toLocaleTimeString(
				undefined,
				opts
		  ) }`
		: s.toLocaleTimeString( undefined, opts );
}

function payTone( payment ) {
	if ( payment === 'paid' || payment === 'credit' || payment === 'free' ) {
		return 'ok';
	}
	if ( payment === 'unpaid' ) {
		return 'wait';
	}
	return 'idle';
}
