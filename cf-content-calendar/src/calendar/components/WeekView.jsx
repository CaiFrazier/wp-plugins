import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
	const { year, month, day, posts, reschedulePost } = useCalendarStore();
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
			reschedulePost( postId, formatDate( date ) );
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

					return (
						<div
							key={ date.toISOString() }
							className={ `cf-cal-week-col${
								isToday( date ) ? ' is-today' : ''
							}${ isActive ? ' is-active' : '' }` }
							onClick={ ( e ) => handleCellClick( e, date ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									handleCellClick( e, date );
								}
							} }
							onDragOver={ handleDragOver }
							onDragLeave={ handleDragLeave }
							onDrop={ ( e ) => handleDrop( e, date ) }
							tabIndex={ 0 }
							role="button"
							aria-label={ date.toLocaleDateString( undefined, {
								weekday: 'long',
								month: 'long',
								day: 'numeric',
							} ) }
						>
							{ dayPosts.length === 0 && ! isActive && (
								<span className="cf-cal-week-empty">
									{ __(
										'Click to add',
										'cf-content-calendar'
									) }
								</span>
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
