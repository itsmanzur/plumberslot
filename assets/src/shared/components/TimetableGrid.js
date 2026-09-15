import { h } from 'preact';
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from 'preact/hooks';
import { Button } from './Button';
import { announce } from '../announce';

const DAYS = [
	{ id: 0, short: 'Mon', long: 'Monday' },
	{ id: 1, short: 'Tue', long: 'Tuesday' },
	{ id: 2, short: 'Wed', long: 'Wednesday' },
	{ id: 3, short: 'Thu', long: 'Thursday' },
	{ id: 4, short: 'Fri', long: 'Friday' },
	{ id: 5, short: 'Sat', long: 'Saturday' },
	{ id: 6, short: 'Sun', long: 'Sunday' },
];

const START_HOUR = 8;
const END_HOUR = 20; // exclusive end for last half-hour start at 19:30

function cellKey( day, hour, half ) {
	return `${ day }-${ hour }-${ half }`;
}

function formatTime( hour, half ) {
	return `${ String( hour ).padStart( 2, '0' ) }:${
		half === 0 ? '00' : '30'
	}`;
}

function cloneMap( source ) {
	return { ...source };
}

function mapsEqual( a, b ) {
	const keys = new Set( [ ...Object.keys( a ), ...Object.keys( b ) ] );
	for ( const key of keys ) {
		if ( ( a[ key ] || 'off' ) !== ( b[ key ] || 'off' ) ) {
			return false;
		}
	}
	return true;
}

function isImmutable( state ) {
	return state === 'booked' || state === 'held';
}

function cellAriaLabel( day, hour, half, state, who ) {
	const time = formatTime( hour, half );
	const STATUS = {
		open: 'open',
		held: 'held',
		off: 'closed',
	};

	let status = STATUS[ state ] || 'closed';
	if ( state === 'booked' ) {
		status = who ? `booked by ${ who }` : 'booked';
	}

	return `${ day.long } ${ time }, ${ status }`;
}

function normalizeInitial( initial = {} ) {
	const next = {};
	Object.keys( initial ).forEach( ( key ) => {
		const value = initial[ key ];
		if ( value === 'on' || value === 'open' ) {
			next[ key ] = 'open';
		} else if ( value === 'bk' || value === 'booked' ) {
			next[ key ] = 'booked';
		} else if ( value === 'pd' || value === 'held' ) {
			next[ key ] = 'held';
		} else if ( value === 'off' || value === '' ) {
			/* skip — off is default */
		} else if ( typeof value === 'string' && key.startsWith( 'w' ) ) {
			next[ key ] = value;
		}
	} );
	return next;
}

/**
 * Weekly availability grid: 7 days × 30-minute cells.
 *
 * @param {Object}   props
 * @param {Object}   props.initial     Seed map: "d-h-half" → open|booked|held; "w…" → who label
 * @param {Function} props.onSave      (cells) => void when Save clicked
 * @param {boolean}  props.readOnly
 * @param {number}   props.startHour
 * @param {number}   props.endHour
 * @param {Function} [props.onChange]  Fires after each paint with the cell map
 * @param {string}   [props.saveLabel]
 */
export function TimetableGrid( {
	initial = {},
	onSave,
	onChange,
	readOnly = false,
	startHour = START_HOUR,
	endHour = END_HOUR,
	saveLabel = 'Save availability',
} ) {
	const seed = useMemo( () => normalizeInitial( initial ), [ initial ] );
	const [ cells, setCells ] = useState( () => cloneMap( seed ) );
	const [ baseline, setBaseline ] = useState( () => cloneMap( seed ) );
	const [ saving, setSaving ] = useState( false );
	const painting = useRef( false );
	const paintMode = useRef( 'open' );
	const interactionStart = useRef( 0 );
	const dirty = ! mapsEqual( cells, baseline );

	const rows = useMemo( () => {
		const list = [];
		for ( let hour = startHour; hour < endHour; hour++ ) {
			list.push( { hour, half: 0 } );
			list.push( { hour, half: 1 } );
		}
		return list;
	}, [ startHour, endHour ] );

	const whoFor = useCallback(
		( key ) => cells[ `w${ key }` ] || seed[ `w${ key }` ] || '',
		[ cells, seed ]
	);

	const stateOf = useCallback( ( key ) => cells[ key ] || 'off', [ cells ] );

	const applyPaint = useCallback(
		( key ) => {
			if ( readOnly ) {
				return;
			}

			setCells( ( prev ) => {
				const current = prev[ key ] || 'off';
				if ( isImmutable( current ) ) {
					announce(
						'That slot is booked or held and cannot be changed.'
					);
					return prev;
				}

				if ( interactionStart.current <= 0 ) {
					interactionStart.current = performance.now();
				}

				const next = cloneMap( prev );
				if ( paintMode.current === 'open' ) {
					next[ key ] = 'open';
				} else {
					delete next[ key ];
				}
				onChange?.( next );
				return next;
			} );
		},
		[ onChange, readOnly ]
	);

	useLayoutEffect( () => {
		if ( interactionStart.current <= 0 ) {
			return;
		}

		const end = performance.now();
		performance.measure( 'tutorslot-timetable-interaction', {
			start: interactionStart.current,
			end,
		} );
		interactionStart.current = 0;
	}, [ cells ] );

	const beginPaint = useCallback(
		( key, event ) => {
			if ( readOnly ) {
				return;
			}

			const current = stateOf( key );
			if ( isImmutable( current ) ) {
				announce(
					'That slot is booked or held and cannot be changed.'
				);
				return;
			}

			painting.current = true;
			paintMode.current = current === 'open' ? 'off' : 'open';
			applyPaint( key );
			event.preventDefault();
		},
		[ applyPaint, readOnly, stateOf ]
	);

	useEffect( () => {
		const end = () => {
			painting.current = false;
		};
		window.addEventListener( 'mouseup', end );
		window.addEventListener( 'touchend', end );
		window.addEventListener( 'touchcancel', end );
		return () => {
			window.removeEventListener( 'mouseup', end );
			window.removeEventListener( 'touchend', end );
			window.removeEventListener( 'touchcancel', end );
		};
	}, [] );

	const onKeyToggle = ( key, event ) => {
		if ( event.key !== ' ' && event.key !== 'Enter' ) {
			return;
		}
		event.preventDefault();
		const current = stateOf( key );
		if ( isImmutable( current ) ) {
			announce( 'That slot is booked or held and cannot be changed.' );
			return;
		}
		paintMode.current = current === 'open' ? 'off' : 'open';
		applyPaint( key );
	};

	const handleSave = async () => {
		if ( saving || ! dirty ) {
			return;
		}
		const snapshot = cloneMap( cells );
		setSaving( true );
		try {
			await onSave?.( snapshot );
			setBaseline( snapshot );
			announce( 'Availability saved.' );
		} catch {
			/* Caller announces the failure. Keep dirty state. */
		} finally {
			setSaving( false );
		}
	};

	const handleReset = () => {
		setCells( cloneMap( baseline ) );
		announce( 'Unsaved changes discarded.' );
	};

	return h(
		'div',
		{ class: 'ts-tt-root' },
		h(
			'div',
			{ class: 'ts-tt-wrap' },
			h(
				'table',
				{
					class: 'ts-tt',
					role: 'grid',
					'aria-label': 'Weekly availability timetable',
				},
				h(
					'thead',
					null,
					h(
						'tr',
						null,
						h(
							'th',
							{ class: 'ts-tt__time-h', scope: 'col' },
							'Time'
						),
						DAYS.map( ( day ) =>
							h(
								'th',
								{ key: day.id, scope: 'col' },
								day.short,
								h( 'span', null, day.long.slice( 0, 3 ) )
							)
						)
					)
				),
				h(
					'tbody',
					null,
					rows.map( ( { hour, half } ) =>
						h(
							'tr',
							{ key: `${ hour }-${ half }` },
							h(
								'td',
								{ class: 'ts-tt__time' },
								half === 0 ? formatTime( hour, 0 ) : ''
							),
							DAYS.map( ( day ) => {
								const key = cellKey( day.id, hour, half );
								const state = stateOf( key );
								const locked = isImmutable( state );
								const who = whoFor( key );
								const label = cellAriaLabel(
									day,
									hour,
									half,
									state,
									who
								);

								return h(
									'td',
									{ key: day.id },
									h( 'button', {
										type: 'button',
										class: [
											'ts-tt__cell',
											state === 'open'
												? 'ts-tt__cell--open'
												: '',
											state === 'booked'
												? 'ts-tt__cell--booked'
												: '',
											state === 'held'
												? 'ts-tt__cell--held'
												: '',
										]
											.filter( Boolean )
											.join( ' ' ),
										'data-k': key,
										'data-who':
											state === 'booked'
												? who
												: undefined,
										'aria-label': label,
										'aria-disabled':
											locked || readOnly
												? 'true'
												: 'false',
										tabIndex: 0,
										onMouseDown: ( event ) =>
											beginPaint( key, event ),
										onMouseEnter: () => {
											if ( painting.current ) {
												applyPaint( key );
											}
										},
										onTouchStart: ( event ) => {
											beginPaint( key, event );
										},
										onTouchMove: ( event ) => {
											if ( ! painting.current ) {
												return;
											}
											const touch = event.touches[ 0 ];
											if ( ! touch ) {
												return;
											}
											const el =
												document.elementFromPoint(
													touch.clientX,
													touch.clientY
												);
											const cell =
												el && el.closest
													? el.closest(
															'.ts-tt__cell'
													  )
													: null;
											if ( cell && cell.dataset.k ) {
												applyPaint( cell.dataset.k );
											}
											event.preventDefault();
										},
										onKeyDown: ( event ) =>
											onKeyToggle( key, event ),
									} )
								);
							} )
						)
					)
				)
			)
		),
		h(
			'div',
			{ class: 'ts-tt__legend', 'aria-label': 'Legend' },
			h(
				'span',
				null,
				h( 'i', {
					style: { background: '#fff' },
					'aria-hidden': 'true',
				} ),
				'Closed ○'
			),
			h(
				'span',
				null,
				h( 'i', {
					style: { background: 'var(--ts-highlight)' },
					'aria-hidden': 'true',
				} ),
				'Open ◐'
			),
			h(
				'span',
				null,
				h( 'i', {
					style: { background: 'var(--ts-primary)' },
					'aria-hidden': 'true',
				} ),
				'Booked ●'
			),
			h(
				'span',
				null,
				h( 'i', {
					style: {
						background:
							'repeating-linear-gradient(45deg, var(--ts-warn-wash) 0 5px, #fff 5px 10px)',
					},
					'aria-hidden': 'true',
				} ),
				'Held ▨'
			)
		),
		! readOnly
			? h(
					'div',
					{ class: 'ts-tt__actions' },
					h(
						Button,
						{
							onClick: handleSave,
							dirty,
							disabled: ! dirty || saving,
						},
						saving ? 'Saving…' : saveLabel
					),
					h(
						Button,
						{
							variant: 'ghost',
							onClick: handleReset,
							disabled: ! dirty,
						},
						'Discard'
					),
					dirty
						? h(
								'span',
								{ class: 'ts-tt__dirty', role: 'status' },
								'Unsaved changes'
						  )
						: null
			  )
			: null
	);
}

export { DAYS, cellKey, formatTime, normalizeInitial };
