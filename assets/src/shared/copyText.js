/**
 * Copy text to the clipboard. Falls back when Clipboard API is missing
 * or blocked (common on http://*.local WordPress installs).
 *
 * @param {string} text Text to copy.
 * @return {Promise<void>}
 */
export async function copyText( text ) {
	if ( navigator.clipboard?.writeText ) {
		try {
			await navigator.clipboard.writeText( text );
			return;
		} catch {
			// Insecure context / permission denied — use legacy path.
		}
	}

	const input = document.createElement( 'textarea' );
	input.value = text;
	input.setAttribute( 'readonly', '' );
	input.style.position = 'fixed';
	input.style.left = '-9999px';
	document.body.appendChild( input );
	input.select();
	input.setSelectionRange( 0, input.value.length );

	let ok = false;
	try {
		ok = document.execCommand( 'copy' );
	} finally {
		input.remove();
	}

	if ( ! ok ) {
		throw new Error( 'Copy failed' );
	}
}
