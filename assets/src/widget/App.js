import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import { ErrorState, Skeleton, announce } from '../shared';
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
export function BookingApp( {
	technicianId,
	serviceId = 0,
	view = 'booking',
} ) {
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
	// The technician a chosen slot actually belongs to, in business mode
	// (technicianId falsy — no technician was pinned in the shortcode).
	const [ selectedTechnicianId, setSelectedTechnicianId ] = useState( 0 );
	// Real technician id backing the `technician` state below. Equal to
	// technicianId in the normal single-technician flow; starts at 0 in
	// business mode and becomes real once a slot is resolved.
	const [ resolvedTechnicianId, setResolvedTechnicianId ] = useState(
		technicianId || 0
	);
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
		( async () => {
			try {
				// No technician pinned in the shortcode: let the customer pick a
				// service first and resolve a real technician once they pick a slot.
				const data = technicianId
					? await get( `public/technicians/${ technicianId }`, {
							timezone,
						} )
					: await get( 'public/business', { timezone } );
				setTechnician( data );
				setResolvedTechnicianId( data.id );
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
		const draft = readBookingDraft( resolvedTechnicianId );
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
	}, [
		status,
		technician,
		resolvedTechnicianId,
		draftRestored,
		view,
		boot.loggedIn,
	] );

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
		saveBookingDraft( resolvedTechnicianId, {
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
		saveBookingDraft( resolvedTechnicianId, {
			serviceId: selectedServiceId,
			start: selectedStart,
			timezone,
			address,
			step: 'confirm',
		} );
		setStep( 'confirm' );
	};

	/**
	 * Business mode only: a slot has just been picked, and it belongs to a
	 * real technician (carried on the slot as `technician_id`). Swap the
	 * synthetic id-0 business object for that technician's real profile, and
	 * re-point selectedServiceId at that technician's own service row —
	 * the aggregate service's `id` is only a representative id from one
	 * technician in the group, not necessarily this one.
	 *
	 * @param {number} realTechnicianId The technician id the chosen slot came from.
	 */
	const onSlotResolved = async ( realTechnicianId ) => {
		if ( technician?.id !== 0 || ! realTechnicianId ) {
			return;
		}
		try {
			const real = await get(
				`public/technicians/${ realTechnicianId }`,
				{ timezone }
			);
			const currentName = (
				( technician.services || [] ).find(
					( s ) => s.id === selectedServiceId
				)?.name || ''
			).toLowerCase();
			const ownService = ( real.services || [] ).find(
				( s ) => s.name.toLowerCase() === currentName
			);
			setTechnician( real );
			setResolvedTechnicianId( realTechnicianId );
			if ( ownService ) {
				setSelectedServiceId( ownService.id );
			}
		} catch ( err ) {
			setError( err.message || 'That time is no longer available.' );
			setStatus( 'error' );
		}
	};

	const selectSlot = ( start, slotTechnicianId = 0 ) => {
		setSelectedStart( start );
		setSelectedTechnicianId( slotTechnicianId || 0 );
	};

	const leaveTimeStep = async () => {
		if ( technician?.id === 0 ) {
			await onSlotResolved( selectedTechnicianId );
		}
		setStep( 'address' );
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
				onSelectStart: selectSlot,
				onContinue: leaveTimeStep,
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
				serviceAreaZips: technician.service_area_zips || [],
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
