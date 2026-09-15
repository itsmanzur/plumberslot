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
	category: '',
	duration_min: 60,
	price_major: '',
	is_free_estimate: false,
	status: 'active',
} );

export function ServicesScreen() {
	const config = getConfig();
	const manageTechnicians = can( 'manageTechnicians' );
	const [ technicianId, setTechnicianId ] = useState( config.technicianId || 0 );
	const [ technicians, setTechnicians ] = useState( [] );
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
		if ( ! manageTechnicians ) {
			return;
		}
		( async () => {
			try {
				const data = await get( 'technicians' );
				const list = data.technicians || [];
				setTechnicians( list );
				if ( ! technicianId && list.length ) {
					setTechnicianId( list[ 0 ].id );
				}
			} catch {
				/* keep own technicianId */
			}
		} )();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- mount resolve
	}, [] );

	const load = async () => {
		if ( ! technicianId ) {
			setError(
				'No technician profile is linked yet. Finish setup first, or pick a technician.'
			);
			setStatus( 'error' );
			return;
		}
		setStatus( 'loading' );
		try {
			const data = await get( `technicians/${ technicianId }/services` );
			setRows( data.services || [] );
			setCurrency( data.currency || 'USD' );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load services.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ technicianId ] );

	const openCreate = () => {
		setEditingId( 0 );
		setForm( emptyForm() );
		setModal( 'create' );
	};

	const openEdit = ( row ) => {
		setEditingId( row.id );
		setForm( {
			name: row.name || '',
			category: row.category || '',
			duration_min: row.duration_min || 60,
			price_major: String( ( Number( row.price_minor ) || 0 ) / 100 ),
			is_free_estimate: Boolean( row.is_free_estimate ),
			status: row.status || 'active',
		} );
		setModal( 'edit' );
	};

	const onSave = async () => {
		const name = form.name.trim();
		if ( ! name ) {
			announce( 'Service name is required.' );
			return;
		}
		setSaving( true );
		const payload = {
			name,
			category: form.category.trim(),
			duration_min: Math.max( 15, Number( form.duration_min ) || 60 ),
			price_minor: majorToMinor( form.price_major ),
			is_free_estimate: Boolean( form.is_free_estimate ),
			status: form.status === 'inactive' ? 'inactive' : 'active',
		};
		try {
			if ( modal === 'edit' && editingId ) {
				await patch(
					`technicians/${ technicianId }/services/${ editingId }`,
					payload
				);
				announce( 'Service updated.' );
			} else {
				await post( `technicians/${ technicianId }/services`, payload );
				announce( 'Service added.' );
			}
			setModal( null );
			await load();
		} catch ( err ) {
			announce( err.message || 'Could not save service.' );
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
			await del( `technicians/${ technicianId }/services/${ deleteTarget.id }` );
			announce( 'Service removed.' );
			setDeleteTarget( null );
			await load();
		} catch ( err ) {
			announce( err.message || 'Could not remove service.' );
		} finally {
			setDeleting( false );
		}
	};

	const countLabel =
		rows.length === 1
			? 'One service on your booking page.'
			: `${ rows.length || 'No' } services on your booking page.`;

	return h(
		'div',
		{ class: 'ts-admin-screen', 'data-screen': 'services' },
		h( PageHeader, {
			eyebrow: 'Offerings',
			title: 'Services & pricing',
			subtitle: `${ countLabel } Set what customers can book and what each job costs.`,
			actions: technicianId
				? [
						{
							id: 'add',
							label: 'Add service',
							variant: 'primary',
							onClick: openCreate,
						},
				  ]
				: [],
		} ),
		manageTechnicians && technicians.length > 1
			? h(
					'div',
					{ class: 'ts-admin-card ts-services__technician-pick' },
					h(
						'label',
						{ class: 'ts-admin-field' },
						h( 'span', null, 'Technician' ),
						h(
							'select',
							{
								value: technicianId,
								onChange: ( event ) =>
									setTechnicianId( Number( event.target.value ) ),
							},
							technicians.map( ( technician ) =>
								h(
									'option',
									{ key: technician.id, value: technician.id },
									technician.display_name
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
						title: 'No services yet',
						description:
							'Add what you teach — name, length, and price. Parents pick one when they book.',
						actionLabel: 'Add service',
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
										h( 'th', null, 'Service' ),
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
														class: 'ts-services__name',
													},
													h( 'b', null, row.name ),
													row.category
														? h(
																'small',
																{
																	class: 'ts-admin__muted',
																},
																row.category
														  )
														: null,
													row.is_free_estimate
														? h(
																'small',
																{
																	class: 'ts-services__free-estimate',
																},
																'Free estimate'
														  )
														: null
												)
											),
											h(
												'td',
												{ class: 'plumberslot-mono' },
												`${ row.duration_min } min`
											),
											h(
												'td',
												{ class: 'plumberslot-mono' },
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
				title: modal === 'edit' ? 'Edit service' : 'Add service',
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
				field( 'Category (optional)', form.category, ( v ) =>
					setForm( { ...form, category: v } )
				),
				field(
					'Appointment length (minutes)',
					form.duration_min,
					( v ) =>
						setForm( {
							...form,
							duration_min: Number( v ) || 60,
						} ),
					'number'
				),
				field(
					`Price per appointment (${ currency })`,
					form.price_major,
					( v ) => setForm( { ...form, price_major: v } ),
					'number'
				),
				h( Toggle, {
					label: 'Offer as a free estimate',
					explanation:
						'Free-estimate services are marked on the booking page so customers can try you first.',
					checked: form.is_free_estimate,
					onChange: ( checked ) =>
						setForm( { ...form, is_free_estimate: checked } ),
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
				title: 'Remove service?',
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
