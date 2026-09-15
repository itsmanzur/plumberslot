import { h } from 'preact';
import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	ErrorState,
	WizardRail,
	announce,
	navigateTo,
} from '../../shared';
import { ApiError, get, getBoot, post, remove } from '../api';
import { clearBookingDraft } from '../draft';
import { formatInZone, money } from '../lib';
import { RAIL } from './SubjectStep';

export function ConfirmStep( {
	tutor,
	subject,
	start,
	timezone,
	onBack,
	onBooked,
	onRetakeSlot,
} ) {
	const boot = getBoot();
	const [ hold, setHold ] = useState( null );
	const [ holdStatus, setHoldStatus ] = useState( 'idle' );
	const [ seconds, setSeconds ] = useState( 0 );
	const [ forChild, setForChild ] = useState( false );
	const [ children, setChildren ] = useState( [] );
	const [ childId, setChildId ] = useState( 0 );
	const [ childrenStatus, setChildrenStatus ] = useState( 'loading' );
	const [ notes, setNotes ] = useState( '' );
	const [ payMethod, setPayMethod ] = useState( () =>
		defaultPayMethod( boot.payments )
	);
	const [ seriesOn, setSeriesOn ] = useState( false );
	const [ seriesDays, setSeriesDays ] = useState( () => {
		const d = new Date( start );
		return Number.isNaN( d.getTime() ) ? [ 1 ] : [ d.getUTCDay() ];
	} );
	const [ seriesCount, setSeriesCount ] = useState( 8 );
	const [ skipped, setSkipped ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const holdTokenRef = useRef( '' );

	const price = subject?.is_trial ? 0 : subject?.price_minor || 0;
	const useCredit = payMethod === 'package';
	const accountName = boot.user?.name || '';
	const selectedChild = children.find(
		( child ) => child.student_id === childId
	);
	const studentName = selectedChild?.student_name || '';

	const payOptions = useMemo(
		() => buildPayOptions( boot.payments, tutor.display_name ),
		[ boot.payments, tutor.display_name ]
	);

	const toggleDay = ( value ) => {
		setSeriesDays( ( prev ) => {
			if ( prev.includes( value ) ) {
				return prev.length === 1
					? prev
					: prev.filter( ( d ) => d !== value );
			}
			return [ ...prev, value ].sort( ( a, b ) => a - b );
		} );
	};

	const acquireHold = async () => {
		setError( '' );
		setHoldStatus( 'loading' );
		try {
			const data = await post( 'bookings/hold', {
				tutor_id: tutor.id,
				start,
			} );
			holdTokenRef.current = data.token || '';
			setHold( data );
			setHoldStatus( 'ready' );
			setSeconds(
				Number( data.expires_in ) || tutor.hold_minutes * 60 || 600
			);
			announce( 'Slot held for you.' );
		} catch ( err ) {
			setHoldStatus( 'error' );
			setError( err.message || 'Could not hold this slot.' );
			if ( err instanceof ApiError && err.status === 409 ) {
				onRetakeSlot?.( err.message );
			}
		}
	};

	useEffect( () => {
		if ( ! boot.loggedIn ) {
			setChildrenStatus( 'ready' );
			return undefined;
		}

		let active = true;
		get( 'relations/children' )
			.then( ( data ) => {
				if ( ! active ) {
					return;
				}
				const items = data.items || [];
				setChildren( items );
				setChildId( items[ 0 ]?.student_id || 0 );
				setChildrenStatus( 'ready' );
			} )
			.catch( () => {
				if ( active ) {
					setChildrenStatus( 'error' );
				}
			} );

		return () => {
			active = false;
		};
	}, [ boot.loggedIn ] );

	useEffect( () => {
		holdTokenRef.current = hold?.token || '';
	}, [ hold?.token ] );

	useEffect(
		() => () => {
			const token = holdTokenRef.current;
			holdTokenRef.current = '';
			if ( token ) {
				releaseHoldToken( token );
			}
		},
		[]
	);

	useEffect( () => {
		if ( ! seriesOn || ! holdTokenRef.current ) {
			return;
		}
		const token = holdTokenRef.current;
		holdTokenRef.current = '';
		setHold( null );
		setSeconds( 0 );
		setHoldStatus( 'idle' );
		releaseHoldToken( token );
	}, [ seriesOn ] );

	const leaveConfirm = async () => {
		const token = holdTokenRef.current;
		holdTokenRef.current = '';
		setHold( null );
		setSeconds( 0 );
		setHoldStatus( 'idle' );
		if ( token ) {
			try {
				await remove( 'bookings/hold', { token } );
			} catch {
				// The hold may already have expired or been consumed.
			}
		}
		onBack();
	};

	useEffect( () => {
		if ( ! boot.loggedIn || seriesOn ) {
			return undefined;
		}
		acquireHold();
		return undefined;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ start, tutor.id, seriesOn ] );

	useEffect( () => {
		if ( seconds <= 0 ) {
			return undefined;
		}
		const id = window.setInterval( () => {
			setSeconds( ( s ) => {
				if ( s <= 1 ) {
					window.clearInterval( id );
					announce( 'Hold expired. Refresh to try again.' );
					return 0;
				}
				return s - 1;
			} );
		}, 1000 );
		return () => window.clearInterval( id );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- restart only when hold token changes
	}, [ hold?.token ] );

	const submit = async () => {
		if ( ! boot.loggedIn ) {
			navigateTo( boot.loginUrl, { sameOrigin: true } );
			return;
		}
		if ( forChild && ! childId ) {
			announce( 'Choose a linked student.' );
			setError( 'Choose a linked student to continue.' );
			return;
		}
		if ( ! seriesOn && ( seconds <= 0 || ! hold?.token ) ) {
			await acquireHold();
			return;
		}
		setBusy( true );
		setError( '' );
		setSkipped( [] );
		try {
			const composedNotes = composeNotes( {
				forChild,
				studentName,
				accountName,
				notes,
				payMethod,
			} );

			if ( seriesOn ) {
				const result = await post( 'series', {
					tutor_id: tutor.id,
					subject_id: subject.id,
					start,
					timezone,
					days: seriesDays,
					count: seriesCount,
					notes: composedNotes,
					student_id: forChild ? childId : undefined,
					use_credit: useCredit,
				} );
				if ( ( result.skipped || [] ).length ) {
					setSkipped( result.skipped );
					announce(
						`Course booked with ${ result.skipped.length } skipped dates.`
					);
				} else {
					announce( 'Weekly course booked.' );
				}
				clearBookingDraft();
				onBooked( {
					...result,
					id: result.booked?.[ 0 ],
					series_label: result.label,
					dashboard_url: boot.dashboardUrl,
					subject: subject?.name,
					tutor: tutor.display_name,
					start_utc: start,
					status: 'confirmed',
				} );
			} else {
				const result = await post( 'bookings', {
					tutor_id: tutor.id,
					subject_id: subject.id,
					start,
					timezone,
					lock_token: hold.token,
					notes: composedNotes,
					student_id: forChild ? childId : undefined,
					use_credit: useCredit,
				} );

				const gateway = gatewayFor( payMethod, boot.payments );
				if (
					gateway &&
					! useCredit &&
					( result.price_minor || 0 ) > 0
				) {
					announce( 'Redirecting to payment…' );
					const payment = await post( 'payments/start', {
						booking_id: result.id,
						gateway,
						success_url: returnUrl( 'success', result.id ),
						cancel_url: returnUrl( 'cancel', result.id ),
					} );
					if ( payment?.url ) {
						if ( navigateTo( payment.url ) ) {
							clearBookingDraft();
							return;
						}
						throw new ApiError(
							'Payment provider returned an unsafe redirect URL.'
						);
					}
				}

				announce( 'Lesson booked.' );
				clearBookingDraft();
				onBooked( result );
			}
		} catch ( err ) {
			setError( err.message || 'Booking failed.' );
			if ( err instanceof ApiError && err.status === 409 ) {
				announce( err.message );
				onRetakeSlot?.( err.message );
			}
		} finally {
			setBusy( false );
		}
	};

	const shell = ( body, footer ) =>
		h(
			'div',
			{ class: 'ts-book ts-book--step' },
			h(
				'div',
				{ class: 'ts-book__hd' },
				h( WizardRail, { steps: RAIL, current: 2 } )
			),
			h( 'div', { class: 'ts-book__body' }, body ),
			h( 'footer', { class: 'ts-book__ft' }, footer )
		);

	const mm = String( Math.floor( seconds / 60 ) ).padStart( 2, '0' );
	const ss = String( seconds % 60 ).padStart( 2, '0' );
	const subjectLine = [ subject?.name, subject?.level ]
		.filter( Boolean )
		.join( ' · ' );
	const priceLabel =
		price > 0
			? money( price, subject?.currency || tutor.currency )
			: 'Free';
	const holdNotice = renderHoldNotice(
		seriesOn,
		holdStatus,
		seconds,
		mm,
		ss
	);

	return shell(
		[
			h( 'h2', { key: 'title' }, 'Confirm and book' ),
			holdNotice,
			error
				? h( ErrorState, {
						key: 'err',
						title: 'Could not book',
						description: error,
				  } )
				: null,
			skipped.length
				? h(
						Callout,
						{
							key: 'skip',
							tone: 'warn',
							title: 'Some weeks were skipped:',
						},
						skipped
							.map( ( s ) => formatInZone( s, timezone ) )
							.join( ', ' )
				  )
				: null,
			h(
				'dl',
				{ key: 'sum', class: 'ts-book__summary' },
				row( 'Subject', subjectLine || subject?.name ),
				row( 'When', formatInZone( start, timezone ) ),
				row(
					'Where',
					`${ meetingLabel(
						tutor.meeting_provider
					) } · link after confirm`
				),
				row( 'Total', priceLabel, true )
			),
			h(
				'div',
				{
					key: 'who',
					class: 'ts-book__section',
				},
				h(
					'h3',
					{ class: 'ts-book__section-title' },
					'Who is this for?'
				),
				h(
					'div',
					{
						class: 'ts-book__who',
						role: 'group',
						'aria-label': 'Who is the lesson for',
					},
					whoOption(
						! forChild,
						() => setForChild( false ),
						'For me',
						accountName
							? `Booked as ${ accountName }`
							: 'You are the student'
					),
					whoOption(
						forChild,
						() => setForChild( true ),
						'For my child',
						childOptionHint( childrenStatus, children ),
						childrenStatus !== 'ready' || ! children.length
					)
				)
			),
			forChild
				? h(
						'label',
						{ key: 'child', class: 'ts-book__field' },
						h( 'span', null, 'Linked student' ),
						h(
							'select',
							{
								value: childId,
								onChange: ( event ) =>
									setChildId(
										Number.parseInt(
											event.target.value,
											10
										)
									),
							},
							children.map( ( child ) =>
								h(
									'option',
									{
										key: child.student_id,
										value: child.student_id,
									},
									child.student_name
								)
							)
						)
				  )
				: null,
			h(
				'label',
				{ key: 'notes', class: 'ts-book__field' },
				h(
					'span',
					null,
					`Note for ${ firstName( tutor.display_name ) }`,
					h( 'small', null, 'Optional' )
				),
				h( 'textarea', {
					rows: 2,
					placeholder: 'Anything helpful before the lesson',
					value: notes,
					onInput: ( e ) => setNotes( e.target.value ),
				} )
			),
			price > 0
				? h(
						'div',
						{
							key: 'pay-wrap',
							class: 'ts-book__pay-wrap ts-book__section',
						},
						h(
							'h3',
							{ class: 'ts-book__section-title' },
							'Payment'
						),
						paymentReadinessNote( boot.payments ),
						h(
							'div',
							{
								class: 'ts-book__pay',
								role: 'radiogroup',
								'aria-label': 'Payment method',
							},
							payOptions.map( ( opt ) =>
								pay(
									opt.id,
									opt.label,
									opt.badge,
									payMethod,
									setPayMethod
								)
							)
						)
				  )
				: null,
			h(
				'details',
				{ key: 'series', class: 'ts-book__more' },
				h( 'summary', null, 'Book as a weekly course' ),
				h(
					'div',
					{ class: 'ts-book__series' },
					h(
						'label',
						{ class: 'ts-book__field ts-book__check' },
						h( 'input', {
							type: 'checkbox',
							checked: seriesOn,
							onChange: ( e ) => setSeriesOn( e.target.checked ),
						} ),
						h( 'span', null, 'Repeat this lesson weekly' )
					),
					seriesOn
						? h(
								'div',
								{ class: 'ts-book__series-opts' },
								h(
									'div',
									{
										class: 'ts-book__days',
										role: 'group',
										'aria-label': 'Weekdays',
									},
									DAY_OPTS.map( ( d ) =>
										h(
											'label',
											{
												key: d.value,
												class: 'ts-book__day',
											},
											h( 'input', {
												type: 'checkbox',
												checked: seriesDays.includes(
													d.value
												),
												onChange: () =>
													toggleDay( d.value ),
											} ),
											h( 'span', null, d.label )
										)
									)
								),
								h(
									'label',
									{ class: 'ts-book__field' },
									h( 'span', null, 'Number of lessons' ),
									h( 'input', {
										type: 'number',
										min: 1,
										max: 104,
										value: seriesCount,
										onInput: ( e ) =>
											setSeriesCount(
												Math.max(
													1,
													Math.min(
														104,
														Number.parseInt(
															e.target.value,
															10
														) || 1
													)
												)
											),
									} )
								)
						  )
						: null
				)
			),
		],
		[
			h(
				Button,
				{ key: 'back', variant: 'ghost', onClick: leaveConfirm },
				'← Back'
			),
			h(
				Button,
				{ key: 'go', disabled: busy, onClick: submit },
				confirmLabel(
					seconds,
					busy,
					price,
					subject,
					tutor,
					seriesOn,
					seriesCount
				)
			),
		]
	);
}

function renderHoldNotice( seriesOn, holdStatus, seconds, mm, ss ) {
	if ( seriesOn ) {
		return null;
	}

	if ( holdStatus === 'idle' || holdStatus === 'loading' ) {
		return h(
			'div',
			{
				key: 'hold',
				class: 'ts-book__hold',
				role: 'status',
			},
			'Securing this time…'
		);
	}

	if ( holdStatus === 'error' ) {
		return null;
	}

	if ( seconds <= 0 ) {
		return h(
			'div',
			{
				key: 'hold',
				class: 'ts-book__hold is-expired',
				role: 'status',
			},
			'Hold expired — refresh to keep this time.'
		);
	}

	return h(
		'div',
		{
			key: 'hold',
			class: 'ts-book__hold',
			role: 'status',
		},
		h( 'span', { 'aria-hidden': 'true' }, '⏱' ),
		h(
			'span',
			null,
			'Held for ',
			h( 'span', { class: 'tutorslot-mono' }, `${ mm }:${ ss }` )
		)
	);
}

function composeNotes( {
	forChild,
	studentName,
	accountName,
	notes,
	payMethod,
} ) {
	const lines = [];
	if ( forChild ) {
		lines.push( `Student: ${ studentName.trim() }` );
		if ( accountName ) {
			lines.push( `Parent: ${ accountName }` );
		}
	}
	if ( notes.trim() ) {
		lines.push( notes.trim() );
	}
	if ( payMethod && payMethod !== 'direct' ) {
		lines.push( `Payment: ${ payMethod }` );
	}
	return lines.join( '\n' );
}

function paymentReadinessNote( payments = {} ) {
	if ( payments.online_ready ) {
		return null;
	}
	if (
		payments.needs_gateway ||
		( payments.enabled !== false && ! payments.stripe && ! payments.bkash )
	) {
		return h(
			Callout,
			{
				key: 'pay-warn',
				tone: 'warn',
				title: 'Online checkout is not connected:',
			},
			'Card and bKash are not set up on this site yet. Choose pay the tutor directly, or use a lesson package if you have one.'
		);
	}
	if ( payments.enabled === false ) {
		return h(
			Callout,
			{
				key: 'pay-off',
				title: 'Online payments are off:',
			},
			'Pay the tutor directly after booking, or use a lesson package.'
		);
	}
	return null;
}

function defaultPayMethod( payments = {} ) {
	if ( payments.online_ready === false && payments.enabled === false ) {
		return 'direct';
	}
	if ( payments.bkash ) {
		return 'bkash';
	}
	if ( payments.stripe ) {
		return 'card';
	}
	return 'direct';
}

function buildPayOptions( payments = {}, tutorName = '' ) {
	const opts = [];
	if ( payments.enabled !== false ) {
		if ( payments.bkash ) {
			opts.push( { id: 'bkash', label: 'bKash', badge: 'Instant' } );
		}
		if ( payments.stripe ) {
			opts.push( {
				id: 'card',
				label: 'Card',
				badge: 'Visa · Mastercard',
			} );
		}
	}
	opts.push( {
		id: 'package',
		label: 'Lesson package',
		badge: 'Use credits',
	} );
	opts.push( {
		id: 'direct',
		label: `Pay ${ firstName( tutorName ) } directly`,
		badge: 'After confirm',
	} );
	return opts;
}

function whoOption( selected, onSelect, label, hint, disabled = false ) {
	return h(
		'button',
		{
			type: 'button',
			class: [ 'ts-book__who-option', selected ? 'is-selected' : '' ]
				.filter( Boolean )
				.join( ' ' ),
			'aria-pressed': selected ? 'true' : 'false',
			disabled,
			onClick: onSelect,
		},
		h( 'strong', null, label ),
		h( 'span', null, hint )
	);
}

function childOptionHint( status, children ) {
	if ( status === 'loading' ) {
		return 'Loading linked students…';
	}
	if ( status === 'error' ) {
		return 'Linked students unavailable';
	}
	if ( ! children.length ) {
		return 'No confirmed student link';
	}
	return 1 === children.length
		? children[ 0 ].student_name
		: `${ children.length } linked students`;
}

function releaseHoldToken( token ) {
	remove( 'bookings/hold', { token }, { keepalive: true } ).catch(
		() => undefined
	);
}

function row( label, value, isTotal = false ) {
	return h(
		'div',
		{ class: isTotal ? 'is-total' : '' },
		h( 'dt', null, label ),
		h( 'dd', null, value )
	);
}

function pay( id, label, badge, current, onChange ) {
	return h(
		'label',
		{
			class: [
				'ts-book__pay-option',
				current === id ? 'is-selected' : '',
			]
				.filter( Boolean )
				.join( ' ' ),
		},
		h( 'input', {
			type: 'radio',
			name: 'ts-pay',
			value: id,
			checked: current === id,
			onChange: () => onChange( id ),
		} ),
		h( 'span', null, label ),
		badge ? h( 'span', { class: 'ts-book__pay-badge' }, badge ) : null
	);
}

function confirmLabel(
	seconds,
	busy,
	price,
	subject,
	tutor,
	seriesOn = false,
	seriesCount = 1
) {
	if ( ! seriesOn && seconds <= 0 ) {
		return 'Refresh hold';
	}
	if ( busy ) {
		return 'Booking…';
	}
	const amount = money( price, subject?.currency || tutor.currency );
	if ( seriesOn ) {
		return `Book ${ seriesCount } lessons · ${ amount } each`;
	}
	if ( price > 0 ) {
		return `Confirm · ${ amount }`;
	}
	return 'Confirm booking';
}

function firstName( name = '' ) {
	return String( name ).trim().split( /\s+/ )[ 0 ] || 'the tutor';
}

function meetingLabel( provider = '' ) {
	const raw = String( provider );
	if ( /google/i.test( raw ) ) {
		return 'Google Meet';
	}
	if ( /zoom/i.test( raw ) ) {
		return 'Zoom';
	}
	return raw || 'Online';
}

const DAY_OPTS = [
	{ value: 0, label: 'Sun' },
	{ value: 1, label: 'Mon' },
	{ value: 2, label: 'Tue' },
	{ value: 3, label: 'Wed' },
	{ value: 4, label: 'Thu' },
	{ value: 5, label: 'Fri' },
	{ value: 6, label: 'Sat' },
];

function gatewayFor( payMethod, payments = {} ) {
	if ( payMethod === 'bkash' && payments.bkash ) {
		return 'bkash';
	}
	if (
		( payMethod === 'card' || payMethod === 'stripe' ) &&
		payments.stripe
	) {
		return 'stripe';
	}
	return null;
}

function returnUrl( flag, bookingId ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tutorslot_pay', flag );
	url.searchParams.set( 'booking', String( bookingId ) );
	return url.toString();
}
