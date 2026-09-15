import { h } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import { Button } from './Button';

export function Modal( {
	open,
	title,
	children,
	onClose,
	primaryLabel,
	onPrimary,
	primaryDisabled = false,
	secondaryLabel = 'Cancel',
} ) {
	const panelRef = useRef( null );

	useEffect( () => {
		if ( ! open || ! panelRef.current ) {
			return undefined;
		}

		const node = panelRef.current;
		const previouslyFocused = node.ownerDocument.activeElement;
		const focusable = node.querySelector(
			'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
		);

		if ( focusable ) {
			focusable.focus();
		}

		const onKey = ( event ) => {
			if ( event.key === 'Escape' ) {
				onClose?.();
			}
		};

		document.addEventListener( 'keydown', onKey );

		return () => {
			document.removeEventListener( 'keydown', onKey );
			if ( previouslyFocused && previouslyFocused.focus ) {
				previouslyFocused.focus();
			}
		};
	}, [ open, onClose ] );

	return h(
		'div',
		{
			class: 'ts-modal-root',
			hidden: ! open,
		},
		h( 'button', {
			type: 'button',
			class: 'ts-modal__backdrop',
			'aria-label': 'Close dialog',
			onClick: onClose,
		} ),
		h(
			'div',
			{
				class: 'ts-modal__panel',
				role: 'dialog',
				'aria-modal': 'true',
				'aria-labelledby': 'ts-modal-title',
				ref: panelRef,
			},
			h(
				'div',
				{ class: 'ts-modal__hd' },
				h( 'h2', { id: 'ts-modal-title' }, title ),
				h(
					'button',
					{
						type: 'button',
						class: 'ts-modal__close',
						onClick: onClose,
						'aria-label': 'Close',
					},
					'×'
				)
			),
			h( 'div', { class: 'ts-modal__body' }, children ),
			h(
				'div',
				{ class: 'ts-modal__actions' },
				h(
					Button,
					{ variant: 'ghost', onClick: onClose },
					secondaryLabel
				),
				primaryLabel && onPrimary
					? h(
							Button,
							{
								onClick: onPrimary,
								disabled: primaryDisabled,
							},
							primaryLabel
					  )
					: null
			)
		)
	);
}
