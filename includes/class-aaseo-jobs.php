<?php
defined( 'ABSPATH' ) || exit;

/**
 * 模块注册表 + 队列调度层。
 *
 * 队列本体由 AASEO_Queue 提供(站上有 Action Scheduler 就用它,没有就用 WordPress
 * 自带的 cron),这个类只做三件队列不管的事:
 *   1. 把候选对象分批入队(不一次性取全量 ID)
 *   2. 在每条任务执行前把闸门过一遍(配额、就绪状态、暂停开关)
 *   3. 统计运行态给后台看
 */
class AASEO_Jobs {

	const STATE_OPTION = 'aaseo_job_state';
	const ENQUEUE_CHUNK = 200;   // 每次入队 200 条,分多批 —— 避免一次性取上万 ID
	                             // (cron 驱动下会被 AASEO_Queue::chunk_size() 收小,理由见那里)

	/**
	 * 入队动作参数的格式版本。**改动参数形状时必须 +1。**
	 *
	 * 为什么需要它:队列执行动作时用 do_action_ref_array( hook, array_values( args ) ),
	 * **键名会丢,只按位置传**(Action Scheduler 与 WP-Cron 两条路径都是如此)。
	 * 于是升级插件时,队列里遗留的旧格式动作会被新签名按位置错读 ——
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

	/**
	 * 分批入队链的 hook 名。
	 *
	 * 单独拎出来是因为它和工作项的 hook(AASEO_Job::hook())是**两个**队列身份:
	 * 清队列时必须两个都点名清 —— cron 驱动没有 group 概念,漏一个就会留下一节
	 * 还能自己接着跑的入队链。
	 */
	public static function chain_hook( $slug ) {
		return 'aaseo_enqueue_' . str_replace( '-', '_', $slug );
	}

	/** 把每个模块的执行回调挂到它自己的队列 hook 上 */
	public function attach_handlers() {
		foreach ( $this->jobs as $slug => $job ) {
			add_action( $job->hook(), array( $this, 'run_one' ), 10, 2 );
			add_action( self::chain_hook( $slug ), array( $this, 'enqueue_chunk' ), 10, 3 );
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
			return new WP_Error( 'no_job', sprintf( __( 'Unknown job: %s', 'ilang-auto-ai-seo' ), $slug ) );
		}
		if ( ! $job->is_ready() ) {
			return new WP_Error( 'not_ready', __( 'Job is not ready to run (API key missing?)', 'ilang-auto-ai-seo' ) );
		}

		/*
		 * 这里原来先查一遍 "Action Scheduler 在不在",没有就报错。那个分支已经没有意义了 ——
		 * cron 驱动不依赖任何第三方,队列**恒可用**。真正会出事的是另一回事:
		 * 排队动作本身失败(别的插件用 pre_schedule_event 之类把它挡了)。
		 * 与其做一个永远为真的前置检查,不如**排完看结果** —— 那才是用户真正关心的
		 * "我点了开始,到底有没有排进去"。
		 */
		$total = (int) $job->count_candidates();
		if ( $limit > 0 ) {
			$total = min( $total, (int) $limit );
		}

		/*
		 * 这一条告警得在**进度条旁边**说,不能只写在页面下方的环境区:
		 * 站点关了 WP-Cron 又没配服务器 crontab 时,队列排得进去、却根本没人来踢 ——
		 * 用户看到的就是一个永远停在 0 的进度条。最需要这句解释的时刻就是刚点下"开始"的时刻。
		 */
		$note = '';
		if ( 'cron' === AASEO_Queue::driver() && defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$note = AASEO_Probe::cron_disabled_warning();
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
			'note'     => $note,
		) );

		$queued = AASEO_Queue::enqueue_async(
			self::chain_hook( $slug ),
			array( 'slug' => $slug, 'cursor' => 0, 'v' => self::ENQUEUE_ARGS_VERSION ),
			$job->group()
		);
		if ( ! $queued ) {
			// 没排进去就别让界面显示"运行中" —— 那会让人对着一个永远不动的进度条等下去
			$this->set_state( $slug, array_merge( $this->state( $slug ), array( 'status' => 'idle' ) ) );
			$message = __( 'Could not add the task to the background queue.', 'ilang-auto-ai-seo' );
			// 排队失败几乎总是别的插件挂了 pre_schedule_event 之类的过滤器;
			// WordPress 给的那句原话是用户唯一查得下去的线索,带上
			$why = AASEO_Queue::last_error();
			if ( '' !== $why ) {
				$message .= ' ' . $why;
			}
			return new WP_Error( 'not_queued', $message );
		}
		return true;
	}

	/**
	 * 分批入队(游标分页)。
	 *
	 * 每次只取"键 < 游标"的下一批,把本批最小键作为下一批的游标。
	 * 游标严格递减,所以:每条恰好访问一次、不会漏、不会重、必然终止 ——
	 * 不需要补扫轮次,也不需要轮数上限。
	 *
	 * 仍然保留 AASEO_Queue::has_scheduled() 去重:同一模块被连点两次"开始"时,
	 * 两条入队链会各自扫一遍,去重能挡住重复排队。
	 */
	public function enqueue_chunk( $slug, $cursor = 0, $args_version = 0 ) {
		// 队列会把关联数组展开成位置参数(AS 与 WP-Cron 都是),兼容两种形态
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
			AASEO_Queue::schedule_single(
				time() + 300,
				self::chain_hook( $slug ),
				array( 'slug' => $slug, 'cursor' => (int) $cursor, 'v' => self::ENQUEUE_ARGS_VERSION ),
				$job->group()
			);
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

		// 批量由驱动决定:cron 路径下要收小,理由见 AASEO_Queue::chunk_size()
		$chunk = AASEO_Queue::chunk_size( self::ENQUEUE_CHUNK );
		$want  = $limit > 0 ? min( $chunk, $limit - $done ) : $chunk;
		$ids   = array_map( 'intval', (array) $job->find_candidates( $want, (int) $cursor ) );
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
			if ( AASEO_Queue::has_scheduled( $job->hook(), $payload, $job->group() ) ) {
				continue; // 已在队列里,不重复排
			}
			if ( ! AASEO_Queue::enqueue_async( $job->hook(), $payload, $job->group() ) ) {
				continue; // 没排进去就别计数 —— queued 是"已排队"的账,不是"试过"的账
			}
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
		 * 这里宁可少排一批也不肯冒无限入队的风险 —— 那会把队列表(或 cron option)撑爆。
		 */
		$next = min( $ids );
		if ( $next <= 0 || ( (int) $cursor > 0 && $next >= (int) $cursor ) ) {
			return;
		}

		AASEO_Queue::enqueue_async(
			self::chain_hook( $slug ),
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
			$object_id = isset( $args['object_id'] ) ? $args['object_id'] : 0;
		}
		/*
		 * 强转必须在这里做,不能只在上面那个数组分支里做:cron 驱动把参数**规范成字符串**
		 * 存进事件里(否则 md5(serialize()) 认不出同一条活,去重会失效),
		 * 于是按位置传进来的 $object_id 是 '123' 而不是 123。
		 * 在入口统一钉成 int,下游(handle_one 及各子类)就完全感知不到驱动的差别。
		 */
		$slug      = (string) $slug;
		$object_id = (int) $object_id;

		$job = $this->get( $slug );
		if ( ! $job || ! $object_id ) {
			return;
		}

		$state  = $this->state( $slug );
		$status = isset( $state['status'] ) ? $state['status'] : 'idle';

		/*
		 * **取消闸门 —— 这是"取消了却还在花钱"的唯一堵口。**
		 *
		 * 队列两侧都拦不住已经发出去的那一条:
		 *   · WP-Cron:wp-cron.php 先 wp_unschedule_event 再**无条件** do_action,
		 *     它不会回头复查这个事件是不是已经被取消了;
		 *   · AS:as_unschedule_all_actions 只能取消 PENDING 的,已经被 claim 成 RUNNING 的
		 *     取消之后照样执行;
		 *   · 再加上 1.1.x 遗留的孤儿动作(见 AASEO_Queue::purge_legacy())。
		 * 三条路都从这里进来,而 handle_one 不查自己的完成标记 —— 拦不住就是重复调 AI。
		 *
		 * 闸门**只收 idle**(取消/从未启动),不能写成 `'running' !== $status` 就拦:
		 * AS 并发跑最后一批时,pending_count 可能已经归零、状态被先一步置成 done,
		 * 用 running 收口会把还没跑完的那几条一起丢掉 —— 那是实打实的活丢了。
		 */
		if ( 'idle' === $status ) {
			return;
		}
		if ( 'paused' === $status ) {
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
				'note' => __( 'Daily token cap reached; will continue tomorrow.', 'ilang-auto-ai-seo' ),
			) ) );
			return;
		}

		$result = $job->handle_one( $object_id );

		if ( is_wp_error( $result ) ) {
			$this->bump( $slug, 'failed', 1 );
			$message = sprintf( '%s #%d: %s', $slug, $object_id, $result->get_error_message() );

			/*
			 * **抛不抛异常要看"这一条是谁在跑",这不是风格问题。**
			 *
			 * AS 在执行:抛。Action Scheduler 会接住它、记进自己的日志表(工具→Scheduled Actions
			 * 可查),并按其策略标记该动作。异常消息只进日志、不进页面,但静态分析看不出
			 * 这一点,故仍对拼入的值做转义。
			 *
			 * 其余情况(wp-cron.php、CLI、直接 do_action):**绝对不能抛**。wp-cron.php 里没有人接,
			 * 一条失败就是一个 fatal,同一轮里还没轮到的到期事件全部作废 —— 连别的插件的事件
			 * 一起陪葬。一条坏图能拖垮整批,这是实打实的可靠性倒退。
			 * 这边把最后一条错误留在运行态里:失败计数照记,原因也仍然查得到(后台会显示)。
			 *
			 * 判断用 is_as_executing() 而**不是 driver()**:driver() 是站点全局属性,
			 * 驱动半途翻转(装上 WooCommerce)之后,早先排进 wp-cron 的事件仍会由 wp-cron.php
			 * 触发,那时 driver() 已经是 'as' —— 照它判就会在 cron 请求里抛出无人接管的异常。
			 */
			if ( AASEO_Queue::is_as_executing() ) {
				$this->maybe_finish( $slug ); // 最后一条恰好失败时,状态也得能走到 done
				throw new Exception( esc_html( $message ) );
			}
			$this->set_state( $slug, array_merge( $this->state( $slug ), array( 'last_error' => $message ) ) );
			$this->maybe_finish( $slug );
			return;
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
		AASEO_Queue::schedule_single(
			time() + (int) $delay,
			$job->hook(),
			array( 'slug' => $job->slug(), 'object_id' => (int) $object_id ),
			$job->group()
		);
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

	/**
	 * 取消:清掉该模块在队列里所有待执行任务。
	 *
	 * **两个 hook 都要点名清**:工作项挂 aaseo_run_*,分批入队链挂 aaseo_enqueue_*。
	 * AS 路径靠 group 就够,但 cron 驱动没有 group —— 只清工作项会留下一节合法的入队链,
	 * 它下次醒来就从原游标接着扫、接着排、接着花钱,而用户以为自己已经取消了。
	 */
	public function cancel( $slug ) {
		$job = $this->get( $slug );
		if ( $job ) {
			AASEO_Queue::cancel_group(
				array( $job->hook(), self::chain_hook( $slug ) ),
				$job->group()
			);
		}
		$s = $this->state( $slug );
		$this->set_state( $slug, array_merge( $s, array( 'status' => 'idle' ) ) );
	}

	/**
	 * 队列里还剩多少条 —— 完成判定的唯一依据,所以这个数必须准确。
	 *
	 * 具体怎么数交给 AASEO_Queue(AS 走存储层的 count 查询,cron 走 cron 数组遍历)。
	 * 那里记着一条实测踩过的坑:as_get_scheduled_actions() 根本没有 count 返回格式,
	 * 传 'count' 拿回来的是 (int)数组 —— 永远是 0 或 1,于是任务跑到一半就被标成"已完成"。
	 */
	public function pending_count( $slug ) {
		$job = $this->get( $slug );
		if ( ! $job ) {
			return 0;
		}
		return (int) AASEO_Queue::pending_count( $job->hook() );
	}

	/**
	 * @param bool $terminal 由链条最后一环(空扫描)调用时为 true:
	 *                       AS 路径下那一环自己正处于 RUNNING,而 as_has_scheduled_action()
	 *                       连 RUNNING 一起查,链条检查会看见自己 —— 不跳过就永远收不了尾。
	 *                       cron 路径下事件在执行前就已被摘出 cron 数组,看不见自己,
	 *                       这个参数是无害的空操作。两边语义因此一致。
	 */
	private function maybe_finish( $slug, $terminal = false ) {
		if ( 0 !== $this->pending_count( $slug ) ) {
			return;
		}
		/*
		 * 工作项排空 ≠ 干完了:入队链是分批的,这一批跑完时下一批往往还挂在队列上。
		 * 只看工作项就会在中途报"已完成",而其实还有几百条没入队 —— 实测见过。
		 */
		if ( ! $terminal && AASEO_Queue::has_scheduled( self::chain_hook( $slug ) ) ) {
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
			'missing_file' => __( 'image file no longer exists on disk (orphaned attachment record — safe to clean up)', 'ilang-auto-ai-seo' ),
			'decorative'   => __( 'too small to be meaningful content (logo/icon — alt is intentionally left empty)', 'ilang-auto-ai-seo' ),
			'has_value'    => __( 'already written by a human — left untouched', 'ilang-auto-ai-seo' ),
			'desc_ok'      => __( 'existing description already does its job (AI-judged) — left untouched', 'ilang-auto-ai-seo' ),
			'no_content'   => __( 'post has no content to describe', 'ilang-auto-ai-seo' ),
			'gone'         => __( 'post no longer exists or is not published', 'ilang-auto-ai-seo' ),
			'thin'         => __( 'too few posts to be worth describing (threshold configurable)', 'ilang-auto-ai-seo' ),
			'no_targets'   => __( 'no related posts share a category or tag — nothing worth linking to', 'ilang-auto-ai-seo' ),
			'no_links'     => __( 'reviewed — no link scored high enough to suggest (that is a pass, not a failure)', 'ilang-auto-ai-seo' ),
			'no_links_out' => __( 'no outbound links to check', 'ilang-auto-ai-seo' ),
			'links_ok'     => __( 'all outbound links respond — nothing to report', 'ilang-auto-ai-seo' ),
		);
	}

	/** 修补原因的人话解释 */
	public static function repair_explanations() {
		return array(
			'length'   => __( 'first draft ran too long and was rewritten to fit', 'ilang-auto-ai-seo' ),
			'language' => __( "first draft came back in the wrong language and was rewritten in the site's language", 'ilang-auto-ai-seo' ),
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
