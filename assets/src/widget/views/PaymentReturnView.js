import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	ErrorState,
	Skeleton,
	navigateTo,
} from '../../shared';
import { get, getBoot } from '../api';
import { formatInZone, money } from '../lib';
import { DoneView, bookingReference } from './DoneView';

const CONFIRMED_STATUSES = [ 'confirmed', 'completed' ];
const FINISHED_PAYMENTS = [ 'paid', 'free', 'credit' ];
const POLL_LIMIT = 3;
const POLL_DELAY = 1500;

export function PaymentReturnView( { outcome, bookingId, timezone } ) {
	const [ status, setStatus ] = useState( 'loading' );
	const [ booking, setBooking ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const titleRef = useRef( null );
	const titleId = `plumberslot-payment-result-${ bookingId || 'unknown' }`;

	useEffect( () => {
		let active = true;
		let timer;

		const verify = async ( attempt = 0 ) => {
			if ( ! Number.isInteger( bookingId ) || bookingId <= 0 ) {
				setError(
					'The payment return is missing a valid booking reference.'
				);
				setStatus( 'error' );
				return;
			}

			if ( 0 === attempt ) {
				setStatus( 'loading' );
				setError( '' );
			}

			try {
				const result = await get( `bookings/${ bookingId }` );
				if ( ! active ) {
					return;
				}
				setBooking( result );
				setStatus( 'ready' );

				if (
					outcome === 'success' &&
					paymentState( result, outcome ) === 'pending' &&
					attempt < POLL_LIMIT - 1
				) {
					timer = window.setTimeout(
						() => verify( attempt + 1 ),
						POLL_DELAY
					);
				}
			} catch ( err ) {
				if ( ! active ) {
					return;
				}
				setError(
					err.message || 'We could not verify this booking right now.'
				);
				setStatus( 'error' );
			}
		};

		verify();
		return () => {
			active = false;
			window.clearTimeout( timer );
		};
	}, [ bookingId, outcome, reloadKey ] );

	useEffect( () => {
		if ( status === 'ready' ) {
			titleRef.current?.focus();
		}
	}, [ status ] );

	if ( status === 'loading' ) {
		return h(
			'div',
			{ class: 'ts-book ts-book--done ts-book--result' },
			h( 'p', { class: 'ts-book__result-kicker' }, 'Secure checkout' ),
			h( 'h2', null, 'Verifying your booking…' ),
			h( Skeleton, { lines: 4 } )
		);
	}

	if ( status === 'error' || ! booking ) {
		const boot = getBoot();

		return h(
			'div',
			{ class: 'ts-book ts-book--done ts-book--result' },
			h( ErrorState, {
				title: 'We could not verify this payment',
				description: boot.loggedIn
					? error
					: 'Sign in with the account that made this booking to verify its payment status.',
				actionLabel: boot.loggedIn ? 'Try again' : 'Sign in to verify',
				onAction: boot.loggedIn
					? () => setReloadKey( ( value ) => value + 1 )
					: () => {
							navigateTo( boot.loginUrl, { sameOrigin: true } );
						},
			} ),
			h(
				Button,
				{
					variant: 'secondary',
					onClick: () => goToDashboard( boot ),
				},
				'Check my dashboard'
			)
		);
	}

	const state = paymentState( booking, outcome );
	if ( state === 'confirmed' ) {
		return h( DoneView, {
			technician: bookingTechnician( booking ),
			service: bookingService( booking ),
			booking,
			timezone: timezone || booking.technician_timezone || 'UTC',
			context: 'payment',
		} );
	}

	const boot = getBoot();
	const copy = resultCopy( state );
	return h(
		'div',
		{
			class: `ts-book ts-book--done ts-book--result is-${ state }`,
			'aria-labelledby': titleId,
		},
		h(
			'div',
			{
				class: `ts-book__result-mark is-${ state }`,
				'aria-hidden': 'true',
			},
			copy.mark
		),
		h( 'p', { class: 'ts-book__result-kicker' }, copy.kicker ),
		h(
			'h2',
			{
				id: titleId,
				ref: titleRef,
				tabIndex: -1,
			},
			copy.title
		),
		h( Callout, { tone: copy.tone, title: copy.callout }, copy.message ),
		h(
			'dl',
			{ class: 'ts-book__result-details ts-book__kv' },
			resultRow( 'Booking', bookingReference( booking.id ) ),
			resultRow( 'Appointment', booking.service || 'Appointment' ),
			resultRow(
				'When',
				booking.when ||
					formatInZone(
						booking.start_utc,
						timezone || booking.technician_timezone || 'UTC'
					)
			),
			resultRow( 'Payment', paymentLabel( booking, state ) )
		),
		h(
			'div',
			{ class: 'ts-book__done-actions' },
			state === 'pending'
				? h(
						Button,
						{
							onClick: () =>
								setReloadKey( ( value ) => value + 1 ),
						},
						'Refresh status'
					)
				: h(
						Button,
						{ onClick: clearPaymentReturn },
						'Return to booking'
					),
			h(
				Button,
				{
					variant: 'secondary',
					onClick: () => goToDashboard( boot ),
				},
				'Check my dashboard'
			)
		)
	);
}

function paymentState( booking, outcome ) {
	if (
		CONFIRMED_STATUSES.includes( booking.status ) ||
		FINISHED_PAYMENTS.includes( booking.payment )
	) {
		return 'confirmed';
	}
	if ( booking.status === 'refunded' || booking.payment === 'refunded' ) {
		return 'refunded';
	}
	if ( outcome === 'cancel' || booking.status === 'cancelled' ) {
		return 'cancelled';
	}
	if ( booking.status === 'payment_failed' ) {
		return 'failed';
	}
	if ( booking.status === 'payment_expired' ) {
		return 'expired';
	}
	return 'pending';
}

function resultCopy( state ) {
	if ( state === 'cancelled' ) {
		return {
			mark: '×',
			kicker: 'Checkout cancelled',
			title: 'Your payment was not completed',
			callout: 'No confirmed charge:',
			message:
				'You can return to booking or check your dashboard before trying again.',
			tone: 'warn',
		};
	}
	if ( state === 'failed' ) {
		return {
			mark: '!',
			kicker: 'Payment needs attention',
			title: 'We could not complete the payment',
			callout: 'Payment failed:',
			message:
				'No booking confirmation was issued. Choose another payment method or try again.',
			tone: 'danger',
		};
	}
	if ( state === 'expired' ) {
		return {
			mark: '⌛',
			kicker: 'Checkout expired',
			title: 'Your payment window has closed',
			callout: 'No active booking:',
			message:
				'The appointment time was released. Return to booking to choose an available time again.',
			tone: 'warn',
		};
	}
	if ( state === 'refunded' ) {
		return {
			mark: '↩',
			kicker: 'Payment refunded',
			title: 'This payment has been refunded',
			callout: 'Refund recorded:',
			message:
				'Your dashboard has the latest booking and refund details.',
			tone: 'warn',
		};
	}
	return {
		mark: '…',
		kicker: 'Secure checkout',
		title: 'We are confirming your payment',
		callout: 'Confirmation pending:',
		message:
			'The gateway returned successfully, but verified server confirmation is still pending. Do not pay again.',
		tone: 'warn',
	};
}

function paymentLabel( booking, state ) {
	if ( state === 'pending' ) {
		return 'Awaiting confirmation';
	}
	if ( state === 'cancelled' ) {
		return 'Cancelled';
	}
	if ( state === 'failed' ) {
		return 'Failed';
	}
	if ( state === 'expired' ) {
		return 'Expired';
	}
	if ( state === 'refunded' ) {
		return 'Refunded';
	}
	return money( booking.price_minor, booking.currency );
}

function resultRow( label, value ) {
	return h( 'div', null, h( 'dt', null, label ), h( 'dd', null, value ) );
}

function bookingTechnician( booking ) {
	return {
		display_name: booking.technician || 'your technician',
		default_duration: booking.duration_min || 60,
		meeting_provider: booking.meeting_provider || 'Online appointment',
		currency: booking.currency || 'USD',
	};
}

function bookingService( booking ) {
	return {
		name: booking.service || 'Appointment',
		duration_min: booking.duration_min || 60,
	};
}

function goToDashboard( boot ) {
	navigateTo( boot.dashboardUrl || '/', { sameOrigin: true } );
}

function clearPaymentReturn() {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'plumberslot_pay' );
	url.searchParams.delete( 'booking' );
	window.location.href = url.toString();
}
