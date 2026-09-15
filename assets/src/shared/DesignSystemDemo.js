import { h } from 'preact';
import { useState } from 'preact/hooks';
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
	StatTile,
	StatusChip,
	TimezoneBanner,
	TimetableGrid,
	TodayStrip,
	Toggle,
	WizardRail,
	announce,
} from './index';

const DEMO_SEED = ( () => {
	const seed = {};
	[ 0, 1, 2, 3, 4 ].forEach( ( d ) => {
		[ 15, 16, 17, 18 ].forEach( ( hour ) => {
			seed[ `${ d }-${ hour }-0` ] = 'on';
			seed[ `${ d }-${ hour }-1` ] = 'on';
		} );
	} );
	[ 9, 10, 11 ].forEach( ( hour ) => {
		seed[ `5-${ hour }-0` ] = 'on';
		seed[ `5-${ hour }-1` ] = 'on';
	} );
	seed[ '0-11-0' ] = 'bk';
	seed[ 'w0-11-0' ] = 'Ayaan';
	seed[ '0-11-1' ] = 'bk';
	seed[ '0-16-0' ] = 'bk';
	seed[ 'w0-16-0' ] = 'Tasnim';
	seed[ '0-16-1' ] = 'bk';
	seed[ '1-8-0' ] = 'bk';
	seed[ 'w1-8-0' ] = 'Rafi';
	seed[ '1-8-1' ] = 'bk';
	seed[ '2-17-0' ] = 'bk';
	seed[ 'w2-17-0' ] = 'Sadia';
	seed[ '2-17-1' ] = 'bk';
	seed[ '3-18-0' ] = 'pd';
	return seed;
} )();

const DAYS = [
	{ id: 'mon', dow: 'Mon', date: '28' },
	{ id: 'tue', dow: 'Tue', date: '29' },
	{ id: 'wed', dow: 'Wed', date: '30' },
	{ id: 'thu', dow: 'Thu', date: '31' },
	{ id: 'fri', dow: 'Fri', date: '1', disabled: true },
];

/**
 * Visual baseline for shared components (Phase 2 exit gate).
 */
export function DesignSystemDemo() {
	const [ day, setDay ] = useState( 'tue' );
	const [ slot, setSlot ] = useState( '10:00' );
	const [ payments, setPayments ] = useState( true );
	const [ modalOpen, setModalOpen ] = useState( false );
	const [ showSkeleton, setShowSkeleton ] = useState( false );

	return h(
		'div',
		{ class: 'plumberslot-admin ts-ds' },
		h(
			'header',
			{ class: 'ts-ds__section' },
			h(
				'p',
				{ class: 'plumberslot-admin__eyebrow' },
				'PlumberSlot design system'
			),
			h( 'h1', null, 'Shared component baseline' ),
			h(
				'p',
				null,
				'Admin and booking widget share these tokens and components. Override --ts-ink, --ts-primary, --ts-highlight, and --ts-radius to rebrand.'
			)
		),
		h(
			'section',
			{ class: 'ts-ds__section', 'aria-labelledby': 'ds-buttons' },
			h( 'h2', { id: 'ds-buttons' }, 'Buttons & chips' ),
			h(
				'p',
				null,
				'Primary, secondary, ghost, danger, and dirty Save highlight.'
			),
			h(
				'div',
				{ class: 'ts-ds__row' },
				h( Button, null, 'Primary' ),
				h( Button, { variant: 'secondary' }, 'Secondary' ),
				h( Button, { variant: 'ghost' }, 'Ghost' ),
				h( Button, { variant: 'danger' }, 'Danger' ),
				h( Button, { dirty: true }, 'Save changes' ),
				h( StatusChip, { tone: 'ok' }, 'Confirmed' ),
				h( StatusChip, { tone: 'wait' }, 'Pending' ),
				h( StatusChip, { tone: 'open' }, 'Open' ),
				h( StatusChip, { tone: 'booked' }, 'Booked' ),
				h( StatusChip, { tone: 'held' }, 'Held' )
			)
		),
		h(
			'section',
			{ class: 'ts-ds__section', 'aria-labelledby': 'ds-feedback' },
			h( 'h2', { id: 'ds-feedback' }, 'Callouts & states' ),
			h( 'p', null, 'Notes, empty, error, and skeleton loading.' ),
			h(
				Callout,
				{ title: 'Tip:' },
				'Times follow the tutor timezone below.'
			),
			h(
				Callout,
				{ tone: 'warn', title: 'Heads up:' },
				'Buffer minutes apply between lessons.'
			),
			h(
				'div',
				{ class: 'ts-ds__row', style: { marginTop: '16px' } },
				h( EmptyState, {
					title: 'No lessons yet',
					description: 'When students book, they will appear here.',
					actionLabel: 'Add availability',
					onAction: () => announce( 'Add availability action' ),
				} ),
				h( ErrorState, {
					title: 'Could not load slots',
					description: 'Check your connection and try again.',
					actionLabel: 'Retry',
					onAction: () => {
						setShowSkeleton( true );
						announce( 'Retrying…' );
						window.setTimeout(
							() => setShowSkeleton( false ),
							1200
						);
					},
				} )
			),
			showSkeleton ? h( Skeleton, { lines: 4 } ) : null
		),
		h(
			'section',
			{ class: 'ts-ds__section', 'aria-labelledby': 'ds-wizard' },
			h( 'h2', { id: 'ds-wizard' }, 'Wizard, timezone, toggles' ),
			h( WizardRail, {
				current: 1,
				steps: [
					{ id: 'profile', label: 'Profile' },
					{ id: 'hours', label: 'Hours' },
					{ id: 'pay', label: 'Payments' },
					{ id: 'done', label: 'Done' },
				],
			} ),
			h( TimezoneBanner, { timezone: 'Asia/Dhaka' } ),
			h( Toggle, {
				label: 'Accept card payments',
				explanation:
					'Students can pay online before the lesson is confirmed.',
				checked: payments,
				onChange: setPayments,
			} ),
			h(
				'div',
				{ class: 'ts-ds__row', style: { marginTop: '12px' } },
				h(
					Button,
					{
						variant: 'secondary',
						onClick: () => setModalOpen( true ),
					},
					'Open modal'
				),
				h(
					Button,
					{
						variant: 'ghost',
						onClick: () =>
							announce(
								'Slot taken — please choose another time.'
							),
					},
					'Announce slot taken'
				)
			)
		),
		h(
			'section',
			{ class: 'ts-ds__section', 'aria-labelledby': 'ds-booking' },
			h( 'h2', { id: 'ds-booking' }, 'Booking controls' ),
			h(
				'p',
				null,
				'Day strip, slot buttons, stats, today strip, credits.'
			),
			h( DayStrip, { days: DAYS, value: day, onChange: setDay } ),
			h(
				'div',
				{ class: 'ts-ds__row', style: { marginTop: '12px' } },
				h( SlotButton, {
					label: '10:00',
					selected: slot === '10:00',
					onClick: () => setSlot( '10:00' ),
				} ),
				h( SlotButton, {
					label: '10:30',
					selected: slot === '10:30',
					onClick: () => setSlot( '10:30' ),
				} ),
				h( SlotButton, { label: '11:00', tone: 'held' } ),
				h( SlotButton, { label: '11:30', tone: 'booked' } )
			),
			h(
				'div',
				{ class: 'ts-tiles', style: { marginTop: '16px' } },
				h( StatTile, {
					label: 'Lessons',
					value: '12',
					hint: '+3 this week',
				} ),
				h( StatTile, {
					label: 'Open hours',
					value: '18',
					hint: 'This week',
				} ),
				h( StatTile, {
					label: 'Credits',
					value: '4',
					hint: '2 students',
				} ),
				h( StatTile, { label: 'Revenue', value: '৳8.2k', hint: 'MTD' } )
			),
			h(
				'div',
				{ style: { marginTop: '16px' } },
				h( TodayStrip, {
					nowPct: 42,
					nowLabel: '14:20',
					items: [
						{
							id: 1,
							title: 'Ayaan · Math',
							startPct: 8,
							widthPct: 14,
							done: true,
						},
						{
							id: 2,
							title: 'Tasnim · Eng',
							startPct: 45,
							widthPct: 16,
						},
						{
							id: 3,
							title: 'Rafi · Physics',
							startPct: 70,
							widthPct: 18,
						},
					],
				} )
			),
			h(
				'div',
				{ style: { marginTop: '16px', maxWidth: '320px' } },
				h( CreditMeter, {
					used: 6,
					total: 10,
				} )
			)
		),
		h(
			'section',
			{ class: 'ts-ds__section', 'aria-labelledby': 'ds-tt' },
			h( 'h2', { id: 'ds-tt' }, 'TimetableGrid' ),
			h(
				'p',
				null,
				'Drag to paint (mouse/touch). First cell sets paint or erase. Space/Enter toggles. Booked ● and held ▨ cells stay locked. Save highlights when dirty.'
			),
			h( TimetableGrid, {
				initial: DEMO_SEED,
				onSave: () => announce( 'Availability saved.' ),
			} )
		),
		h(
			Modal,
			{
				open: modalOpen,
				title: 'Confirm save',
				onClose: () => setModalOpen( false ),
				primaryLabel: 'Save',
				onPrimary: () => {
					setModalOpen( false );
					announce( 'Saved from modal.' );
				},
			},
			'Unsaved availability changes will be written to the server.'
		)
	);
}
