import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import { EmptyState, ErrorState, Skeleton, announce } from '../shared';
import { get, getBoot } from './api';
import { clearBookingDraft, readBookingDraft, saveBookingDraft } from './draft';
import { detectTimezone } from './lib';
import { AccountStep } from './views/AccountStep';
import { AddressStep } from './views/AddressStep';
import { ConfirmStep } from './views/ConfirmStep';
import { DoneView } from './views/DoneView';
import { PaymentReturnView } from './views/PaymentReturnView';
import { ProfileView } from './views/ProfileView';
import { ServiceStep } from './views/ServiceStep';
import { TimeStep } from './views/TimeStep';

/**
 * @param {Object} props
 * @param {number} props.technicianId
 * @param {number} props.serviceId
 * @param {string} props.view
 */
export function BookingApp( { technicianId, serviceId = 0, view = 'booking' } ) {
	const boot = getBoot();
	const payReturn =
		typeof window !== 'undefined'
			? new URLSearchParams( window.location.search ).get(
					'plumberslot_pay'
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
	const [ technician, setTechnician ] = useState( null );
	const [ step, setStep ] = useState(
		view === 'profile' ? 'profile' : 'service'
	);
	const [ selectedServiceId, setSelectedServiceId ] = useState(
		serviceId || 0
	);
	const [ timezone, setTimezone ] = useState( detectTimezone() );
	const [ selectedStart, setSelectedStart ] = useState( '' );
	const [ address, setAddress ] = useState( {
		line1: '',
		line2: '',
		city: '',
		state: '',
		zip: '',
	} );
	const [ booking, setBooking ] = useState( null );
	const [ draftRestored, setDraftRestored ] = useState( false );

	useEffect( () => {
		if ( ! technicianId ) {
			setStatus( 'empty' );
			return;
		}
		( async () => {
			try {
				const data = await get( `public/technicians/${ technicianId }`, {
					timezone,
				} );
				setTechnician( data );
				if ( serviceId ) {
					const exists = ( data.services || [] ).some(
						( s ) => s.id === serviceId
					);
					if ( exists ) {
						setSelectedServiceId( serviceId );
						if ( view !== 'profile' ) {
							setStep( 'time' );
						}
					}
				}
				setStatus( 'ready' );
			} catch ( err ) {
				setError( err.message || 'Technician unavailable.' );
				setStatus( 'error' );
			}
		} )();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- bootstrap once per technician mount
	}, [ technicianId ] );

	useEffect( () => {
		if (
			status !== 'ready' ||
			! technician ||
			draftRestored ||
			view === 'profile'
		) {
			return;
		}
		const draft = readBookingDraft( technicianId );
		if ( ! draft?.start || ! draft?.serviceId ) {
			setDraftRestored( true );
			return;
		}
		const exists = ( technician.services || [] ).some(
			( s ) => s.id === draft.serviceId
		);
		if ( ! exists ) {
			clearBookingDraft();
			setDraftRestored( true );
			return;
		}
		setSelectedServiceId( draft.serviceId );
		setSelectedStart( draft.start );
		if ( draft.timezone ) {
			setTimezone( draft.timezone );
		}
		const draftAddress = draft.address || {};
		const addressComplete =
			Boolean( ( draftAddress.line1 || '' ).trim() ) &&
			Boolean( ( draftAddress.city || '' ).trim() ) &&
			Boolean( ( draftAddress.state || '' ).trim() ) &&
			Boolean( ( draftAddress.zip || '' ).trim() );
		if ( draft.address ) {
			setAddress( {
				line1: draftAddress.line1 || '',
				line2: draftAddress.line2 || '',
				city: draftAddress.city || '',
				state: draftAddress.state || '',
				zip: draftAddress.zip || '',
			} );
		}
		if ( ! addressComplete ) {
			// Missing address: send the customer back to fill it in rather
			// than discarding the rest of an otherwise-resumable draft.
			setStep( 'address' );
		} else if ( boot.loggedIn ) {
			setStep( 'confirm' );
			clearBookingDraft();
			announce( 'Welcome back — confirm your appointment.' );
		} else {
			setStep( 'account' );
		}
		setDraftRestored( true );
	}, [ status, technician, technicianId, draftRestored, view, boot.loggedIn ] );

	useEffect( () => {
		if ( step === 'account' && boot.loggedIn ) {
			setStep( 'confirm' );
		}
	}, [ step, boot.loggedIn ] );

	const service = useMemo(
		() =>
			( technician?.services || [] ).find(
				( s ) => s.id === selectedServiceId
			),
		[ technician, selectedServiceId ]
	);

	const persistAndAccount = () => {
		saveBookingDraft( technicianId, {
			serviceId: selectedServiceId,
			start: selectedStart,
			timezone,
			address,
			step: 'account',
		} );
		setStep( 'account' );
	};

	const goConfirm = () => {
		if ( ! boot.loggedIn ) {
			persistAndAccount();
			return;
		}
		saveBookingDraft( technicianId, {
			serviceId: selectedServiceId,
			start: selectedStart,
			timezone,
			address,
			step: 'confirm',
		} );
		setStep( 'confirm' );
	};

	if ( payReturn === 'success' || payReturn === 'cancel' ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
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
			{ class: 'plumberslot-widget plumberslot-root' },
			h( Skeleton, { lines: 5 } )
		);
	}

	if ( status === 'empty' ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( EmptyState, {
				title: 'Choose a technician',
				description:
					'Add a technician slug to the shortcode to open booking.',
			} )
		);
	}

	if ( status === 'error' || ! technician ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( ErrorState, {
				title: 'Booking unavailable',
				description: error,
			} )
		);
	}

	if ( step === 'done' && booking ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( DoneView, { technician, service, booking, timezone } )
		);
	}

	if ( step === 'profile' ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( ProfileView, {
				technician,
				onBook: () => setStep( 'service' ),
			} )
		);
	}

	if ( step === 'service' ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( ServiceStep, {
				technician,
				selectedId: selectedServiceId,
				onSelect: setSelectedServiceId,
				onContinue: () => setStep( 'time' ),
				onBack: view === 'profile' ? () => setStep( 'profile' ) : null,
			} )
		);
	}

	if ( step === 'time' ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( TimeStep, {
				technician,
				service,
				timezone,
				onTimezone: setTimezone,
				selectedStart,
				onSelectStart: setSelectedStart,
				onContinue: () => setStep( 'address' ),
				continueLabel: 'Continue →',
				onBack: () => setStep( 'service' ),
			} )
		);
	}

	if ( step === 'address' ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( AddressStep, {
				address,
				onChange: setAddress,
				onContinue: goConfirm,
				onBack: () => setStep( 'time' ),
			} )
		);
	}

	if ( step === 'account' ) {
		return h(
			'div',
			{ class: 'plumberslot-widget plumberslot-root' },
			h( AccountStep, {
				technician,
				service,
				start: selectedStart,
				timezone,
				loginUrl: boot.loginUrl,
				onBack: () => setStep( 'address' ),
				onSignedIn: boot.loggedIn ? () => setStep( 'confirm' ) : null,
			} )
		);
	}

	return h(
		'div',
		{ class: 'plumberslot-widget plumberslot-root' },
		h( ConfirmStep, {
			technician,
			service,
			start: selectedStart,
			timezone,
			address,
			onBack: () => setStep( 'address' ),
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
