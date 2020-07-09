/**
 * WordPress dependencies
 */
import { render, Component } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import '@wordpress/components/build-style/style.css';

/**
 * External dependencies
 */
import {
	APP_ROOT_ID,
	CLOSE_LINK,
	CURRENT_THEME,
	FINISH_LINK,
	OPTIONS_REST_ENDPOINT,
	READER_THEMES_REST_ENDPOINT,
	UPDATES_NONCE,
	USER_FIELD_DEVELOPER_TOOLS_ENABLED,
	USER_REST_ENDPOINT,
} from 'amp-settings'; // From WP inline script.
import PropTypes from 'prop-types';

/**
 * Internal dependencies
 */
import '../css/variables.css';
import '../css/core-components.css';
import '../css/elements.css';
import './style.css';
import { OptionsContextProvider } from '../components/options-context-provider';
import { ReaderThemesContextProvider } from '../components/reader-themes-context-provider';
import { PAGES } from './pages';
import { SetupWizard } from './setup-wizard';
import { NavigationContextProvider } from './components/navigation-context-provider';
import { UserContextProvider } from './components/user-context-provider';
import { ErrorScreen } from './components/error-screen';
import { SiteScanContextProvider } from './components/site-scan-context-provider';
import { ReaderModeOverrideContextProvider } from './components/reader-mode-override';

const { ajaxurl: wpAjaxUrl } = global;

/**
 * Context providers for the application.
 *
 * @param {Object} props Component props.
 * @param {any} props.children Component children.
 */
export function Providers( { children } ) {
	return (
		<OptionsContextProvider
			optionsRestEndpoint={ OPTIONS_REST_ENDPOINT }
		>
			<UserContextProvider
				userOptionDeveloperTools={ USER_FIELD_DEVELOPER_TOOLS_ENABLED }
				userRestEndpoint={ USER_REST_ENDPOINT }
			>
				<NavigationContextProvider pages={ PAGES }>
					<ReaderThemesContextProvider
						currentTheme={ CURRENT_THEME }
						wpAjaxUrl={ wpAjaxUrl }
						readerThemesEndpoint={ READER_THEMES_REST_ENDPOINT }
						updatesNonce={ UPDATES_NONCE }
					>
						<ReaderModeOverrideContextProvider>
							<SiteScanContextProvider>
								{ children }
							</SiteScanContextProvider>
						</ReaderModeOverrideContextProvider>
					</ReaderThemesContextProvider>
				</NavigationContextProvider>
			</UserContextProvider>
		</OptionsContextProvider>
	);
}

Providers.propTypes = {
	children: PropTypes.any,
};

/**
 * Catches errors in the application and displays a fallback screen.
 *
 * @see https://reactjs.org/docs/error-boundaries.html
 */
class ErrorBoundary extends Component {
	static propTypes = {
		children: PropTypes.any,
	}

	constructor( props ) {
		super( props );

		this.state = { error: null };
	}

	componentDidCatch( error ) {
		this.setState( { error } );
	}

	render() {
		const { error } = this.state;

		if ( error ) {
			return (
				<ErrorScreen error={ error } finishLink={ FINISH_LINK } />
			);
		}

		return this.props.children;
	}
}

domReady( () => {
	const root = document.getElementById( APP_ROOT_ID );

	if ( root ) {
		render(
			<ErrorBoundary>
				<Providers>
					<SetupWizard closeLink={ CLOSE_LINK } finishLink={ FINISH_LINK } />
				</Providers>
			</ErrorBoundary>,
			root,
		);
	}
} );
