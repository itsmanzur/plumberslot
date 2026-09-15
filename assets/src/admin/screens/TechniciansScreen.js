import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import {
	Button,
	EmptyState,
	Modal,
	PersonCell,
	StatusChip,
	announce,
} from '../../shared';
import { can } from '../api/config';
import { get, patch, post } from '../api/client';
import { PageHeader, ScreenState } from '../components/PageHeader';

export function TechniciansScreen() {
	const allowed = can( 'manageTechnicians' );
	const [ status, setStatus ] = useState( allowed ? 'loading' : 'error' );
	const [ error, setError ] = useState(
		allowed
			? ''
			: 'You need the manage-technicians capability to open this screen.'
	);
	const [ technicians, setTechnicians ] = useState( [] );
	const [ inviteOpen, setInviteOpen ] = useState( false );
	const [ email, setEmail ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ rate, setRate ] = useState( '' );
	const [ edit, setEdit ] = useState( null );

	const load = async () => {
		if ( ! allowed ) {
			return;
		}
		setStatus( 'loading' );
		try {
			const data = await get( 'technicians' );
			setTechnicians( data.technicians || [] );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load technicians.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- mount-only fetch
	}, [] );

	const onInvite = async () => {
		await post( 'technicians', {
			email,
			display_name: name,
			hourly_rate_minor: majorToMinor( rate ),
		} );
		announce( 'Invitation sent.' );
		setInviteOpen( false );
		setEmail( '' );
		setName( '' );
		setRate( '' );
		load();
	};

	const onResend = async ( id ) => {
		await post( `technicians/${ id }/resend` );
		announce( 'Invitation resent.' );
	};

	const onSaveEdit = async () => {
		if ( ! edit ) {
			return;
		}
		await patch( `technicians/${ edit.id }`, {
			display_name: edit.display_name,
			hourly_rate_minor: majorToMinor( edit.rate_major ),
			status: edit.status,
		} );
		announce( 'Technician updated.' );
		setEdit( null );
		load();
	};

	const openEdit = ( technician ) => {
		setEdit( {
			...technician,
			rate_major: String(
				( Number( technician.hourly_rate_minor ) || 0 ) / 100
			),
		} );
	};

	const countLabel =
		technicians.length === 1
			? 'One technician on this site.'
			: `${ technicians.length || 'No' } technicians on this site.`;

	const currency =
		technicians[ 0 ]?.currency ||
		technicians.find( ( t ) => t.currency )?.currency;

	return h(
		'div',
		{ class: 'ts-admin-screen', 'data-screen': 'technicians' },
		h( PageHeader, {
			eyebrow: 'Team',
			title: 'Technicians',
			subtitle: `${ countLabel } Invite teachers and set rates.`,
			actions: allowed
				? [
						{
							id: 'invite',
							label: 'Invite technician',
							variant: 'primary',
							onClick: () => setInviteOpen( true ),
						},
					]
				: [],
		} ),
		h(
			ScreenState,
			{ status, error },
			status === 'ready' && technicians.length === 0
				? h( EmptyState, {
						title: 'No technicians yet',
						description:
							'Invite teachers by email. They get a link to set their hours after accepting.',
						actionLabel: 'Invite technician',
						onAction: () => setInviteOpen( true ),
					} )
				: h(
						'div',
						{ class: 'ts-admin-card ts-admin-card--flush' },
						h(
							'div',
							{ class: 'ts-admin-table-wrap' },
							h(
								'table',
								{ class: 'ts-admin-table' },
								h(
									'thead',
									null,
									h(
										'tr',
										null,
										h( 'th', null, 'Technician' ),
										h( 'th', null, 'Services' ),
										h( 'th', null, 'Rate' ),
										h( 'th', null, 'Status' ),
										h( 'th', null, '' )
									)
								),
								h(
									'tbody',
									null,
									technicians.map( ( technician ) =>
										h(
											'tr',
											{ key: technician.id },
											h(
												'td',
												null,
												h( PersonCell, {
													name: technician.display_name,
													context:
														technician.email || '',
													initials:
														technician.initials,
												} )
											),
											h(
												'td',
												null,
												Array.isArray(
													technician.services
												)
													? technician.services.join(
															', '
														) || '—'
													: '—'
											),
											h(
												'td',
												{ class: 'plumberslot-mono' },
												`${
													( technician.hourly_rate_minor ||
														0 ) / 100
												} ${ technician.currency }`
											),
											h(
												'td',
												null,
												h(
													StatusChip,
													{
														tone: technicianTone(
															technician.status
														),
													},
													technician.status
												)
											),
											h(
												'td',
												null,
												h(
													'div',
													{ class: 'ts-ds__row' },
													h(
														Button,
														{
															size: 'sm',
															variant: 'ghost',
															onClick: () =>
																openEdit(
																	technician
																),
														},
														'Edit'
													),
													technician.status ===
														'invited'
														? h(
																Button,
																{
																	size: 'sm',
																	variant:
																		'secondary',
																	onClick:
																		() =>
																			onResend(
																				technician.id
																			),
																},
																'Resend'
															)
														: null
												)
											)
										)
									)
								)
							)
						),
						h(
							'p',
							{
								class: 'ts-admin__muted',
								style: {
									padding: '12px 20px 16px',
									margin: 0,
								},
							},
							'Technicians set their own weekly hours from /technician-dashboard after accepting the invite.'
						)
					)
		),
		h(
			Modal,
			{
				open: inviteOpen,
				title: 'Invite a technician',
				onClose: () => setInviteOpen( false ),
				primaryLabel: 'Send invite',
				onPrimary: onInvite,
			},
			h(
				'div',
				{ class: 'ts-admin-fields' },
				field( 'Email', email, setEmail, 'email' ),
				field( 'Display name', name, setName, 'text' ),
				field(
					currency ? `Hourly rate (${ currency })` : 'Hourly rate',
					rate,
					setRate,
					'number'
				)
			)
		),
		h(
			Modal,
			{
				open: Boolean( edit ),
				title: edit ? `Edit ${ edit.display_name }` : 'Edit technician',
				onClose: () => setEdit( null ),
				primaryLabel: 'Save',
				onPrimary: onSaveEdit,
			},
			edit
				? h(
						'div',
						{ class: 'ts-admin-fields' },
						field( 'Display name', edit.display_name, ( v ) =>
							setEdit( { ...edit, display_name: v } )
						),
						field(
							edit.currency
								? `Hourly rate (${ edit.currency })`
								: 'Hourly rate',
							edit.rate_major,
							( v ) => setEdit( { ...edit, rate_major: v } ),
							'number'
						),
						h(
							'label',
							{ class: 'ts-admin-field' },
							h( 'span', null, 'Status' ),
							h(
								'select',
								{
									value: edit.status,
									onChange: ( event ) =>
										setEdit( {
											...edit,
											status: event.target.value,
										} ),
								},
								[ 'active', 'invited', 'disabled' ].map(
									( s ) =>
										h( 'option', { key: s, value: s }, s )
								)
							)
						)
					)
				: null
		)
	);
}

function majorToMinor( value ) {
	const n = Number( value );
	if ( ! Number.isFinite( n ) || n < 0 ) {
		return 0;
	}
	return Math.round( n * 100 );
}

function field( label, value, onInput, type = 'text' ) {
	return h(
		'label',
		{ class: 'ts-admin-field' },
		h( 'span', null, label ),
		h( 'input', {
			type,
			value,
			onInput: ( event ) => onInput( event.target.value ),
		} )
	);
}

function technicianTone( status ) {
	if ( status === 'active' ) {
		return 'ok';
	}
	if ( status === 'invited' ) {
		return 'wait';
	}
	return 'off';
}
