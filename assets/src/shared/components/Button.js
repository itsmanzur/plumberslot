import { h } from 'preact';

const VARIANTS = {
	primary: 'ts-btn--primary',
	secondary: 'ts-btn--secondary',
	ghost: 'ts-btn--ghost',
	danger: 'ts-btn--danger',
};

export function Button( {
	children,
	variant = 'primary',
	size = 'md',
	dirty = false,
	className = '',
	type = 'button',
	...rest
} ) {
	const classes = [
		'ts-btn',
		VARIANTS[ variant ] || VARIANTS.primary,
		size === 'sm' ? 'ts-btn--sm' : '',
		dirty ? 'ts-btn--dirty' : '',
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	return h(
		'button',
		{
			type,
			class: classes,
			...rest,
		},
		children
	);
}
