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
 * @return {{line1:string,line2:string,city:string,state:string,zip:string}}
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
