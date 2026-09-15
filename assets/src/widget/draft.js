const DRAFT_KEY = 'tutorslot_booking_draft';

/**
 * @param {number} tutorId
 * @param {Object} draft
 */
export function saveBookingDraft( tutorId, draft ) {
	if ( typeof sessionStorage === 'undefined' || ! tutorId ) {
		return;
	}
	try {
		sessionStorage.setItem(
			DRAFT_KEY,
			JSON.stringify( {
				tutorId: Number( tutorId ),
				subjectId: Number( draft.subjectId ) || 0,
				start: draft.start || '',
				timezone: draft.timezone || '',
				step: draft.step || 'confirm',
				savedAt: Date.now(),
			} )
		);
	} catch {
		/* private mode */
	}
}

/**
 * @param {number} tutorId
 * @return {Object|null} The saved draft, or null when none is available.
 */
export function readBookingDraft( tutorId ) {
	if ( typeof sessionStorage === 'undefined' || ! tutorId ) {
		return null;
	}
	try {
		const raw = sessionStorage.getItem( DRAFT_KEY );
		if ( ! raw ) {
			return null;
		}
		const draft = JSON.parse( raw );
		if ( Number( draft.tutorId ) !== Number( tutorId ) ) {
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
