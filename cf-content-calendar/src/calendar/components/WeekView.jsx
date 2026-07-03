import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import useCalendarStore from '../store';
import {
	getWeekDays,
	postsForDay,
	isToday,
	isSameDay,
	formatDate,
	parseLocalDate,
} from '../utils/dates';
import PostChip from './PostChip';
import CreatePostForm from './CreatePostForm';

export default function WeekView() {
	const { year, month, day, posts, requestReschedule } = useCalendarStore();
	const [ activeDay, setActiveDay ] = useState( null );

	const weekDays = getWeekDays( new Date( year, month, day ) );

	function handleDragOver( e ) {
		e.preventDefault();
		e.currentTarget.classList.add( 'is-drag-over' );
	}

	function handleDragLeave( e ) {
		e.currentTarget.classList.remove( 'is-drag-over' );
	}

	function handleDrop( e, date ) {
		e.preventDefault();
		e.currentTarget.classList.remove( 'is-drag-over' );
		const postId = parseInt( e.dataTransfer.getData( 'text/plain' ), 10 );
		if ( postId ) {
			requestReschedule( postId, formatDate( date ) );
		}
	}

	function handleCellClick( e, date ) {
		if ( e.target.closest( '.cf-cal-chip' ) ) {
			return;
		}
		setActiveDay( ( prev ) =>
			prev && isSameDay( prev, date ) ? null : date
		);
	}

	return (
		<div className="cf-cal-week">
			{ /* Column headers */ }
			<div className="cf-cal-week-header">
				{ weekDays.map( ( date ) => (
					<div
						key={ date.toISOString() }
						className={ `cf-cal-week-day-header${
							isToday( date ) ? ' is-today' : ''
						}` }
					>
						<span className="cf-cal-week-day-name">
							{ date.toLocaleDateString( undefined, {
								weekday: 'short',
							} ) }
						</span>
						<span
							className={ `cf-cal-week-day-number${
								isToday( date ) ? ' is-today' : ''
							}` }
						>
							{ date.getDate() }
						</span>
					</div>
				) ) }
			</div>

			{ /* Day columns */ }
			<div className="cf-cal-week-body">
				{ weekDays.map( ( date ) => {
					const dayPosts = postsForDay( posts, date ).sort(
						( a, b ) => {
							return (
								parseLocalDate( a.date ) -
								parseLocalDate( b.date )
							);
						}
					);
					const isActive = activeDay && isSameDay( activeDay, date );

					const dayLabel = date.toLocaleDateString( undefined, {
						weekday: 'long',
						month: 'long',
						day: 'numeric',
					} );

					return (
						// Mouse-only conveniences; the keyboard/AT path is the
						// real <button> controls inside (add button + chips).
						// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
						<div
							key={ date.toISOString() }
							className={ `cf-cal-week-col${
								isToday( date ) ? ' is-today' : ''
							}${ isActive ? ' is-active' : '' }` }
							onClick={ ( e ) => handleCellClick( e, date ) }
							onDragOver={ handleDragOver }
							onDragLeave={ handleDragLeave }
							onDrop={ ( e ) => handleDrop( e, date ) }
						>
							{ ! isActive && (
								<button
									type="button"
									className="cf-cal-week-add"
									aria-label={ sprintf(
										/* translators: %s is a date, e.g. "Monday, July 6". */
										__(
											'Add a post on %s',
											'cf-content-calendar'
										),
										dayLabel
									) }
									onClick={ ( e ) => {
										e.stopPropagation();
										setActiveDay( date );
									} }
								>
									{ dayPosts.length === 0
										? __(
												'Click to add',
												'cf-content-calendar'
										  )
										: '+' }
								</button>
							) }

							{ dayPosts.map( ( post ) => {
								const postDate = parseLocalDate( post.date );
								const timeStr = postDate.toLocaleTimeString(
									undefined,
									{
										hour: '2-digit',
										minute: '2-digit',
									}
								);
								return (
									<div
										key={ post.id }
										className="cf-cal-week-post"
									>
										<span className="cf-cal-week-post-time">
											{ timeStr }
										</span>
										<PostChip post={ post } />
									</div>
								);
							} ) }

							{ isActive && (
								<CreatePostForm
									date={ date }
									onClose={ () => setActiveDay( null ) }
								/>
							) }
						</div>
					);
				} ) }
			</div>
		</div>
	);
}
