const WEB_PROTOCOLS = new Set( [ 'http:', 'https:' ] );

/**
 * Normalize a navigation target and reject executable or credentialed URLs.
 *
 * @param {unknown} value              Candidate URL.
 * @param {Object}  options            URL policy.
 * @param {boolean} options.sameOrigin Restrict the target to the current origin.
 * @param {string}  options.base       Explicit base URL, primarily for tests.
 * @return {string} A safe absolute URL, or an empty string when rejected.
 */
export function safeUrl(
	value,
	{ sameOrigin = false, base = currentOrigin() } = {}
) {
	if ( typeof value !== 'string' || ! value.trim() ) {
		return '';
	}

	try {
		const origin = new URL( base ).origin;
		const url = new URL( value.trim(), origin );

		if (
			! WEB_PROTOCOLS.has( url.protocol ) ||
			url.username ||
			url.password ||
			( sameOrigin && url.origin !== origin )
		) {
			return '';
		}

		return url.href;
	} catch {
		return '';
	}
}

/**
 * Navigate only after the target passes the URL policy.
 *
 * @param {unknown} value   Candidate URL.
 * @param {Object}  options safeUrl options.
 * @return {boolean} Whether navigation was started.
 */
export function navigateTo( value, options = {} ) {
	const url = safeUrl( value, options );
	if ( ! url || typeof window === 'undefined' ) {
		return false;
	}

	window.location.assign( url );
	return true;
}

/**
 * Open a new tab only after the target passes the URL policy.
 *
 * @param {unknown} value   Candidate URL.
 * @param {Object}  options safeUrl options.
 * @return {boolean} Whether a window was opened.
 */
export function openUrl( value, options = {} ) {
	const url = safeUrl( value, options );
	if ( ! url || typeof window === 'undefined' ) {
		return false;
	}

	window.open( url, '_blank', 'noopener,noreferrer' );
	return true;
}

function currentOrigin() {
	return typeof window !== 'undefined'
		? window.location.origin
		: 'http://localhost';
}
