import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import {
	Button,
	StatTile,
	StatusChip,
	TodayStrip,
	announce,
	copyText,
	navigateTo,
	openUrl,
} from '../../shared';
import { get } from '../api/client';
import { getConfig } from '../api/config';
import { ScreenState } from '../components/PageHeader';

function formatMoney( minor, currency ) {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: currency || 'USD',
			maximumFractionDigits: 0,
		} ).format( ( Number( minor ) || 0 ) / 100 );
	} catch {
		return `${ ( Number( minor ) || 0 ) / 100 } ${ currency || '' }`;
	}
}

function dashboardSummary( data ) {
	const sessions = data?.today?.length || 0;
	const next = data?.next_up?.[ 0 ];
	const count = `${ sessions } session${ sessions === 1 ? '' : 's' } today.`;

	return next
		? `${ count } Next up at ${ next.when }.`
		: `${ count } Your schedule is clear.`;
}

function bookingActionLabel( row ) {
	if ( row.meeting_ready && row.join_url ) {
		return 'Join';
	}

	return 'View';
}

function onBookingAction( row, openAdmin ) {
	if ( row.meeting_ready && row.join_url ) {
		openUrl( row.join_url, { sameOrigin: true } );
		return;
	}
	openAdmin( 'plumberslot-bookings' );
}

function formatSnapshotHint( windowDays, count ) {
	if ( count === undefined ) {
		return `Last ${ windowDays } days`;
	}
	return `${ count } booking${ count === 1 ? '' : 's' } · last ${ windowDays } days`;
}

export function DashboardScreen() {
	const config = getConfig();
	const initialData = config.initialDashboard || null;
	const [ status, setStatus ] = useState( initialData ? 'ready' : 'loading' );
	const [ error, setError ] = useState( '' );
	const [ data, setData ] = useState( initialData );
	const [ snapshot, setSnapshot ] = useState( null );

	useEffect( () => {
		if ( initialData ) {
			return undefined;
		}

		let alive = true;

		( async () => {
			try {
				const payload = await get( 'dashboard', {
					technician_id: config.technicianId || undefined,
				} );

				if ( alive ) {
					setData( payload );
					setStatus( 'ready' );
				}
			} catch ( err ) {
				if ( alive ) {
					setError( err.message || 'Could not load dashboard.' );
					setStatus( 'error' );
				}
			}
		} )();

		return () => {
			alive = false;
		};
	}, [ config.technicianId, initialData ] );

	// A site manager's business-wide rollup. The endpoint itself denies a
	// non-manager (404, the same indistinct rejection every gated route in
	// this plugin uses), so a failed or empty response just means nothing
	// renders here -- no separate capability check needed on this side.
	useEffect( () => {
		let alive = true;

		( async () => {
			try {
				const payload = await get( 'dashboard/snapshot' );
				if ( alive ) {
					setSnapshot( payload );
				}
			} catch {
				// Not a manager, or the snapshot route isn't reachable -- fine, render nothing.
			}
		} )();

		return () => {
			alive = false;
		};
	}, [] );

	const adminUrl = ( page ) => `${ config.urls.admin }?page=${ page }`;
	const openAdmin = ( page ) => {
		navigateTo( adminUrl( page ), { sameOrigin: true } );
	};
	const openBookingPage = () => {
		if ( data?.booking_url ) {
			openUrl( data.booking_url, { sameOrigin: true } );
		}
	};
	const onCopy = async () => {
		if ( ! data?.booking_url ) {
			return;
		}

		try {
			await copyText( data.booking_url );
			announce( 'Booking link copied.' );
		} catch {
			announce( 'Could not copy. Select the link and copy manually.' );
		}
	};
	const attention = useMemo(
		() => ( data?.needs_attention || [] ).slice( 0, 4 ),
		[ data ]
	);

	return h(
		'div',
		{ class: 'ts-admin-screen ts-dashboard', 'data-screen': 'dashboard' },
		h(
			'header',
			{ class: 'ts-dashboard__header' },
			h(
				'div',
				{ class: 'ts-dashboard__intro' },
				h(
					'p',
					{ class: 'ts-admin__eyebrow' },
					data?.date || 'PlumberSlot'
				),
				h( 'h1', null, data?.greeting || 'Dashboard' ),
				h(
					'p',
					{ class: 'ts-admin__sub' },
					data ? dashboardSummary( data ) : config.timezone
				)
			),
			h(
				'div',
				{ class: 'ts-admin__actions' },
				h(
					Button,
					{
						variant: 'secondary',
						onClick: () => openAdmin( 'plumberslot-availability' ),
					},
					'Block time off'
				),
				h(
					Button,
					{ onClick: openBookingPage, disabled: ! data?.booking_url },
					'Preview booking page'
				)
			)
		),
		snapshot
			? h(
					'div',
					{
						class: 'ts-tiles ts-dashboard__snapshot',
						'aria-label': 'Business snapshot',
					},
					h( StatTile, {
						label: 'Busiest technician',
						value: snapshot.busiest_technician?.name || '—',
						hint: formatSnapshotHint(
							snapshot.window_days,
							snapshot.busiest_technician?.count
						),
					} ),
					h( StatTile, {
						label: 'Top service',
						value: snapshot.top_service?.name || '—',
						hint: formatSnapshotHint(
							snapshot.window_days,
							snapshot.top_service?.count
						),
					} ),
					h( StatTile, {
						label: 'No-show rate',
						value:
							snapshot.no_show_rate === null ||
							snapshot.no_show_rate === undefined
								? '—'
								: `${ snapshot.no_show_rate }%`,
						hint: formatSnapshotHint( snapshot.window_days ),
					} )
				)
			: null,
		h(
			ScreenState,
			{ status, error },
			data
				? h(
						'div',
						{ class: 'ts-admin-stack' },
						h(
							'section',
							{ class: 'ts-admin-card ts-dashboard__today' },
							h(
								'div',
								{ class: 'ts-admin-card__header' },
								h( 'h2', null, 'Today' ),
								h(
									'span',
									{ class: 'ts-admin__eyebrow' },
									data.timezone_label || data.timezone
								)
							),
							h( TodayStrip, {
								items: data.today || [],
								nowPct: data.now_pct || 0,
								nowLabel: 'Now',
								className: 'ts-strip--embedded',
							} )
						),
						h(
							'div',
							{ class: 'ts-tiles' },
							h( StatTile, {
								label: 'This week',
								value: String(
									data.tiles?.weekly_sessions ?? 0
								),
								hint: 'sessions booked',
							} ),
							h( StatTile, {
								label: 'Earnings',
								value: formatMoney(
									data.tiles?.earnings_minor,
									data.tiles?.currency
								),
								hint: 'confirmed this week',
							} ),
							h( StatTile, {
								label: 'Fill rate',
								value: `${ data.tiles?.fill_rate ?? 0 }%`,
								hint: 'of the hours you opened',
							} ),
							h( StatTile, {
								label: 'Credits held',
								value: String( data.tiles?.credits_held ?? 0 ),
								hint: 'appointments prepaid',
							} )
						),
						h(
							'div',
							{ class: 'ts-dashboard__columns' },
							h(
								'section',
								{ class: 'ts-admin-card ts-dashboard__next' },
								h(
									'div',
									{ class: 'ts-admin-card__header' },
									h( 'h2', null, 'Next up' ),
									h(
										Button,
										{
											variant: 'ghost',
											size: 'sm',
											onClick: () =>
												openAdmin(
													'plumberslot-bookings'
												),
										},
										'See all bookings →'
									)
								),
								data.next_up?.length
									? h(
											'div',
											{ class: 'ts-admin-table-wrap' },
											h(
												'table',
												{
													class: 'ts-admin-table ts-dashboard__table',
												},
												h(
													'thead',
													null,
													h(
														'tr',
														null,
														h(
															'th',
															null,
															'Customer'
														),
														h(
															'th',
															null,
															'Service'
														),
														h( 'th', null, 'When' ),
														h(
															'th',
															null,
															'Payment'
														),
														h( 'th', {
															'aria-label':
																'Actions',
														} )
													)
												),
												h(
													'tbody',
													null,
													data.next_up.map( ( row ) =>
														h(
															'tr',
															{ key: row.id },
															h(
																'td',
																null,
																h(
																	'div',
																	{
																		class: 'ts-dashboard__person',
																	},
																	h(
																		'span',
																		{
																			class: 'ts-dashboard__avatar',
																		},
																		row.initials
																	),
																	h(
																		'span',
																		null,
																		h(
																			'b',
																			{
																				class: 'ts-dashboard__person-name',
																			},
																			row.customer
																		),
																		row.has_photos
																			? h(
																					'span',
																					{
																						class: 'ts-dashboard__photo-flag',
																						title: 'Photos attached',
																						'aria-label':
																							'Photos attached',
																					},
																					'📷'
																				)
																			: null,
																		row.is_emergency
																			? h(
																					'span',
																					{
																						class: 'ts-dashboard__emergency-flag',
																						title: 'Emergency booking',
																						'aria-label':
																							'Emergency booking',
																					},
																					'⚡'
																				)
																			: null,
																		row.address
																			? h(
																					'small',
																					{
																						class: 'ts-dashboard__person-address',
																					},
																					row.address
																				)
																			: null
																	)
																)
															),
															h(
																'td',
																null,
																row.service
															),
															h(
																'td',
																{
																	class: 'plumberslot-mono',
																},
																row.when
															),
															h(
																'td',
																null,
																h(
																	StatusChip,
																	{
																		tone: row.payment_tone,
																	},
																	row.payment
																)
															),
															h(
																'td',
																{
																	class: 'ts-dashboard__row-action',
																},
																h(
																	Button,
																	{
																		variant:
																			'secondary',
																		size: 'sm',
																		onClick:
																			() =>
																				onBookingAction(
																					row,
																					openAdmin
																				),
																	},
																	bookingActionLabel(
																		row
																	)
																)
															)
														)
													)
												)
											)
										)
									: h(
											'p',
											{ class: 'ts-dashboard__empty' },
											'No upcoming appointments.'
										)
							),
							h(
								'div',
								{ class: 'ts-admin-stack ts-dashboard__side' },
								h(
									'section',
									{
										class: 'ts-admin-card ts-dashboard__side-card',
									},
									h(
										'h2',
										{ class: 'ts-dashboard__side-title' },
										'Needs your attention'
									),
									attention.length
										? h(
												'ul',
												{
													class: 'ts-dashboard__attention',
												},
												attention.map( ( item ) =>
													h(
														'li',
														{ key: item.id },
														h(
															'button',
															{
																type: 'button',
																class: 'ts-dashboard__attention-btn',
																onClick: () =>
																	openAdmin(
																		'plumberslot-bookings'
																	),
															},
															h(
																'span',
																null,
																item.label
															),
															h(
																'b',
																null,
																item.value ||
																	'→'
															)
														)
													)
												)
											)
										: h(
												'p',
												{ class: 'ts-admin__muted' },
												'Nothing waiting on you.'
											)
								),
								h(
									'section',
									{
										class: 'ts-admin-card ts-dashboard__side-card',
									},
									h(
										'h2',
										{ class: 'ts-dashboard__side-title' },
										'Your booking page'
									),
									h(
										'p',
										{ class: 'ts-admin__muted' },
										'Anyone with this link can see your open hours and book.'
									),
									h(
										'div',
										{
											class: 'ts-dashboard__booking-url plumberslot-mono',
										},
										data.booking_url
									),
									h(
										'div',
										{
											class: 'ts-admin__actions ts-dashboard__booking-actions',
										},
										h(
											Button,
											{
												variant: 'secondary',
												size: 'sm',
												onClick: onCopy,
											},
											'Copy link'
										),
										h(
											Button,
											{
												variant: 'ghost',
												size: 'sm',
												onClick: openBookingPage,
											},
											'Preview'
										)
									)
								)
							)
						)
					)
				: null
		)
	);
}
