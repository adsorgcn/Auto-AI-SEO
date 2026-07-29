<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI 命令 —— 最强执行路径(无 web 超时)。
 *
 * 刻意与后台 UI 共用同一套 Job 实现,不写第二份逻辑:
 * 后台按钮走 Action Scheduler 队列,CLI 直接同步循环,处理函数是同一个 handle_one()。
 * 遍历借鉴官方 wp media regenerate 的做法:每 N 条清一次对象缓存,防长跑内存膨胀。
 */
class AASEO_CLI {

	const CACHE_FLUSH_INTERVAL = 200;

	/**
	 * 列出所有可用模块及其状态。
	 *
	 * @subcommand jobs
	 */
	public function jobs() {
		$rows = array();
		foreach ( AASEO_Jobs::instance()->all() as $slug => $job ) {
			$s      = $job->state();
			$rows[] = array(
				'slug'      => $slug,
				'label'     => $job->label(),
				'pending'   => $job->count_candidates(),
				'status'    => isset( $s['status'] ) ? $s['status'] : 'idle',
				'done'      => isset( $s['done'] ) ? $s['done'] : 0,
				'failed'    => isset( $s['failed'] ) ? $s['failed'] : 0,
				'ready'     => $job->is_ready() ? 'yes' : 'no',
			);
		}
		if ( ! $rows ) {
			WP_CLI::warning( 'No jobs registered yet.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'label', 'pending', 'status', 'done', 'failed', 'ready' ) );
	}

	/**
	 * 环境探针报告。
	 *
	 * @subcommand probe
	 */
	public function probe() {
		$s = AASEO_Probe::static_probe( true );
		WP_CLI::log( 'Execution path : ' . AASEO_Probe::execution_path() );
		WP_CLI::log( 'PHP            : ' . $s['php_version'] . ' | memory ' . $s['memory_limit']
			. ' | max_execution_time ' . $s['max_execution_time'] . ' (0 = unlimited as reported)' );
		WP_CLI::log( 'Image          : GD ' . ( $s['gd'] ? 'yes' : 'no' )
			. ' | Imagick ' . ( $s['imagick'] ? 'yes' : 'no' )
			. ' | Photon ' . ( $s['photon'] ? 'yes' : 'no' ) );
		WP_CLI::log( 'Scheduler      : ' . ( $s['action_scheduler'] ? 'available' : 'MISSING' ) );
		foreach ( array( 'text', 'vision' ) as $kind ) {
			$c = AASEO_Probe::calibration( $kind );
			WP_CLI::log( sprintf(
				'%-6s         : timeout %ds | batch %d | %s',
				$kind,
				AASEO_Probe::timeout_for( $kind ),
				AASEO_Probe::batch_size( $kind ),
				$c ? sprintf( 'measured n=%d median=%ss p90=%ss', $c['n'], $c['median'], $c['p90'] ) : 'no measurements yet'
			) );
		}
		WP_CLI::log( '' );
		foreach ( AASEO_Probe::explain( 'vision' ) as $line ) {
			WP_CLI::log( '  · ' . $line );
		}
	}

	/**
	 * 跑一个模块。
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : 模块 slug(见 wp aaseo jobs)
	 *
	 * [--limit=<n>]
	 * : 最多处理多少条,默认全部
	 *
	 * [--dry-run]
	 * : 只列出将要处理的对象,不实际调用 AI
	 *
	 * [--queue]
	 * : 交给 Action Scheduler 后台跑,而不是在本进程同步跑
	 *
	 * [--yes]
	 * : 跳过成本确认
	 *
	 * @subcommand run
	 */
	public function run( $args, $assoc ) {
		list( $slug ) = $args;
		$job          = AASEO_Jobs::instance()->get( $slug );
		if ( ! $job ) {
			WP_CLI::error( "Unknown job: $slug" );
		}
		if ( ! $job->is_ready() ) {
			WP_CLI::error( 'Job is not ready — check the API key in Settings.' );
		}

		$dry   = ! empty( $assoc['dry-run'] );
		$limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0;

		if ( ! empty( $assoc['queue'] ) ) {
			$res = AASEO_Jobs::instance()->start( $slug, $limit );
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( $res->get_error_message() );
			}
			WP_CLI::success( sprintf(
				'Queued in the background%s. Watch: wp aaseo jobs',
				$limit > 0 ? sprintf( ' (up to %d item(s))', $limit ) : ''
			) );
			return;
		}

		$total = $limit > 0 ? min( $limit, $job->count_candidates() ) : $job->count_candidates();
		if ( ! $total ) {
			WP_CLI::success( 'Nothing to do.' );
			return;
		}

		// 跑前预估:让用户看到会花多少钱再决定
		$models = AASEO_Options::models( $job->model_kind() );
		$est    = AASEO_Usage::estimate( $slug, $models ? $models[0] : '', $total, $job->estimate_tokens() );
		WP_CLI::log( sprintf(
			'%d item(s) · est. %s tokens · est. %s%s',
			$est['count'], number_format( $est['tokens'] ), $est['money'],
			$est['measured'] ? ' (based on measured usage on this site)' : ' (rough default, no measurements yet)'
		) );
		if ( ! $est['price_verified'] ) {
			WP_CLI::log( '  note: unit price for this model is an upper-bound estimate — override it in Settings for exact figures.' );
		}
		if ( ! $dry ) {
			WP_CLI::confirm( 'Proceed?', $assoc );
		}

		$progress = WP_CLI\Utils\make_progress_bar( $job->label(), $total );
		$done     = $failed = $skipped = $n = 0;
		$offset   = 0;

		while ( $n < $total ) {
			$ids = $job->find_candidates( min( 200, $total - $n ), $offset );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				if ( $n >= $total ) {
					break;
				}
				++$n;
				if ( $dry ) {
					WP_CLI::log( sprintf( '[dry] #%d %s', $id, get_the_title( $id ) ) );
					$progress->tick();
					continue;
				}
				if ( AASEO_Usage::cap_reached() ) {
					WP_CLI::warning( 'Daily token cap reached — stopping. Raise it in Settings or resume tomorrow.' );
					break 2;
				}
				$r = $job->handle_one( $id );
				if ( is_wp_error( $r ) ) {
					++$failed;
					WP_CLI::warning( sprintf( '#%d %s', $id, $r->get_error_message() ) );
				} elseif ( 'skipped' === $r ) {
					++$skipped;
				} else {
					++$done;
				}
				$progress->tick();
				// 长跑防内存膨胀(官方 wp media regenerate 的做法)
				if ( 0 === $n % self::CACHE_FLUSH_INTERVAL ) {
					WP_CLI\Utils\wp_clear_object_cache();
				}
			}
			// find_candidates 通常只返回"还没处理的",处理完自然前移;若模块是静态列表则需推进 offset
			$offset += count( $ids );
		}
		$progress->finish();

		$spent = AASEO_Usage::today();
		WP_CLI::success( sprintf(
			'%d done, %d skipped, %d failed. Today: %s tokens, %s',
			$done, $skipped, $failed,
			number_format( $spent['tokens'] ), AASEO_Usage::format_money( $spent['micro'] )
		) );
		// 跳过的原因说清楚,而不是丢一个数字让人猜
		$state = AASEO_Jobs::state( $slug );
		if ( ! empty( $state['skip_reasons'] ) ) {
			$why = AASEO_Jobs::skip_explanations();
			foreach ( (array) $state['skip_reasons'] as $reason => $n ) {
				WP_CLI::log( sprintf( '  %d skipped × %s', $n, isset( $why[ $reason ] ) ? $why[ $reason ] : $reason ) );
			}
		}
		if ( ! empty( $state['repairs'] ) ) {
			$why = AASEO_Jobs::repair_explanations();
			foreach ( (array) $state['repairs'] as $reason => $n ) {
				WP_CLI::log( sprintf( '  %d rewritten × %s', $n, isset( $why[ $reason ] ) ? $why[ $reason ] : $reason ) );
			}
		}
	}

	/**
	 * 暂停 / 恢复 / 取消 一个模块的后台队列。
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : pause | resume | cancel
	 *
	 * <job>
	 * : 模块 slug
	 *
	 * @subcommand queue
	 */
	public function queue( $args ) {
		list( $action, $slug ) = $args;
		$registry              = AASEO_Jobs::instance();
		if ( ! $registry->get( $slug ) ) {
			WP_CLI::error( "Unknown job: $slug" );
		}
		switch ( $action ) {
			case 'pause':
				$registry->pause( $slug );
				WP_CLI::success( "Paused: $slug" );
				break;
			case 'resume':
				$registry->resume( $slug );
				WP_CLI::success( "Resumed: $slug" );
				break;
			case 'cancel':
				$registry->cancel( $slug );
				WP_CLI::success( "Cancelled queued actions for: $slug" );
				break;
			default:
				WP_CLI::error( 'Action must be pause, resume or cancel.' );
		}
	}

	/**
	 * 用量与花费账单。
	 *
	 * @subcommand usage
	 */
	public function usage() {
		$today = AASEO_Usage::today();
		WP_CLI::log( sprintf( 'Today: %s calls · %s tokens · %s',
			number_format( $today['calls'] ), number_format( $today['tokens'] ),
			AASEO_Usage::format_money( $today['micro'] ) ) );
		$cap = (int) AASEO_Options::get( 'daily_token_cap' );
		if ( $cap > 0 ) {
			WP_CLI::log( sprintf( 'Daily cap: %s tokens · %s remaining',
				number_format( $cap ), number_format( AASEO_Usage::remaining_today() ) ) );
		}
		$rows = array();
		foreach ( AASEO_Usage::summary() as $r ) {
			$rows[] = array(
				'job'    => $r->job,
				'model'  => $r->model,
				'calls'  => $r->calls,
				'fails'  => $r->fails,
				'tokens' => number_format( (int) $r->pt + (int) $r->ct ),
				'avg_s'  => round( (float) $r->avg_sec, 1 ),
				'cost'   => AASEO_Usage::format_money( (int) $r->micro ),
			);
		}
		if ( $rows ) {
			WP_CLI::log( '' );
			WP_CLI\Utils\format_items( 'table', $rows, array( 'job', 'model', 'calls', 'fails', 'tokens', 'avg_s', 'cost' ) );
		}
	}
}
