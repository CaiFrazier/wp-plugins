import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useCalendarStore from '../store';
import Toolbar from './Toolbar';
import MonthView from './MonthView';
import WeekView from './WeekView';
import ListView from './ListView';
import RescheduleConfirmDialog from './RescheduleConfirmDialog';

export default function CalendarApp() {
	const { view, year, month, day, filters, fetchPosts, error, clearError } =
		useCalendarStore();

	// Re-fetch whenever the visible window OR the active filters change.
	const filterKey = JSON.stringify( filters );
	useEffect( () => {
		fetchPosts();
	}, [ view, year, month, day, filterKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<div className="cf-cal-wrap">
			<h1 className="cf-cal-heading">
				{ __( 'Content Calendar', 'cf-content-calendar' ) }
			</h1>

			<Toolbar />

			{ error && (
				<div className="notice notice-error cf-cal-notice is-dismissible">
					<p>{ error }</p>
					<button
						type="button"
						className="notice-dismiss"
						onClick={ clearError }
						aria-label={ __(
							'Dismiss error',
							'cf-content-calendar'
						) }
					/>
				</div>
			) }

			{ 'month' === view && <MonthView /> }
			{ 'week' === view && <WeekView /> }
			{ 'list' === view && <ListView /> }

			<RescheduleConfirmDialog />
		</div>
	);
}
