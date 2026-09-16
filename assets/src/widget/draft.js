const DRAFT_KEY = 'plumberslot_booking_draft';

/**
 * @param {number} technicianId
 * @param {Object} draft
 */
export function saveBookingDraft( technicianId, draft ) {
	if ( typeof sessionStorage === 'undefined' || ! technicianId ) {
		return;
	}
	try {
		sessionStorage.setItem(
			DRAFT_KEY,
			JSON.stringify( {
				technicianId: Number( technicianId ),
				serviceId: Number( draft.serviceId ) || 0,
				start: draft.start || '',
				timezone: draft.timezone || '',
				address: normalizeAddress( draft.address ),
				photoIds: normalizePhotoIds( draft.photoIds ),
				emergencyRequested: Boolean( draft.emergencyRequested ),
				step: draft.step || 'confirm',
				savedAt: Date.now(),
			} )
		);
	} catch {
		/* private mode */
	}
}

/**
 * @param {Object} [address]
 * @return {{line1:string,line2:string,city:string,state:string,zip:string}} Address with every field defaulted to an empty string.
 */
function normalizeAddress( address ) {
	const a = address || {};
	return {
		line1: a.line1 || '',
		line2: a.line2 || '',
		city: a.city || '',
		state: a.state || '',
		zip: a.zip || '',
	};
}

/**
 * Uploaded photos are already-persisted attachments (see UploadsController),
 * so the draft only needs to remember which ones the customer picked and the
 * preview URL to show without another round trip.
 *
 * @param {Array} [photoIds]
 * @return {Array<{id:number,url:string}>} Photos with every field defaulted and the list capped at 3.
 */
function normalizePhotoIds( photoIds ) {
	if ( ! Array.isArray( photoIds ) ) {
		return [];
	}
	return photoIds
		.filter( ( p ) => p && Number.isFinite( Number( p.id ) ) )
		.slice( 0, 3 )
		.map( ( p ) => ( { id: Number( p.id ), url: String( p.url || '' ) } ) );
}

/**
 * @param {number} technicianId
 * @return {Object|null} The saved draft, or null when none is available.
 */
export function readBookingDraft( technicianId ) {
	if ( typeof sessionStorage === 'undefined' || ! technicianId ) {
		return null;
	}
	try {
		const raw = sessionStorage.getItem( DRAFT_KEY );
		if ( ! raw ) {
			return null;
		}
		const draft = JSON.parse( raw );
		if ( Number( draft.technicianId ) !== Number( technicianId ) ) {
			return null;
		}
		// Drop drafts older than 2 hours.
		if ( Date.now() - Number( draft.savedAt || 0 ) > 2 * 60 * 60 * 1000 ) {
			clearBookingDraft();
			return null;
		}
		return draft;
	} catch {
		return null;
	}
}

export function clearBookingDraft() {
	if ( typeof sessionStorage === 'undefined' ) {
		return;
	}
	try {
		sessionStorage.removeItem( DRAFT_KEY );
	} catch {
		/* ignore */
	}
}
