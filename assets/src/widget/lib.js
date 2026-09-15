export function money( minor, currency = 'USD' ) {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency,
			maximumFractionDigits: 0,
		} ).format( ( Number( minor ) || 0 ) / 100 );
	} catch {
		return `${ ( Number( minor ) || 0 ) / 100 } ${ currency }`;
	}
}

export function detectTimezone() {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
	} catch {
		return 'UTC';
	}
}

export function formatInZone( iso, timeZone, opts = {} ) {
	try {
		return new Intl.DateTimeFormat( undefined, {
			timeZone,
			weekday: 'short',
			day: 'numeric',
			month: 'short',
			hour: '2-digit',
			minute: '2-digit',
			...opts,
		} ).format( new Date( iso ) );
	} catch {
		return iso;
	}
}

export function dayKeyInZone( iso, timeZone ) {
	try {
		return new Intl.DateTimeFormat( 'en-CA', {
			timeZone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		} ).format( new Date( iso ) );
	} catch {
		return iso.slice( 0, 10 );
	}
}

export function buildDayStrip( fromDate, days = 7, timeZone ) {
	const start = new Date( fromDate );
	const items = [];
	for ( let i = 0; i < days; i++ ) {
		const d = new Date( start.getTime() + i * 86400000 );
		const id = new Intl.DateTimeFormat( 'en-CA', {
			timeZone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		} ).format( d );
		items.push( {
			id,
			dow: new Intl.DateTimeFormat( undefined, {
				timeZone,
				weekday: 'short',
			} ).format( d ),
			date: new Intl.DateTimeFormat( undefined, {
				timeZone,
				day: 'numeric',
			} ).format( d ),
			disabled: false,
		} );
	}
	return items;
}

export function icsDownload( {
	title,
	startIso,
	endIso,
	description,
	uid = 'appointment@plumberslot',
} ) {
	const stamp = ( iso ) =>
		new Date( iso )
			.toISOString()
			.replace( /[-:]/g, '' )
			.replace( /\.\d{3}/, '' );
	const body = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//PlumberSlot//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'BEGIN:VEVENT',
		`UID:${ escapeIcsText( uid ) }`,
		`DTSTAMP:${ stamp( new Date().toISOString() ) }`,
		`DTSTART:${ stamp( startIso ) }`,
		`DTEND:${ stamp( endIso ) }`,
		`SUMMARY:${ escapeIcsText( title ) }`,
		`DESCRIPTION:${ escapeIcsText( description || 'PlumberSlot appointment' ) }`,
		'END:VEVENT',
		'END:VCALENDAR',
	].join( '\r\n' );
	const blob = new Blob( [ body ], { type: 'text/calendar' } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = 'plumberslot-appointment.ics';
	a.click();
	window.setTimeout( () => URL.revokeObjectURL( url ), 0 );
}

function escapeIcsText( value ) {
	return String( value )
		.replace( /\\/g, '\\\\' )
		.replace( /\r?\n/g, '\\n' )
		.replace( /,/g, '\\,' )
		.replace( /;/g, '\\;' );
}
