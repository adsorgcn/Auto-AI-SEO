<?php
defined( 'ABSPATH' ) || exit;

/**
 * 后台队列门面 —— 一套调用,两个驱动。
 *
 * 为什么有这一层:队列本体原来是**打包进来的** Action Scheduler。打包的代价很实在 ——
 * 官方 Plugin Check 对那份第三方代码报 312 个 ERROR(全不是我们写的),而且它是 GPLv3,
 * 会把整个发行包拽成 GPLv3。所以改成**可选依赖**:
 *
 *   · 站上已经有别的插件提供 Action Scheduler(WooCommerce 等极常见)→ 照旧用它,行为不变;
 *   · 没有 → 用 WordPress 自带的 cron 兜底。慢一点、粗一点,但不丢活、不重复干活。
 *
 * 两条路径必须让上层**看不出区别**,难点全在这几处,逐条在下面的方法里解释:
 *   1. 参数传递语义(AS 走 array_values,WP-Cron 也按位置展开 —— 但类型会漂)
 *   2. 去重(WP-Cron 用 md5(serialize(args)) 认身份,序列化差一点就认不出来)
 *   3. 待处理计数(WP-Cron 没有查询 API,而这个数是"任务完成没有"的唯一依据)
 *   4. 立即执行(WP-Cron 没有"异步"概念,只能排在当下再想办法把它踢起来)
 */
class AASEO_Queue {

	/**
	 * cron 驱动:每 CRON_STEP 秒最多放行 CRON_BURST 条工作项。
	 *
	 * 为什么要摊开排,而不是全部排在 time():wp-cron.php 一次请求会把**当时所有到期事件**
	 * 一口气跑完,而且它不给自己设时间预算。几十条 AI 调用堆在同一秒,请求会在第几条上被
	 * PHP(或前置的 nginx)掐死;被掐死的那一条在执行前就已经被 WordPress 从 cron 数组里
	 * 摘掉了 —— 于是它从队列里消失,只能靠"候选集即重试"下次再捞。
	 * 摊开之后每轮 cron 只跑几条,请求跑得完,活就不会掉在半路。
	 *
	 * 代价是慢:每分钟几条,两千张图要跑几个小时。这是明码标价的取舍,环境面板里会讲清楚。
	 * CRON_BURST 只是**上限**,实际用几条由 cron_burst() 按本站实测耗时推算。
	 */
	const CRON_BURST = 5;
	const CRON_STEP  = 60;

	/**
	 * "队列里有 1.1.x 遗留的 Action Scheduler 动作待清理"的标记。
	 * 由 AASEO_Install::maybe_upgrade() 写下,由 purge_legacy() 清掉。理由见那两处。
	 */
	const PURGE_OPTION = 'aaseo_purge_legacy_queue';

	/** 本次请求内已排了第几条 —— 摊开排队的序号,见 next_cron_slot() */
	private static $cron_seq = 0;

	/** shutdown 上的 spawn 钩子只挂一次 */
	private static $spawn_hooked = false;

	/** 当前是不是正**由 Action Scheduler 执行**一条动作,见 is_as_executing() */
	private static $as_executing = false;

	/** 最近一次 cron 排队失败的原因(WordPress 给的原话),见 last_error() */
	private static $last_error = '';

	// ---------------------------------------------------------------- 启动

	/**
	 * 挂钩子。由主文件的 aaseo_boot() 调用。
	 *
	 * 只做一件事:记录"这一刻是不是 Action Scheduler 在执行我们的动作"。
	 * 为什么需要它,见 is_as_executing()。
	 */
	public static function init() {
		add_action( 'action_scheduler_begin_execute', array( __CLASS__, 'mark_as_executing' ), 0 );
		add_action( 'action_scheduler_after_execute', array( __CLASS__, 'clear_as_executing' ), 99 );
		add_action( 'action_scheduler_failed_execution', array( __CLASS__, 'clear_as_executing' ), 99 );
	}

	/** @internal 钩子回调,勿直接调用 */
	public static function mark_as_executing() {
		self::$as_executing = true;
	}

	/** @internal 钩子回调,勿直接调用 */
	public static function clear_as_executing() {
		self::$as_executing = false;
	}

	/**
	 * 当前这一条动作是不是 Action Scheduler 在跑?
	 *
	 * **这和 driver() 不是一回事,混用会出 fatal。** driver() 回答的是"本站现在该用哪个队列",
	 * 是个全局属性;而"谁在执行手头这一条"是**每条动作各自的事实** —— 驱动在任务半途翻转时
	 * (装上 WooCommerce → 从 cron 变成 as),此前排进 wp-cron 的事件仍会由 wp-cron.php 触发,
	 * 那时 driver() 已经是 'as' 了。若照着 driver() 决定"失败就抛异常",异常会在 wp-cron.php 里
	 * 没人接 —— 整个 cron 请求 fatal,同一轮里别的插件的到期事件全被跳过。
	 *
	 * 也**不能用 wp_doing_cron() 反过来判断**:Action Scheduler 自己的队列运行器正是被
	 * action_scheduler_run_queue 这个 cron 事件驱动的,那样判会把正常的 AS 路径也当成 cron,
	 * 失败就不再写进 AS 的日志表 —— 把唯一的排错线索关掉。
	 *
	 * 只有 AS 亲自 begin_execute 过、还没 after_execute 的那个窗口里,才算 AS 在执行。
	 */
	public static function is_as_executing() {
		return self::$as_executing;
	}

	/** 最近一次排队失败时 WordPress 给的原因;没有就是空串 */
	public static function last_error() {
		return (string) self::$last_error;
	}

	// ---------------------------------------------------------------- 驱动判定

	/**
	 * 当前用哪个驱动。有 Action Scheduler 就优先用它(它有独立的表、日志和重试策略,
	 * 各方面都比 WP-Cron 强);没有才退到 cron。
	 *
	 * @return string 'as' | 'cron'
	 */
	public static function driver() {
		return self::as_ready() ? 'as' : 'cron';
	}

	/**
	 * 有没有可用的驱动。
	 *
	 * **恒为 true** —— cron 驱动不依赖任何第三方,永远在。留着这个方法是为了让调用方
	 * 保持"先问再排"的写法,将来真出现"排不进去"的驱动时不用回头改调用点。
	 * 注意:它回答的是"有没有队列",不是"队列跑不跑得动" —— 站点若把 WP-Cron 关了又没配
	 * 系统 crontab,队列在,但没人来踢它。那种情况在环境面板里如实说,而不是在这里返回 false
	 * (返回 false 会把配了系统 crontab 的正常站点一起误伤,那才是更常见的情形)。
	 */
	public static function available() {
		return true;
	}

	/**
	 * Action Scheduler 到底能不能用。
	 *
	 * 光看 function_exists 不够:AS 的 API 在 action_scheduler_init 之前是**空转**的,
	 * as_enqueue_async_action() 直接返回 0 并记一条 doing_it_wrong。那个窗口里报 'as',
	 * 排队就会静默丢失。所以没初始化就当它不在,走 cron —— 宁可慢,不可丢。
	 */
	private static function as_ready() {
		if ( ! function_exists( 'as_enqueue_async_action' )
			|| ! function_exists( 'as_schedule_single_action' )
			|| ! function_exists( 'as_has_scheduled_action' )
			|| ! function_exists( 'as_unschedule_all_actions' )
			|| ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}
		// 不传函数名调用 —— 传了会触发 _doing_it_wrong,而我们这里只是在"问",不是在"用"
		if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'is_initialized' ) ) {
			return (bool) ActionScheduler::is_initialized();
		}
		return true;
	}

	/**
	 * 一批入队多少条工作项。
	 *
	 * cron 驱动下刻意收小:cron 事件全都存在 `cron` 这一个 option 里,排几百条就是往一个
	 * option 里塞几百个事件,每次读写整块反序列化 —— AS 用独立的表正是为了躲开这个。
	 * 配合上面的摊开排队,收小之后队列里常驻的事件数是个位数,链条自己就把节奏卡住了:
	 * 上一批快跑完时下一批才排进来。
	 *
	 * @param int $default AS 路径下用的批量
	 */
	public static function chunk_size( $default ) {
		return 'cron' === self::driver() ? 25 : max( 1, (int) $default );
	}

	// ---------------------------------------------------------------- 入队

	/**
	 * 立即排一条(尽可能快地跑起来)。
	 *
	 * @return bool 真的排进去了吗 —— 调用方据此判断"点了开始却什么都没发生"
	 */
	public static function enqueue_async( $hook, $args = array(), $group = '' ) {
		if ( 'as' === self::driver() ) {
			return (int) as_enqueue_async_action( (string) $hook, (array) $args, (string) $group ) > 0;
		}
		// WP-Cron 没有"异步"这回事,排在当下就是最快的了;spawn 在 cron_schedule 里做
		return self::cron_schedule( self::next_cron_slot(), $hook, $args );
	}

	/**
	 * 定时排一条。
	 *
	 * cron 分支不能把调用方的时间戳原样透传 —— 那会绕过 enqueue_async 的摊开排队。
	 * 最坏的情形是**到额重排**:上层用 time() + seconds_until_tomorrow(),而后者算的是
	 * "距本地午夜还有多少秒",两者相加是**同一个常数**,于是当天所有到额的条目全部落在
	 * 同一秒上 —— 午夜后第一个 cron 请求会一口气把它们全跑掉,请求被 PHP 掐死,
	 * 而每一条在 do_action 之前就已被 wp_unschedule_event 摘出 cron 数组,直接蒸发。
	 *
	 * 所以在 cron 分支加一层**确定性抖动**:偏移量由 hook+参数散列决定,同一条活
	 * 无论重排多少次都落在同一格 —— 幂等,也不破坏 md5(serialize(args)) 的去重身份。
	 * (抖动只加在 cron 这一侧;AS 有自己的队列运行器,不需要被无谓打散。)
	 *
	 * @param int $ts unix 时间戳
	 * @return bool
	 */
	public static function schedule_single( $ts, $hook, $args = array(), $group = '' ) {
		if ( 'as' === self::driver() ) {
			return (int) as_schedule_single_action( (int) $ts, (string) $hook, (array) $args, (string) $group ) > 0;
		}
		$hook = (string) $hook;
		$args = self::cron_args( $args );
		return self::cron_schedule( (int) $ts + self::cron_jitter( $hook, $args ), $hook, $args );
	}

	// ---------------------------------------------------------------- 查询

	/**
	 * 同 hook + 同参数的任务是否已在队列里(去重用)。
	 *
	 * 两条路径都查,不只查当前驱动。原因是驱动**会在任务半途改变** —— 用户停用了
	 * WooCommerce,AS 就没了,驱动从 'as' 掉到 'cron'。这时只查 cron 会看不见 AS 里
	 * 还挂着的那批,于是同一批活被重新排一遍(用户为同一张图付两次钱)。
	 * 多查一边的代价只是一次 option 读取,方向也安全:宁可误判"已排过"而少排,
	 * 也不要漏判而重排 —— 漏排的对象仍在候选集里,下次自然会被捞回来。
	 *
	 * @param array|null $args null = 不限参数,只问"这个 hook 上还有活吗"
	 */
	public static function has_scheduled( $hook, $args = null, $group = '' ) {
		if ( self::as_ready() && as_has_scheduled_action( (string) $hook, $args, (string) $group ) ) {
			return true;
		}
		return self::cron_has( $hook, $args );
	}

	/**
	 * 该 hook 上还有多少条待处理。**这个数必须准确** —— 它是"任务是否已完成"的唯一依据,
	 * 算小了就会把跑到一半的任务标成"已完成"(AS 那边实测踩过一次:as_get_scheduled_actions()
	 * 根本没有 count 返回格式,传 'count' 拿回来的是 (int)数组,永远是 0 或 1)。
	 *
	 * 两条路径都数,理由同 has_scheduled():多数不会误判完成,少数会。
	 *
	 * 两边的语义是对齐的 —— 正在执行的那一条都不算在内:
	 *   · AS:执行中的动作状态是 RUNNING,不是 PENDING;
	 *   · WP-Cron:wp-cron.php 在 do_action 之前就把事件从 cron 数组里摘掉了。
	 */
	public static function pending_count( $hook ) {
		$n = 0;
		if ( self::as_ready() ) {
			$n += (int) ActionScheduler_Store::instance()->query_actions(
				array(
					'hook'   => (string) $hook,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				),
				'count'
			);
		}
		return $n + self::cron_count( $hook );
	}

	// ---------------------------------------------------------------- 清空

	/**
	 * 清空一个模块的全部排队。
	 *
	 * $hook 可以传数组:一个模块的活分挂在两个 hook 上(工作项 aaseo_run_*,
	 * 分批入队链 aaseo_enqueue_*)。AS 路径靠 group 就能一网打尽,**cron 路径没有 group 概念**,
	 * 必须把每个 hook 都点名清 —— 只清工作项会留下一节合法的入队链,插件一重新启用它就
	 * 从原游标接着扫、接着花钱,用户根本没按过"开始"。
	 *
	 * @param string|string[] $hook
	 * @param string          $group AS 的组名;cron 路径忽略(每个模块的 hook 本来就唯一)
	 */
	public static function cancel_group( $hook, $group = '' ) {
		$hooks = array_filter( array_map( 'strval', (array) $hook ) );

		if ( self::as_ready() ) {
			if ( '' !== (string) $group ) {
				as_unschedule_all_actions( '', array(), (string) $group );
			}
			foreach ( $hooks as $one ) {
				as_unschedule_all_actions( $one );   // 无 group 的散件兜底
			}
		}
		foreach ( $hooks as $one ) {
			wp_unschedule_hook( $one );
		}
	}

	/**
	 * 清掉 1.1.x 遗留在 Action Scheduler 表里的动作。挂在 init:1(见主文件)。
	 *
	 * 为什么必须有这一趟:1.1.0 打包了 Action Scheduler,升级到 1.2.0 时那些 pending 的
	 * aaseo_run_* / aaseo_enqueue_* 就烂在 wp_actionscheduler_actions 表里了 ——
	 * **升级路径上没有任何地方会清它们**。WordPress 更新插件走 deactivate_plugins( $plugin, true ),
	 * silent=true **不跑停用钩子**,AASEO_Install::deactivate() 根本不会被调用;
	 * 而升级完成时站上已经没有 AS 了,就算跑了也够不着那张表。
	 *
	 * 于是它们成了会自己复活的孤儿:用户哪天装上 WooCommerce,AS 一初始化就把这批全跑掉 ——
	 * 那可是真金白银的 AI 调用。所以由升级例程留下标记(见 AASEO_Install::maybe_upgrade()),
	 * 由这里在 AS **真的回来了**的那一刻清账。
	 *
	 * 为什么挂 init:1 而不是 action_scheduler_init:后者在 plugins_loaded:1 就烧掉了,
	 * 那时模块还没注册(注册发生在 aaseo_boot 里),all() 是空的,等于什么都没清。
	 * AS 一直不回来,标记就一直留着 —— 留着没有任何副作用,回来那天照样清得掉。
	 */
	public static function purge_legacy() {
		if ( ! get_option( self::PURGE_OPTION ) ) {
			return;
		}
		// AS 不在就先不动:标记留到它回来那天再兑现(孤儿动作只有 AS 在时才跑得起来)
		if ( ! self::as_ready() || ! class_exists( 'AASEO_Jobs' ) ) {
			return;
		}
		foreach ( AASEO_Jobs::instance()->all() as $job ) {
			as_unschedule_all_actions( $job->hook() );
			as_unschedule_all_actions( AASEO_Jobs::chain_hook( $job->slug() ) );
			as_unschedule_all_actions( '', array(), $job->group() );
		}
		delete_option( self::PURGE_OPTION );
	}

	// ---------------------------------------------------------------- cron 驱动内部

	/**
	 * 排一条 cron 事件。
	 *
	 * @return bool
	 */
	private static function cron_schedule( $ts, $hook, $args ) {
		$ts               = (int) $ts;
		$ts               = $ts > 0 ? $ts : time();
		$hook             = (string) $hook;
		$args             = self::cron_args( $args );
		self::$last_error = '';

		/*
		 * 先自己去重。WordPress 自带的重复检测只看前后 10 分钟的窗口,而我们的工作项
		 * 会摊到几十分钟甚至第二天(到额后推迟重排),窗口外就查不到了 —— 那正是会
		 * 重复干活的地方。这里把窗口拉到全量。
		 */
		if ( wp_next_scheduled( $hook, $args ) ) {
			return true;   // 已经在队列里 = 这活会被干,对调用方而言就是成功
		}

		/*
		 * 第四个参数 $wp_error=true 必须传 —— 不传的话失败只回一个 false,
		 * "为什么排不进去"就丢了。而排不进去恰恰几乎总是别人干的
		 * (pre_schedule_event 过滤器、schedule_event 被别的插件挡掉),
		 * 那句原话是用户唯一能拿去查的线索,要一路带回界面上。
		 */
		$ok = wp_schedule_single_event( $ts, $hook, $args, true );
		if ( is_wp_error( $ok ) ) {
			self::$last_error = $ok->get_error_message();
			return false;
		}
		if ( false === $ok ) {
			return false;
		}
		if ( $ts <= time() ) {
			self::request_spawn();
		}
		return true;
	}

	/**
	 * 把参数规范成 **cron 能安全存、回调能原样收** 的形状。
	 *
	 * 两件事一起解决:
	 *
	 * 1. **按位置传**。AS 执行动作时用 do_action_ref_array( hook, array_values( args ) ),
	 *    关联数组的键会丢;WP-Cron 则是 do_action_ref_array( hook, args ) 原样展开 ——
	 *    带字符串键的数组在 PHP 8 的 call_user_func_array 里会被当成**具名参数**,
	 *    参数名对不上直接抛错。所以这里一律 array_values(),让两条路径下回调收到的
	 *    参数**位置完全相同**。键的顺序由调用点的数组字面量固定,不会漂。
	 *
	 * 2. **类型钉死成字符串**。WP-Cron 用 md5( serialize( $args ) ) 认事件身份,
	 *    serialize(123) 和 serialize('123') 是两个东西 —— 类型一漂,去重就查不到,
	 *    同一条活会被排两遍。存进 option 再取出来的往返里(尤其是用 JSON 序列化的
	 *    外部对象缓存)整数变字符串是真实发生过的事。全部转成字符串,这一类漂移就不存在了;
	 *    读的一侧本来就在 (int) 强转,收到字符串没有任何差别。
	 */
	private static function cron_args( $args ) {
		$out = array();
		foreach ( (array) $args as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$out[] = $value;   // 不该出现;原样带上,身份至少仍是确定的
				continue;
			}
			$out[] = is_string( $value ) ? $value : (string) $value;
		}
		return $out;
	}

	/** @param array|null $args null = 不限参数 */
	private static function cron_has( $hook, $args ) {
		if ( null === $args ) {
			return self::cron_count( $hook ) > 0;
		}
		return (bool) wp_next_scheduled( (string) $hook, self::cron_args( $args ) );
	}

	/**
	 * 数 cron 数组里该 hook 有多少条事件。
	 *
	 * WP-Cron 没有查询 API,只能自己遍历。结构是 [时间戳][hook][事件签名] => 事件,
	 * 所以同一时间戳下同 hook 的**不同参数**是不同条,必须按第三层的条数累加 ——
	 * 只数时间戳(或只数 hook 出现次数)会把一整批算成一条,任务就会在中途被判"已完成"。
	 */
	private static function cron_count( $hook ) {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return 0;
		}
		$hook  = (string) $hook;
		$total = 0;
		foreach ( $crons as $events ) {
			if ( isset( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
				$total += count( $events[ $hook ] );
			}
		}
		return $total;
	}

	/**
	 * 本次请求内第几条 → 排在什么时候。见 CRON_BURST 的注释。
	 */
	private static function next_cron_slot() {
		$slot = (int) floor( self::$cron_seq / self::cron_burst() ) * self::CRON_STEP;
		++self::$cron_seq;
		return time() + $slot;
	}

	/**
	 * 同一个时间格里放几条 —— **不写死,按本站实测推算**。
	 *
	 * 写死 5 条和本仓库自己的标定数据是矛盾的:AASEO_Probe 给视觉调用的保守默认超时是 90 秒、
	 * cron 路径的批量只给 2,可见单条视觉调用是十几到几十秒的量级。5 条串在同一时间戳上
	 * 就是 50~150 秒,而托管平台常在 30~60 秒硬掐请求(explain() 里就是这么跟用户说的)。
	 * 被掐死的那一条在执行前已被摘出 cron 数组 —— 不会自动重来,只能等下次重新扫候选集。
	 *
	 * 所以按"这个请求还剩多少时间预算"倒推:预算取 ini 值与 60 秒的小者(ini 报 0 = 号称无限,
	 * 那更不能信,按 30 秒算),只用其中 6 成留余量,除以实测单条耗时;没有实测数据时
	 * 按 25 秒/条这个偏保守的假设走。最终不超过 CRON_BURST。
	 */
	private static function cron_burst() {
		$budget = (int) ini_get( 'max_execution_time' );
		$budget = $budget > 0 ? min( $budget, 60 ) : 30;
		$calib  = class_exists( 'AASEO_Probe' ) ? AASEO_Probe::calibration( 'vision' ) : null;
		$per    = $calib ? max( 1, (int) ceil( $calib['median'] ) ) : 25;
		return max( 1, min( self::CRON_BURST, (int) floor( $budget * 0.6 / $per ) ) );
	}

	/**
	 * 确定性抖动:同一条活永远落在同一格,不同的活散在 0~29 分钟内。见 schedule_single()。
	 *
	 * 用散列而不是随机数,是因为重排必须**幂等** —— 同一条活被推迟两次要落在同一秒,
	 * 否则 wp_next_scheduled() 认不出它已经排过,同一条活会在队列里躺两份。
	 */
	private static function cron_jitter( $hook, array $args ) {
		$slots = 30;
		return ( abs( crc32( (string) $hook . '|' . wp_json_encode( $args ) ) ) % $slots ) * self::CRON_STEP;
	}

	/**
	 * 请求结束时踢一次 cron。
	 *
	 * 为什么挂 shutdown 而不是当场踢:当场踢时本请求往往还没把整批排完,而 wp-cron.php
	 * 一进来就对"当前到期的事件"拍了快照 —— 后排进去的那些只能等下一轮。放到 shutdown,
	 * 该排的都排完了,一次就全带走。
	 */
	private static function request_spawn() {
		if ( self::$spawn_hooked ) {
			return;
		}
		self::$spawn_hooked = true;
		add_action( 'shutdown', array( __CLASS__, 'spawn_now' ), 99 );
	}

	/** shutdown 回调:见 request_spawn() */
	public static function spawn_now() {
		if ( ! function_exists( 'spawn_cron' ) ) {
			return;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;   // 已经在 cron 请求里了,再踢一脚是自己踩自己
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;   // 站点改用系统 crontab 驱动,那是管理员的显式决定,不越过它
		}
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return;   // 那条分支会 wp_redirect() + die(),在别人的请求里触发是灾难
		}
		// spawn_cron() 自带 60 秒锁,连着调也不会真的连着发回环请求
		spawn_cron();
	}
}
