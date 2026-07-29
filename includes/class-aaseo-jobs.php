<?php
defined( 'ABSPATH' ) || exit;

/**
 * 模块注册表 + 队列调度层。
 *
 * 队列本体是 Action Scheduler,这个类只做三件它不管的事:
 *   1. 把候选对象分批入队(不一次性取全量 ID)
 *   2. 在每条任务执行前把闸门过一遍(配额、就绪状态、暂停开关)
 *   3. 统计运行态给后台看
 */
class AASEO_Jobs {

	const STATE_OPTION = 'aaseo_job_state';
	const ENQUEUE_CHUNK = 200;   // 每次入队 200 条,分多轮 —— 避免一次性取上万 ID
	const MAX_ENQUEUE_ROUNDS = 5; // 补扫轮数上限,防止处理速度跟不上时无限循环

	/** @var AASEO_Jobs */
	private static $instance;

	/** @var AASEO_Job[] slug => job */
	private $jobs = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		$self = self::instance();
		add_action( 'init', array( $self, 'attach_handlers' ), 5 );
	}

	/** 模块自注册入口:do_action('aaseo_register_jobs', $registry) 时调用 */
	public function register( AASEO_Job $job ) {
		$this->jobs[ $job->slug() ] = $job;
		return $this;
	}

	/** @return AASEO_Job[] */
	public function all() {
		return $this->jobs;
	}

	/** @return AASEO_Job|null */
	public function get( $slug ) {
		return isset( $this->jobs[ $slug ] ) ? $this->jobs[ $slug ] : null;
	}

	/** 把每个模块的执行回调挂到它自己的 AS hook 上 */
	public function attach_handlers() {
		foreach ( $this->jobs as $slug => $job ) {
			add_action( $job->hook(), array( $this, 'run_one' ), 10, 2 );
			add_action( 'aaseo_enqueue_' . str_replace( '-', '_', $slug ), array( $this, 'enqueue_chunk' ), 10, 2 );
		}
	}

	// ---------------------------------------------------------------- 入队

	/**
	 * 启动一个模块的批量任务。
	 * 不在这里把所有候选一次性入队,而是排一个"入队任务",由它分批把工作项入队 ——
	 * 入队本身也是后台工作,这样点一下按钮就返回,不会卡住请求。
	 */
	public function start( $slug, $limit = 0 ) {
		$job = $this->get( $slug );
		if ( ! $job ) {
			return new WP_Error( 'no_job', 'Unknown job: ' . $slug );
		}
		if ( ! $job->is_ready() ) {
			return new WP_Error( 'not_ready', 'Job is not ready to run (API key missing?)' );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'no_scheduler', 'Action Scheduler unavailable' );
		}

		$total = (int) $job->count_candidates();
		if ( $limit > 0 ) {
			$total = min( $total, (int) $limit );
		}
		$this->set_state( $slug, array(
			'status'   => 'running',
			'total'    => $total,
			'limit'    => (int) $limit,
			'queued'   => 0,
			'done'     => 0,
			'failed'   => 0,
			'skipped'  => 0,
			'started'  => current_time( 'mysql', true ),
			'note'     => '',
		) );

		as_enqueue_async_action(
			'aaseo_enqueue_' . str_replace( '-', '_', $slug ),
			array( 'slug' => $slug, 'offset' => 0, 'round' => 0 ),
			$job->group()
		);
		return true;
	}

	/**
	 * 分批入队。
	 *
	 * 这里有个不显眼但会漏活的陷阱:候选集是"还没处理的对象",**处理完就从集合里消失**,
	 * 于是 offset 会跳过后面等量的条目。所以:
	 *   · 逐条用 as_has_scheduled_action() 去重,不怕重复扫到同一条;
	 *   · 一轮扫完(取不满一批)后,若仍有候选,从 offset 0 再扫一轮补漏;
	 *   · 轮数设上限,避免"处理速度跟不上入队速度"时无限循环。
	 */
	public function enqueue_chunk( $slug, $offset = 0, $round = 0 ) {
		// AS 会把关联数组展开成位置参数,兼容两种形态
		if ( is_array( $slug ) ) {
			$args   = $slug;
			$slug   = isset( $args['slug'] ) ? $args['slug'] : '';
			$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
			$round  = isset( $args['round'] ) ? (int) $args['round'] : 0;
		}
		$job = $this->get( $slug );
		if ( ! $job ) {
			return;
		}

		$state = $this->state( $slug );
		$limit = isset( $state['limit'] ) ? (int) $state['limit'] : 0;
		$done  = (int) ( isset( $state['queued'] ) ? $state['queued'] : 0 );
		if ( $limit > 0 && $done >= $limit ) {
			return; // --limit 已排满
		}

		$want  = $limit > 0 ? min( self::ENQUEUE_CHUNK, $limit - $done ) : self::ENQUEUE_CHUNK;
		$ids   = $job->find_candidates( $want, (int) $offset );
		$fresh = 0;
		foreach ( $ids as $id ) {
			$payload = array( 'slug' => $slug, 'object_id' => (int) $id );
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( $job->hook(), $payload, $job->group() ) ) {
				continue; // 已在队列里,不重复排
			}
			as_enqueue_async_action( $job->hook(), $payload, $job->group() );
			++$fresh;
		}
		if ( $fresh ) {
			$this->bump( $slug, 'queued', $fresh );
		}

		$next_offset = (int) $offset + count( $ids );
		$next_round  = (int) $round;

		if ( count( $ids ) < $want ) {
			// 这一轮扫到底了 —— 若还有候选(说明被 offset 跳过),从头补扫
			$next_offset = 0;
			++$next_round;
			if ( $next_round > self::MAX_ENQUEUE_ROUNDS || ! $job->count_candidates() ) {
				return;
			}
			if ( ! $fresh ) {
				return; // 整轮没排进任何新条目,说明剩下的都在队列里了
			}
		}

		as_enqueue_async_action(
			'aaseo_enqueue_' . str_replace( '-', '_', $slug ),
			array( 'slug' => $slug, 'offset' => $next_offset, 'round' => $next_round ),
			$job->group()
		);
	}

	// ---------------------------------------------------------------- 执行

	/**
	 * 执行单条。闸门顺序:暂停 → 就绪 → 每日配额。
	 * 任一闸门拦下就把这条**重新排到稍后**(而不是丢弃),实现"到额自动停、次日续跑"。
	 */
	public function run_one( $slug, $object_id = 0 ) {
		if ( is_array( $slug ) ) {
			$args      = $slug;
			$slug      = isset( $args['slug'] ) ? $args['slug'] : '';
			$object_id = isset( $args['object_id'] ) ? (int) $args['object_id'] : 0;
		}
		$job = $this->get( $slug );
		if ( ! $job || ! $object_id ) {
			return;
		}

		$state = $this->state( $slug );
		if ( 'paused' === ( isset( $state['status'] ) ? $state['status'] : '' ) ) {
			$this->requeue_later( $job, $object_id, 300, 'paused' );
			return;
		}
		if ( ! $job->is_ready() ) {
			$this->requeue_later( $job, $object_id, 900, 'not ready' );
			return;
		}
		if ( AASEO_Usage::cap_reached() ) {
			// 每日额度用尽 → 推到明天再跑,不消耗重试次数
			$this->requeue_later( $job, $object_id, $this->seconds_until_tomorrow(), 'daily cap reached' );
			$this->set_state( $slug, array_merge( $state, array(
				'note' => __( 'Daily token cap reached; will continue tomorrow.', 'auto-ai-seo' ),
			) ) );
			return;
		}

		$result = $job->handle_one( $object_id );

		if ( is_wp_error( $result ) ) {
			$this->bump( $slug, 'failed', 1 );
			/*
			 * 抛异常让 Action Scheduler 记进它自己的日志表(工具→Scheduled Actions 可查),
			 * 并按其重试策略处理。异常消息只进日志、不进页面,但静态分析无法判断这一点,
			 * 故仍对拼入的值做转义。
			 */
			throw new Exception( esc_html( sprintf(
				'%s #%d: %s',
				$slug,
				$object_id,
				$result->get_error_message()
			) ) );
		}
		if ( 'skipped' === $result ) {
			$this->bump( $slug, 'skipped', 1 );
			return;
		}
		$this->bump( $slug, 'done', 1 );
		$this->maybe_finish( $slug );
	}

	private function requeue_later( AASEO_Job $job, $object_id, $delay, $why ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + (int) $delay,
				$job->hook(),
				array( 'slug' => $job->slug(), 'object_id' => (int) $object_id ),
				$job->group()
			);
		}
	}

	private function seconds_until_tomorrow() {
		$now      = (int) current_time( 'timestamp' );
		$midnight = strtotime( 'tomorrow midnight', $now );
		return max( 60, $midnight - $now );
	}

	// ---------------------------------------------------------------- 控制

	public function pause( $slug ) {
		$s = $this->state( $slug );
		$this->set_state( $slug, array_merge( $s, array( 'status' => 'paused' ) ) );
	}

	public function resume( $slug ) {
		$s = $this->state( $slug );
		$this->set_state( $slug, array_merge( $s, array( 'status' => 'running', 'note' => '' ) ) );
	}

	/** 取消:清掉该模块在 AS 里所有待执行任务 */
	public function cancel( $slug ) {
		$job = $this->get( $slug );
		if ( $job && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), $job->group() );
			as_unschedule_all_actions( $job->hook() );
		}
		$s = $this->state( $slug );
		$this->set_state( $slug, array_merge( $s, array( 'status' => 'idle' ) ) );
	}

	/** 队列里还剩多少条(直接问 Action Scheduler,它才是真相) */
	public function pending_count( $slug ) {
		$job = $this->get( $slug );
		if ( ! $job || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		return (int) as_get_scheduled_actions( array(
			'hook'     => $job->hook(),
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 0,
		), 'count' );
	}

	private function maybe_finish( $slug ) {
		if ( 0 === $this->pending_count( $slug ) ) {
			$s = $this->state( $slug );
			$this->set_state( $slug, array_merge( $s, array(
				'status'   => 'done',
				'finished' => current_time( 'mysql', true ),
			) ) );
		}
	}

	// ---------------------------------------------------------------- 运行态

	/**
	 * 记一条跳过的原因。
	 *
	 * 目的不是记日志,是**把判断结论告诉用户**:与其在界面上显示"18 条失败"让人发慌,
	 * 不如说清"18 张图在磁盘上已不存在(孤儿附件记录),可以清理" —— 该由插件消化的
	 * 判断成本,不要转手给人。
	 */
	public static function note_skip( $slug, $reason ) {
		self::tally( $slug, 'skip_reasons', $reason );
	}

	/**
	 * 记一次"输出不合格、修补后写入"。
	 *
	 * 刻意与 note_skip 分开计数:这些条目最终是**写进去了**的,报成 "skipped" 就是谎报。
	 * 它当仪表盘用 —— 修补率一旦变高,说明该改的是提示词,不是加大兜底。
	 */
	public static function note_repair( $slug, $reason ) {
		self::tally( $slug, 'repairs', $reason );
	}

	private static function tally( $slug, $bucket, $reason ) {
		$self             = self::instance();
		$state            = self::state( $slug );
		$tally            = isset( $state[ $bucket ] ) && is_array( $state[ $bucket ] ) ? $state[ $bucket ] : array();
		$tally[ $reason ] = (int) ( isset( $tally[ $reason ] ) ? $tally[ $reason ] : 0 ) + 1;
		$state[ $bucket ] = $tally;
		$self->write_state( $slug, $state );
	}

	/** 跳过原因的人话解释 */
	public static function skip_explanations() {
		return array(
			'missing_file' => __( 'image file no longer exists on disk (orphaned attachment record — safe to clean up)', 'auto-ai-seo' ),
			'decorative'   => __( 'too small to be meaningful content (logo/icon — alt is intentionally left empty)', 'auto-ai-seo' ),
			'has_value'    => __( 'already written by a human — left untouched', 'auto-ai-seo' ),
		);
	}

	/** 修补原因的人话解释 */
	public static function repair_explanations() {
		return array(
			'length'   => __( 'first draft ran too long and was rewritten to fit', 'auto-ai-seo' ),
			'language' => __( "first draft came back in the wrong language and was rewritten in the site's language", 'auto-ai-seo' ),
		);
	}

	public static function state( $slug ) {
		$all = get_option( self::STATE_OPTION );
		$all = is_array( $all ) ? $all : array();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : array(
			'status' => 'idle', 'total' => 0, 'queued' => 0,
			'done' => 0, 'failed' => 0, 'skipped' => 0, 'note' => '',
		);
	}

	private function set_state( $slug, array $state ) {
		$this->write_state( $slug, $state );
	}

	/** note_skip() 是静态的,需要一个可从静态上下文调用的写入口 */
	private function write_state( $slug, array $state ) {
		$all          = get_option( self::STATE_OPTION );
		$all          = is_array( $all ) ? $all : array();
		$all[ $slug ] = $state;
		update_option( self::STATE_OPTION, $all, false );
	}

	private function bump( $slug, $key, $by = 1 ) {
		$s        = $this->state( $slug );
		$s[ $key ] = (int) ( isset( $s[ $key ] ) ? $s[ $key ] : 0 ) + (int) $by;
		$this->set_state( $slug, $s );
	}
}
