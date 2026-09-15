/**
 * UI grid uses Mon=0 … Sun=6 (reference mockup).
 * Backend / SlotEngine uses Sun=0 … Sat=6 (PHP date('w')).
 */

export function uiDayToWeekday( uiDay ) {
	return ( uiDay + 1 ) % 7;
}

export function weekdayToUiDay( weekday ) {
	return ( weekday + 6 ) % 7;
}

/**
 * Expand weekly rules into TimetableGrid seed cells (30-min open).
 *
 * @param {Array<{weekday:number,start_min:number,end_min:number}>} week
 */
export function weekToCells( week = [] ) {
	const cells = {};

	week.forEach( ( rule ) => {
		const uiDay = weekdayToUiDay( Number( rule.weekday ) );
		for (
			let min = Number( rule.start_min );
			min < Number( rule.end_min );
			min += 30
		) {
			const hour = Math.floor( min / 60 );
			const half = min % 60 >= 30 ? 1 : 0;
			cells[ `${ uiDay }-${ hour }-${ half }` ] = 'open';
		}
	} );

	return cells;
}

/**
 * Collapse open cells into contiguous weekday blocks for PUT /availability.
 *
 * @param {Object} cells     TimetableGrid snapshot
 * @param {number} startHour
 * @param {number} endHour
 */
export function cellsToWeek( cells = {}, startHour = 8, endHour = 20 ) {
	const week = [];

	for ( let uiDay = 0; uiDay < 7; uiDay++ ) {
		const mins = [];

		for ( let hour = startHour; hour < endHour; hour++ ) {
			for ( let half = 0; half < 2; half++ ) {
				const key = `${ uiDay }-${ hour }-${ half }`;
				if ( cells[ key ] === 'open' ) {
					mins.push( hour * 60 + half * 30 );
				}
			}
		}

		mins.sort( ( a, b ) => a - b );
		let i = 0;
		while ( i < mins.length ) {
			const start = mins[ i ];
			let end = start + 30;
			i += 1;
			while ( i < mins.length && mins[ i ] === end ) {
				end += 30;
				i += 1;
			}
			week.push( {
				weekday: uiDayToWeekday( uiDay ),
				start_min: start,
				end_min: end,
			} );
		}
	}

	return week;
}

/** Weekday-evening shortcut: Mon–Fri 15:00–19:00 */
export function shortcutWeekdayEvenings() {
	const cells = {};
	for ( let d = 0; d < 5; d++ ) {
		for ( let hour = 15; hour < 19; hour++ ) {
			cells[ `${ d }-${ hour }-0` ] = 'open';
			cells[ `${ d }-${ hour }-1` ] = 'open';
		}
	}
	return cells;
}

/** Weekend mornings: Sat–Sun 09:00–12:00 */
export function shortcutWeekendMornings() {
	const cells = {};
	for ( let d = 5; d < 7; d++ ) {
		for ( let hour = 9; hour < 12; hour++ ) {
			cells[ `${ d }-${ hour }-0` ] = 'open';
			cells[ `${ d }-${ hour }-1` ] = 'open';
		}
	}
	return cells;
}
