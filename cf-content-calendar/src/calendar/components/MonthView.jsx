import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
	const { year, month, posts, requestReschedule } = useCalendarStore();
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
			requestReschedule( postId, formatDate( date ) );
		}
	}

	// Mouse convenience: clicking the empty area of a day opens the create
	// form. The cell itself is not a button (keyboard users use the explicit
	// add button below), so this only needs to ignore clicks on chips.
	function handleCellClick( e, date ) {
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

					const dayLabel = date.toLocaleDateString( undefined, {
						weekday: 'long',
						month: 'long',
						day: 'numeric',
					} );

					return (
						// The cell's click/drag are mouse-only conveniences; the
						// keyboard/AT path is the real <button> controls inside
						// (the add button and each chip). Keyboard-driven
						// reschedule is tracked separately (CCAL-P1-003).
						// eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events
						<div
							key={ date.toISOString() }
							className={ cellClass }
							onClick={ ( e ) => handleCellClick( e, date ) }
							onDragOver={ handleDragOver }
							onDragLeave={ handleDragLeave }
							onDrop={ ( e ) => handleDrop( e, date ) }
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

							{ ! isOtherMonth && ! isActive && (
								<button
									type="button"
									className="cf-cal-day-add"
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
									+
								</button>
							) }

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
