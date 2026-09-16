import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	CreditMeter,
	DayStrip,
	EmptyState,
	ErrorState,
	Modal,
	Skeleton,
	SlotButton,
	StatusChip,
	announce,
	navigateTo,
} from '../shared';
import { del, get, getBoot, post } from './api';

const TABS = [
	{ id: 'upcoming', label: 'Upcoming' },
	{ id: 'past', label: 'Past' },
];

export function CustomerDashboard() {
	const boot = getBoot();
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ tab, setTab ] = useState( 'upcoming' );
	const [ upcoming, setUpcoming ] = useState( [] );
	const [ past, setPast ] = useState( [] );
	const [ credits, setCredits ] = useState( [] );
	const [ ledger, setLedger ] = useState( [] );
	const [ busyId, setBusyId ] = useState( 0 );
	const [ cancelTarget, setCancelTarget ] = useState( null );

	// Reschedule dialog state. There is no shared slot-picker component to pull
	// in from the admin bundle (BookingsScreen.js's version lives in a
	// different webpack entry and isn't factored out into assets/src/shared),
	// so this is a smaller, self-contained version of the same idea: load the
	// next week of open slots for the booking's technician and let the
	// customer pick one.
	const [ reschedule, setReschedule ] = useState( null );
	const [ rescheduleSlot, setRescheduleSlot ] = useState( '' );
	const [ slotStatus, setSlotStatus ] = useState( 'idle' );
	const [ slotError, setSlotError ] = useState( '' );
	const [ slots, setSlots ] = useState( [] );
	const [ day, setDay ] = useState( '' );
	const [ savingMove, setSavingMove ] = useState( false );

	const load = async () => {
		try {
			const [ nextUp, done, creditRes, ledgerRes ] = await Promise.all( [
				get( 'bookings', {
					scope: 'mine',
					per_page: 50,
					tab: 'upcoming',
				} ),
				get( 'bookings', {
					scope: 'mine',
					per_page: 20,
					tab: 'past',
				} ),
				get( 'credits' ),
				get( 'credits/ledger', { limit: 10 } ),
			] );
			setUpcoming( nextUp.bookings || [] );
			setPast( done.bookings || [] );
			setCredits( creditRes.items || [] );
			setLedger( ledgerRes.items || [] );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load your dashboard.' );
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

	const tz = useMemo(
		() => reschedule?.technician_timezone || detectTimezone(),
		[ reschedule ]
	);

	const dayStrip = useMemo(
		() => buildDayStrip( new Date(), 8, tz ),
		[ tz ]
	);

	useEffect( () => {
		if ( ! reschedule ) {
			return undefined;
		}
		let alive = true;
		( async () => {
			setSlotStatus( 'loading' );
			setSlotError( '' );
			try {
				const from = new Date();
				from.setHours( 0, 0, 0, 0 );
				const to = new Date( from.getTime() + 8 * 86400000 );
				// Unlike the admin reschedule dialog, this never passes
				// exclude_booking: SlotsController::can_exclude_booking() only
				// lets the technician (or a site manager) ask the slot engine
				// to ignore a booking, so a plain customer request with that
				// param would just be denied. Their own current slot simply
				// shows as taken, which is fine — there's no reason to
				// "reschedule" to the same time.
				const data = await get( 'slots', {
					technician_id: reschedule.technician_id,
					from: from.toISOString(),
					to: to.toISOString(),
					duration: reschedule.duration_min || 60,
					timezone: tz,
				} );
				if ( ! alive ) {
					return;
				}
				setSlots( data.slots || [] );
				setSlotStatus( 'ready' );
			} catch ( err ) {
				if ( ! alive ) {
					return;
				}
				setSlotError( err.message || 'Could not load open times.' );
				setSlotStatus( 'error' );
			}
		} )();
		return () => {
			alive = false;
		};
	}, [ reschedule, tz ] );

	const daysWithAvailability = useMemo( () => {
		const openDays = new Set(
			slots
				.filter( ( slot ) => slot.state === 'open' )
				.map( ( slot ) => dayKeyInZone( slot.start, tz ) )
		);
		return dayStrip.map( ( item ) => ( {
			...item,
			disabled: ! openDays.has( item.id ),
		} ) );
	}, [ dayStrip, slots, tz ] );

	useEffect( () => {
		if ( ! reschedule || slotStatus !== 'ready' ) {
			return;
		}
		const firstOpen = daysWithAvailability.find(
			( item ) => ! item.disabled
		);
		if (
			firstOpen &&
			( ! day ||
				daysWithAvailability.find( ( d ) => d.id === day )?.disabled )
		) {
			setDay( firstOpen.id );
		}
	}, [ reschedule, slotStatus, daysWithAvailability, day ] );

	const slotsForDay = useMemo( () => {
		if ( ! day ) {
			return [];
		}
		return slots.filter(
			( slot ) =>
				slot.state === 'open' && dayKeyInZone( slot.start, tz ) === day
		);
	}, [ slots, day, tz ] );

	const openReschedule = ( row ) => {
		setReschedule( row );
		setRescheduleSlot( '' );
		setSlots( [] );
		setSlotError( '' );
		setSlotStatus( 'loading' );
		setDay( '' );
	};

	const closeReschedule = () => {
		setReschedule( null );
		setRescheduleSlot( '' );
		setSlots( [] );
		setSlotError( '' );
		setSlotStatus( 'idle' );
	};

	const onReschedule = async () => {
		if ( ! reschedule || ! rescheduleSlot ) {
			announce( 'Pick an open time first.' );
			return;
		}
		setSavingMove( true );
		try {
			await post( `bookings/${ reschedule.id }/reschedule`, {
				start: rescheduleSlot,
			} );
			announce( 'Appointment moved.' );
			closeReschedule();
			await load();
		} catch ( err ) {
			const message = err.message || 'Could not move this appointment.';
			setSlotError( message );
			announce( message );
		} finally {
			setSavingMove( false );
		}
	};

	const onCancel = async () => {
		if ( ! cancelTarget ) {
			return;
		}
		setBusyId( cancelTarget.id );
		try {
			await del( `bookings/${ cancelTarget.id }` );
			announce( 'Appointment cancelled.' );
			setCancelTarget( null );
			await load();
		} catch ( err ) {
			const message = err.message || 'Could not cancel this appointment.';
			setError( message );
			announce( message );
		} finally {
			setBusyId( 0 );
		}
	};

	if ( status === 'login' ) {
		return h(
			'div',
			{ class: 'ts-dash' },
			h(
				Callout,
				{ title: 'Sign in:' },
				'Sign in to see your bookings, service plan balance, and reschedule or cancel an appointment.'
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
				actionLabel: 'Try again',
				onAction: load,
			} )
		);
	}

	const rows = tab === 'past' ? past : upcoming;

	return h(
		'div',
		{ class: 'ts-dash' },
		h(
			'header',
			{ class: 'ts-dash__hero' },
			h(
				'p',
				{ class: 'ts-dash__eyebrow' },
				boot.user?.name || 'Your account'
			),
			h( 'h1', null, 'Your appointments' )
		),
		error ? h( Callout, { tone: 'warn', title: 'Notice:' }, error ) : null,
		h(
			'div',
			{ class: 'ts-dash__grid' },
			h(
				'section',
				{ class: 'ts-dash__panel' },
				h(
					'div',
					{ class: 'ts-dash__tabs', role: 'tablist' },
					TABS.map( ( item ) =>
						h(
							'button',
							{
								type: 'button',
								key: item.id,
								class: [
									'ts-dash__tab',
									tab === item.id ? 'is-active' : '',
								]
									.filter( Boolean )
									.join( ' ' ),
								role: 'tab',
								'aria-selected':
									tab === item.id ? 'true' : 'false',
								onClick: () => setTab( item.id ),
							},
							item.label,
							h(
								'span',
								{ class: 'ts-dash__dot' },
								item.id === 'past'
									? past.length
									: upcoming.length
							)
						)
					)
				),
				rows.length === 0
					? h( EmptyState, {
							title:
								tab === 'past'
									? 'No past appointments'
									: 'No upcoming appointments',
							description:
								tab === 'past'
									? 'Completed and cancelled visits will show up here.'
									: 'Book an appointment and it will appear here.',
							actionLabel: 'Book an appointment',
							onAction: () =>
								navigateTo( boot.bookingUrl, {
									sameOrigin: true,
								} ),
						} )
					: rows.map( ( row ) =>
							h(
								'article',
								{
									key: row.id,
									class: 'ts-dash__job ts-dash__job--customer',
								},
								h(
									'div',
									{ class: 'ts-dash__what' },
									h(
										'b',
										null,
										`${ row.service } with ${ row.technician }`
									),
									h(
										'small',
										{ class: 'ts-mono' },
										[ row.when, row.series_label ]
											.filter( Boolean )
											.join( ' · ' )
									),
									formatAddress( row )
										? h(
												'small',
												{ class: 'ts-dash__meta' },
												formatAddress( row )
											)
										: null,
									row.photos && row.photos.length
										? photoStrip( row.photos )
										: null
								),
								h(
									'div',
									{ class: 'ts-dash__job-actions' },
									h(
										StatusChip,
										{ tone: statusTone( row.status ) },
										row.status
									),
									isConfirmedUpcoming( row )
										? h(
												'div',
												{ class: 'ts-dash__actions' },
												h(
													Button,
													{
														variant: 'secondary',
														size: 'sm',
														onClick: () =>
															openReschedule(
																row
															),
													},
													'Reschedule'
												),
												h(
													Button,
													{
														variant: 'ghost',
														size: 'sm',
														disabled:
															busyId === row.id,
														onClick: () =>
															setCancelTarget(
																row
															),
													},
													'Cancel'
												)
											)
										: null
								)
							)
						)
			),
			h(
				'section',
				{ class: 'ts-dash__panel' },
				h( 'h2', null, 'Service plan balance' ),
				credits.length === 0
					? h( EmptyState, {
							title: 'No service plan',
							description:
								'Ask about a prepaid job package to save on future visits.',
						} )
					: credits.map( ( credit ) =>
							h(
								'div',
								{ key: credit.id, class: 'ts-dash__credit' },
								h( CreditMeter, {
									used: credit.used,
									total: credit.total,
									label: 'Job package',
								} ),
								credit.expires_at
									? h(
											'p',
											{ class: 'ts-dash__meta' },
											`Expires ${ formatDate( credit.expires_at ) }`
										)
									: null
							)
						),
				ledger.length
					? h(
							'div',
							{ class: 'ts-dash__ledger-wrap' },
							h( 'h3', null, 'Recent activity' ),
							h(
								'ul',
								{ class: 'ts-dash__ledger' },
								ledger.map( ( entry ) =>
									h(
										'li',
										{ key: entry.id },
										h( 'span', null, ledgerLabel( entry ) ),
										h(
											'span',
											{ class: 'ts-mono' },
											formatDate( entry.created_at )
										)
									)
								)
							)
						)
					: null
			)
		),
		h(
			Modal,
			{
				open: Boolean( reschedule ),
				title: 'Move your appointment',
				onClose: closeReschedule,
				primaryLabel: savingMove ? 'Saving…' : 'Save new time',
				primaryDisabled:
					savingMove || ! rescheduleSlot || slotStatus !== 'ready',
				onPrimary: onReschedule,
			},
			reschedule
				? h(
						'div',
						{ class: 'ts-dash__reschedule' },
						h(
							'p',
							{ class: 'ts-dash__meta' },
							`Currently ${ reschedule.when }. Pick an open time below.`
						),
						slotError
							? h( Callout, { tone: 'warn' }, slotError )
							: null,
						slotStatus === 'loading'
							? h( 'p', null, 'Loading open times…' )
							: null,
						slotStatus === 'ready'
							? renderOpenTimes( {
									daysWithAvailability,
									day,
									slotsForDay,
									tz,
									rescheduleSlot,
									onDayChange: ( id ) => {
										setDay( id );
										setRescheduleSlot( '' );
									},
									onSlotChange: setRescheduleSlot,
								} )
							: null
					)
				: null
		),
		h(
			Modal,
			{
				open: Boolean( cancelTarget ),
				title: 'Cancel this appointment?',
				onClose: () => setCancelTarget( null ),
				primaryLabel:
					busyId === cancelTarget?.id
						? 'Cancelling…'
						: 'Yes, cancel it',
				primaryDisabled: busyId === cancelTarget?.id,
				onPrimary: onCancel,
				secondaryLabel: 'Keep it',
			},
			cancelTarget
				? h(
						'p',
						{ class: 'ts-dash__meta' },
						`${ cancelTarget.service } with ${ cancelTarget.technician }, ${ cancelTarget.when }. This can't be undone.`
					)
				: null
		)
	);
}

function statusTone( status ) {
	if ( status === 'confirmed' || status === 'completed' ) {
		return 'ok';
	}
	if (
		status === 'cancelled' ||
		status === 'no_show' ||
		status === 'refunded' ||
		status === 'moved' ||
		status === 'payment_expired'
	) {
		return 'off';
	}
	return 'wait';
}

function renderOpenTimes( {
	daysWithAvailability,
	day,
	slotsForDay,
	tz,
	rescheduleSlot,
	onDayChange,
	onSlotChange,
} ) {
	if ( daysWithAvailability.every( ( item ) => item.disabled ) ) {
		return h(
			'p',
			{ class: 'ts-dash__meta' },
			'No open times in the next week. Contact the technician to reschedule.'
		);
	}

	return h(
		'div',
		{ class: 'ts-dash-stack' },
		h( DayStrip, {
			days: daysWithAvailability,
			value: day,
			onChange: onDayChange,
		} ),
		slotsForDay.length
			? h(
					'div',
					{ class: 'ts-slots' },
					slotsForDay.map( ( slot ) =>
						h( SlotButton, {
							key: slot.start,
							label: slotTimeLabel( slot.start, tz ),
							tone: 'open',
							selected: rescheduleSlot === slot.start,
							onClick: () => onSlotChange( slot.start ),
						} )
					)
				)
			: h(
					'p',
					{ class: 'ts-dash__meta' },
					'No open times this day. Try another day.'
				)
	);
}

function isConfirmedUpcoming( row ) {
	if ( ! row || row.status !== 'confirmed' ) {
		return false;
	}
	const start = Date.parse( row.start_utc );
	return ! Number.isNaN( start ) && start > Date.now();
}

function formatAddress( row ) {
	const street = [ row.address_line1, row.address_line2 ]
		.map( ( part ) => ( part || '' ).trim() )
		.filter( Boolean )
		.join( ', ' );
	const cityLine = [ row.address_city, row.address_state, row.address_zip ]
		.map( ( part ) => ( part || '' ).trim() )
		.filter( Boolean )
		.join( ' ' );
	return [ street, cityLine ].filter( Boolean ).join( ' · ' );
}

function photoStrip( photos ) {
	return h(
		'div',
		{ class: 'ts-dash__photos' },
		photos.slice( 0, 4 ).map( ( photo ) =>
			h( 'img', {
				key: photo.id,
				class: 'ts-dash__photo-thumb',
				src: photo.thumb_url || photo.url,
				alt: '',
			} )
		)
	);
}

function ledgerLabel( entry ) {
	const action = String( entry.action || '' ).replace( /^credit\./, '' );
	const labels = {
		purchased: 'Package purchased',
		used: 'Job credit used',
		refunded: 'Job credit refunded',
		expired: 'Package expired',
	};
	return labels[ action ] || action || 'Activity';
}

function formatDate( value ) {
	if ( ! value ) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat( undefined, {
			day: 'numeric',
			month: 'short',
			year: 'numeric',
		} ).format( new Date( value ) );
	} catch {
		return String( value );
	}
}

function detectTimezone() {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
	} catch {
		return 'UTC';
	}
}

function dayKeyInZone( iso, timeZone ) {
	try {
		return new Intl.DateTimeFormat( 'en-CA', {
			timeZone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		} ).format( new Date( iso ) );
	} catch {
		return String( iso ).slice( 0, 10 );
	}
}

function buildDayStrip( fromDate, days, timeZone ) {
	const start = new Date( fromDate );
	const items = [];
	for ( let i = 0; i < days; i++ ) {
		const current = new Date( start.getTime() + i * 86400000 );
		items.push( {
			id: dayKeyInZone( current.toISOString(), timeZone ),
			dow: new Intl.DateTimeFormat( undefined, {
				timeZone,
				weekday: 'short',
			} ).format( current ),
			date: new Intl.DateTimeFormat( undefined, {
				timeZone,
				day: 'numeric',
			} ).format( current ),
			disabled: false,
		} );
	}
	return items;
}

function slotTimeLabel( iso, timeZone ) {
	try {
		return new Intl.DateTimeFormat( undefined, {
			timeZone,
			hour: 'numeric',
			minute: '2-digit',
		} ).format( new Date( iso ) );
	} catch {
		return iso;
	}
}
