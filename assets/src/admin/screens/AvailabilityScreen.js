import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { Button, TimezoneBanner, TimetableGrid, announce } from '../../shared';
import { del, get, post, put } from '../api/client';
import { getConfig } from '../api/config';
import { PageHeader, ScreenState } from '../components/PageHeader';
import { cellsToWeek, weekToCells } from '../lib/weekMap';

export function AvailabilityScreen() {
	const config = getConfig();
	const technicianId = config.technicianId;
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ initialCells, setInitialCells ] = useState( {} );
	const [ gridKey, setGridKey ] = useState( 0 );
	const [ defaults, setDefaults ] = useState( {
		default_lesson_minutes: 60,
		buffer_minutes: 0,
		lead_time_minutes: 0,
	} );
	const [ exceptions, setExceptions ] = useState( [] );
	const [ dirtyDefaults, setDirtyDefaults ] = useState( false );
	const [ exceptionDate, setExceptionDate ] = useState( '' );
	const [ exceptionNote, setExceptionNote ] = useState( '' );

	const load = async () => {
		if ( ! technicianId ) {
			setError(
				'No technician profile is linked to this account yet. Finish setup first.'
			);
			setStatus( 'error' );
			return;
		}
		setStatus( 'loading' );
		try {
			const data = await get( `availability/${ technicianId }` );
			setInitialCells( weekToCells( data.week || [] ) );
			setDefaults(
				data.defaults || {
					default_lesson_minutes: 60,
					buffer_minutes: 0,
					lead_time_minutes: 0,
				}
			);
			setExceptions( data.exceptions || [] );
			setGridKey( ( k ) => k + 1 );
			setDirtyDefaults( false );
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load availability.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ technicianId ] );

	useEffect( () => {
		const onBeforeUnload = ( event ) => {
			if ( dirtyDefaults ) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', onBeforeUnload );
		return () =>
			window.removeEventListener( 'beforeunload', onBeforeUnload );
	}, [ dirtyDefaults ] );

	const onSaveWeek = async ( cells ) => {
		const week = cellsToWeek( cells );
		await put( `availability/${ technicianId }`, { week } );
		announce( 'Availability saved.' );
		setInitialCells( weekToCells( week ) );
	};

	const onSaveDefaults = async () => {
		await post( `availability/${ technicianId }/defaults`, defaults );
		setDirtyDefaults( false );
		announce( 'Appointment defaults saved.' );
	};

	const onAddException = async () => {
		if ( ! exceptionDate ) {
			return;
		}
		await post( `availability/${ technicianId }/exceptions`, {
			on_date: exceptionDate,
			kind: 'closed',
			note: exceptionNote,
		} );
		setExceptionDate( '' );
		setExceptionNote( '' );
		announce( 'Time off added.' );
		await load();
	};

	const onDeleteException = async ( id ) => {
		await del( `availability/${ technicianId }/exceptions/${ id }` );
		announce( 'Time off removed.' );
		await load();
	};

	const initial = initialCells;

	return h(
		'div',
		{ class: 'ts-admin-screen', 'data-screen': 'availability' },
		h( PageHeader, {
			eyebrow: 'Your week',
			title: 'When can customers book you?',
			subtitle:
				'Paint open hours on the grid. Customers see the same shape when they book.',
			actions: [
				{
					id: 'defaults',
					label: 'Save defaults',
					variant: 'primary',
					dirty: dirtyDefaults,
					disabled: ! dirtyDefaults,
					onClick: onSaveDefaults,
				},
			],
		} ),
		h(
			ScreenState,
			{ status, error },
			h(
				'div',
				{ class: 'ts-admin-two ts-avail' },
				h(
					'section',
					{ class: 'ts-admin-card ts-admin-card--flush' },
					h(
						'div',
						{ class: 'ts-admin-card__header' },
						h(
							'div',
							{ class: 'ts-avail__week-nav' },
							h( 'strong', null, 'Weekly pattern' ),
							h(
								'span',
								{ class: 'ts-admin__muted' },
								'Repeats every week'
							)
						),
						h( TimezoneBanner, { timezone: config.timezone } )
					),
					h(
						'div',
						{ class: 'ts-admin-card__body' },
						h( TimetableGrid, {
							key: gridKey,
							initial,
							onSave: onSaveWeek,
						} ),
						h(
							'ul',
							{ class: 'ts-avail__legend' },
							h( 'li', { class: 'is-open' }, 'Open' ),
							h( 'li', { class: 'is-booked' }, 'Booked' ),
							h( 'li', { class: 'is-held' }, 'Held' ),
							h( 'li', { class: 'is-off' }, 'Time off' )
						),
						h(
							'p',
							{ class: 'ts-admin__muted ts-avail__note' },
							'This pattern repeats every week. Use Time off for one-off closures.'
						)
					)
				),
				h(
					'div',
					{ class: 'ts-admin-stack ts-avail__side' },
					h(
						'section',
						{ class: 'ts-admin-card' },
						h(
							'h2',
							{ class: 'ts-admin__side-h' },
							'Appointment defaults'
						),
						h(
							'div',
							{ class: 'ts-admin-fields' },
							h( Field, {
								label: 'Appointment length',
								type: 'number',
								value: defaults.default_lesson_minutes,
								suffix: 'min',
								onInput: ( v ) => {
									setDefaults( {
										...defaults,
										default_lesson_minutes: Number( v ),
									} );
									setDirtyDefaults( true );
								},
							} ),
							h( Field, {
								label: 'Buffer between appointments',
								type: 'number',
								value: defaults.buffer_minutes,
								suffix: 'min',
								onInput: ( v ) => {
									setDefaults( {
										...defaults,
										buffer_minutes: Number( v ),
									} );
									setDirtyDefaults( true );
								},
							} ),
							h( Field, {
								label: 'Minimum notice before booking',
								type: 'number',
								value: defaults.lead_time_minutes,
								suffix: 'min',
								onInput: ( v ) => {
									setDefaults( {
										...defaults,
										lead_time_minutes: Number( v ),
									} );
									setDirtyDefaults( true );
								},
							} )
						)
					),
					h(
						'section',
						{ class: 'ts-admin-card' },
						h( 'h2', { class: 'ts-admin__side-h' }, 'Time off' ),
						h(
							'div',
							{ class: 'ts-admin-fields' },
							h( Field, {
								label: 'Date',
								type: 'date',
								value: exceptionDate,
								onInput: setExceptionDate,
							} ),
							h( Field, {
								label: 'Note',
								type: 'text',
								value: exceptionNote,
								onInput: setExceptionNote,
							} ),
							h(
								Button,
								{ onClick: onAddException, size: 'sm' },
								'Add time off'
							)
						),
						exceptions.length
							? h(
									'ul',
									{ class: 'ts-admin-list' },
									exceptions.map( ( row ) =>
										h(
											'li',
											{ key: row.id },
											h(
												'span',
												null,
												`${ row.on_date }${
													row.note
														? ` — ${ row.note }`
														: ''
												}`
											),
											h(
												Button,
												{
													variant: 'ghost',
													size: 'sm',
													onClick: () =>
														onDeleteException(
															row.id
														),
												},
												'Remove'
											)
										)
									)
								)
							: h(
									'p',
									{ class: 'ts-admin__muted' },
									'No exceptions yet.'
								)
					)
				)
			)
		)
	);
}

function Field( { label, type, value, onInput, suffix = '' } ) {
	return h(
		'label',
		{ class: 'ts-admin-field' },
		h( 'span', null, label ),
		h(
			'div',
			{ class: suffix ? 'ts-admin-field__with-suffix' : '' },
			h( 'input', {
				type,
				value,
				onInput: ( event ) => onInput( event.target.value ),
			} ),
			suffix ? h( 'em', null, suffix ) : null
		)
	);
}
