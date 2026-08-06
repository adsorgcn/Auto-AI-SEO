<?php
defined( 'ABSPATH' ) || exit;

/**
 * 三层探针 —— 参数由环境实测决定,不写死。
 *
 * 第一层 静态探针:读 ini / 扩展 / cron 模式。便宜,但**会骗人** —— 托管平台上
 *   max_execution_time 常报 0(无限),而实际请求被前置的 nginx 硬掐在 30-60 秒
 *   (图片优化类插件的官方文档也承认这一现象)。故静态值只做粗判。
 *
 * 第二层 标定探针:真跑几条完整链路,量出每条的实际耗时与用量。批大小、超时由此得出,
 *   不由 ini 得出。跨环境的经验值不能直接搬 —— 同一模型在不同网络路径上实测可差数倍。
 *
 * 第三层 运行时自适应:滚动看失败率与降级情况,遇超时/限流自动降速,恢复后回升。
 *   借鉴图片优化插件的 "evasive actions" 思路:先自己想办法绕过失败,而不是一失败
 *   就把问题抛给用户。
 */
class AASEO_Probe {

	const STATIC_OPTION = 'aaseo_probe_static';
	const CALIB_OPTION  = 'aaseo_probe_calibration';
	const HEALTH_OPTION = 'aaseo_probe_health';

	// ---------------------------------------------------------------- 第一层

	public static function static_probe( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_option( self::STATIC_OPTION );
			if ( is_array( $cached ) && isset( $cached['at'] ) ) {
				/*
				 * 这四格**必须每次现算**,不能吃缓存 —— 它们全是零成本的判断,却全都会变,
				 * 而这个 option 写一次就永不过期:
				 *   · queue_driver:装上或停用任何提供 Action Scheduler 的插件(WooCommerce
				 *     最常见)就变了,吃缓存会让环境面板长期显示一个早就不成立的结论;
				 *   · wp_cron_disabled:explain() 正是靠它决定要不要打印"关了 WP-Cron 又没配
				 *     服务器 crontab 就根本不会跑"那句唯一的告警。站点是**后来**才在
				 *     wp-config.php 里 define 常量的话,吃缓存就等于这句告警永远不出现;
				 *   · alternate_wp_cron / is_cli:同理,缓存往往是在一次网页请求里写下的,
				 *     拿它回答"现在是不是跑在 CLI 里"必错。
				 * 顺带把 1.1.0 及更早版本缓存里没有 queue_driver 这一格的情况一起补上。
				 */
				$cached['queue_driver']      = AASEO_Queue::driver();
				$cached['wp_cron_disabled']  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
				$cached['alternate_wp_cron'] = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
				$cached['is_cli']            = defined( 'WP_CLI' ) && WP_CLI;
				return $cached;
			}
		}
		$data = array(
			'at'                  => current_time( 'mysql', true ),
			'php_version'         => PHP_VERSION,
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'memory_bytes'        => wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ),
			'gd'                  => extension_loaded( 'gd' ),
			'imagick'             => extension_loaded( 'imagick' ),
			'curl_multi'          => function_exists( 'curl_multi_init' ),
			'wp_cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_wp_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'is_cli'              => defined( 'WP_CLI' ) && WP_CLI,
			// 实际在用的队列驱动:'as' = 站上有别的插件提供 Action Scheduler;'cron' = WP 自带
			'queue_driver'        => AASEO_Queue::driver(),
			// Jetpack 的 Photon 可在 CDN 端缩放图片 → 免掉本地图像处理,是免费红利
			'photon'              => class_exists( 'Jetpack' ) && function_exists( 'jetpack_photon_url' ),
		);
		update_option( self::STATIC_OPTION, $data, false );
		return $data;
	}

	/**
	 * 执行路径:CLI 最强 → Action Scheduler(回环接力)→ WP-Cron(靠访问触发)。
	 *
	 * 两项都现算,不吃 static_probe 的缓存:is_cli 缓存下来会把"缓存是在网页请求里写的、
	 * 现在跑在 CLI 里"这种最常见的情形一路报错;驱动更是随时会变。这两个都是零成本的判断,
	 * 没有任何缓存的理由。
	 */
	public static function execution_path() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		return 'as' === AASEO_Queue::driver() ? 'scheduler' : 'cron';
	}

	// ---------------------------------------------------------------- 第二层

	/**
	 * 记录一次标定样本(由标定流程在真跑几条后调用)。
	 * 存滚动窗口,取中位数比平均值抗离群 —— 冷启动那一条常是离群值。
	 */
	public static function record_sample( $kind, $seconds, $prompt_tokens, $completion_tokens ) {
		$all             = get_option( self::CALIB_OPTION );
		$all             = is_array( $all ) ? $all : array();
		$list            = isset( $all[ $kind ] ) ? (array) $all[ $kind ] : array();
		$list[]          = array( 's' => round( (float) $seconds, 2 ), 'in' => (int) $prompt_tokens, 'out' => (int) $completion_tokens );
		$all[ $kind ]    = array_slice( $list, -30 );
		update_option( self::CALIB_OPTION, $all, false );
	}

	public static function calibration( $kind ) {
		$all  = get_option( self::CALIB_OPTION );
		$list = ( is_array( $all ) && isset( $all[ $kind ] ) ) ? (array) $all[ $kind ] : array();
		if ( count( $list ) < 3 ) {
			return null;   // 样本太少,不足以作判断
		}
		$secs = wp_list_pluck( $list, 's' );
		sort( $secs );
		$n = count( $secs );
		return array(
			'n'      => $n,
			'median' => $secs[ (int) floor( $n / 2 ) ],
			'p90'    => $secs[ (int) floor( $n * 0.9 ) > $n - 1 ? $n - 1 : (int) floor( $n * 0.9 ) ],
			'max'    => end( $secs ),
			'avg_in' => (int) round( array_sum( wp_list_pluck( $list, 'in' ) ) / $n ),
			'avg_out'=> (int) round( array_sum( wp_list_pluck( $list, 'out' ) ) / $n ),
		);
	}

	/**
	 * 单次调用超时(秒)。有标定数据就按 p90 留 3 倍余量,没有则给一个保守默认。
	 * 不沿用任何别处得来的经验值。
	 */
	public static function timeout_for( $kind ) {
		$calib = self::calibration( $kind );
		if ( $calib ) {
			return (int) max( 20, min( 180, ceil( $calib['p90'] * 3 ) ) );
		}
		return 'vision' === $kind ? 90 : 45;
	}

	/** 每批处理多少条(受运行时健康度调节) */
	public static function batch_size( $kind ) {
		$path = self::execution_path();
		// cron 路径比 AS 弱:wp-cron.php 一次请求要把所有到期事件跑完,而且不给自己留时间预算,
		// 堆太多就会在半路被 PHP 掐死。批量给得比 scheduler 保守。
		$bases = array( 'cli' => 20, 'scheduler' => 5, 'cron' => 2 );
		$base  = isset( $bases[ $path ] ) ? $bases[ $path ] : 1;
		$calib = self::calibration( $kind );
		if ( $calib && $calib['median'] > 8 ) {
			$base = max( 1, (int) floor( $base / 2 ) );   // 单条就慢,批量收窄
		}
		return max( 1, (int) round( $base * self::health_factor( $kind ) ) );
	}

	// ---------------------------------------------------------------- 第三层

	/** 记一次失败(客户端在降级时调用),用于运行时降速判断 */
	public static function note_failure( $kind, $model, $code ) {
		$h              = get_option( self::HEALTH_OPTION );
		$h              = is_array( $h ) ? $h : array();
		$k              = isset( $h[ $kind ] ) ? $h[ $kind ] : array( 'fails' => 0, 'window' => time(), 'last' => '' );
		// 15 分钟滚动窗口
		if ( time() - (int) $k['window'] > 900 ) {
			$k = array( 'fails' => 0, 'window' => time(), 'last' => '' );
		}
		$k['fails'] = (int) $k['fails'] + 1;
		$k['last']  = (string) $code;
		$k['model'] = (string) $model;
		$h[ $kind ] = $k;
		update_option( self::HEALTH_OPTION, $h, false );
	}

	public static function note_success( $kind ) {
		$h = get_option( self::HEALTH_OPTION );
		if ( ! is_array( $h ) || ! isset( $h[ $kind ] ) ) {
			return;
		}
		$h[ $kind ]['fails'] = max( 0, (int) $h[ $kind ]['fails'] - 1 );
		update_option( self::HEALTH_OPTION, $h, false );
	}

	/**
	 * 健康系数 0.25~1.0 —— 近期失败越多,批量越小(自动降速)。
	 * 这是 "evasive action":不报错、不停机,先自己降速绕过去。
	 */
	public static function health_factor( $kind ) {
		$h = get_option( self::HEALTH_OPTION );
		if ( ! is_array( $h ) || ! isset( $h[ $kind ]['fails'] ) ) {
			return 1.0;
		}
		$fails = (int) $h[ $kind ]['fails'];
		if ( $fails <= 2 ) {
			return 1.0;
		}
		if ( $fails <= 5 ) {
			return 0.5;
		}
		return 0.25;
	}

	public static function health( $kind ) {
		$h = get_option( self::HEALTH_OPTION );
		return ( is_array( $h ) && isset( $h[ $kind ] ) ) ? $h[ $kind ] : array( 'fails' => 0, 'last' => '' );
	}

	// ---------------------------------------------------------------- 呈现

	/**
	 * "本站关了 WP-Cron"的那句告警。
	 *
	 * 单独拎出来是因为**两个地方要说同一句话**:环境面板(explain())和刚点下"开始"时的
	 * 运行态提示(AASEO_Jobs::start())。同一个字面量只留一份,翻译也就只有一条。
	 */
	public static function cron_disabled_warning() {
		return __( 'WP-Cron is switched off on this site (DISABLE_WP_CRON). Background batches only advance when your server cron calls wp-cron.php — if no server cron is set up, they will not run at all. Use WP-CLI instead.', 'ilang-auto-ai-seo' );
	}

	/**
	 * 每个自动推导出的参数都配一句"为什么是这个值" ——
	 * 设置页把探测结论讲给用户听,而不是甩一堆数字让他猜。
	 */
	public static function explain( $kind ) {
		$s     = self::static_probe();
		$calib = self::calibration( $kind );
		$path  = self::execution_path();
		$out   = array();

		$paths = array(
			'cli'       => __( 'WP-CLI available — batches run with no web timeout. Strongest path.', 'ilang-auto-ai-seo' ),
			'scheduler' => __( 'Running through Action Scheduler: work continues across requests via loopback, so a web timeout cannot stop a batch.', 'ilang-auto-ai-seo' ),
			'cron'      => __( "Running through WordPress' own cron. This plugin no longer bundles a queue library; if any plugin on this site provides Action Scheduler (WooCommerce, for example), it is used automatically instead.", 'ilang-auto-ai-seo' ),
		);
		// execution_path() 只会返回上面这三个之一;真出了第四种,宁可少说一句也不编一句
		if ( isset( $paths[ $path ] ) ) {
			$out[] = $paths[ $path ];
		}

		/*
		 * cron 路径的可靠性差在哪儿,如实说 —— 不是"一样快",是"取决于有没有人访问你的站"。
		 * 用户看得见这句,才不会对着一个几小时不动的进度条怀疑插件坏了。
		 */
		if ( 'cron' === $path ) {
			$out[] = __( "WordPress cron is driven by visits to your site, so batches advance a few items at a time and a quiet site advances slowly. Nothing is lost — a batch picks up where it left off. WP-CLI runs the same work at full speed.", 'ilang-auto-ai-seo' );
			if ( ! empty( $s['wp_cron_disabled'] ) ) {
				$out[] = self::cron_disabled_warning();
			}
		}

		if ( 0 === $s['max_execution_time'] ) {
			$out[] = __( 'PHP reports no execution limit, but hosts often cut web requests off in front of PHP — measured timing is used instead of this value.', 'ilang-auto-ai-seo' );
		}
		if ( $calib ) {
			$out[] = sprintf(
				/* translators: 1: sample count, 2: median seconds, 3: timeout seconds */
				__( 'Measured on this site: %1$d samples, median %2$ss per item — per-call timeout set to %3$ss.', 'ilang-auto-ai-seo' ),
				$calib['n'], $calib['median'], self::timeout_for( $kind )
			);
		} else {
			$out[] = __( 'No measurements yet — conservative defaults in use. They tighten automatically once a batch has run.', 'ilang-auto-ai-seo' );
		}
		$hf = self::health_factor( $kind );
		if ( $hf < 1.0 ) {
			$out[] = sprintf(
				/* translators: %s: percentage */
				__( 'Recent failures detected — throughput reduced to %s%% until things recover.', 'ilang-auto-ai-seo' ),
				(int) ( $hf * 100 )
			);
		}
		if ( ! empty( $s['photon'] ) ) {
			$out[] = __( 'Jetpack image CDN detected — images are resized on the CDN, saving local processing.', 'ilang-auto-ai-seo' );
		}
		return $out;
	}
}
