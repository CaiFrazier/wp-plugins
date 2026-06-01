import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useCalendarStore from '../store';
import {
	getMonthGrid,
	postsForDay,
	isSameDay,
	isToday,
	isCurrentMonth,
	formatDate,
} from '../utils/dates';
import PostChip from './PostChip';
import CreatePostForm from './CreatePostForm';

const DAY_HEADERS = [
	__( 'Sun', 'cf-content-calendar' ),
	__( 'Mon', 'cf-content-calendar' ),
	__( 'Tue', 'cf-content-calendar' ),
	__( 'Wed', 'cf-content-calendar' ),
	__( 'Thu', 'cf-content-calendar' ),
	__( 'Fri', 'cf-content-calendar' ),
	__( 'Sat', 'cf-content-calendar' ),
];

export default function MonthView() {
	const { year, month, posts, reschedulePost } = useCalendarStore();
	const [ activeDay, setActiveDay ] = useState( null ); // Date | null — open create form

	const grid = getMonthGrid( year, month );

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
		// Only open the form when clicking the empty area — not a chip.
		if ( e.target.closest( '.cf-cal-chip' ) ) {
			return;
		}
		setActiveDay( ( prev ) =>
			prev && isSameDay( prev, date ) ? null : date
		);
	}

	return (
		<div className="cf-cal-month">
			{ /* Day-of-week headers */ }
			<div className="cf-cal-month-header" aria-hidden="true">
				{ DAY_HEADERS.map( ( d ) => (
					<div key={ d } className="cf-cal-day-header">
						{ d }
					</div>
				) ) }
			</div>

			{ /* Calendar grid */ }
			<div className="cf-cal-month-grid">
				{ grid.map( ( date ) => {
					const dayPosts = postsForDay( posts, date );
					const isOtherMonth = ! isCurrentMonth( date, year, month );
					const isTodayDate = isToday( date );
					const isActive = activeDay && isSameDay( activeDay, date );

					let cellClass = 'cf-cal-day-cell';
					if ( isOtherMonth ) {
						cellClass += ' is-other-month';
					}
					if ( isTodayDate ) {
						cellClass += ' is-today';
					}
					if ( isActive ) {
						cellClass += ' is-active';
					}

					return (
						<div
							key={ date.toISOString() }
							className={ cellClass }
							onClick={ ( e ) => handleCellClick( e, date ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									handleCellClick( e, date );
								}
							} }
							onDragOver={ handleDragOver }
							onDragLeave={ handleDragLeave }
							onDrop={ ( e ) => handleDrop( e, date ) }
							tabIndex={ isOtherMonth ? -1 : 0 }
							role="button"
							aria-label={ date.toLocaleDateString( undefined, {
								weekday: 'long',
								month: 'long',
								day: 'numeric',
							} ) }
						>
							<span
								className="cf-cal-day-number"
								aria-current={
									isTodayDate ? 'date' : undefined
								}
							>
								{ date.getDate() }
							</span>

							<div className="cf-cal-day-chips">
								{ dayPosts.map( ( post ) => (
									<PostChip key={ post.id } post={ post } />
								) ) }
							</div>

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
