/**
 * TutorSlot booking block editor UI.
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
	const { tutor = '', subject = 0 } = attributes;
	const [ tutors, setTutors ] = useState( [] );
	const [ subjects, setSubjects ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const blockProps = useBlockProps( {
		className: 'tutorslot-block-preview',
	} );

	useEffect( () => {
		let alive = true;
		( async () => {
			try {
				const data = await apiFetch( { path: '/tutorslot/v1/tutors' } );
				if ( alive ) {
					setTutors( data.tutors || [] );
				}
			} catch {
				if ( alive ) {
					setTutors( [] );
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
		if ( ! tutor ) {
			setSubjects( [] );
			return undefined;
		}
		const match = tutors.find( ( row ) => row.slug === tutor );
		if ( ! match ) {
			return undefined;
		}
		let alive = true;
		( async () => {
			try {
				const data = await apiFetch( {
					path: `/tutorslot/v1/tutors/${ match.id }/subjects`,
				} );
				if ( alive ) {
					setSubjects( data.subjects || [] );
				}
			} catch {
				if ( alive ) {
					setSubjects( [] );
				}
			}
		} )();
		return () => {
			alive = false;
		};
	}, [ tutor, tutors ] );

	const tutorOptions = [
		{ label: __( 'Select a tutor', 'tutorslot' ), value: '' },
		...tutors.map( ( row ) => ( {
			label: row.display_name,
			value: row.slug,
		} ) ),
	];

	const subjectOptions = [
		{ label: __( 'Any subject', 'tutorslot' ), value: '0' },
		...subjects.map( ( row ) => ( {
			label: row.name,
			value: String( row.id ),
		} ) ),
	];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Booking widget', 'tutorslot' ) }
					initialOpen
				>
					{ loading ? (
						<Spinner />
					) : (
						<>
							<SelectControl
								label={ __( 'Tutor', 'tutorslot' ) }
								value={ tutor }
								options={ tutorOptions }
								onChange={ ( value ) =>
									setAttributes( {
										tutor: value,
										subject: 0,
									} )
								}
							/>
							<SelectControl
								label={ __(
									'Subject (optional)',
									'tutorslot'
								) }
								value={ String( subject || 0 ) }
								options={ subjectOptions }
								onChange={ ( value ) =>
									setAttributes( {
										subject: Number( value ) || 0,
									} )
								}
							/>
							<TextControl
								label={ __( 'Tutor slug', 'tutorslot' ) }
								help={ __(
									'Used if the tutor list is unavailable.',
									'tutorslot'
								) }
								value={ tutor }
								onChange={ ( value ) =>
									setAttributes( { tutor: value } )
								}
							/>
						</>
					) }
				</PanelBody>
			</InspectorControls>
			<div className="tutorslot-block-preview__card">
				<strong>{ __( 'TutorSlot booking', 'tutorslot' ) }</strong>
				<p>
					{ tutor
						? __( 'Tutor:', 'tutorslot' ) + ' ' + tutor
						: __( 'Choose a tutor in the sidebar.', 'tutorslot' ) }
				</p>
			</div>
		</div>
	);
}

registerBlockType( 'tutorslot/booking', {
	edit: Edit,
	save() {
		return null;
	},
} );
