/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, PanelRow } from '@wordpress/components';
import { useMemo } from '@wordpress/element';

/**
 * External dependencies
 */

import PropTypes from 'prop-types';

/**
 * Internal dependencies
 */
import './style.css';
import { Selectable } from '../../components/selectable';
import { AMPNotice, NOTICE_TYPE_SUCCESS, NOTICE_TYPE_INFO, NOTICE_TYPE_WARNING, NOTICE_SIZE_SMALL, NOTICE_SIZE_LARGE } from '../../components/amp-notice';
import { Standard } from './svg-standard';
import { Transitional } from './svg-transitional';
import { Reader } from './svg-reader';
import { getSelectionDetails, MOST_RECOMMENDED, RECOMMENDED } from './get-selection-details';

/**
 * An individual mode selection component.
 *
 * @param {Object} props Component props.
 * @param {string|Object} props.compatibility Compatibility content.
 * @param {string|Object} props.illustration An illustration for the selection.
 * @param {Array} props.details Array of strings representing details about the mode and recommendation.
 * @param {Function} props.onChange Callback to select the mode.
 * @param {number} props.recommended Recommendation level. -1: not recommended. 0: good. 1: Most recommended.
 * @param {boolean} props.selected Whether the mode is selected.
 * @param {string} props.title The title for the selection.
 */
export function Selection( { compatibility, id, illustration, details, onChange, recommended, selected, title } ) {
	const { recommendationLevelType, recommendationLevelText } = useMemo( () => {
		switch ( recommended ) {
			case MOST_RECOMMENDED:
				return {
					recommendationLevelText: __( 'The best option for your site.', 'amp' ),
					recommendationLevelType: NOTICE_TYPE_SUCCESS,
				};

			case RECOMMENDED:
				return {
					recommendationLevelText: __( 'A good option for your site.', 'amp' ),
					recommendationLevelType: NOTICE_TYPE_INFO,
				};

			default:
				return {
					recommendationLevelText: __( 'Not recommended for your site.', 'amp' ),
					recommendationLevelType: NOTICE_TYPE_WARNING,
				};
		}
	}, [ recommended ] );

	return (
		<Selectable className="template-mode-selection" selected={ selected }>
			<label htmlFor={ id }>
				<div className="template-mode-selection__input-container">
					<input
						type="radio"
						id={ id }
						checked={ selected }
						onChange={ onChange }
					/>
				</div>
				<div className="template-mode-selection__illustration">
					{ illustration }
				</div>
				<div className="template-mode-selection__description">
					<h2>
						{ title }
					</h2>

					<AMPNotice size={ NOTICE_SIZE_SMALL } type={ recommendationLevelType }>
						{ recommendationLevelText }
					</AMPNotice>
				</div>
			</label>
			<PanelBody title={ __( 'Details', 'amp' ) } initialOpen={ 1 === recommended }>
				<PanelRow>

					<ul>
						{ details.map( ( detail, index ) => (
							<li key={ `${ id }-detail-${ index }` }>
								{ detail }
							</li>
						) ) }
					</ul>
				</PanelRow>
			</PanelBody>
			<PanelBody title={ __( 'Compatibility', 'amp' ) } initialOpen={ false }>
				<PanelRow>
					<AMPNotice size={ NOTICE_SIZE_LARGE } type={ recommendationLevelType }>
						{ __( 'Lorem ipsum dolor sit amet', 'amp' ) }
					</AMPNotice>
					<p>
						{ compatibility }
					</p>
				</PanelRow>
			</PanelBody>
		</Selectable>
	);
}

Selection.propTypes = {
	compatibility: PropTypes.node.isRequired,
	id: PropTypes.string.isRequired,
	illustration: PropTypes.node.isRequired,
	details: PropTypes.arrayOf( PropTypes.string.isRequired ),
	onChange: PropTypes.func.isRequired,
	recommended: PropTypes.oneOf( [ -1, 0, 1 ] ).isRequired,
	selected: PropTypes.bool.isRequired,
	title: PropTypes.string.isRequired,
};

/**
 * The interface for the mode selection screen. Avoids using context for easier testing.
 *
 * @param {Object} props Component props.
 * @param {string} props.currentMode The selected mode.
 * @param {boolean} props.developerToolsOption Whether the user has enabled developer tools.
 * @param {Array} props.pluginIssues The plugin issues found in the site scan.
 * @param {Function} props.setCurrentMode The callback to update the selected mode.
 * @param {Array} props.themeIssues The theme issues found in the site scan.
 */
export function ScreenUI( { currentMode, developerToolsOption, pluginIssues, setCurrentMode, themeIssues } ) {
	const standardId = 'standard-mode';
	const transitionalId = 'transitional-mode';
	const readerId = 'reader-mode';

	const selectionConfig = useMemo( () => {
		return getSelectionDetails(
			{
				userIsTechnical: developerToolsOption === true,
				hasPluginIssues: 0 < pluginIssues.length,
				hasThemeIssues: 0 < themeIssues.length,
			},
		);
	}, [ developerToolsOption, pluginIssues.length, themeIssues.length ] );

	return (
		<form>
			<Selection
				{ ...selectionConfig.standard }
				id={ standardId }
				illustration={ <Standard /> }
				onChange={ () => {
					setCurrentMode( 'standard' );
				} }
				selected={ currentMode === 'standard' }
				title={ __( 'Standard', 'amp' ) }
			/>

			<Selection
				{ ...selectionConfig.transitional }
				id={ transitionalId }
				illustration={ <Transitional /> }
				onChange={ () => {
					setCurrentMode( 'transitional' );
				} }
				selected={ currentMode === 'transitional' }
				title={ __( 'Transitional', 'amp' ) }
			/>

			<Selection
				{ ...selectionConfig.reader }
				id={ readerId }
				illustration={ <Reader /> }
				onChange={ () => {
					setCurrentMode( 'reader' );
				} }
				selected={ currentMode === 'reader' }
				title={ __( 'Reader', 'amp' ) }
			/>
		</form>
	);
}

ScreenUI.propTypes = {
	currentMode: PropTypes.string.isRequired,
	developerToolsOption: PropTypes.bool.isRequired,
	setCurrentMode: PropTypes.func.isRequired,
	pluginIssues: PropTypes.arrayOf( PropTypes.string ).isRequired,
	themeIssues: PropTypes.arrayOf( PropTypes.string ).isRequired,
};
