/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { SimplePanel } from './panel';
import { InputGroup, getCommonValue } from './elements';

function TextPanel( { selectedElements, onSetProperties } ) {
	const content = getCommonValue( selectedElements, 'content' );
	const [ state, setState ] = useState( { content } );
	useEffect( () => {
		setState( { content } );
	}, [ content ] );
	const handleSubmit = ( evt ) => {
		onSetProperties( state );
		evt.preventDefault();
	};
	return (
		<SimplePanel title="Actions" onSubmit={ handleSubmit }>
			<InputGroup
				type="text"
				label="Text content"
				value={ state.content }
				isMultiple={ content === '' }
				onChange={ ( value ) => setState( { ...state, content: value } ) }
			/>
		</SimplePanel>
	);
}

TextPanel.propTypes = {
	selectedElements: PropTypes.array.isRequired,
	onSetProperties: PropTypes.func.isRequired,
};

export default TextPanel;