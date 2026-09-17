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
	openUrl,
} from '../shared';
import { ApiError, del, get, getBoot, post } from './api';

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

	// Leave-a-review form state, keyed by booking id. Kept local rather than
	// refetching the whole dashboard on submit, so the confirmation message
	// ("awaiting approval") can be shown in place of the form without a
	// round trip -- has_review itself only updates on the next full load().
	const [ reviewDrafts, setReviewDrafts ] = useState( {} );
	const [ reviewSubmitting, setReviewSubmitting ] = useState( 0 );
	const [ reviewJustSubmitted, setReviewJustSubmitted ] = useState( {} );

	// Buy a Service Plan.
	const [ catalog, setCatalog ] = useState( null );
	const [ catalogStatus, setCatalogStatus ] = useState( 'loading' );
	const [ buying, setBuying ] = useState( false );
	const [ planNotice, setPlanNotice ] = useState( null );

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

	const loadCatalog = async () => {
		setCatalogStatus( 'loading' );
		try {
			const data = await get( 'credits/packages' );
			setCatalog( data );
			setCatalogStatus( 'ready' );
		} catch {
			// A public, read-only catalog lookup failing is not worth surfacing
			// as a dashboard-wide error -- the Buy a Service Plan card just
			// stays hidden.
			setCatalogStatus( 'error' );
		}
	};

	useEffect( () => {
		if ( ! boot.loggedIn ) {
			return;
		}
		loadCatalog();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Returning from a Service Plan checkout: ?plumberslot_credit=success|cancel
	// on this same dashboard URL, mirroring how the booking widget's
	// PaymentReturnView reads ?plumberslot_pay=... . The true balance only
	// updates once the gateway's webhook actually lands, which can trail the
	// redirect by a beat, so a success is followed by one delayed re-check
	// rather than an indefinite poll -- a plain refresh-on-return would also
	// be an acceptable MVP, this just closes the common race without adding
	// real complexity.
	useEffect( () => {
		if ( typeof window === 'undefined' || ! boot.loggedIn ) {
			return;
		}

		const params = new URLSearchParams( window.location.search );
		const flag = params.get( 'plumberslot_credit' );
		if ( ! flag ) {
			return;
		}

		const cleared = new URL( window.location.href );
		cleared.searchParams.delete( 'plumberslot_credit' );
		window.history.replaceState( {}, '', cleared.toString() );

		if ( flag === 'success' ) {
			setPlanNotice( {
				tone: 'ok',
				message:
					"Payment received. It can take a moment to reflect below — we'll refresh automatically.",
			} );
			window.setTimeout( load, 2500 );
		} else {
			setPlanNotice( {
				tone: 'warn',
				message: 'Checkout was cancelled. No charge was made.',
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const buyPlan = async () => {
		if ( ! catalog || buying ) {
			return;
		}
		setBuying( true );
		setPlanNotice( null );
		try {
			const requiresPayment = ( catalog.price_minor || 0 ) > 0;
			const gateway = requiresPayment
				? pickGateway( boot.payments )
				: 'none';

			if ( requiresPayment && ! gateway ) {
				throw new ApiError(
					'Online payment is not available right now.'
				);
			}

			const result = await post( 'credits/checkout', {
				total: catalog.default_total,
				gateway,
				success_url: creditReturnUrl( 'success' ),
				cancel_url: creditReturnUrl( 'cancel' ),
			} );

			if ( result.requires_payment && result.url ) {
				announce( 'Redirecting to payment…' );
				if ( navigateTo( result.url ) ) {
					return;
				}
				throw new ApiError(
					'Payment provider returned an unsafe redirect URL.'
				);
			}

			announce( 'Service plan added.' );
			setPlanNotice( {
				tone: 'ok',
				message: 'Your Service Plan is ready to use.',
			} );
			await load();
		} catch ( err ) {
			const message = err.message || 'Could not start the purchase.';
			setPlanNotice( { tone: 'warn', message } );
			announce( message );
		} finally {
			setBuying( false );
		}
	};

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

	const setReviewRating = ( id, rating ) => {
		setReviewDrafts( ( prev ) => ( {
			...prev,
			[ id ]: { ...( prev[ id ] || { body: '' } ), rating },
		} ) );
	};

	const setReviewBody = ( id, body ) => {
		setReviewDrafts( ( prev ) => ( {
			...prev,
			[ id ]: { ...( prev[ id ] || { rating: 0 } ), body },
		} ) );
	};

	const submitReview = async ( row ) => {
		const draft = reviewDrafts[ row.id ] || { rating: 0, body: '' };
		if ( ! draft.rating ) {
			announce( 'Pick a star rating first.' );
			return;
		}
		setReviewSubmitting( row.id );
		try {
			await post( `bookings/${ row.id }/review`, {
				rating: draft.rating,
				body: draft.body || undefined,
			} );
			announce( 'Review submitted.' );
			setReviewJustSubmitted( ( prev ) => ( {
				...prev,
				[ row.id ]: true,
			} ) );
		} catch ( err ) {
			const message = err.message || 'Could not submit your review.';
			setError( message );
			announce( message );
		} finally {
			setReviewSubmitting( 0 );
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
									renderJobActions( row, {
										busyId,
										onReschedule: openReschedule,
										onCancel: setCancelTarget,
										reviewDrafts,
										reviewSubmitting,
										reviewJustSubmitted,
										onReviewRatingChange: setReviewRating,
										onReviewBodyChange: setReviewBody,
										onReviewSubmit: submitReview,
									} )
								)
							)
						)
			),
			h(
				'section',
				{ class: 'ts-dash__panel' },
				h( 'h2', null, 'Service plan balance' ),
				planNotice
					? h(
							Callout,
							{ tone: planNotice.tone },
							planNotice.message
						)
					: null,
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
					: null,
				renderBuyPlan( {
					catalog,
					catalogStatus,
					buying,
					payments: boot.payments,
					onBuy: buyPlan,
				} )
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

// A handful of clickable star characters, matching how ProfileView.js
// already renders a *displayed* rating as plain repeated '★' characters --
// this is the same idea made interactive, not a new star-rating library.
function StarPicker( { value, onChange } ) {
	return h(
		'div',
		{
			class: 'ts-dash__star-picker',
			role: 'radiogroup',
			'aria-label': 'Rating',
		},
		[ 1, 2, 3, 4, 5 ].map( ( n ) =>
			h(
				'button',
				{
					type: 'button',
					key: n,
					class: 'ts-dash__star',
					'aria-pressed': value >= n ? 'true' : 'false',
					'aria-label': `${ n } star${ n === 1 ? '' : 's' }`,
					onClick: () => onChange( n ),
				},
				value >= n ? '★' : '☆'
			)
		)
	);
}

function renderJobActions(
	row,
	{
		busyId,
		onReschedule,
		onCancel,
		reviewDrafts,
		reviewSubmitting,
		reviewJustSubmitted,
		onReviewRatingChange,
		onReviewBodyChange,
		onReviewSubmit,
	}
) {
	if ( isConfirmedUpcoming( row ) ) {
		return h(
			'div',
			{ class: 'ts-dash__actions' },
			h(
				Button,
				{
					variant: 'secondary',
					size: 'sm',
					onClick: () => onReschedule( row ),
				},
				'Reschedule'
			),
			h(
				Button,
				{
					variant: 'ghost',
					size: 'sm',
					disabled: busyId === row.id,
					onClick: () => onCancel( row ),
				},
				'Cancel'
			)
		);
	}

	if ( row.status !== 'completed' ) {
		return null;
	}

	return h(
		'div',
		{ class: 'ts-dash__actions ts-dash__actions--completed' },
		h(
			Button,
			{
				variant: 'ghost',
				size: 'sm',
				onClick: () =>
					openUrl( `/plumberslot/receipt?booking=${ row.id }`, {
						sameOrigin: true,
					} ),
			},
			'Print receipt'
		),
		renderReviewBlock( row, {
			draft: reviewDrafts[ row.id ] || { rating: 0, body: '' },
			submitting: reviewSubmitting,
			justSubmitted: reviewJustSubmitted[ row.id ],
			onRatingChange: onReviewRatingChange,
			onBodyChange: onReviewBodyChange,
			onSubmit: onReviewSubmit,
		} )
	);
}

function renderReviewBlock(
	row,
	{ draft, submitting, justSubmitted, onRatingChange, onBodyChange, onSubmit }
) {
	if ( justSubmitted ) {
		return h(
			'p',
			{ class: 'ts-dash__meta' },
			'Thanks — your review is awaiting approval.'
		);
	}

	if ( row.has_review ) {
		return h( 'p', { class: 'ts-dash__meta' }, 'Review submitted.' );
	}

	return h(
		'div',
		{ class: 'ts-dash__review-form' },
		h( StarPicker, {
			value: draft.rating,
			onChange: ( n ) => onRatingChange( row.id, n ),
		} ),
		h( 'textarea', {
			class: 'ts-dash__review-body',
			placeholder: 'Optional comments',
			rows: 2,
			value: draft.body,
			onInput: ( e ) => onBodyChange( row.id, e.target.value ),
		} ),
		h(
			Button,
			{
				size: 'sm',
				disabled: ! draft.rating || submitting === row.id,
				onClick: () => onSubmit( row ),
			},
			submitting === row.id ? 'Submitting…' : 'Submit review'
		)
	);
}

function renderBuyPlan( { catalog, catalogStatus, buying, payments, onBuy } ) {
	if ( catalogStatus !== 'ready' || ! catalog ) {
		return null;
	}

	const requiresPayment = ( catalog.price_minor || 0 ) > 0;
	const gatewayReady =
		! requiresPayment || Boolean( pickGateway( payments ) );
	const buyLabel = buying ? 'Starting…' : buyButtonLabel( requiresPayment );

	return h(
		'div',
		{ class: 'ts-dash__buy-plan' },
		h( 'h3', null, 'Buy a Service Plan' ),
		h(
			'p',
			null,
			`${ catalog.default_total } appointments for ${ formatMoney( catalog.price_minor, catalog.currency ) }`
		),
		catalog.expiry_days
			? h(
					'p',
					{ class: 'ts-dash__meta' },
					`Valid ${ catalog.expiry_days } days from purchase`
				)
			: null,
		gatewayReady
			? h( Button, { onClick: onBuy, disabled: buying }, buyLabel )
			: h(
					'p',
					{ class: 'ts-dash__meta' },
					'Online payment is not available right now.'
				)
	);
}

function buyButtonLabel( requiresPayment ) {
	return requiresPayment ? 'Buy now' : 'Get it free';
}

function pickGateway( payments = {} ) {
	if ( payments?.stripe ) {
		return 'stripe';
	}
	if ( payments?.bkash ) {
		return 'bkash';
	}
	return null;
}

function creditReturnUrl( flag ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'plumberslot_credit', flag );
	return url.toString();
}

function formatMoney( minor, currency = 'USD' ) {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency,
			maximumFractionDigits: 0,
		} ).format( ( Number( minor ) || 0 ) / 100 );
	} catch {
		return `${ ( Number( minor ) || 0 ) / 100 } ${ currency }`;
	}
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
