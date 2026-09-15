import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	TimetableGrid,
	Toggle,
	WizardRail,
	announce,
	copyText,
	navigateTo,
	openUrl,
} from '../../shared';
import { get, post } from '../api/client';
import { getConfig } from '../api/config';
import { PageHeader } from '../components/PageHeader';
import {
	cellsToWeek,
	shortcutWeekdayEvenings,
	shortcutWeekendMornings,
} from '../lib/weekMap';

const SUGGESTIONS = [
	'Mathematics',
	'English',
	'Physics',
	'Chemistry',
	'Biology',
	'Bangla',
	'ICT',
	'Economics',
];

const STEPS = [
	{ id: 'who', label: 'Who teaches' },
	{ id: 'services', label: 'Services' },
	{ id: 'hours', label: 'Your hours' },
	{ id: 'pay', label: 'Payments' },
];

export function SetupWizardScreen() {
	const config = getConfig();
	const [ step, setStep ] = useState( 0 );
	const [ mode, setMode ] = useState( 'solo' );
	const [ tags, setTags ] = useState( [] );
	const [ draft, setDraft ] = useState( '' );
	const [ cells, setCells ] = useState( shortcutWeekdayEvenings() );
	const [ gridKey, setGridKey ] = useState( 0 );
	const [ payments, setPayments ] = useState( true );
	const [ startedAt ] = useState( () => Math.floor( Date.now() / 1000 ) );
	const [ result, setResult ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		( async () => {
			try {
				const status = await get( 'setup' );
				if ( status.completed && status.shortcode ) {
					setResult( {
						shortcode: status.shortcode,
						booking_url: status.booking_url,
						setup_mode: status.setup_mode || 'solo',
						elapsed_seconds: 0,
					} );
					setMode(
						status.setup_mode === 'centre' ? 'centre' : 'solo'
					);
					setStep( 4 );
				}
			} catch {
				/* first-run */
			}
		} )();
	}, [] );

	const addTag = ( name ) => {
		const clean = name.trim();
		if ( ! clean || tags.includes( clean ) ) {
			return;
		}
		setTags( [ ...tags, clean ] );
		setDraft( '' );
	};

	const openCells = Object.keys( cells ).length;

	const finish = async () => {
		if ( ! tags.length ) {
			announce( 'Add at least one service before finishing.' );
			setStep( 1 );
			return;
		}
		if ( ! openCells ) {
			announce( 'Paint at least one open hour before finishing.' );
			setStep( 2 );
			return;
		}
		setSaving( true );
		try {
			const payload = await post( 'setup', {
				mode,
				services: tags,
				week: cellsToWeek( cells ),
				payments_enabled: payments,
				started_at: startedAt,
			} );
			setResult( payload );
			setStep( 4 );
			announce( 'Setup complete. Your booking link is ready.' );
		} catch ( err ) {
			announce( err.message || 'Setup failed.' );
		} finally {
			setSaving( false );
		}
	};

	const copyLink = async () => {
		if ( ! result?.booking_url ) {
			announce( 'Booking link is not ready yet.' );
			return;
		}
		try {
			await copyText( result.booking_url );
			announce( 'Booking link copied.' );
		} catch {
			announce( 'Could not copy. Select the link and copy manually.' );
		}
	};

	const copyShortcode = async () => {
		if ( ! result?.shortcode ) {
			return;
		}
		try {
			await copyText( result.shortcode );
			announce( 'Shortcode copied.' );
		} catch {
			announce(
				'Could not copy. Select the shortcode and copy manually.'
			);
		}
	};

	const goTechnicians = () => {
		const page = config.screens?.technicians || 'plumberslot-technicians';
		navigateTo( `${ config.urls.admin }?page=${ page }`, {
			sameOrigin: true,
		} );
	};

	if ( step >= 4 && result ) {
		const isCentre = result.setup_mode === 'centre' || mode === 'centre';

		return h(
			'div',
			{
				class: 'ts-admin-screen ts-admin--narrow',
				'data-screen': 'setup',
			},
			h(
				'section',
				{ class: 'ts-admin-card ts-admin-done' },
				h( 'h1', null, 'You are bookable' ),
				h(
					'p',
					{ class: 'ts-admin__sub' },
					`First bookable slot ready in ${
						result.elapsed_seconds || 0
					}s. Share this link with parents.`
				),
				h(
					'div',
					{ class: 'ts-admin-done__url plumberslot-mono' },
					result.booking_url || '—'
				),
				h(
					'div',
					{ class: 'ts-admin-done__actions' },
					h( Button, { onClick: copyLink }, 'Copy booking link' ),
					h(
						Button,
						{
							variant: 'secondary',
							onClick: () =>
								openUrl( result.booking_url, {
									sameOrigin: true,
								} ),
						},
						'Open booking page'
					),
					isCentre
						? h(
								Button,
								{ variant: 'secondary', onClick: goTechnicians },
								'Invite technicians'
						  )
						: null
				),
				h(
					'details',
					{ class: 'ts-admin-done__advanced' },
					h( 'summary', null, 'WordPress shortcode' ),
					h(
						'div',
						{ class: 'ts-admin-done__shortcode plumberslot-mono' },
						result.shortcode
					),
					h(
						Button,
						{
							variant: 'ghost',
							size: 'sm',
							onClick: copyShortcode,
						},
						'Copy shortcode'
					)
				)
			)
		);
	}

	return h(
		'div',
		{
			class: 'ts-admin-screen ts-admin--narrow',
			'data-screen': 'setup',
		},
		h( PageHeader, {
			eyebrow: 'Setup',
			title: 'Set up PlumberSlot',
			subtitle: 'Four steps to a link you can paste.',
		} ),
		h( WizardRail, { steps: STEPS, current: step } ),
		step === 0
			? h(
					'section',
					{ class: 'ts-admin-card' },
					h( 'h2', null, 'Who teaches here?' ),
					h(
						'div',
						{ class: 'ts-admin-pick' },
						h(
							'button',
							{
								type: 'button',
								class: [
									'ts-admin-pick__card',
									mode === 'solo' ? 'is-selected' : '',
								]
									.filter( Boolean )
									.join( ' ' ),
								onClick: () => setMode( 'solo' ),
							},
							h( 'strong', null, 'Solo technician' ),
							h(
								'span',
								null,
								'One teacher. Your hours, your rates, your booking page.'
							)
						),
						h(
							'button',
							{
								type: 'button',
								class: [
									'ts-admin-pick__card',
									mode === 'centre' ? 'is-selected' : '',
								]
									.filter( Boolean )
									.join( ' ' ),
								onClick: () => setMode( 'centre' ),
							},
							h( 'strong', null, 'Coaching centre' ),
							h(
								'span',
								null,
								'Several technicians. Invite them after setup.'
							)
						)
					)
			  )
			: null,
		step === 1
			? h(
					'section',
					{ class: 'ts-admin-card' },
					h( 'h2', null, 'Services you teach' ),
					h(
						'div',
						{ class: 'ts-ds__row' },
						SUGGESTIONS.map( ( name ) =>
							h(
								Button,
								{
									key: name,
									size: 'sm',
									variant: tags.includes( name )
										? 'primary'
										: 'secondary',
									onClick: () => addTag( name ),
								},
								name
							)
						)
					),
					h(
						'div',
						{
							class: 'ts-admin-fields',
							style: { marginTop: '12px' },
						},
						h(
							'label',
							{ class: 'ts-admin-field' },
							h( 'span', null, 'Add a service' ),
							h( 'input', {
								type: 'text',
								value: draft,
								onInput: ( event ) =>
									setDraft( event.target.value ),
								onKeyDown: ( event ) => {
									if ( event.key === 'Enter' ) {
										event.preventDefault();
										addTag( draft );
									}
								},
							} )
						)
					),
					tags.length
						? h( 'p', null, `Selected: ${ tags.join( ', ' ) }` )
						: h(
								'p',
								{ class: 'ts-admin__muted' },
								'Pick at least one service so parents have something to book.'
						  )
			  )
			: null,
		step === 2
			? h(
					'section',
					{ class: 'ts-admin-card' },
					h( 'h2', null, 'Paint the hours customers can book' ),
					h(
						'div',
						{
							class: 'ts-ds__row',
							style: { marginBottom: '12px' },
						},
						h(
							Button,
							{
								size: 'sm',
								variant: 'secondary',
								onClick: () => {
									setCells( shortcutWeekdayEvenings() );
									setGridKey( ( k ) => k + 1 );
								},
							},
							'Weekday evenings'
						),
						h(
							Button,
							{
								size: 'sm',
								variant: 'secondary',
								onClick: () => {
									setCells( {
										...cells,
										...shortcutWeekendMornings(),
									} );
									setGridKey( ( k ) => k + 1 );
								},
							},
							'Weekend mornings'
						),
						h(
							Button,
							{
								size: 'sm',
								variant: 'ghost',
								onClick: () => {
									setCells( {} );
									setGridKey( ( k ) => k + 1 );
								},
							},
							'Clear'
						)
					),
					h( TimetableGrid, {
						key: gridKey,
						initial: cells,
						onChange: setCells,
						onSave: ( next ) => {
							setCells( next );
							announce( 'Hours captured for setup.' );
						},
					} )
			  )
			: null,
		step === 3
			? h(
					'section',
					{ class: 'ts-admin-card' },
					h( 'h2', null, 'Payments' ),
					h( Toggle, {
						label: 'Enable online payments',
						explanation:
							'Turn this on when you are ready for card or bKash checkout. You can finish gateway keys in Settings after setup.',
						checked: payments,
						onChange: setPayments,
					} ),
					payments && ! config.payments?.online_ready
						? h(
								Callout,
								{
									tone: 'warn',
									title: 'Connect a gateway next:',
									style: { marginTop: '14px' },
								},
								'Online payments are on, but Stripe/bKash are not set yet. Until then customers will book with “pay technician directly” or job packages. Add keys under Settings → Connections after you finish.'
						  )
						: null
			  )
			: null,
		h(
			'div',
			{ class: 'ts-admin__actions', style: { marginTop: '16px' } },
			step > 0
				? h(
						Button,
						{
							variant: 'ghost',
							onClick: () => setStep( ( s ) => s - 1 ),
						},
						'Back'
				  )
				: null,
			step > 0 && step < 3
				? h(
						Button,
						{
							variant: 'secondary',
							onClick: () => setStep( ( s ) => s + 1 ),
						},
						'Skip for now'
				  )
				: null,
			h(
				Button,
				{
					disabled: saving,
					onClick: () =>
						step < 3 ? setStep( ( s ) => s + 1 ) : finish(),
				},
				finishLabel( step, saving )
			)
		)
	);
}

function finishLabel( step, saving ) {
	if ( step < 3 ) {
		return 'Continue →';
	}
	if ( saving ) {
		return 'Saving…';
	}
	return 'Finish';
}
