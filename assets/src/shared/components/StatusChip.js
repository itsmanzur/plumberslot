import { h } from 'preact';

const TONES = {
	idle: { className: '', icon: '○', label: 'Idle' },
	ok: { className: 'ts-chip--ok', icon: '✓', label: 'OK' },
	wait: { className: 'ts-chip--wait', icon: '…', label: 'Waiting' },
	off: { className: 'ts-chip--off', icon: '×', label: 'Off' },
	open: { className: 'ts-chip--open', icon: '◐', label: 'Open' },
	booked: { className: 'ts-chip--booked', icon: '●', label: 'Booked' },
	held: { className: 'ts-chip--held', icon: '▨', label: 'Held' },
};

export function StatusChip( { tone = 'idle', children, className = '' } ) {
	const meta = TONES[ tone ] || TONES.idle;

	return h(
		'span',
		{
			class: [ 'ts-chip', meta.className, className ]
				.filter( Boolean )
				.join( ' ' ),
		},
		h( 'span', { class: 'ts-chip__mark', 'aria-hidden': 'true' } ),
		h(
			'span',
			{ class: 'ts-chip__icon', 'aria-hidden': 'true' },
			meta.icon
		),
		h( 'span', { class: 'ts-chip__text' }, children || meta.label )
	);
}
