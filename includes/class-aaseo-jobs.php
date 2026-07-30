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
	const ENQUEUE_CHUNK = 200;   // 每次入队 200 条,分多批 —— 避免一次性取上万 ID

	/**
	 * 入队动作参数的格式版本。**改动参数形状时必须 +1。**
	 *
	 * 为什么需要它:Action Scheduler 执行动作时用 do_action_ref_array( hook, array_values( args ) ),
	 * **键名会丢,只按位置传**。于是升级插件时,队列里遗留的旧格式动作会被新签名按位置错读 ——
	 * 实测踩过:旧动作 {slug, offset:200, round:0} 在新签名下把 200 当成了游标,
	 * 于是只查到 ID<200 的 7 张图,链条随即断掉,几百张活悄悄没了。
	 * 参数里带上版本号、对不上就丢弃,这类事故就不可能发生 —— 而不是靠"升级时记得清队列"。
	 */
	const ENQUEUE_ARGS_VERSION = 2;

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
			add_action( 'aaseo_enqueue_' . str_replace( '-', '_', $slug ), array( $this, 'enqueue_chunk' ), 10, 3 );
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
			/* translators: %s: job slug */
			return new WP_Error( 'no_job', sprintf( __( 'Unknown job: %s', 'auto-ai-seo' ), $slug ) );
		}
		if ( ! $job->is_ready() ) {
			return new WP_Error( 'not_ready', __( 'Job is not ready to run (API key missing?)', 'auto-ai-seo' ) );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'no_scheduler', __( 'Action Scheduler unavailable.', 'auto-ai-seo' ) );
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
			array( 'slug' => $slug, 'cursor' => 0, 'v' => self::ENQUEUE_ARGS_VERSION ),
			$job->group()
		);
		return true;
	}

	/**
	 * 分批入队(游标分页)。
	 *
	 * 每次只取"键 < 游标"的下一批,把本批最小键作为下一批的游标。
	 * 游标严格递减,所以:每条恰好访问一次、不会漏、不会重、必然终止 ——
	 * 不需要补扫轮次,也不需要轮数上限。
	 *
	 * 仍然保留 as_has_scheduled_action() 去重:同一模块被连点两次"开始"时,
	 * 两条入队链会各自扫一遍,去重能挡住重复排队。
	 */
	public function enqueue_chunk( $slug, $cursor = 0, $args_version = 0 ) {
		// AS 会把关联数组展开成位置参数,兼容两种形态
		if ( is_array( $slug ) ) {
			$args         = $slug;
			$slug         = isset( $args['slug'] ) ? $args['slug'] : '';
			$cursor       = isset( $args['cursor'] ) ? (int) $args['cursor'] : 0;
			$args_version = isset( $args['v'] ) ? (int) $args['v'] : 0;
		}
		// 旧格式的遗留动作:按位置读会误判,直接丢弃(见 ENQUEUE_ARGS_VERSION 注释)
		if ( (int) $args_version !== self::ENQUEUE_ARGS_VERSION ) {
			return;
		}
		$job = $this->get( $slug );
		if ( ! $job ) {
			return;
		}

		$state  = $this->state( $slug );
		$status = isset( $state['status'] ) ? $state['status'] : 'idle';
		/*
		 * 状态闸门 —— cancel() 只能取消 PENDING 的动作,一条正在 RUNNING 的链节它够不着,
		 * 那条链节跑完会把整个任务复活。让链条自己看状态:
		 *   · 已取消(idle)/已完成 → 链条就地断掉;
		 *   · 暂停 → 把**同一节**推迟重排,恢复后从原游标继续,而不是丢掉剩余的扫描。
		 */
		if ( 'paused' === $status ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + 300,
					'aaseo_enqueue_' . str_replace( '-', '_', $slug ),
					array( 'slug' => $slug, 'cursor' => (int) $cursor, 'v' => self::ENQUEUE_ARGS_VERSION ),
					$job->group()
				);
			}
			return;
		}
		if ( 'running' !== $status ) {
			return;
		}

		$limit = isset( $state['limit'] ) ? (int) $state['limit'] : 0;
		$done  = (int) ( isset( $state['queued'] ) ? $state['queued'] : 0 );
		if ( $limit > 0 && $done >= $limit ) {
			return; // --limit 已排满
		}

		$want = $limit > 0 ? min( self::ENQUEUE_CHUNK, $limit - $done ) : self::ENQUEUE_CHUNK;
		$ids  = array_map( 'intval', (array) $job->find_candidates( $want, (int) $cursor ) );
		if ( ! $ids ) {
			/*
			 * 扫完了 —— 本节就是链条的最后一环,之后不会再有任何入队动作。
			 * 收尾必须在这里发起:链条按创建顺序执行,这个空扫描总是排在最后一个工作项
			 * 之后跑;而最后一个工作项调用 maybe_finish 时会看见本动作还挂着(pending),
			 * 于是放弃 —— 若这里也不收尾,状态就永远停在 running(实测踩过)。
			 * $terminal=true 让 maybe_finish 跳过"链条还在吗"的检查:本动作自己正 RUNNING,
			 * 不跳过就会看见自己、永远收不了尾。
			 */
			$this->maybe_finish( $slug, true );
			return;
		}

		$fresh = 0;
		foreach ( $ids as $id ) {
			$payload = array( 'slug' => $slug, 'object_id' => $id );
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( $job->hook(), $payload, $job->group() ) ) {
				continue; // 已在队列里,不重复排
			}
			as_enqueue_async_action( $job->hook(), $payload, $job->group() );
			++$fresh;
		}
		if ( $fresh ) {
			$this->bump( $slug, 'queued', $fresh );
			// 又排进了新活 → 状态必须回到 running。
			// 否则一旦中途被 maybe_finish 误标成 done,界面就会一直显示"已完成"而其实还在跑。
			$s = $this->state( $slug );
			if ( 'done' === ( isset( $s['status'] ) ? $s['status'] : '' ) ) {
				$this->set_state( $slug, array_merge( $s, array( 'status' => 'running' ) ) );
			}
		}

		/*
		 * 防守子类不守约定:游标必须严格变小,否则就是死循环。
		 * 这里宁可少排一批也不肯冒无限入队的风险 —— 那会把 AS 表撑爆。
		 */
		$next = min( $ids );
		if ( $next <= 0 || ( (int) $cursor > 0 && $next >= (int) $cursor ) ) {
			return;
		}

		as_enqueue_async_action(
			'aaseo_enqueue_' . str_replace( '-', '_', $slug ),
			array( 'slug' => $slug, 'cursor' => $next, 'v' => self::ENQUEUE_ARGS_VERSION ),
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
			$this->maybe_finish( $slug ); // 最后一条恰好失败时,状态也得能走到 done
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
			$this->maybe_finish( $slug ); // 最后一条恰好被跳过时同理
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

	/**
	 * 队列里还剩多少条(直接问 Action Scheduler 的存储层,它才是真相)。
	 *
	 * 必须走 Store::query_actions( ..., 'count' ):as_get_scheduled_actions() 没有
	 * count 返回格式,之前传 'count' 实际得到的是 (int) 数组 —— 永远是 0 或 1,
	 * 这正是"任务中途被误标为已完成"的根源(实测踩过)。
	 */
	public function pending_count( $slug ) {
		$job = $this->get( $slug );
		if ( ! $job || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}
		return (int) ActionScheduler_Store::instance()->query_actions( array(
			'hook'   => $job->hook(),
			'status' => ActionScheduler_Store::STATUS_PENDING,
		), 'count' );
	}

	/**
	 * @param bool $terminal 由链条最后一环(空扫描)调用时为 true:
	 *                       那一环自己正处于 RUNNING,链条检查会看见自己,必须跳过。
	 */
	private function maybe_finish( $slug, $terminal = false ) {
		if ( 0 !== $this->pending_count( $slug ) ) {
			return;
		}
		/*
		 * 工作项排空 ≠ 干完了:入队链是分批的,这一批跑完时下一批往往还挂在队列上。
		 * 只看工作项就会在中途报"已完成",而其实还有几百条没入队 —— 实测见过。
		 */
		if ( ! $terminal
			&& function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( 'aaseo_enqueue_' . str_replace( '-', '_', $slug ) ) ) {
			return;
		}
		$s = $this->state( $slug );
		// 只从 running 走向 done —— 不去覆盖 paused/idle(那是用户的显式操作)
		if ( 'running' !== ( isset( $s['status'] ) ? $s['status'] : '' ) ) {
			return;
		}
		$this->set_state( $slug, array_merge( $s, array(
			'status'   => 'done',
			'finished' => current_time( 'mysql', true ),
		) ) );
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
			'desc_ok'      => __( 'existing description already does its job (AI-judged) — left untouched', 'auto-ai-seo' ),
			'no_content'   => __( 'post has no content to describe', 'auto-ai-seo' ),
			'gone'         => __( 'post no longer exists or is not published', 'auto-ai-seo' ),
			'thin'         => __( 'too few posts to be worth describing (threshold configurable)', 'auto-ai-seo' ),
			'no_targets'   => __( 'no related posts share a category or tag — nothing worth linking to', 'auto-ai-seo' ),
			'no_links'     => __( 'reviewed — no link scored high enough to suggest (that is a pass, not a failure)', 'auto-ai-seo' ),
			'no_links_out' => __( 'no outbound links to check', 'auto-ai-seo' ),
			'links_ok'     => __( 'all outbound links respond — nothing to report', 'auto-ai-seo' ),
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
