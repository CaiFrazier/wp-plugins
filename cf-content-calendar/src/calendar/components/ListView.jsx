import { __ } from '@wordpress/i18n';
import useCalendarStore from '../store';
import {
	parseLocalDate,
	formatDate,
	isSameDay,
	siteToday,
} from '../utils/dates';
import PostChip from './PostChip';

export default function ListView() {
	const { posts, loading } = useCalendarStore();

	if ( loading ) {
		return (
			<p className="cf-cal-list-loading">
				{ __( 'Loading…', 'cf-content-calendar' ) }
			</p>
		);
	}

	if ( posts.length === 0 ) {
		return (
			<p className="cf-cal-list-empty">
				{ __( 'No posts in this date range.', 'cf-content-calendar' ) }
			</p>
		);
	}

	// Group posts by YYYY-MM-DD.
	const groups = [];
	const seen = new Map();

	const sorted = [ ...posts ].sort(
		( a, b ) => parseLocalDate( a.date ) - parseLocalDate( b.date )
	);

	for ( const post of sorted ) {
		const key = formatDate( parseLocalDate( post.date ) );
		if ( ! seen.has( key ) ) {
			seen.set( key, groups.length );
			groups.push( {
				date: parseLocalDate( post.date ),
				key,
				posts: [],
			} );
		}
		groups[ seen.get( key ) ].posts.push( post );
	}

	const today = siteToday();

	return (
		<div className="cf-cal-list">
			{ groups.map( ( group ) => {
				const isToday = isSameDay( group.date, today );
				const dateLabel = group.date.toLocaleDateString( undefined, {
					weekday: 'long',
					month: 'long',
					day: 'numeric',
					year: 'numeric',
				} );

				return (
					<div
						key={ group.key }
						className={ `cf-cal-list-day${
							isToday ? ' is-today' : ''
						}` }
					>
						<h3 className="cf-cal-list-day-heading">
							{ dateLabel }
							{ isToday && (
								<span className="cf-cal-list-today-badge">
									{ ' ' }
									{ __( 'Today', 'cf-content-calendar' ) }
								</span>
							) }
						</h3>
						<div className="cf-cal-list-day-posts">
							{ group.posts.map( ( post ) => (
								<div
									key={ post.id }
									className="cf-cal-list-post-row"
								>
									<span className="cf-cal-list-post-time">
										{ parseLocalDate(
											post.date
										).toLocaleTimeString( undefined, {
											hour: '2-digit',
											minute: '2-digit',
										} ) }
									</span>
									<PostChip post={ post } />
								</div>
							) ) }
						</div>
					</div>
				);
			} ) }
		</div>
	);
}
