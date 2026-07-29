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
	public function start( $slug ) {
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

		$this->set_state( $slug, array(
			'status'   => 'running',
			'total'    => (int) $job->count_candidates(),
			'queued'   => 0,
			'done'     => 0,
			'failed'   => 0,
			'skipped'  => 0,
			'started'  => current_time( 'mysql', true ),
			'note'     => '',
		) );

		as_enqueue_async_action(
			'aaseo_enqueue_' . str_replace( '-', '_', $slug ),
			array( 'slug' => $slug, 'offset' => 0 ),
			$job->group()
		);
		return true;
	}

	/** 分批入队:取一段候选 → 逐条排任务 → 如还有剩余,排下一轮入队任务 */
	public function enqueue_chunk( $slug, $offset = 0 ) {
		// AS 传参会把关联数组展开成位置参数,兼容两种形态
		if ( is_array( $slug ) ) {
			$args   = $slug;
			$slug   = isset( $args['slug'] ) ? $args['slug'] : '';
			$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		}
		$job = $this->get( $slug );
		if ( ! $job ) {
			return;
		}

		$ids = $job->find_candidates( self::ENQUEUE_CHUNK, (int) $offset );
		foreach ( $ids as $id ) {
			as_enqueue_async_action( $job->hook(), array( 'slug' => $slug, 'object_id' => (int) $id ), $job->group() );
		}
		$this->bump( $slug, 'queued', count( $ids ) );

		if ( count( $ids ) >= self::ENQUEUE_CHUNK ) {
			as_enqueue_async_action(
				'aaseo_enqueue_' . str_replace( '-', '_', $slug ),
				array( 'slug' => $slug, 'offset' => (int) $offset + self::ENQUEUE_CHUNK ),
				$job->group()
			);
		}
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

	public static function state( $slug ) {
		$all = get_option( self::STATE_OPTION );
		$all = is_array( $all ) ? $all : array();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : array(
			'status' => 'idle', 'total' => 0, 'queued' => 0,
			'done' => 0, 'failed' => 0, 'skipped' => 0, 'note' => '',
		);
	}

	private function set_state( $slug, array $state ) {
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
