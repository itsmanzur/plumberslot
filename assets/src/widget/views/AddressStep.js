import { h } from 'preact';
import { useState } from 'preact/hooks';
import { Button, Callout, WizardRail } from '../../shared';
import { uploadPhoto } from '../api';
import { RAIL } from './ServiceStep';

const MAX_PHOTOS = 3;
const MAX_PHOTO_BYTES = 8 * 1024 * 1024;
const ALLOWED_PHOTO_TYPES = [ 'image/jpeg', 'image/png', 'image/webp' ];

/**
 * Collects the job-site address. Plumbing is on-site work, unlike the
 * remote tutoring this widget was forked from, so a booking is not
 * complete without knowing where the technician needs to show up.
 *
 * @param {Object}   props
 * @param {Object}   props.address            { line1, line2, city, state, zip }
 * @param {Function} props.onChange           ( nextAddress ) => void
 * @param {Array}    [props.photoIds]         Uploaded photos so far, as { id, url }.
 * @param {Function} [props.onPhotoIdsChange] ( nextPhotoIds ) => void
 * @param {Function} props.onContinue
 * @param {Function} props.onBack
 * @param {string[]} [props.serviceAreaZips]  Allowed ZIPs; empty/absent means unrestricted.
 */
export function AddressStep( {
	address,
	onChange,
	photoIds = [],
	onPhotoIdsChange,
	onContinue,
	onBack,
	serviceAreaZips = [],
} ) {
	const value = address || {};
	const [ uploading, setUploading ] = useState( false );
	const [ photoError, setPhotoError ] = useState( '' );

	const addPhoto = async ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];
		// Let the same input be used again for the next photo.
		event.target.value = '';
		if ( ! file ) {
			return;
		}
		if ( ! ALLOWED_PHOTO_TYPES.includes( file.type ) ) {
			setPhotoError( 'Photos must be a JPEG, PNG or WebP image.' );
			return;
		}
		if ( file.size > MAX_PHOTO_BYTES ) {
			setPhotoError( 'Photos must be 8 MB or smaller.' );
			return;
		}
		setPhotoError( '' );
		setUploading( true );
		try {
			const result = await uploadPhoto( file );
			onPhotoIdsChange?.( [
				...photoIds,
				{ id: result.id, url: result.url },
			] );
		} catch ( err ) {
			setPhotoError( err.message || 'Could not upload that photo.' );
		} finally {
			setUploading( false );
		}
	};

	const removePhoto = ( id ) => {
		// Drops the reference only; the attachment itself is left for
		// PendingPhotoCleanup to remove if it never ends up on a booking.
		onPhotoIdsChange?.( photoIds.filter( ( p ) => p.id !== id ) );
	};

	const set = ( field ) => ( event ) => {
		onChange( { ...value, [ field ]: event.target.value } );
	};

	const isComplete =
		Boolean( ( value.line1 || '' ).trim() ) &&
		Boolean( ( value.city || '' ).trim() ) &&
		Boolean( ( value.state || '' ).trim() ) &&
		Boolean( ( value.zip || '' ).trim() );

	const zipEntered = ( value.zip || '' ).trim();
	const outsideArea =
		serviceAreaZips.length > 0 &&
		zipEntered.length >= 5 &&
		! serviceAreaZips.includes( zipEntered.toUpperCase() );

	return h(
		'div',
		{ class: 'ts-book ts-book--step' },
		h(
			'div',
			{ class: 'ts-book__hd' },
			h( WizardRail, { steps: RAIL, current: 2 } )
		),
		h(
			'div',
			{ class: 'ts-book__body' },
			h( 'h2', null, 'Where is the job?' ),
			h(
				'p',
				{ class: 'ts-book__sub' },
				'Tell us the service address so the technician knows where to go.'
			),
			h(
				'div',
				{ class: 'ts-book__fields' },
				h(
					'label',
					{ class: 'ts-book__field' },
					h(
						'span',
						null,
						'Street address',
						h( 'small', null, 'Required' )
					),
					h( 'input', {
						type: 'text',
						autocomplete: 'address-line1',
						value: value.line1 || '',
						placeholder: '123 Main St',
						onInput: set( 'line1' ),
					} )
				),
				h(
					'label',
					{ class: 'ts-book__field' },
					h(
						'span',
						null,
						'Apt, suite, unit',
						h( 'small', null, 'Optional' )
					),
					h( 'input', {
						type: 'text',
						autocomplete: 'address-line2',
						value: value.line2 || '',
						placeholder: 'Apt 4B',
						onInput: set( 'line2' ),
					} )
				),
				h(
					'label',
					{ class: 'ts-book__field' },
					h( 'span', null, 'City', h( 'small', null, 'Required' ) ),
					h( 'input', {
						type: 'text',
						autocomplete: 'address-level2',
						value: value.city || '',
						placeholder: 'Springfield',
						onInput: set( 'city' ),
					} )
				),
				h(
					'div',
					{ class: 'ts-book__fields ts-book__fields--two' },
					h(
						'label',
						{ class: 'ts-book__field' },
						h(
							'span',
							null,
							'State',
							h( 'small', null, 'Required' )
						),
						h( 'input', {
							type: 'text',
							autocomplete: 'address-level1',
							value: value.state || '',
							placeholder: 'CA',
							onInput: set( 'state' ),
						} )
					),
					h(
						'label',
						{ class: 'ts-book__field' },
						h(
							'span',
							null,
							'ZIP / postal code',
							h( 'small', null, 'Required' )
						),
						h( 'input', {
							type: 'text',
							autocomplete: 'postal-code',
							value: value.zip || '',
							placeholder: '90210',
							onInput: set( 'zip' ),
						} )
					)
				),
				h(
					'label',
					{ class: 'ts-book__field' },
					h(
						'span',
						null,
						'Mobile number',
						h( 'small', null, 'Optional — for SMS updates' )
					),
					h( 'input', {
						type: 'tel',
						autocomplete: 'tel',
						value: value.mobile || '',
						placeholder: '+1 555 123 4567',
						onInput: set( 'mobile' ),
					} )
				),
				outsideArea
					? h(
							Callout,
							{
								tone: 'warn',
								title: 'Outside our service area:',
							},
							'That ZIP code is outside the area we currently serve. Please call or email us directly to check availability before booking.'
						)
					: null
			),
			h(
				'div',
				{ class: 'ts-book__fields ts-book__photos-section' },
				h(
					'label',
					{ class: 'ts-book__field' },
					h(
						'span',
						null,
						'Add a photo of the problem',
						h( 'small', null, 'Optional' )
					),
					h(
						'p',
						{ class: 'ts-book__sub' },
						'A photo of the leak, fixture or nameplate helps the technician come prepared. Up to 3.'
					)
				),
				photoIds.length
					? h(
							'div',
							{ class: 'ts-book__photos' },
							photoIds.map( ( photo ) =>
								h(
									'div',
									{ key: photo.id, class: 'ts-book__photo' },
									h( 'img', { src: photo.url, alt: '' } ),
									h(
										'button',
										{
											type: 'button',
											class: 'ts-book__photo-remove',
											'aria-label': 'Remove photo',
											onClick: () =>
												removePhoto( photo.id ),
										},
										'×'
									)
								)
							)
						)
					: null,
				photoError
					? h(
							Callout,
							{
								tone: 'danger',
								title: 'Could not add that photo:',
							},
							photoError
						)
					: null,
				photoIds.length < MAX_PHOTOS
					? h(
							'label',
							{ class: 'ts-book__field ts-book__photo-add' },
							h(
								'span',
								null,
								photoIds.length
									? 'Add another photo'
									: 'Choose a photo'
							),
							h( 'input', {
								type: 'file',
								accept: 'image/jpeg,image/png,image/webp',
								disabled: uploading,
								onChange: addPhoto,
							} ),
							uploading
								? h(
										'small',
										{ class: 'ts-book__photo-status' },
										'Uploading…'
									)
								: null
						)
					: null
			)
		),
		h(
			'footer',
			{ class: 'ts-book__ft' },
			h( Button, { variant: 'ghost', onClick: onBack }, '← Back' ),
			h(
				Button,
				{ disabled: ! isComplete || outsideArea, onClick: onContinue },
				'Continue →'
			)
		)
	);
}
