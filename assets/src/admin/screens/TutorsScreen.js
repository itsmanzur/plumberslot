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

export function TutorsScreen() {
	const allowed = can( 'manageTutors' );
	const [ status, setStatus ] = useState( allowed ? 'loading' : 'error' );
	const [ error, setError ] = useState(
		allowed
			? ''
			: 'You need the manage-tutors capability to open this screen.'
	);
	const [ tutors, setTutors ] = useState( [] );
	const [ inviteOpen, setInviteOpen ] = useState( false );
	const [ email, setEmail ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ rate, setRate ] = useState( '' );
	const [ payout, setPayout ] = useState( 100 );
	const [ edit, setEdit ] = useState( null );

	const load = async () => {
		if ( ! allowed ) {
			return;
		}
		setStatus( 'loading' );
		try {
			const data = await get( 'tutors' );
			setTutors( data.tutors || [] );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load tutors.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- mount-only fetch
	}, [] );

	const onInvite = async () => {
		await post( 'tutors', {
			email,
			display_name: name,
			hourly_rate_minor: majorToMinor( rate ),
			payout_share_pct: Number( payout ) || 100,
		} );
		announce( 'Invitation sent.' );
		setInviteOpen( false );
		setEmail( '' );
		setName( '' );
		setRate( '' );
		load();
	};

	const onResend = async ( id ) => {
		await post( `tutors/${ id }/resend` );
		announce( 'Invitation resent.' );
	};

	const onSaveEdit = async () => {
		if ( ! edit ) {
			return;
		}
		await patch( `tutors/${ edit.id }`, {
			display_name: edit.display_name,
			hourly_rate_minor: majorToMinor( edit.rate_major ),
			payout_share_pct: Number( edit.payout_share_pct ) || 0,
			status: edit.status,
		} );
		announce( 'Tutor updated.' );
		setEdit( null );
		load();
	};

	const openEdit = ( tutor ) => {
		setEdit( {
			...tutor,
			rate_major: String(
				( Number( tutor.hourly_rate_minor ) || 0 ) / 100
			),
		} );
	};

	const countLabel =
		tutors.length === 1
			? 'One tutor on this site.'
			: `${ tutors.length || 'No' } tutors on this site.`;

	const currency =
		tutors[ 0 ]?.currency || tutors.find( ( t ) => t.currency )?.currency;

	return h(
		'div',
		{ class: 'ts-admin-screen', 'data-screen': 'tutors' },
		h( PageHeader, {
			eyebrow: 'Team',
			title: 'Tutors',
			subtitle: `${ countLabel } Invite teachers and set rates.`,
			actions: allowed
				? [
						{
							id: 'invite',
							label: 'Invite tutor',
							variant: 'primary',
							onClick: () => setInviteOpen( true ),
						},
				  ]
				: [],
		} ),
		h(
			ScreenState,
			{ status, error },
			status === 'ready' && tutors.length === 0
				? h( EmptyState, {
						title: 'No tutors yet',
						description:
							'Invite teachers by email. They get a link to set their hours after accepting.',
						actionLabel: 'Invite tutor',
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
										h( 'th', null, 'Tutor' ),
										h( 'th', null, 'Subjects' ),
										h( 'th', null, 'Rate' ),
										h( 'th', null, 'Payout' ),
										h( 'th', null, 'Status' ),
										h( 'th', null, '' )
									)
								),
								h(
									'tbody',
									null,
									tutors.map( ( tutor ) =>
										h(
											'tr',
											{ key: tutor.id },
											h(
												'td',
												null,
												h( PersonCell, {
													name: tutor.display_name,
													context: tutor.email || '',
													initials: tutor.initials,
												} )
											),
											h(
												'td',
												null,
												Array.isArray( tutor.subjects )
													? tutor.subjects.join(
															', '
													  ) || '—'
													: '—'
											),
											h(
												'td',
												{ class: 'tutorslot-mono' },
												`${
													( tutor.hourly_rate_minor ||
														0 ) / 100
												} ${ tutor.currency }`
											),
											h(
												'td',
												null,
												`${ tutor.payout_share_pct }%`
											),
											h(
												'td',
												null,
												h(
													StatusChip,
													{
														tone: tutorTone(
															tutor.status
														),
													},
													tutor.status
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
																	tutor
																),
														},
														'Edit'
													),
													tutor.status === 'invited'
														? h(
																Button,
																{
																	size: 'sm',
																	variant:
																		'secondary',
																	onClick:
																		() =>
																			onResend(
																				tutor.id
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
							'Tutors set their own weekly hours from /tutor-dashboard after accepting the invite.'
						)
				  )
		),
		h(
			Modal,
			{
				open: inviteOpen,
				title: 'Invite a tutor',
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
				),
				field( 'Payout share %', payout, setPayout, 'number' )
			)
		),
		h(
			Modal,
			{
				open: Boolean( edit ),
				title: edit ? `Edit ${ edit.display_name }` : 'Edit tutor',
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
						field(
							'Payout %',
							edit.payout_share_pct,
							( v ) =>
								setEdit( { ...edit, payout_share_pct: v } ),
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

function tutorTone( status ) {
	if ( status === 'active' ) {
		return 'ok';
	}
	if ( status === 'invited' ) {
		return 'wait';
	}
	return 'off';
}
