import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import { EmptyState, ErrorState, Skeleton, announce } from '../shared';
import { get, getBoot } from './api';
import { clearBookingDraft, readBookingDraft, saveBookingDraft } from './draft';
import { detectTimezone } from './lib';
import { AccountStep } from './views/AccountStep';
import { ConfirmStep } from './views/ConfirmStep';
import { DoneView } from './views/DoneView';
import { PaymentReturnView } from './views/PaymentReturnView';
import { ProfileView } from './views/ProfileView';
import { SubjectStep } from './views/SubjectStep';
import { TimeStep } from './views/TimeStep';

/**
 * @param {Object} props
 * @param {number} props.tutorId
 * @param {number} props.subjectId
 * @param {string} props.view
 */
export function BookingApp( { tutorId, subjectId = 0, view = 'booking' } ) {
	const boot = getBoot();
	const payReturn =
		typeof window !== 'undefined'
			? new URLSearchParams( window.location.search ).get(
					'tutorslot_pay'
			  )
			: null;
	const returnBookingId =
		typeof window !== 'undefined'
			? Number.parseInt(
					new URLSearchParams( window.location.search ).get(
						'booking'
					) || '0',
					10
			  )
			: 0;
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ tutor, setTutor ] = useState( null );
	const [ step, setStep ] = useState(
		view === 'profile' ? 'profile' : 'subject'
	);
	const [ selectedSubjectId, setSelectedSubjectId ] = useState(
		subjectId || 0
	);
	const [ timezone, setTimezone ] = useState( detectTimezone() );
	const [ selectedStart, setSelectedStart ] = useState( '' );
	const [ booking, setBooking ] = useState( null );
	const [ draftRestored, setDraftRestored ] = useState( false );

	useEffect( () => {
		if ( ! tutorId ) {
			setStatus( 'empty' );
			return;
		}
		( async () => {
			try {
				const data = await get( `public/tutors/${ tutorId }`, {
					timezone,
				} );
				setTutor( data );
				if ( subjectId ) {
					const exists = ( data.subjects || [] ).some(
						( s ) => s.id === subjectId
					);
					if ( exists ) {
						setSelectedSubjectId( subjectId );
						if ( view !== 'profile' ) {
							setStep( 'time' );
						}
					}
				}
				setStatus( 'ready' );
			} catch ( err ) {
				setError( err.message || 'Tutor unavailable.' );
				setStatus( 'error' );
			}
		} )();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- bootstrap once per tutor mount
	}, [ tutorId ] );

	useEffect( () => {
		if (
			status !== 'ready' ||
			! tutor ||
			draftRestored ||
			view === 'profile'
		) {
			return;
		}
		const draft = readBookingDraft( tutorId );
		if ( ! draft?.start || ! draft?.subjectId ) {
			setDraftRestored( true );
			return;
		}
		const exists = ( tutor.subjects || [] ).some(
			( s ) => s.id === draft.subjectId
		);
		if ( ! exists ) {
			clearBookingDraft();
			setDraftRestored( true );
			return;
		}
		setSelectedSubjectId( draft.subjectId );
		setSelectedStart( draft.start );
		if ( draft.timezone ) {
			setTimezone( draft.timezone );
		}
		if ( boot.loggedIn ) {
			setStep( 'confirm' );
			clearBookingDraft();
			announce( 'Welcome back — confirm your lesson.' );
		} else {
			setStep( 'account' );
		}
		setDraftRestored( true );
	}, [ status, tutor, tutorId, draftRestored, view, boot.loggedIn ] );

	useEffect( () => {
		if ( step === 'account' && boot.loggedIn ) {
			setStep( 'confirm' );
		}
	}, [ step, boot.loggedIn ] );

	const subject = useMemo(
		() =>
			( tutor?.subjects || [] ).find(
				( s ) => s.id === selectedSubjectId
			),
		[ tutor, selectedSubjectId ]
	);

	const persistAndAccount = () => {
		saveBookingDraft( tutorId, {
			subjectId: selectedSubjectId,
			start: selectedStart,
			timezone,
			step: 'account',
		} );
		setStep( 'account' );
	};

	const goConfirm = () => {
		if ( ! boot.loggedIn ) {
			persistAndAccount();
			return;
		}
		saveBookingDraft( tutorId, {
			subjectId: selectedSubjectId,
			start: selectedStart,
			timezone,
			step: 'confirm',
		} );
		setStep( 'confirm' );
	};

	if ( payReturn === 'success' || payReturn === 'cancel' ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( PaymentReturnView, {
				outcome: payReturn,
				bookingId: returnBookingId,
				timezone,
			} )
		);
	}

	if ( status === 'loading' ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( Skeleton, { lines: 5 } )
		);
	}

	if ( status === 'empty' ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( EmptyState, {
				title: 'Choose a tutor',
				description:
					'Add a tutor slug to the shortcode to open booking.',
			} )
		);
	}

	if ( status === 'error' || ! tutor ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( ErrorState, {
				title: 'Booking unavailable',
				description: error,
			} )
		);
	}

	if ( step === 'done' && booking ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( DoneView, { tutor, subject, booking, timezone } )
		);
	}

	if ( step === 'profile' ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( ProfileView, {
				tutor,
				onBook: () => setStep( 'subject' ),
			} )
		);
	}

	if ( step === 'subject' ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( SubjectStep, {
				tutor,
				selectedId: selectedSubjectId,
				onSelect: setSelectedSubjectId,
				onContinue: () => setStep( 'time' ),
				onBack: view === 'profile' ? () => setStep( 'profile' ) : null,
			} )
		);
	}

	if ( step === 'time' ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( TimeStep, {
				tutor,
				subject,
				timezone,
				onTimezone: setTimezone,
				selectedStart,
				onSelectStart: setSelectedStart,
				onContinue: goConfirm,
				continueLabel: boot.loggedIn
					? 'Continue →'
					: 'Sign in to continue →',
				onBack: () => setStep( 'subject' ),
			} )
		);
	}

	if ( step === 'account' ) {
		return h(
			'div',
			{ class: 'tutorslot-widget tutorslot-root' },
			h( AccountStep, {
				tutor,
				subject,
				start: selectedStart,
				timezone,
				loginUrl: boot.loginUrl,
				onBack: () => setStep( 'time' ),
				onSignedIn: boot.loggedIn ? () => setStep( 'confirm' ) : null,
			} )
		);
	}

	return h(
		'div',
		{ class: 'tutorslot-widget tutorslot-root' },
		h( ConfirmStep, {
			tutor,
			subject,
			start: selectedStart,
			timezone,
			onBack: () => setStep( 'time' ),
			onBooked: ( result ) => {
				setBooking( result );
				setStep( 'done' );
			},
			onRetakeSlot: ( message ) => {
				announce( message || 'That slot was taken.' );
				setSelectedStart( '' );
				setStep( 'time' );
			},
		} )
	);
}
