/**
 * Announce a short status to screen readers and show a brief toast.
 */

let region;
let hideTimer;

function ensureRegion() {
	if ( region || typeof document === 'undefined' ) {
		return region;
	}

	region = document.createElement( 'div' );
	region.className = 'ts-live';
	region.setAttribute( 'role', 'status' );
	region.setAttribute( 'aria-live', 'polite' );
	region.setAttribute( 'aria-atomic', 'true' );
	document.body.appendChild( region );

	return region;
}

export function announce( message ) {
	const node = ensureRegion();

	if ( ! node || ! message ) {
		return;
	}

	window.clearTimeout( hideTimer );
	node.textContent = '';
	node.classList.remove( 'is-visible' );

	window.setTimeout( () => {
		node.textContent = message;
		node.classList.add( 'is-visible' );
		hideTimer = window.setTimeout( () => {
			node.classList.remove( 'is-visible' );
			node.textContent = '';
		}, 2800 );
	}, 20 );
}
