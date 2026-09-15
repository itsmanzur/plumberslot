/**
 * PlumberSlot booking block editor UI.
 */

import './editor.css';

/* eslint-disable import/no-unresolved -- provided as WP script dependencies */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
/* eslint-enable import/no-unresolved */
function Edit( { attributes, setAttributes } ) {
	const { technician = '', service = 0 } = attributes;
	const [ technicians, setTechnicians ] = useState( [] );
	const [ services, setServices ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const blockProps = useBlockProps( {
		className: 'plumberslot-block-preview',
	} );

	useEffect( () => {
		let alive = true;
		( async () => {
			try {
				const data = await apiFetch( {
					path: '/plumberslot/v1/technicians',
				} );
				if ( alive ) {
					setTechnicians( data.technicians || [] );
				}
			} catch {
				if ( alive ) {
					setTechnicians( [] );
				}
			} finally {
				if ( alive ) {
					setLoading( false );
				}
			}
		} )();
		return () => {
			alive = false;
		};
	}, [] );

	useEffect( () => {
		if ( ! technician ) {
			setServices( [] );
			return undefined;
		}
		const match = technicians.find( ( row ) => row.slug === technician );
		if ( ! match ) {
			return undefined;
		}
		let alive = true;
		( async () => {
			try {
				const data = await apiFetch( {
					path: `/plumberslot/v1/technicians/${ match.id }/services`,
				} );
				if ( alive ) {
					setServices( data.services || [] );
				}
			} catch {
				if ( alive ) {
					setServices( [] );
				}
			}
		} )();
		return () => {
			alive = false;
		};
	}, [ technician, technicians ] );

	const technicianOptions = [
		{ label: __( 'Select a technician', 'plumberslot' ), value: '' },
		...technicians.map( ( row ) => ( {
			label: row.display_name,
			value: row.slug,
		} ) ),
	];

	const serviceOptions = [
		{ label: __( 'Any service', 'plumberslot' ), value: '0' },
		...services.map( ( row ) => ( {
			label: row.name,
			value: String( row.id ),
		} ) ),
	];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Booking widget', 'plumberslot' ) }
					initialOpen
				>
					{ loading ? (
						<Spinner />
					) : (
						<>
							<SelectControl
								label={ __( 'Technician', 'plumberslot' ) }
								value={ technician }
								options={ technicianOptions }
								onChange={ ( value ) =>
									setAttributes( {
										technician: value,
										service: 0,
									} )
								}
							/>
							<SelectControl
								label={ __(
									'Service (optional)',
									'plumberslot'
								) }
								value={ String( service || 0 ) }
								options={ serviceOptions }
								onChange={ ( value ) =>
									setAttributes( {
										service: Number( value ) || 0,
									} )
								}
							/>
							<TextControl
								label={ __( 'Technician slug', 'plumberslot' ) }
								help={ __(
									'Used if the technician list is unavailable.',
									'plumberslot'
								) }
								value={ technician }
								onChange={ ( value ) =>
									setAttributes( { technician: value } )
								}
							/>
						</>
					) }
				</PanelBody>
			</InspectorControls>
			<div className="plumberslot-block-preview__card">
				<strong>{ __( 'PlumberSlot booking', 'plumberslot' ) }</strong>
				<p>
					{ technician
						? __( 'Technician:', 'plumberslot' ) + ' ' + technician
						: __(
								'Choose a technician in the sidebar.',
								'plumberslot'
							) }
				</p>
			</div>
		</div>
	);
}

registerBlockType( 'plumberslot/booking', {
	edit: Edit,
	save() {
		return null;
	},
} );
