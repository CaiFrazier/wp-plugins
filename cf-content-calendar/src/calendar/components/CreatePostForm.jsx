import { useState, useRef, useEffect, useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useCalendarStore from '../store';
import { formatDate } from '../utils/dates';

const cfCalData = window.cfCalData || {};

// A sensible default time for a newly-scheduled post: ~1 hour out if the day is
// today (so it's actually in the future), otherwise 09:00.
function defaultScheduleTime( date ) {
	const today = new Date();
	const isToday =
		date.getFullYear() === today.getFullYear() &&
		date.getMonth() === today.getMonth() &&
		date.getDate() === today.getDate();
	if ( isToday ) {
		const t = new Date( Date.now() + 60 * 60 * 1000 );
		return `${ String( t.getHours() ).padStart( 2, '0' ) }:${ String(
			t.getMinutes()
		).padStart( 2, '0' ) }`;
	}
	return '09:00';
}

export default function CreatePostForm( { date, onClose } ) {
	const { createPost } = useCalendarStore();
	const titleRef = useRef( null );
	const fieldId = useId();
	const [ title, setTitle ] = useState( '' );
	const [ postType, setPostType ] = useState(
		Object.keys( cfCalData.postTypes || {} )[ 0 ] || 'post'
	);
	const [ status, setStatus ] = useState( 'draft' );
	const [ time, setTime ] = useState( () => defaultScheduleTime( date ) );
	const [ authorId, setAuthorId ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	const postTypes = cfCalData.postTypes || {};
	const canPublish = cfCalData.canPublish || false;
	const authors = cfCalData.authors || {};
	const showAuthors =
		cfCalData.canEditOthers && Object.keys( authors ).length > 0;
	const scheduling = canPublish && 'future' === status;

	// The form only mounts when the user explicitly clicks a day slot, so
	// moving focus into the title field is expected. Done via ref rather than
	// the autoFocus attribute (jsx-a11y/no-autofocus).
	useEffect( () => {
		titleRef.current?.focus();
	}, [] );

	async function handleSubmit( e ) {
		e.preventDefault();
		if ( ! title.trim() ) {
			return;
		}

		// Scheduled posts carry a time-of-day so they land in the future;
		// drafts stay date-only.
		const postDate = scheduling
			? `${ formatDate( date ) } ${ time }:00`
			: formatDate( date );

		setSaving( true );
		setError( null );
		try {
			await createPost( {
				title,
				post_type: postType,
				post_date: postDate,
				status: scheduling ? 'future' : 'draft',
				author_id: showAuthors ? authorId : 0,
			} );
			onClose();
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Could not create post.', 'cf-content-calendar' )
			);
			setSaving( false );
		}
	}

	return (
		// The form lives inside the day cell, whose click handler toggles it.
		// These handlers only stop clicks/keys from bubbling up and closing the
		// form mid-edit; they add no interactivity of their own.
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<form
			className="cf-cal-create-form"
			onSubmit={ handleSubmit }
			onClick={ ( e ) => e.stopPropagation() }
			onKeyDown={ ( e ) => e.stopPropagation() }
		>
			{ error && <p className="cf-cal-form-error">{ error }</p> }

			<input
				ref={ titleRef }
				type="text"
				className="cf-cal-form-title widefat"
				placeholder={ __( 'Post title…', 'cf-content-calendar' ) }
				value={ title }
				onChange={ ( e ) => setTitle( e.target.value ) }
				required
			/>

			{ Object.keys( postTypes ).length > 1 && (
				<select
					className="cf-cal-form-type"
					value={ postType }
					onChange={ ( e ) => setPostType( e.target.value ) }
					aria-label={ __( 'Post type', 'cf-content-calendar' ) }
				>
					{ Object.entries( postTypes ).map( ( [ slug, label ] ) => (
						<option key={ slug } value={ slug }>
							{ label }
						</option>
					) ) }
				</select>
			) }

			{ showAuthors && (
				<select
					className="cf-cal-form-author"
					value={ authorId }
					onChange={ ( e ) =>
						setAuthorId( parseInt( e.target.value, 10 ) )
					}
					aria-label={ __( 'Author', 'cf-content-calendar' ) }
				>
					<option value={ 0 }>
						{ __( 'Default author (you)', 'cf-content-calendar' ) }
					</option>
					{ Object.entries( authors ).map( ( [ id, name ] ) => (
						<option key={ id } value={ id }>
							{ name }
						</option>
					) ) }
				</select>
			) }

			{ canPublish && (
				<div className="cf-cal-form-status">
					<label htmlFor={ `${ fieldId }-draft` }>
						<input
							id={ `${ fieldId }-draft` }
							type="radio"
							name={ `${ fieldId }-status` }
							value="draft"
							checked={ 'draft' === status }
							onChange={ () => setStatus( 'draft' ) }
						/>{ ' ' }
						{ __( 'Save as Draft', 'cf-content-calendar' ) }
					</label>
					<label htmlFor={ `${ fieldId }-future` }>
						<input
							id={ `${ fieldId }-future` }
							type="radio"
							name={ `${ fieldId }-status` }
							value="future"
							checked={ 'future' === status }
							onChange={ () => setStatus( 'future' ) }
						/>{ ' ' }
						{ __( 'Schedule', 'cf-content-calendar' ) }
					</label>
				</div>
			) }

			{ scheduling && (
				<label
					className="cf-cal-form-time"
					htmlFor={ `${ fieldId }-time` }
				>
					{ __( 'Time', 'cf-content-calendar' ) }{ ' ' }
					<input
						id={ `${ fieldId }-time` }
						type="time"
						value={ time }
						onChange={ ( e ) => setTime( e.target.value ) }
						required
					/>
				</label>
			) }

			<div className="cf-cal-form-actions">
				<button
					type="submit"
					className="button button-primary"
					disabled={ saving }
				>
					{ saving
						? __( 'Saving…', 'cf-content-calendar' )
						: __( 'Create', 'cf-content-calendar' ) }
				</button>
				<button type="button" className="button" onClick={ onClose }>
					{ __( 'Cancel', 'cf-content-calendar' ) }
				</button>
			</div>
		</form>
	);
}
