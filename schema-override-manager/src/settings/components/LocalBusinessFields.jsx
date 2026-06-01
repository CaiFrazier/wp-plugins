import { TextControl, SelectControl, PanelBody, PanelRow, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const SUBTYPES = [
	{ label: __( 'LocalBusiness (generic)', 'schema-override-manager' ), value: 'LocalBusiness' },
	{ label: 'Restaurant',    value: 'Restaurant' },
	{ label: 'BarOrPub',     value: 'BarOrPub' },
	{ label: 'Bakery',       value: 'Bakery' },
	{ label: 'CafeOrCoffeeShop', value: 'CafeOrCoffeeShop' },
	{ label: 'MedicalBusiness', value: 'MedicalBusiness' },
	{ label: 'Dentist',      value: 'Dentist' },
	{ label: 'Physician',    value: 'Physician' },
	{ label: 'LegalService', value: 'LegalService' },
	{ label: 'Attorney',     value: 'Attorney' },
	{ label: 'FinancialService', value: 'FinancialService' },
	{ label: 'RealEstateAgent', value: 'RealEstateAgent' },
	{ label: 'AutoDealer',   value: 'AutoDealer' },
	{ label: 'HairSalon',    value: 'HairSalon' },
	{ label: 'Store',        value: 'Store' },
	{ label: 'HotelOrMotel', value: 'HotelOrMotel' },
];

const DAYS = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];

export default function LocalBusinessFields( { data, onChange } ) {
	const update = ( key, value ) => onChange( { ...data, [ key ]: value } );
	const address = data.address ?? {};
	const geo     = data.geo ?? {};
	const hours   = data.openingHoursSpecification ?? [];

	const updateAddress = ( key, value ) => update( 'address', { ...address, [ key ]: value } );
	const updateGeo     = ( key, value ) => update( 'geo', { ...geo, [ key ]: value } );

	const addHours = () => update( 'openingHoursSpecification', [
		...hours,
		{ dayOfWeek: 'Monday', opens: '09:00', closes: '17:00' },
	] );

	const removeHours = ( idx ) => update(
		'openingHoursSpecification',
		hours.filter( ( _, i ) => i !== idx )
	);

	const updateHours = ( idx, key, value ) => {
		const next = hours.map( ( h, i ) => i === idx ? { ...h, [ key ]: value } : h );
		update( 'openingHoursSpecification', next );
	};

	return (
		<>
			<PanelBody title={ __( 'LocalBusiness Details', 'schema-override-manager' ) } initialOpen>
				<SelectControl
					label={ __( 'Business Subtype', 'schema-override-manager' ) }
					value={ data.subtype ?? 'LocalBusiness' }
					options={ SUBTYPES }
					onChange={ v => update( 'subtype', v ) }
				/>
				<TextControl
					label={ __( 'Price Range', 'schema-override-manager' ) }
					value={ data.priceRange ?? '' }
					onChange={ v => update( 'priceRange', v ) }
					help="e.g. $$, $–$$$"
				/>
			</PanelBody>

			<PanelBody title={ __( 'Address', 'schema-override-manager' ) } initialOpen={ false }>
				{ [ [ 'streetAddress', __( 'Street Address', 'schema-override-manager' ) ],
					[ 'addressLocality', __( 'City', 'schema-override-manager' ) ],
					[ 'addressRegion', __( 'State / Region', 'schema-override-manager' ) ],
					[ 'postalCode', __( 'Zip / Postal Code', 'schema-override-manager' ) ],
					[ 'addressCountry', __( 'Country Code (e.g. US)', 'schema-override-manager' ) ],
				].map( ( [ key, label ] ) => (
					<TextControl
						key={ key }
						label={ label }
						value={ address[ key ] ?? '' }
						onChange={ v => updateAddress( key, v ) }
					/>
				) ) }
			</PanelBody>

			<PanelBody title={ __( 'Geo Coordinates', 'schema-override-manager' ) } initialOpen={ false }>
				<TextControl
					label={ __( 'Latitude', 'schema-override-manager' ) }
					value={ geo.latitude ?? '' }
					onChange={ v => updateGeo( 'latitude', v ) }
				/>
				<TextControl
					label={ __( 'Longitude', 'schema-override-manager' ) }
					value={ geo.longitude ?? '' }
					onChange={ v => updateGeo( 'longitude', v ) }
				/>
			</PanelBody>

			<PanelBody title={ __( 'Opening Hours', 'schema-override-manager' ) } initialOpen={ false }>
				{ hours.map( ( h, idx ) => (
					<PanelRow key={ idx } className="som-hours-row">
						<SelectControl
							label={ __( 'Day', 'schema-override-manager' ) }
							value={ h.dayOfWeek }
							options={ DAYS.map( d => ( { label: d, value: d } ) ) }
							onChange={ v => updateHours( idx, 'dayOfWeek', v ) }
						/>
						<TextControl
							label={ __( 'Opens', 'schema-override-manager' ) }
							value={ h.opens }
							onChange={ v => updateHours( idx, 'opens', v ) }
							type="time"
						/>
						<TextControl
							label={ __( 'Closes', 'schema-override-manager' ) }
							value={ h.closes }
							onChange={ v => updateHours( idx, 'closes', v ) }
							type="time"
						/>
						<Button isDestructive isSmall onClick={ () => removeHours( idx ) }>
							{ __( 'Remove', 'schema-override-manager' ) }
						</Button>
					</PanelRow>
				) ) }
				<Button variant="secondary" onClick={ addHours }>
					{ __( '+ Add Hours', 'schema-override-manager' ) }
				</Button>
			</PanelBody>
		</>
	);
}
