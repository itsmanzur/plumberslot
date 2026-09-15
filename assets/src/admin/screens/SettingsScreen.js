import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import {
	Button,
	Callout,
	Modal,
	StatusChip,
	Toggle,
	announce,
} from '../../shared';
import { can, getConfig } from '../api/config';
import { get, post } from '../api/client';
import { PageHeader, ScreenState } from '../components/PageHeader';

const BOOKING_TOGGLES = [
	{
		key: 'auto_confirm',
		label: 'Confirm bookings automatically',
		explanation:
			'Turn this off if you would rather approve each request yourself.',
	},
	{
		key: 'allow_customer_reschedule',
		label: 'Let customers reschedule',
		explanation: 'Up to the reschedule window before the lesson starts.',
	},
	{
		key: 'offer_free_estimate',
		label: 'Offer a free estimate',
		explanation: 'One per customer, ever. Applied at checkout.',
	},
	{
		key: 'show_customer_timezone',
		label: "Show times in the customer's own timezone",
		explanation: 'Detected from their browser, with a visible override.',
	},
];

const REMINDER_TOGGLES = [
	{
		key: 'reminder_email_24h',
		label: 'Email the customer 24 hours before',
		explanation: 'And again 1 hour before, with the meeting link.',
	},
	{
		key: 'sms_enabled',
		label: 'Send an SMS 1 hour before',
		explanation: 'Needs an SMS provider under Connections.',
	},
	{
		key: 'notify_technician_on_book',
		label: 'Tell me when someone books',
		explanation: 'Sent to your WordPress account email.',
	},
];

export function SettingsScreen() {
	const allowed = can( 'manageAll' );
	const config = getConfig();
	const [ status, setStatus ] = useState( allowed ? 'loading' : 'error' );
	const [ error, setError ] = useState(
		allowed ? '' : 'Only site managers can change PlumberSlot settings.'
	);
	const [ values, setValues ] = useState( {} );
	const [ auditOpen, setAuditOpen ] = useState( false );
	const [ audit, setAudit ] = useState( [] );
	const [ auditTotal, setAuditTotal ] = useState( 0 );

	useEffect( () => {
		if ( ! allowed ) {
			return;
		}
		( async () => {
			try {
				const data = await get( 'settings' );
				setValues( data || {} );
				setStatus( 'ready' );
			} catch ( err ) {
				setError( err.message || 'Could not load settings.' );
				setStatus( 'error' );
			}
		} )();
	}, [ allowed ] );

	const setBool = ( key, checked ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: checked } ) );
	};

	const save = async ( patch ) => {
		const next = { ...values, ...patch };
		setValues( next );
		await post( 'settings', patch );
		announce( 'Settings saved.' );
		const refresh = Object.keys( patch ).some(
			( key ) =>
				key === 'payments_enabled' ||
				key.includes( 'stripe' ) ||
				key.includes( 'bkash' )
		);
		if ( refresh ) {
			try {
				const data = await get( 'settings' );
				setValues( data || next );
			} catch {
				/* keep optimistic values */
			}
		}
	};

	const openAudit = async () => {
		const data = await get( 'audit', { limit: 50 } );
		setAudit( data.entries || [] );
		setAuditTotal( data.total || 0 );
		setAuditOpen( true );
	};

	const secretField = ( key, label ) =>
		h(
			'label',
			{ class: 'ts-admin-field' },
			h( 'span', null, label ),
			h( 'input', {
				type: 'password',
				value: values[ key ] || '',
				placeholder: values[ key ] ? '********' : '',
				onInput: ( event ) =>
					setValues( ( prev ) => ( {
						...prev,
						[ key ]: event.target.value,
					} ) ),
				onBlur: () => {
					if ( values[ key ] && values[ key ] !== '********' ) {
						save( { [ key ]: values[ key ] } );
					}
				},
			} )
		);

	const textField = ( key, label ) =>
		h(
			'label',
			{ class: 'ts-admin-field' },
			h( 'span', null, label ),
			h( 'input', {
				type: 'text',
				value: values[ key ] || '',
				onInput: ( event ) =>
					setValues( ( prev ) => ( {
						...prev,
						[ key ]: event.target.value,
					} ) ),
				onBlur: () => save( { [ key ]: values[ key ] || '' } ),
			} )
		);

	return h(
		'div',
		{ class: 'ts-admin-screen', 'data-screen': 'settings' },
		h( PageHeader, {
			title: 'Settings',
			subtitle:
				'Eight things most technicians change. Everything else is tucked away below.',
		} ),
		h(
			ScreenState,
			{ status, error },
			h(
				'div',
				{ class: 'ts-admin-two' },
				h(
					'div',
					{ class: 'ts-admin-stack' },
					h(
						'section',
						{ class: 'ts-admin-card' },
						h( 'h2', null, 'Booking' ),
						BOOKING_TOGGLES.map( ( item ) =>
							h( Toggle, {
								key: item.key,
								label: item.label,
								explanation: item.explanation,
								checked: Boolean( values[ item.key ] ),
								onChange: ( checked ) => {
									setBool( item.key, checked );
									save( { [ item.key ]: checked } );
								},
							} )
						)
					),
					h(
						'section',
						{ class: 'ts-admin-card' },
						h( 'h2', null, 'Reminders' ),
						REMINDER_TOGGLES.map( ( item ) =>
							h( Toggle, {
								key: item.key,
								label: item.label,
								explanation: item.explanation,
								checked: Boolean( values[ item.key ] ),
								onChange: ( checked ) => {
									setBool( item.key, checked );
									save( { [ item.key ]: checked } );
								},
							} )
						)
					),
					h(
						'details',
						{ class: 'ts-admin-adv' },
						h(
							'summary',
							null,
							'Advanced',
							h(
								'small',
								null,
								' Slot granularity, cache, hold window, uninstall behaviour'
							)
						),
						h(
							'div',
							{ class: 'ts-admin-fields' },
							selectField(
								'Slot granularity',
								'slot_granularity_minutes',
								values,
								[ 15, 30, 60 ],
								( key, val ) => save( { [ key ]: val } ),
								setValues
							),
							selectField(
								'Slot cache lifetime (seconds)',
								'slot_cache_ttl',
								values,
								[ 300, 900, 3600 ],
								( key, val ) => save( { [ key ]: val } ),
								setValues
							),
							selectField(
								'Booking hold window (minutes)',
								'hold_window_minutes',
								values,
								[ 5, 10, 20 ],
								( key, val ) => save( { [ key ]: val } ),
								setValues
							),
							h( Toggle, {
								label: 'Delete all PlumberSlot data when the plugin is removed',
								explanation:
									'Off by default. Bookings and credits survive a reinstall.',
								checked: Boolean(
									values.delete_data_on_uninstall
								),
								onChange: ( checked ) => {
									setBool(
										'delete_data_on_uninstall',
										checked
									);
									save( {
										delete_data_on_uninstall: checked,
									} );
								},
							} )
						)
					)
				),
				h(
					'div',
					{ class: 'ts-admin-stack' },
					h(
						'section',
						{ class: 'ts-admin-card' },
						h( 'h2', null, 'Security' ),
						h(
							'div',
							{ class: 'ts-admin-kv' },
							h( 'span', null, 'Plugin version' ),
							h(
								'b',
								{ class: 'plumberslot-mono' },
								config.version
							)
						),
						h(
							'div',
							{ class: 'ts-admin-kv' },
							h( 'span', null, 'Known vulnerabilities' ),
							h( 'b', null, 'None' )
						),
						h(
							'div',
							{ class: 'ts-admin-kv' },
							h( 'span', null, 'Audit log' ),
							h( 'b', null, `${ auditTotal || '…' } entries` )
						),
						h(
							Button,
							{
								variant: 'secondary',
								onClick: openAudit,
								style: { marginTop: '12px', width: '100%' },
							},
							'Open audit log'
						)
					),
					h(
						'section',
						{ class: 'ts-admin-card' },
						h( 'h2', { class: 'ts-admin__side-h' }, 'Connections' ),
						paymentsNeedsGateway( values )
							? h(
									Callout,
									{
										tone: 'warn',
										title: 'Online payments need a gateway:',
									},
									'Payments are enabled, but Stripe and bKash are not connected yet. Customers will only see “pay the technician directly” or service plans until you add API keys below.'
							  )
							: null,
						h(
							'dl',
							{ class: 'ts-settings-status' },
							statusRow(
								'Google Meet',
								values.google_client_id
									? 'Connected'
									: 'Not set',
								values.google_client_id ? 'ok' : 'idle'
							),
							statusRow(
								'Stripe',
								paymentsStatus( values ).stripe
									? 'Ready'
									: 'Not set',
								paymentsStatus( values ).stripe ? 'ok' : 'idle'
							),
							statusRow(
								'bKash',
								paymentsStatus( values ).bkash
									? 'Ready'
									: 'Not set',
								paymentsStatus( values ).bkash ? 'ok' : 'idle'
							),
							statusRow(
								'SMS',
								values.sms_enabled ? 'On' : 'Off',
								values.sms_enabled ? 'ok' : 'idle'
							),
							statusRow(
								'Payments',
								paymentsStatusLabel( values ),
								paymentsStatusTone( values )
							)
						),
						h(
							'details',
							{
								class: 'ts-admin-adv',
								style: { marginTop: '14px' },
							},
							h(
								'summary',
								null,
								'API keys & secrets',
								h(
									'small',
									null,
									' Stripe, bKash, Meet, Zoom, SMS'
								)
							),
							h(
								'div',
								{ class: 'ts-admin-fields' },
								h( Toggle, {
									checked: !! values.payments_enabled,
									onChange: ( on ) =>
										save( { payments_enabled: on } ),
									label: 'Online payments enabled',
								} ),
								textField(
									'stripe_publishable_key',
									'Stripe publishable key'
								),
								secretField(
									'stripe_secret_key',
									'Stripe secret key'
								),
								secretField(
									'stripe_webhook_secret',
									'Stripe webhook secret'
								),
								textField( 'bkash_app_key', 'bKash app key' ),
								secretField(
									'bkash_app_secret',
									'bKash app secret'
								),
								textField( 'bkash_username', 'bKash username' ),
								secretField(
									'bkash_password',
									'bKash password'
								),
								h( Toggle, {
									checked: values.bkash_sandbox !== false,
									onChange: ( on ) =>
										save( { bkash_sandbox: on } ),
									label: 'bKash sandbox mode',
								} ),
								textField(
									'google_client_id',
									'Google client ID'
								),
								secretField(
									'google_client_secret',
									'Google client secret'
								),
								textField(
									'zoom_account_id',
									'Zoom account ID'
								),
								textField( 'zoom_client_id', 'Zoom client ID' ),
								secretField(
									'zoom_client_secret',
									'Zoom client secret'
								),
								h( Toggle, {
									checked: !! values.sms_enabled,
									onChange: ( on ) =>
										save( { sms_enabled: on } ),
									label: 'SMS reminders enabled',
								} ),
								secretField( 'sms_api_key', 'SMS API key' ),
								h(
									Callout,
									{ title: 'Note:' },
									'Masked secrets are left unchanged unless you type a new value.'
								)
							)
						)
					)
				)
			)
		),
		h(
			Modal,
			{
				open: auditOpen,
				title: 'Audit log',
				onClose: () => setAuditOpen( false ),
				secondaryLabel: 'Close',
			},
			h(
				'ul',
				{ class: 'ts-admin-list' },
				audit.map( ( row ) =>
					h(
						'li',
						{ key: row.id },
						h(
							'span',
							null,
							`${ row.created_at } · ${ row.actor_name } · ${ row.action }`
						)
					)
				)
			)
		)
	);
}

function paymentsStatus( values = {} ) {
	return (
		values.payments_status || {
			enabled: !! values.payments_enabled,
			stripe: !! values.stripe_publishable_key,
			bkash: !! values.bkash_app_key,
			online_ready: false,
			needs_gateway: !! values.payments_enabled,
		}
	);
}

function paymentsNeedsGateway( values ) {
	return Boolean( paymentsStatus( values ).needs_gateway );
}

function paymentsStatusLabel( values ) {
	const status = paymentsStatus( values );
	if ( ! status.enabled ) {
		return 'Off';
	}
	if ( status.online_ready ) {
		return 'Ready';
	}
	return 'Needs gateway';
}

function paymentsStatusTone( values ) {
	const status = paymentsStatus( values );
	if ( ! status.enabled ) {
		return 'idle';
	}
	if ( status.online_ready ) {
		return 'ok';
	}
	return 'wait';
}

function statusRow( label, value, tone ) {
	return h(
		'div',
		null,
		h( 'dt', null, label ),
		h( 'dd', null, h( StatusChip, { tone }, value ) )
	);
}

function selectField( label, key, values, options, onSave, setValues ) {
	return h(
		'label',
		{ class: 'ts-admin-field' },
		h( 'span', null, label ),
		h(
			'select',
			{
				value: values[ key ] ?? options[ 0 ],
				onChange: ( event ) => {
					const val = Number( event.target.value );
					setValues( ( prev ) => ( { ...prev, [ key ]: val } ) );
					onSave( key, val );
				},
			},
			options.map( ( opt ) =>
				h( 'option', { key: opt, value: opt }, String( opt ) )
			)
		)
	);
}
