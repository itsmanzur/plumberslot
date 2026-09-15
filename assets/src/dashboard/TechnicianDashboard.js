import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	EmptyState,
	ErrorState,
	Skeleton,
	StatusChip,
	TimetableGrid,
	announce,
	navigateTo,
} from '../shared';
import { cellsToWeek, weekToCells } from '../admin/lib/weekMap';
import { get, getBoot, post, put } from './api';

export function TechnicianDashboard() {
	const boot = getBoot();
	const [ status, setStatus ] = useState( 'loading' );
	const [ error, setError ] = useState( '' );
	const [ jobs, setJobs ] = useState( [] );
	const [ attendanceDue, setAttendanceDue ] = useState( [] );
	const [ technicianId, setTechnicianId ] = useState(
		boot.technicianId || 0
	);
	const [ weekCells, setWeekCells ] = useState( null );
	const [ gridKey, setGridKey ] = useState( 0 );
	const [ noteDrafts, setNoteDrafts ] = useState( {} );
	const [ busyId, setBusyId ] = useState( 0 );

	const load = async () => {
		try {
			const [ teaching, past ] = await Promise.all( [
				get( 'bookings', {
					scope: 'teaching',
					per_page: 50,
					tab: 'upcoming',
				} ),
				get( 'bookings', {
					scope: 'teaching',
					per_page: 50,
					tab: 'past',
					status: 'confirmed',
				} ),
			] );
			const items = teaching.bookings || teaching.items || [];
			const ended = ( past.bookings || past.items || [] ).filter(
				( row ) => hasJobEnded( row.end_utc )
			);
			setJobs( items );
			setAttendanceDue( ended );
			const tid =
				boot.technicianId ||
				items[ 0 ]?.technician_id ||
				ended[ 0 ]?.technician_id ||
				0;
			setTechnicianId( tid );
			if ( tid ) {
				const avail = await get( `availability/${ tid }` );
				setWeekCells( weekToCells( avail.week || [] ) );
				setGridKey( ( k ) => k + 1 );
			} else {
				setWeekCells( null );
			}
			setStatus( 'ready' );
		} catch ( err ) {
			setError( err.message || 'Could not load technician dashboard.' );
			setStatus( 'error' );
		}
	};

	useEffect( () => {
		if ( ! boot.loggedIn ) {
			setStatus( 'login' );
			return;
		}
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const mark = async ( id, attendance ) => {
		setBusyId( id );
		try {
			await post( `bookings/${ id }/attendance`, { status: attendance } );
			announce(
				attendance === 'completed'
					? 'Marked complete.'
					: 'Marked no-show.'
			);
			await load();
		} catch ( err ) {
			setError( err.message );
		} finally {
			setBusyId( 0 );
		}
	};

	const saveNote = async ( id ) => {
		setBusyId( id );
		try {
			await post( `bookings/${ id }/notes`, {
				notes: noteDrafts[ id ] || '',
			} );
			announce( 'Note saved.' );
			await load();
		} catch ( err ) {
			setError( err.message );
		} finally {
			setBusyId( 0 );
		}
	};

	const onSaveWeek = async ( cells ) => {
		if ( ! technicianId ) {
			announce( 'No technician profile is linked to this account yet.' );
			return;
		}
		try {
			await put( `availability/${ technicianId }`, {
				week: cellsToWeek( cells ),
			} );
			setWeekCells( cells );
			setError( '' );
		} catch ( err ) {
			setError( err.message || 'Could not save availability.' );
			announce( err.message || 'Could not save availability.' );
			throw err;
		}
	};

	if ( status === 'login' ) {
		return h(
			'div',
			{ class: 'ts-dash' },
			h(
				Callout,
				{ title: 'Sign in:' },
				'Technicians manage jobs from this page, not wp-admin.'
			),
			h(
				Button,
				{
					onClick: () =>
						navigateTo( boot.loginUrl, { sameOrigin: true } ),
				},
				'Sign in'
			)
		);
	}

	if ( status === 'loading' ) {
		return h( 'div', { class: 'ts-dash' }, h( Skeleton, { lines: 6 } ) );
	}

	if ( status === 'error' ) {
		return h(
			'div',
			{ class: 'ts-dash' },
			h( ErrorState, {
				title: 'Dashboard unavailable',
				description: error,
			} )
		);
	}

	return h(
		'div',
		{ class: 'ts-dash' },
		h(
			'header',
			{ class: 'ts-dash__hero' },
			h(
				'p',
				{ class: 'ts-dash__eyebrow' },
				boot.user?.name || 'Technician'
			),
			h( 'h1', null, 'Your job schedule' )
		),
		error ? h( Callout, { tone: 'warn', title: 'Notice:' }, error ) : null,
		h(
			'div',
			{ class: 'ts-dash__grid ts-dash__grid--technician' },
			h(
				'section',
				{ class: 'ts-dash__panel' },
				h( 'h2', null, 'Upcoming jobs' ),
				jobs.length === 0
					? h( EmptyState, {
							title: 'No jobs booked',
							description:
								'When customers book you, they appear here.',
						} )
					: jobs.map( ( row ) =>
							h(
								'article',
								{
									key: row.id,
									class: 'ts-dash__job ts-dash__job--technician',
								},
								h(
									'div',
									{ class: 'ts-dash__what' },
									h(
										'b',
										null,
										`${ row.service } · ${ row.customer }`
									),
									h(
										'small',
										{ class: 'ts-mono' },
										[ row.start_utc, row.series_label ]
											.filter( Boolean )
											.join( ' · ' )
									)
								),
								h(
									StatusChip,
									{ tone: statusTone( row.status ) },
									row.status
								),
								h(
									'label',
									{ class: 'ts-dash__note-field' },
									h( 'span', null, 'After-job note' ),
									h( 'textarea', {
										rows: 2,
										value:
											noteDrafts[ row.id ] ??
											row.notes ??
											'',
										onInput: ( e ) =>
											setNoteDrafts( ( d ) => ( {
												...d,
												[ row.id ]: e.target.value,
											} ) ),
									} ),
									h(
										Button,
										{
											variant: 'ghost',
											disabled: busyId === row.id,
											onClick: () => saveNote( row.id ),
										},
										'Save note'
									)
								)
							)
						),
				h( 'h2', null, 'Attendance due' ),
				attendanceDue.length === 0
					? h( EmptyState, {
							title: 'No attendance to record',
							description:
								'Completed job times that need an outcome appear here.',
						} )
					: attendanceDue.map( ( row ) =>
							h(
								'article',
								{
									key: `attendance-${ row.id }`,
									class: 'ts-dash__job ts-dash__job--technician',
								},
								h(
									'div',
									{ class: 'ts-dash__what' },
									h(
										'b',
										null,
										`${ row.service } · ${ row.customer }`
									),
									h(
										'small',
										{ class: 'ts-mono' },
										row.start_utc
									)
								),
								h(
									StatusChip,
									{ tone: 'wait' },
									'attendance due'
								),
								h(
									'div',
									{ class: 'ts-dash__actions' },
									h(
										Button,
										{
											variant: 'secondary',
											disabled: busyId === row.id,
											onClick: () =>
												mark( row.id, 'completed' ),
										},
										'Complete'
									),
									h(
										Button,
										{
											variant: 'ghost',
											disabled: busyId === row.id,
											onClick: () =>
												mark( row.id, 'no_show' ),
										},
										'No-show'
									)
								)
							)
						)
			),
			h(
				'section',
				{ class: 'ts-dash__panel' },
				h(
					'div',
					{ class: 'ts-dash__panel-hd' },
					h( 'h2', null, 'Your availability' )
				),
				weekCells
					? h(
							'div',
							{ class: 'ts-dash__avail' },
							h(
								'p',
								{ class: 'ts-dash__hint' },
								'Paint the hours customers can book, then save. This pattern repeats every week.'
							),
							h( TimetableGrid, {
								key: gridKey,
								initial: weekCells,
								onSave: onSaveWeek,
								saveLabel: 'Save hours',
							} )
						)
					: h( EmptyState, {
							title: 'No technician profile yet',
							description:
								'Accept your invite or ask the site manager to finish linking your account.',
						} )
			)
		)
	);
}

function statusTone( status ) {
	if ( status === 'confirmed' || status === 'completed' ) {
		return 'ok';
	}
	if (
		status === 'cancelled' ||
		status === 'no_show' ||
		status === 'payment_expired'
	) {
		return 'off';
	}
	return 'wait';
}

function hasJobEnded( endUtc ) {
	const normalized = String( endUtc || '' )
		.trim()
		.replace( ' ', 'T' );
	const timestamp = Date.parse(
		/[zZ]|[+-]\d{2}:?\d{2}$/.test( normalized )
			? normalized
			: `${ normalized }Z`
	);

	return Number.isFinite( timestamp ) && timestamp <= Date.now();
}
