import { h } from 'preact';
import { useEffect, useMemo, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	DayStrip,
	ErrorState,
	SlotButton,
	WizardRail,
} from '../../shared';
import { get } from '../api';
import { handleListboxKeyDown } from '../a11y';
import {
	buildDayStrip,
	dayKeyInZone,
	detectTimezone,
	formatInZone,
} from '../lib';
import { RAIL } from './ServiceStep';

export function TimeStep( {
	technician,
	service,
	timezone,
	onTimezone,
	selectedStart,
	onSelectStart,
	onContinue,
	continueLabel = 'Continue →',
	onBack,
	emergencyRequested = false,
	onEmergencyRequestedChange,
} ) {
	const [ days ] = useState( () =>
		buildDayStrip( new Date(), 7, timezone || detectTimezone() )
	);
	const [ day, setDay ] = useState( days[ 0 ]?.id );
	const [ slots, setSlots ] = useState( [] );
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ stale, setStale ] = useState( false );
	const [ editingTz, setEditingTz ] = useState( false );
	const tz = timezone || detectTimezone();

	// technician.id === 0 is the synthetic "no technician chosen yet"
	// business object (see PublicTechnicianController::business()) — earliest
	// opening across every technician who offers this service, not just one.
	const isBusiness = technician.id === 0;
	// Only a service the technician explicitly marked is offered the "need
	// it today?" toggle at all -- guards against a stale flag surviving a
	// service switch even if a caller forgets to reset it.
	const emergencyEligible = Boolean( service?.is_emergency_available );
	const emergencyActive = emergencyEligible && emergencyRequested;
	const emergencyWindowHours =
		Number( technician.emergency_window_hours ) || 6;

	const load = async ( { silent = false } = {} ) => {
		if ( ! silent ) {
			setStatus( 'loading' );
		}
		try {
			const now = new Date();
			const from = emergencyActive
				? now
				: new Date( now.getFullYear(), now.getMonth(), now.getDate() );
			const to = emergencyActive
				? new Date( now.getTime() + emergencyWindowHours * 3600000 )
				: new Date( from.getTime() + 8 * 86400000 );
			const duration =
				service?.duration_min || technician.default_duration || 60;
			const data = isBusiness
				? await get( 'slots-any', {
						technician_ids: ( service?.technician_ids || [] ).join(
							','
						),
						from: from.toISOString(),
						to: to.toISOString(),
						duration,
						timezone: tz,
					} )
				: await get( 'slots', {
						technician_id: technician.id,
						from: from.toISOString(),
						to: to.toISOString(),
						duration,
						timezone: tz,
					} );
			setSlots( data.slots || [] );
			setStale( false );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load times.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		load();
		const timer = window.setInterval(
			() => load( { silent: true } ),
			30000
		);
		return () => window.clearInterval( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ technician.id, service?.id, tz, emergencyActive ] );

	const hasOpenSlots = slots.some( ( slot ) => slot.state === 'open' );
	const showNoEmergencySlots =
		emergencyActive && ! isBusiness && status === 'ready' && ! hasOpenSlots;

	const byDay = useMemo( () => {
		const map = {};
		slots.forEach( ( slot ) => {
			const key = dayKeyInZone( slot.start, tz );
			if ( ! map[ key ] ) {
				map[ key ] = [];
			}
			map[ key ].push( slot );
		} );
		return map;
	}, [ slots, tz ] );

	const dayItems = useMemo(
		() =>
			days.map( ( item ) => ( {
				...item,
				disabled: ! ( byDay[ item.id ] || [] ).some(
					( s ) => s.state === 'open'
				),
			} ) ),
		[ byDay, days ]
	);
	const firstOpenDayId = dayItems.find( ( item ) => ! item.disabled )?.id;

	useEffect( () => {
		if ( status !== 'ready' ) {
			return;
		}

		const selectedDayIsOpen = dayItems.some(
			( item ) => item.id === day && ! item.disabled
		);
		if ( ! selectedDayIsOpen ) {
			setDay( firstOpenDayId );
		}
	}, [ day, dayItems, firstOpenDayId, status ] );

	const daySlots = byDay[ day ] || [];
	const duration = service?.duration_min || technician.default_duration || 60;
	const selectedLabel = selectedStart
		? formatRange( selectedStart, duration, tz )
		: 'Pick a time';

	const timeFieldsContent = showNoEmergencySlots
		? [
				h(
					Callout,
					{
						key: 'no-emergency',
						tone: 'warn',
						title: 'No emergency slots:',
					},
					`No emergency openings in the next ${ emergencyWindowHours } hours. Try a regular booking, or turn off “Need it today?” above.`
				),
			]
		: [
				h( 'h3', { key: 'day-h3' }, 'Pick a day' ),
				h(
					'p',
					{ key: 'day-sub', class: 'ts-book__sub' },
					'Dots mark days with at least one open slot.'
				),
				h( DayStrip, {
					key: 'day-strip',
					days: dayItems,
					value: day,
					onChange: ( nextDay ) => {
						setDay( nextDay );
						onSelectStart( null );
					},
				} ),
				stale
					? h(
							Callout,
							{ key: 'stale', tone: 'warn', title: 'Updated:' },
							'Some slots were taken. Pick again.'
						)
					: null,
				h( 'h3', { key: 'time-h3' }, 'Pick a time' ),
				h(
					'div',
					{
						key: 'slots',
						class: 'ts-book__slots',
						role: 'listbox',
						'aria-label': 'Open times',
					},
					status === 'loading'
						? h(
								'p',
								{ class: 'ts-book__muted' },
								'Loading times…'
							)
						: renderSlots(
								daySlots,
								tz,
								selectedStart,
								onSelectStart,
								setStale
							)
				),
				h(
					'p',
					{ key: 'note', class: 'ts-book__muted' },
					`Each appointment runs ${ duration } minutes. Taken slots update live.`
				),
			];

	return h(
		'div',
		{ class: 'ts-book ts-book--step' },
		h(
			'div',
			{ class: 'ts-book__hd' },
			h( WizardRail, { steps: RAIL, current: 1 } )
		),
		h(
			'div',
			{ class: 'ts-book__body' },
			h( 'h2', null, 'When works for you?' ),
			emergencyEligible
				? h(
						'label',
						{
							class: 'ts-book__field ts-book__check ts-book__emergency-toggle',
						},
						h( 'input', {
							type: 'checkbox',
							checked: emergencyRequested,
							onChange: ( event ) =>
								onEmergencyRequestedChange?.(
									event.target.checked
								),
						} ),
						h(
							'span',
							null,
							`⚡ Need it today? Show the soonest openings in the next ${ emergencyWindowHours } hours.`
						)
					)
				: null,
			h(
				'div',
				{ class: 'ts-book__tz-row' },
				h(
					'div',
					{ class: 'ts-tz', role: 'status' },
					h( 'span', null, 'Times shown in ' ),
					h( 'strong', { class: 'plumberslot-mono' }, tz ),
					h(
						'button',
						{
							type: 'button',
							class: 'ts-tz__change',
							onClick: () => setEditingTz( ( v ) => ! v ),
						},
						editingTz ? 'Done' : 'Change'
					)
				),
				editingTz
					? h(
							'label',
							{ class: 'ts-book__tz-field' },
							h( 'span', null, 'Timezone' ),
							h( 'input', {
								type: 'text',
								value: tz,
								'aria-label': 'Override timezone',
								onChange: ( event ) =>
									onTimezone( event.target.value ),
							} )
						)
					: null
			),
			status === 'error'
				? h( ErrorState, {
						title: 'Times unavailable',
						description: error,
						actionLabel: 'Retry',
						onAction: () => load(),
					} )
				: null,
			status !== 'error'
				? h( 'div', { class: 'ts-book__fields' }, timeFieldsContent )
				: null
		),
		h(
			'footer',
			{ class: 'ts-book__ft' },
			h( Button, { variant: 'ghost', onClick: onBack }, '← Back' ),
			h(
				'div',
				{ class: 'ts-book__footer-end' },
				h( 'span', { class: 'plumberslot-mono' }, selectedLabel ),
				h(
					Button,
					{ disabled: ! selectedStart, onClick: onContinue },
					continueLabel
				)
			)
		)
	);
}

function formatRange( startIso, durationMin, tz ) {
	try {
		const start = new Date( startIso );
		const end = new Date( start.getTime() + durationMin * 60000 );
		const dayPart = formatInZone( startIso, tz, {
			hour: undefined,
			minute: undefined,
		} );
		const startTime = formatInZone( startIso, tz, {
			weekday: undefined,
			day: undefined,
			month: undefined,
		} );
		const endTime = formatInZone( end.toISOString(), tz, {
			weekday: undefined,
			day: undefined,
			month: undefined,
		} );
		return `${ dayPart } · ${ startTime }–${ endTime }`;
	} catch {
		return formatInZone( startIso, tz );
	}
}

function renderSlots( daySlots, tz, selectedStart, onSelectStart, setStale ) {
	if ( ! daySlots.length ) {
		return h( 'p', { class: 'ts-book__muted' }, 'No open times this day.' );
	}

	const selectedIndex = daySlots.findIndex(
		( slot ) => slot.start === selectedStart && slot.state === 'open'
	);
	const firstOpenIndex = daySlots.findIndex(
		( slot ) => slot.state === 'open'
	);

	return daySlots.map( ( slot, index ) => {
		const time = formatInZone( slot.start, tz, {
			weekday: undefined,
			day: undefined,
			month: undefined,
		} );
		const gone = slot.state !== 'open';
		let tone = 'open';
		if ( slot.state === 'held' ) {
			tone = 'held';
		} else if ( gone ) {
			tone = 'gone';
		}

		return h( SlotButton, {
			key: slot.start,
			label: time,
			tone,
			selected: selectedStart === slot.start,
			disabled: gone,
			listboxOption: true,
			tabIndex:
				selectedIndex === index ||
				( selectedIndex < 0 && firstOpenIndex === index )
					? 0
					: -1,
			onClick: () => {
				onSelectStart( slot.start, slot.technician_id );
				setStale( false );
			},
			onKeyDown: ( event ) =>
				handleListboxKeyDown( event, {
					items: daySlots,
					currentIndex: index,
					isDisabled: ( item ) => item.state !== 'open',
					onSelect: ( nextSlot ) => {
						onSelectStart( nextSlot.start, nextSlot.technician_id );
						setStale( false );
					},
				} ),
		} );
	} );
}
