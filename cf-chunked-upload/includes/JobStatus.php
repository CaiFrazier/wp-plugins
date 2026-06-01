<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * The finalize job's progress/result record, stored in a transient the
 * polling endpoint reads. Kept tiny and serializable; never holds file bytes.
 */
final class JobStatus {

	const PREFIX = 'cf_cu_job_';

	/**
	 * 8 h. Every set() (pending → running progress ticks → terminal) refreshes
	 * this, so a job that keeps reporting progress never expires under the
	 * client. The generous floor covers the gap before the first progress
	 * tick and slow single-pass assembly of a multi-GB file on poor storage,
	 * so /finalize-status can't 404 a result the job actually produced.
	 */
	const TTL = 28800;

	const PENDING  = 'pending';
	const RUNNING  = 'running';
	const COMPLETE = 'complete';
	const ERROR    = 'error';

	public static function key( string $job_id ): string {
		return self::PREFIX . $job_id;
	}

	public static function set( string $job_id, array $record ): void {
		set_transient( self::key( $job_id ), $record, self::TTL );
	}

	public static function get( string $job_id ): ?array {
		$v = get_transient( self::key( $job_id ) );
		return is_array( $v ) ? $v : null;
	}

	public static function pending( string $job_id ): void {
		self::set( $job_id, [ 'status' => self::PENDING ] );
	}

	public static function running( string $job_id, float $progress = 0.0 ): void {
		self::set(
			$job_id,
			[
				'status'   => self::RUNNING,
				'progress' => $progress,
			]
		);
	}

	public static function complete( string $job_id, array $result ): void {
		self::set(
			$job_id,
			[
				'status' => self::COMPLETE,
				'result' => $result,
			]
		);
	}

	public static function error( string $job_id, string $code, string $message ): void {
		self::set(
			$job_id,
			[
				'status' => self::ERROR,
				'error'  => [
					'code'    => $code,
					'message' => $message,
				],
			]
		);
	}
}
