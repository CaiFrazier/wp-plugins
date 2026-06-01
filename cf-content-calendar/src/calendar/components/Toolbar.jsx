import { __ } from '@wordpress/i18n';
import useCalendarStore from '../store';
import { getMonthLabel, getWeekLabel } from '../utils/dates';

const cfCalData = window.cfCalData || {};

const VIEWS = [
	{ id: 'month', label: __( 'Month', 'cf-content-calendar' ) },
	{ id: 'week', label: __( 'Week', 'cf-content-calendar' ) },
	{ id: 'list', label: __( 'List', 'cf-content-calendar' ) },
];

const STATUSES = [
	{ id: 'publish', label: __( 'Published', 'cf-content-calendar' ) },
	{ id: 'future', label: __( 'Scheduled', 'cf-content-calendar' ) },
	{ id: 'draft', label: __( 'Draft', 'cf-content-calendar' ) },
	{ id: 'pending', label: __( 'Pending', 'cf-content-calendar' ) },
];

export default function Toolbar() {
	const {
		view,
		year,
		month,
		day,
		setView,
		navigate,
		goToToday,
		filters,
		setFilters,
		loading,
	} = useCalendarStore();

	const date = new Date( year, month, day );
	const rangeLabel =
		'month' === view ? getMonthLabel( year, month ) : getWeekLabel( date );
	const postTypes = cfCalData.postTypes || {};
	const authors = cfCalData.authors || {};
	const showAuthors =
		cfCalData.canEditOthers && Object.keys( authors ).length > 0;

	function togglePostType( slug ) {
		const next = filters.postTypes.includes( slug )
			? filters.postTypes.filter( ( t ) => t !== slug )
			: [ ...filters.postTypes, slug ];
		setFilters( { postTypes: next } );
	}

	function toggleStatus( id ) {
		const next = filters.statuses.includes( id )
			? filters.statuses.filter( ( s ) => s !== id )
			: [ ...filters.statuses, id ];
		setFilters( { statuses: next } );
	}

	return (
		<div
			className="cf-cal-toolbar"
			role="toolbar"
			aria-label={ __( 'Calendar controls', 'cf-content-calendar' ) }
		>
			{ /* Navigation */ }
			<div className="cf-cal-toolbar-nav">
				<button
					type="button"
					className="button"
					onClick={ () => navigate( -1 ) }
					aria-label={ __( 'Previous', 'cf-content-calendar' ) }
				>
					&#8249;
				</button>

				<button type="button" className="button" onClick={ goToToday }>
					{ __( 'Today', 'cf-content-calendar' ) }
				</button>

				<button
					type="button"
					className="button"
					onClick={ () => navigate( 1 ) }
					aria-label={ __( 'Next', 'cf-content-calendar' ) }
				>
					&#8250;
				</button>

				<span className="cf-cal-range-label" aria-live="polite">
					{ loading
						? __( 'Loading…', 'cf-content-calendar' )
						: rangeLabel }
				</span>
			</div>

			{ /* View switcher */ }
			<div
				className="cf-cal-view-switcher"
				role="group"
				aria-label={ __( 'Calendar view', 'cf-content-calendar' ) }
			>
				{ VIEWS.map( ( v ) => (
					<button
						key={ v.id }
						type="button"
						className={ `button${
							view === v.id ? ' button-primary' : ''
						}` }
						onClick={ () => setView( v.id ) }
						aria-pressed={ view === v.id }
					>
						{ v.label }
					</button>
				) ) }
			</div>

			{ /* Post type filter */ }
			{ Object.keys( postTypes ).length > 1 && (
				<div
					className="cf-cal-filter"
					aria-label={ __( 'Post types', 'cf-content-calendar' ) }
				>
					{ Object.entries( postTypes ).map( ( [ slug, label ] ) => (
						<label
							key={ slug }
							htmlFor={ `cf-cal-pt-${ slug }` }
							className="cf-cal-filter-item"
						>
							<input
								id={ `cf-cal-pt-${ slug }` }
								type="checkbox"
								checked={ filters.postTypes.includes( slug ) }
								onChange={ () => togglePostType( slug ) }
							/>{ ' ' }
							{ label }
						</label>
					) ) }
				</div>
			) }

			{ /* Author filter */ }
			{ showAuthors && (
				<div className="cf-cal-filter">
					<label
						htmlFor="cf-cal-author-filter"
						className="cf-cal-filter-item"
					>
						{ __( 'Author', 'cf-content-calendar' ) }{ ' ' }
						<select
							id="cf-cal-author-filter"
							value={ filters.authorId }
							onChange={ ( e ) =>
								setFilters( {
									authorId: parseInt( e.target.value, 10 ),
								} )
							}
						>
							<option value={ 0 }>
								{ __( 'All authors', 'cf-content-calendar' ) }
							</option>
							{ Object.entries( authors ).map(
								( [ id, name ] ) => (
									<option key={ id } value={ id }>
										{ name }
									</option>
								)
							) }
						</select>
					</label>
				</div>
			) }

			{ /* Status filter */ }
			<div
				className="cf-cal-filter"
				aria-label={ __( 'Post statuses', 'cf-content-calendar' ) }
			>
				{ STATUSES.map( ( s ) => (
					<label
						key={ s.id }
						htmlFor={ `cf-cal-st-${ s.id }` }
						className="cf-cal-filter-item"
					>
						<input
							id={ `cf-cal-st-${ s.id }` }
							type="checkbox"
							checked={ filters.statuses.includes( s.id ) }
							onChange={ () => toggleStatus( s.id ) }
						/>{ ' ' }
						{ s.label }
					</label>
				) ) }
			</div>
		</div>
	);
}
