import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import {
	Button,
	DayStrip,
	EmptyState,
	Modal,
	PersonCell,
	SlotButton,
	StatusChip,
	announce,
	copyText,
	openUrl,
} from '../../shared';
import { buildDayStrip, dayKeyInZone, detectTimezone } from '../../widget/lib';
import { api, del, get, post } from '../api/client';
import { getConfig } from '../api/config';
import { PageHeader, ScreenState } from '../components/PageHeader';

const TABS = [
	{ id: 'upcoming', label: 'Upcoming' },
	{ id: 'past', label: 'Past' },
	{ id: 'cancelled', label: 'Cancelled' },
	{ id: 'needs', label: 'Needs action' },
];

export function BookingsScreen() {
	const [ tab, setTab ] = useState( 'upcoming' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ detail, setDetail ] = useState( null );
	const [ reschedule, setReschedule ] = useState( null );
	const [ rescheduleSlot, setRescheduleSlot ] = useState( '' );
	const [ slotStatus, setSlotStatus ] = useState( 'idle' );
	const [ slotError, setSlotError ] = useState( '' );
	const [ slots, setSlots ] = useState( [] );
	const [ savingMove, setSavingMove ] = useState( false );

	const tz = useMemo(
		() =>
			reschedule?.technician_timezone ||
			getConfig().timezone ||
			detectTimezone(),
		[ reschedule ]
	);

	const dayStrip = useMemo(
		() => buildDayStrip( new Date(), 7, tz ),
		[ tz ]
	);
	const [ day, setDay ] = useState( '' );

	useEffect( () => {
		if ( dayStrip.length && ! day ) {
			setDay( dayStrip[ 0 ].id );
		}
	}, [ dayStrip, day ] );

	const load = async () => {
		setStatus( 'loading' );
		try {
			const data = await get( 'bookings', {
				scope: 'teaching',
				tab,
				search,
				page,
				per_page: 20,
			} );
			setRows( data.bookings || [] );
			setTotal( data.total || 0 );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load bookings.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tab, page ] );

	const onSearch = ( event ) => {
		event.preventDefault();
		setPage( 1 );
		load();
	};

	const onExport = async () => {
		const response = await api( 'bookings/export?scope=teaching', {
			raw: true,
		} );
		const text = await response.text();
		const blob = new Blob( [ text ], { type: 'text/csv' } );
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = 'plumberslot-bookings.csv';
		a.click();
		URL.revokeObjectURL( url );
		announce( 'CSV exported.' );
	};

	const onCancel = async ( id ) => {
		await del( `bookings/${ id }` );
		announce( 'Booking cancelled.' );
		setDetail( null );
		load();
	};

	const openReschedule = ( row ) => {
		setDetail( null );
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
				const data = await get( 'slots', {
					technician_id: reschedule.technician_id,
					from: from.toISOString(),
					to: to.toISOString(),
					duration: reschedule.duration_min || 60,
					timezone: tz,
					exclude_booking: reschedule.id,
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

	const slotsForDay = useMemo( () => {
		if ( ! day ) {
			return [];
		}
		return slots.filter(
			( slot ) =>
				slot.state === 'open' && dayKeyInZone( slot.start, tz ) === day
		);
	}, [ slots, day, tz ] );

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

	const onReschedule = async () => {
		if ( ! reschedule || ! rescheduleSlot ) {
			announce( 'Pick an open slot first.' );
			return;
		}
		setSavingMove( true );
		try {
			await post( `bookings/${ reschedule.id }/reschedule`, {
				start: rescheduleSlot,
			} );
			announce( 'Booking rescheduled.' );
			closeReschedule();
			load();
		} catch ( err ) {
			announce( err.message || 'Could not reschedule.' );
		} finally {
			setSavingMove( false );
		}
	};

	const copyLink = async () => {
		try {
			const url = await resolveBookingUrl();
			if ( ! url ) {
				announce(
					'No booking page yet. Finish setup or open Dashboard for your link.'
				);
				return;
			}
			await copyText( url );
			announce( 'Booking link copied.' );
		} catch {
			announce(
				'Could not copy. Open Dashboard and copy the link manually.'
			);
		}
	};

	const openBookingPage = async () => {
		try {
			const url = await resolveBookingUrl();
			if ( ! url ) {
				announce(
					'No booking page yet. Finish setup or open Dashboard for your link.'
				);
				return;
			}
			openUrl( url, { sameOrigin: true } );
		} catch {
			announce( 'Could not open the booking page.' );
		}
	};

	const empty = status === 'ready' && rows.length === 0;

	return h(
		'div',
		{ class: 'ts-admin-screen', 'data-screen': 'bookings' },
		h( PageHeader, {
			eyebrow: 'Schedule',
			title: 'Bookings',
			subtitle: 'Everything scheduled, past and upcoming.',
			actions: [
				{ id: 'export', label: 'Export CSV', onClick: onExport },
			],
		} ),
		h(
			ScreenState,
			{ status, error },
			empty
				? h( EmptyState, {
						title: 'No bookings yet',
						description:
							'Your schedule is saved. Share your booking link to get your first job.',
						actionLabel: 'Copy booking link',
						onAction: copyLink,
						secondaryLabel: 'Open booking page',
						onSecondary: openBookingPage,
				  } )
				: h(
						'div',
						{ class: 'ts-admin-card ts-admin-card--flush' },
						h(
							'div',
							{
								class: 'ts-admin-card__header ts-bookings__toolbar',
							},
							h(
								'div',
								{ class: 'ts-admin-tabs', role: 'tablist' },
								TABS.map( ( item ) =>
									h(
										'button',
										{
											type: 'button',
											key: item.id,
											role: 'tab',
											class:
												item.id === tab
													? 'is-active'
													: '',
											'aria-selected':
												item.id === tab
													? 'true'
													: 'false',
											onClick: () => {
												setTab( item.id );
												setPage( 1 );
											},
										},
										item.label
									)
								)
							),
							h(
								'form',
								{
									class: 'ts-admin-search',
									onSubmit: onSearch,
								},
								h( 'input', {
									type: 'search',
									placeholder: 'Search customer or service',
									value: search,
									onInput: ( event ) =>
										setSearch( event.target.value ),
									'aria-label': 'Search bookings',
								} ),
								h(
									Button,
									{
										type: 'submit',
										variant: 'secondary',
										size: 'sm',
									},
									'Filter'
								)
							)
						),
						h(
							'div',
							{ class: 'ts-admin-table-wrap' },
							h(
								'table',
								{ class: 'ts-admin-table' },
								h(
									'thead',
									null,
									h(
										'tr',
										null,
										h( 'th', null, 'Customer' ),
										h( 'th', null, 'Service' ),
										h( 'th', null, 'Address' ),
										h( 'th', null, 'When' ),
										h( 'th', null, 'Series' ),
										h( 'th', null, 'Payment' ),
										h( 'th', null, 'Status' ),
										h( 'th', null, '' )
									)
								),
								h(
									'tbody',
									null,
									rows.map( ( row ) =>
										h(
											'tr',
											{ key: row.id },
											h(
												'td',
												null,
												h( PersonCell, {
													name: row.customer,
													context: row.payer || '',
													initials: row.initials,
												} )
											),
											h( 'td', null, row.service ),
											h(
												'td',
												{ class: 'plumberslot-mono' },
												formatCityZip( row )
											),
											h(
												'td',
												{ class: 'plumberslot-mono' },
												formatWhen( row )
											),
											h(
												'td',
												null,
												row.series_label ||
													( row.series_id
														? `Weekly ${
																row.series_index ||
																1
														  }/${
																row.series_total ||
																'?'
														  }`
														: '—' )
											),
											h(
												'td',
												null,
												h(
													StatusChip,
													{
														tone: paymentTone(
															row.payment
														),
													},
													row.payment || '—'
												)
											),
											h(
												'td',
												null,
												h(
													StatusChip,
													{
														tone: statusTone(
															row.status
														),
													},
													row.status
												)
											),
											h(
												'td',
												null,
												h(
													'div',
													{ class: 'ts-ds__row' },
													row.meeting_ready &&
														row.join_url
														? h(
																Button,
																{
																	size: 'sm',
																	variant:
																		'secondary',
																	onClick:
																		() =>
																			openUrl(
																				row.join_url,
																				{
																					sameOrigin: true,
																				}
																			),
																},
																'Join'
														  )
														: null,
													h(
														Button,
														{
															size: 'sm',
															variant: 'ghost',
															onClick: () =>
																setDetail(
																	row
																),
															'aria-label':
																'Open booking',
														},
														'Open'
													)
												)
											)
										)
									)
								)
							)
						),
						h(
							'div',
							{ class: 'ts-admin-pager' },
							h(
								Button,
								{
									variant: 'ghost',
									disabled: page <= 1,
									onClick: () => setPage( ( p ) => p - 1 ),
								},
								'Previous'
							),
							h(
								'span',
								null,
								`Page ${ page } · ${ total } total`
							),
							h(
								Button,
								{
									variant: 'ghost',
									disabled: page * 20 >= total,
									onClick: () => setPage( ( p ) => p + 1 ),
								},
								'Next'
							)
						),
						h(
							'p',
							{
								class: 'ts-admin__muted',
								style: { padding: '0 20px 16px' },
							},
							'Series appointments stay linked. Moving one week leaves the others alone.'
						)
				  )
		),
		h(
			Modal,
			{
				open: Boolean( detail ),
				title: detail ? `Booking #${ detail.id }` : 'Booking',
				onClose: () => setDetail( null ),
				primaryLabel: canReschedule( detail )
					? 'Reschedule'
					: undefined,
				onPrimary: canReschedule( detail )
					? () => openReschedule( detail )
					: undefined,
				secondaryLabel: 'Close',
			},
			detail
				? h(
						'div',
						{ class: 'ts-admin-stack' },
						h(
							'p',
							null,
							h( 'b', null, detail.customer ),
							` · ${ detail.service }`
						),
						h(
							'p',
							{ class: 'ts-bookings__when' },
							detail.when || formatWhen( detail )
						),
						h( 'p', null, `Address: ${ formatAddress( detail ) }` ),
						h( 'p', null, `Payment: ${ detail.payment }` ),
						h( 'p', null, `Status: ${ detail.status }` ),
						h(
							'div',
							{ class: 'ts-ds__row' },
							detail.meeting_ready && detail.join_url
								? h(
										Button,
										{
											onClick: () =>
												openUrl( detail.join_url, {
													sameOrigin: true,
												} ),
										},
										'Join appointment'
								  )
								: null,
							h(
								Button,
								{
									variant: 'ghost',
									onClick: () => onCancel( detail.id ),
								},
								'Cancel booking'
							),
							detail.payment === 'paid' || detail.payment_ref
								? h(
										Button,
										{
											variant: 'ghost',
											onClick: async () => {
												try {
													await post(
														`payments/booking/${ detail.id }/refund`
													);
													announce( 'Refunded.' );
													setDetail( null );
													load();
												} catch ( err ) {
													announce(
														err.message ||
															'Refund failed.'
													);
												}
											},
										},
										'Refund payment'
								  )
								: null
						)
				  )
				: null
		),
		h(
			Modal,
			{
				open: Boolean( reschedule ),
				title: reschedule
					? `Move ${ reschedule.customer }’s appointment`
					: 'Reschedule',
				onClose: closeReschedule,
				primaryLabel: savingMove ? 'Saving…' : 'Save new time',
				primaryDisabled:
					savingMove || ! rescheduleSlot || slotStatus !== 'ready',
				onPrimary: onReschedule,
			},
			reschedule
				? h(
						'div',
						{ class: 'ts-bookings__reschedule' },
						h(
							'p',
							{ class: 'ts-admin__muted' },
							`Currently ${
								reschedule.when || formatWhen( reschedule )
							}. Pick an open slot below.`
						),
						slotStatus === 'loading'
							? h( 'p', null, 'Loading open times…' )
							: null,
						slotStatus === 'error'
							? h(
									'p',
									{
										class: 'ts-admin__state ts-admin__state--error',
									},
									slotError
							  )
							: null,
						slotStatus === 'ready'
							? h(
									'div',
									{ class: 'ts-admin-stack' },
									daysWithAvailability.every(
										( item ) => item.disabled
									)
										? h(
												'p',
												{ class: 'ts-admin__muted' },
												'No open slots in the next week. Add availability first, then try again.'
										  )
										: h(
												'div',
												{
													class: 'ts-admin-stack',
												},
												h( DayStrip, {
													days: daysWithAvailability,
													value: day,
													onChange: ( id ) => {
														setDay( id );
														setRescheduleSlot( '' );
													},
												} ),
												slotsForDay.length
													? h(
															'div',
															{
																class: 'ts-slots',
															},
															slotsForDay.map(
																( slot ) =>
																	h(
																		SlotButton,
																		{
																			key: slot.start,
																			label: slotTimeLabel(
																				slot.start,
																				tz
																			),
																			tone: 'open',
																			selected:
																				rescheduleSlot ===
																				slot.start,
																			onClick:
																				() =>
																					setRescheduleSlot(
																						slot.start
																					),
																		}
																	)
															)
													  )
													: h(
															'p',
															{
																class: 'ts-admin__muted',
															},
															'No open slots this day. Try another day.'
													  )
										  )
							  )
							: null
				  )
				: null
		)
	);
}

function formatWhen( row ) {
	if ( row.when ) {
		return row.when;
	}
	try {
		return new Date( row.start_utc ).toLocaleString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
		} );
	} catch {
		return row.start_utc;
	}
}

function formatCityZip( row ) {
	const city = ( row.address_city || '' ).trim();
	const zip = ( row.address_zip || '' ).trim();
	return [ city, zip ].filter( Boolean ).join( ' ' ) || '—';
}

function formatAddress( row ) {
	const streetLine = [ row.address_line1, row.address_line2 ]
		.map( ( part ) => ( part || '' ).trim() )
		.filter( Boolean )
		.join( ', ' );
	const stateZip = [ row.address_state, row.address_zip ]
		.map( ( part ) => ( part || '' ).trim() )
		.filter( Boolean )
		.join( ' ' );
	const cityLine = [ ( row.address_city || '' ).trim(), stateZip ]
		.filter( Boolean )
		.join( ', ' );
	return [ streetLine, cityLine ].filter( Boolean ).join( ', ' ) || '—';
}

function canReschedule( row ) {
	if ( ! row ) {
		return false;
	}
	const blocked = [
		'cancelled',
		'refunded',
		'completed',
		'moved',
		'no_show',
	];
	if ( blocked.includes( row.status ) ) {
		return false;
	}
	const start = Date.parse( row.start_utc );
	return ! Number.isNaN( start ) && start > Date.now();
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

async function resolveBookingUrl() {
	const config = getConfig();
	try {
		const dash = await get( 'dashboard', {
			technician_id: config.technicianId || undefined,
		} );
		if ( dash?.booking_url ) {
			return dash.booking_url;
		}
	} catch {
		/* fall through to setup */
	}

	try {
		const setup = await get( 'setup' );
		if ( setup?.booking_url ) {
			return setup.booking_url;
		}
	} catch {
		/* no url */
	}

	return '';
}

function statusTone( status ) {
	if ( status === 'confirmed' || status === 'completed' ) {
		return 'ok';
	}
	if ( status === 'pending' ) {
		return 'wait';
	}
	return 'off';
}

function paymentTone( payment ) {
	const p = String( payment || '' ).toLowerCase();
	if ( p.includes( 'paid' ) || p.includes( 'credit' ) ) {
		return 'ok';
	}
	if ( p.includes( 'wait' ) || p.includes( 'pending' ) ) {
		return 'wait';
	}
	return 'idle';
}
