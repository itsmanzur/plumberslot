const LISTBOX_KEYS = new Set( [
	'ArrowDown',
	'ArrowRight',
	'ArrowUp',
	'ArrowLeft',
	'Home',
	'End',
] );

export function handleListboxKeyDown(
	event,
	{ items, currentIndex, isDisabled = () => false, onSelect }
) {
	if ( ! LISTBOX_KEYS.has( event.key ) ) {
		return;
	}

	const enabledIndexes = items
		.map( ( item, index ) => ( isDisabled( item ) ? -1 : index ) )
		.filter( ( index ) => index >= 0 );

	if ( ! enabledIndexes.length ) {
		return;
	}

	event.preventDefault();
	const enabledPosition = enabledIndexes.indexOf( currentIndex );
	let targetPosition = enabledPosition >= 0 ? enabledPosition : 0;

	if ( event.key === 'Home' ) {
		targetPosition = 0;
	} else if ( event.key === 'End' ) {
		targetPosition = enabledIndexes.length - 1;
	} else if ( event.key === 'ArrowDown' || event.key === 'ArrowRight' ) {
		targetPosition = ( targetPosition + 1 ) % enabledIndexes.length;
	} else {
		targetPosition =
			( targetPosition - 1 + enabledIndexes.length ) %
			enabledIndexes.length;
	}

	const targetIndex = enabledIndexes[ targetPosition ];
	onSelect( items[ targetIndex ] );
	event.currentTarget
		.closest( '[role="listbox"]' )
		?.querySelectorAll( '[role="option"]' )
		[ targetIndex ]?.focus();
}
