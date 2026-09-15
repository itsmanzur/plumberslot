import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import {
	Button,
	EmptyState,
	Modal,
	StatusChip,
	Toggle,
	announce,
} from '../../shared';
import { can, getConfig } from '../api/config';
import { del, get, patch, post } from '../api/client';
import { PageHeader, ScreenState } from '../components/PageHeader';

const emptyForm = () => ( {
	name: '',
	level: '',
	curriculum: '',
	duration_min: 60,
	price_major: '',
	is_trial: false,
	status: 'active',
} );

export function SubjectsScreen() {
	const config = getConfig();
	const manageTutors = can( 'manageTutors' );
	const [ tutorId, setTutorId ] = useState( config.tutorId || 0 );
	const [ tutors, setTutors ] = useState( [] );
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ rows, setRows ] = useState( [] );
	const [ currency, setCurrency ] = useState( 'USD' );
	const [ modal, setModal ] = useState( null ); // 'create' | 'edit'
	const [ form, setForm ] = useState( emptyForm() );
	const [ editingId, setEditingId ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ deleteTarget, setDeleteTarget ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );

	useEffect( () => {
		if ( ! manageTutors ) {
			return;
		}
		( async () => {
			try {
				const data = await get( 'tutors' );
				const list = data.tutors || [];
				setTutors( list );
				if ( ! tutorId && list.length ) {
					setTutorId( list[ 0 ].id );
				}
			} catch {
				/* keep own tutorId */
			}
		} )();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- mount resolve
	}, [] );

	const load = async () => {
		if ( ! tutorId ) {
			setError(
				'No tutor profile is linked yet. Finish setup first, or pick a tutor.'
			);
			setStatus( 'error' );
			return;
		}
		setStatus( 'loading' );
		try {
			const data = await get( `tutors/${ tutorId }/subjects` );
			setRows( data.subjects || [] );
			setCurrency( data.currency || 'USD' );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load subjects.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tutorId ] );

	const openCreate = () => {
		setEditingId( 0 );
		setForm( emptyForm() );
		setModal( 'create' );
	};

	const openEdit = ( row ) => {
		setEditingId( row.id );
		setForm( {
			name: row.name || '',
			level: row.level || '',
			curriculum: row.curriculum || '',
			duration_min: row.duration_min || 60,
			price_major: String( ( Number( row.price_minor ) || 0 ) / 100 ),
			is_trial: Boolean( row.is_trial ),
			status: row.status || 'active',
		} );
		setModal( 'edit' );
	};

	const onSave = async () => {
		const name = form.name.trim();
		if ( ! name ) {
			announce( 'Subject name is required.' );
			return;
		}
		setSaving( true );
		const payload = {
			name,
			level: form.level.trim(),
			curriculum: form.curriculum.trim(),
			duration_min: Math.max( 15, Number( form.duration_min ) || 60 ),
			price_minor: majorToMinor( form.price_major ),
			is_trial: Boolean( form.is_trial ),
			status: form.status === 'inactive' ? 'inactive' : 'active',
		};
		try {
			if ( modal === 'edit' && editingId ) {
				await patch(
					`tutors/${ tutorId }/subjects/${ editingId }`,
					payload
				);
				announce( 'Subject updated.' );
			} else {
				await post( `tutors/${ tutorId }/subjects`, payload );
				announce( 'Subject added.' );
			}
			setModal( null );
			await load();
		} catch ( err ) {
			announce( err.message || 'Could not save subject.' );
		} finally {
			setSaving( false );
		}
	};

	const onDelete = ( row ) => setDeleteTarget( row );

	const confirmDelete = async () => {
		if ( ! deleteTarget ) {
			return;
		}
		setDeleting( true );
		try {
			await del( `tutors/${ tutorId }/subjects/${ deleteTarget.id }` );
			announce( 'Subject removed.' );
			setDeleteTarget( null );
			await load();
		} catch ( err ) {
			announce( err.message || 'Could not remove subject.' );
		} finally {
			setDeleting( false );
		}
	};

	const countLabel =
		rows.length === 1
			? 'One subject on your booking page.'
			: `${ rows.length || 'No' } subjects on your booking page.`;

	return h(
		'div',
		{ class: 'ts-admin-screen', 'data-screen': 'subjects' },
		h( PageHeader, {
			eyebrow: 'Offerings',
			title: 'Subjects & pricing',
			subtitle: `${ countLabel } Set what parents can book and what each lesson costs.`,
			actions: tutorId
				? [
						{
							id: 'add',
							label: 'Add subject',
							variant: 'primary',
							onClick: openCreate,
						},
				  ]
				: [],
		} ),
		manageTutors && tutors.length > 1
			? h(
					'div',
					{ class: 'ts-admin-card ts-subjects__tutor-pick' },
					h(
						'label',
						{ class: 'ts-admin-field' },
						h( 'span', null, 'Tutor' ),
						h(
							'select',
							{
								value: tutorId,
								onChange: ( event ) =>
									setTutorId( Number( event.target.value ) ),
							},
							tutors.map( ( tutor ) =>
								h(
									'option',
									{ key: tutor.id, value: tutor.id },
									tutor.display_name
								)
							)
						)
					)
			  )
			: null,
		h(
			ScreenState,
			{ status, error },
			status === 'ready' && rows.length === 0
				? h( EmptyState, {
						title: 'No subjects yet',
						description:
							'Add what you teach — name, length, and price. Parents pick one when they book.',
						actionLabel: 'Add subject',
						onAction: openCreate,
				  } )
				: null,
			status === 'ready' && rows.length > 0
				? h(
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
										h( 'th', null, 'Subject' ),
										h( 'th', null, 'Length' ),
										h( 'th', null, 'Price' ),
										h( 'th', null, 'Status' ),
										h( 'th', { 'aria-label': 'Actions' } )
									)
								),
								h(
									'tbody',
									null,
									rows.map( ( row ) =>
										h(
											'tr',
											{ key: row.id },
											h(
												'td',
												null,
												h(
													'div',
													{
														class: 'ts-subjects__name',
													},
													h( 'b', null, row.name ),
													row.level || row.curriculum
														? h(
																'small',
																{
																	class: 'ts-admin__muted',
																},
																[
																	row.level,
																	row.curriculum,
																]
																	.filter(
																		Boolean
																	)
																	.join(
																		' · '
																	)
														  )
														: null,
													row.is_trial
														? h(
																'small',
																{
																	class: 'ts-subjects__trial',
																},
																'Trial'
														  )
														: null
												)
											),
											h(
												'td',
												{ class: 'tutorslot-mono' },
												`${ row.duration_min } min`
											),
											h(
												'td',
												{ class: 'tutorslot-mono' },
												formatMoney(
													row.price_minor,
													row.currency || currency
												)
											),
											h(
												'td',
												null,
												h(
													StatusChip,
													{
														tone:
															row.status ===
															'active'
																? 'ok'
																: 'off',
													},
													row.status
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
																openEdit( row ),
														},
														'Edit'
													),
													h(
														Button,
														{
															size: 'sm',
															variant: 'ghost',
															onClick: () =>
																onDelete( row ),
														},
														'Remove'
													)
												)
											)
										)
									)
								)
							)
						)
				  )
				: null
		),
		h(
			Modal,
			{
				open: Boolean( modal ),
				title: modal === 'edit' ? 'Edit subject' : 'Add subject',
				onClose: () => setModal( null ),
				primaryLabel: saving ? 'Saving…' : 'Save',
				primaryDisabled: saving,
				onPrimary: onSave,
			},
			h(
				'div',
				{ class: 'ts-admin-fields' },
				field( 'Name', form.name, ( v ) =>
					setForm( { ...form, name: v } )
				),
				field( 'Level (optional)', form.level, ( v ) =>
					setForm( { ...form, level: v } )
				),
				field( 'Curriculum (optional)', form.curriculum, ( v ) =>
					setForm( { ...form, curriculum: v } )
				),
				field(
					'Lesson length (minutes)',
					form.duration_min,
					( v ) =>
						setForm( {
							...form,
							duration_min: Number( v ) || 60,
						} ),
					'number'
				),
				field(
					`Price per lesson (${ currency })`,
					form.price_major,
					( v ) => setForm( { ...form, price_major: v } ),
					'number'
				),
				h( Toggle, {
					label: 'Offer as a trial lesson',
					explanation:
						'Trial subjects are marked on the booking page so parents can try you first.',
					checked: form.is_trial,
					onChange: ( checked ) =>
						setForm( { ...form, is_trial: checked } ),
				} ),
				h(
					'label',
					{ class: 'ts-admin-field' },
					h( 'span', null, 'Visibility' ),
					h(
						'select',
						{
							value: form.status,
							onChange: ( event ) =>
								setForm( {
									...form,
									status: event.target.value,
								} ),
						},
						h( 'option', { value: 'active' }, 'Active — bookable' ),
						h(
							'option',
							{ value: 'inactive' },
							'Inactive — hidden'
						)
					)
				)
			)
		),
		h(
			Modal,
			{
				open: Boolean( deleteTarget ),
				title: 'Remove subject?',
				onClose: () => setDeleteTarget( null ),
				primaryLabel: deleting ? 'Removing…' : 'Remove',
				primaryDisabled: deleting,
				onPrimary: confirmDelete,
			},
			deleteTarget
				? `Remove “${ deleteTarget.name }”? Parents will no longer see it when booking.`
				: ''
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

function formatMoney( minor, currency ) {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: currency || 'USD',
			maximumFractionDigits: 2,
		} ).format( ( Number( minor ) || 0 ) / 100 );
	} catch {
		return `${ ( Number( minor ) || 0 ) / 100 } ${ currency || '' }`;
	}
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
