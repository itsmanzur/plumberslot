import { h } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import { Button, announce, navigateTo, safeUrl } from '../../shared';
import { getBoot } from '../api';
import { formatInZone, icsDownload, money } from '../lib';

export function DoneView( {
	technician,
	service,
	booking,
	timezone,
	context = 'booking',
} ) {
	const boot = getBoot();
	const titleRef = useRef( null );
	const start = booking.start_utc;
	const end = booking.end_utc;
	const reference = bookingReference( booking.id );
	const titleId = `plumberslot-completion-${ booking.id || 'pending' }`;
	const deadline = booking.reschedule_deadline
		? formatInZone( booking.reschedule_deadline, timezone )
		: '';
	const duration =
		booking.duration_min || service?.duration_min || technician.default_duration;
	const customer = booking.customer || 'You';
	const joinUrl = safeUrl( booking.join_url, { sameOrigin: true } );
	const dashboardUrl = safeUrl( booking.dashboard_url || boot.dashboardUrl, {
		sameOrigin: true,
	} );
	const dateShort = shortDate( start, timezone );
	const heading =
		context === 'payment'
			? `Payment confirmed. ${ customer } is booked${ weekdayHint(
					start,
					timezone
			  ) }.`
			: `Booked. ${ customer } is set${ weekdayHint( start, timezone ) }.`;

	useEffect( () => {
		titleRef.current?.focus();
		announce(
			context === 'payment'
				? 'Payment and booking confirmed.'
				: 'Lesson booked.'
		);
	}, [ context ] );

	const addCalendar = () => {
		icsDownload( {
			title: `${ service?.name || 'Lesson' } with ${
				technician.display_name
			}`,
			startIso: start,
			endIso: end || start,
			description: calendarDescription( joinUrl, reference ),
			uid: `booking-${ booking.id || 'lesson' }@plumberslot`,
		} );
		announce( 'Calendar file downloaded.' );
	};

	return h(
		'div',
		{
			class: 'ts-book ts-book--done',
			'aria-labelledby': titleId,
		},
		h( 'div', { class: 'ts-book__tick', 'aria-hidden': 'true' }, '✓' ),
		h(
			'p',
			{ class: 'ts-book__result-kicker' },
			context === 'payment' ? 'Payment confirmed' : 'Booking confirmed',
			' · ',
			h( 'span', { class: 'plumberslot-mono' }, reference )
		),
		h(
			'h2',
			{
				id: titleId,
				ref: titleRef,
				tabIndex: -1,
			},
			heading
		),
		h(
			'p',
			{ class: 'ts-book__muted' },
			context === 'payment'
				? 'Your verified payment is recorded. Booking details and reminders are on their way.'
				: "We've emailed the booking details. Reminders go out 24 hours and 1 hour before the lesson."
		),
		h(
			'section',
			{
				class: 'ts-book__cal',
				'aria-label': 'Booking details',
			},
			h(
				'div',
				{ class: 'ts-book__cal-top' },
				h(
					'span',
					null,
					`${ service?.name || 'Lesson' } with ${
						technician.display_name
					}`
				),
				h( 'span', { class: 'plumberslot-mono' }, dateShort )
			),
			h(
				'div',
				{ class: 'ts-book__cal-mid' },
				h(
					'dl',
					{ class: 'ts-book__kv' },
					kv( 'Booking', reference ),
					kv(
						'Starts',
						h(
							'span',
							{ class: 'plumberslot-mono' },
							`${ formatInZone( start, timezone, {
								weekday: undefined,
								day: undefined,
								month: undefined,
							} ) } ${ timezone }`
						)
					),
					kv( 'Runs for', `${ duration } minutes` ),
					kv( 'Join', meetingDetails( booking, technician ) ),
					kv( 'Payment', completionPaymentLabel( booking, technician ) ),
					booking.series_label
						? kv( 'Series', booking.series_label )
						: null
				)
			)
		),
		h(
			'div',
			{ class: 'ts-book__done-actions' },
			joinUrl
				? h(
						Button,
						{
							onClick: () =>
								navigateTo( joinUrl, { sameOrigin: true } ),
						},
						'Join lesson'
				  )
				: null,
			h(
				Button,
				{
					variant: joinUrl ? 'secondary' : 'primary',
					onClick: addCalendar,
				},
				'Add to calendar'
			),
			h(
				Button,
				{
					variant: joinUrl ? 'ghost' : 'secondary',
					onClick: () =>
						navigateTo( dashboardUrl, { sameOrigin: true } ),
				},
				'Go to my dashboard'
			)
		),
		deadline
			? h(
					'p',
					{ class: 'ts-book__muted' },
					`Need a different time? You can move this lesson yourself until ${ deadline }.`
			  )
			: null
	);
}

export function bookingReference( id ) {
	const number = Number.parseInt( id, 10 );
	return number > 0
		? `TS-${ String( number ).padStart( 6, '0' ) }`
		: 'TS-PENDING';
}

function kv( label, value ) {
	if ( value === null || value === undefined ) {
		return null;
	}
	return h( 'div', null, h( 'dt', null, label ), h( 'dd', null, value ) );
}

function meetingDetails( booking, technician ) {
	const joinUrl = safeUrl( booking.join_url, { sameOrigin: true } );
	if ( joinUrl ) {
		return h(
			'a',
			{
				href: joinUrl,
				class: 'ts-book__secure-link',
			},
			'Open secure lesson link'
		);
	}

	return `${
		booking.meeting_provider || technician.meeting_provider || 'Online lesson'
	} · link before the lesson`;
}

function completionPaymentLabel( booking, technician ) {
	if ( booking.payment === 'credit' ) {
		return 'Package credit';
	}
	if ( booking.payment === 'free' || Number( booking.price_minor ) <= 0 ) {
		return 'Free';
	}
	if (
		booking.payment === 'unpaid' ||
		booking.status === 'pending_payment'
	) {
		return 'Payment pending';
	}
	return money( booking.price_minor, booking.currency || technician.currency );
}

function calendarDescription( joinUrl, reference ) {
	const parts = [ `PlumberSlot lesson · ${ reference }` ];
	if ( joinUrl ) {
		parts.push( `Join: ${ joinUrl }` );
	} else {
		parts.push( 'The secure join link arrives before the lesson.' );
	}
	return parts.join( '\n' );
}

function shortDate( iso, tz ) {
	try {
		return new Intl.DateTimeFormat( undefined, {
			timeZone: tz,
			day: '2-digit',
			month: 'short',
		} )
			.format( new Date( iso ) )
			.toUpperCase();
	} catch {
		return '';
	}
}

function weekdayHint( iso, tz ) {
	try {
		const day = new Intl.DateTimeFormat( undefined, {
			timeZone: tz,
			weekday: 'long',
		} ).format( new Date( iso ) );
		return ` for ${ day }`;
	} catch {
		return '';
	}
}
