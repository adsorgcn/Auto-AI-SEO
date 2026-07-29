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
			'action_scheduler'    => function_exists( 'as_enqueue_async_action' ),
			// Jetpack 的 Photon 可在 CDN 端缩放图片 → 免掉本地图像处理,是免费红利
			'photon'              => class_exists( 'Jetpack' ) && function_exists( 'jetpack_photon_url' ),
		);
		update_option( self::STATIC_OPTION, $data, false );
		return $data;
	}

	/** 执行路径:CLI 最强 → AS(cron/loopback) → 受限(前台 nibble) */
	public static function execution_path() {
		$s = self::static_probe();
		if ( ! empty( $s['is_cli'] ) ) {
			return 'cli';
		}
		if ( ! empty( $s['action_scheduler'] ) ) {
			return 'scheduler';
		}
		return 'limited';
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
		$path  = self::execution_path();
		$base  = 'cli' === $path ? 20 : ( 'scheduler' === $path ? 5 : 1 );
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
	 * 每个自动推导出的参数都配一句"为什么是这个值" ——
	 * 设置页把探测结论讲给用户听,而不是甩一堆数字让他猜。
	 */
	public static function explain( $kind ) {
		$s     = self::static_probe();
		$calib = self::calibration( $kind );
		$path  = self::execution_path();
		$out   = array();

		$paths = array(
			'cli'       => __( 'WP-CLI available — batches run with no web timeout. Strongest path.', 'auto-ai-seo' ),
			'scheduler' => __( 'Running through Action Scheduler: work continues across requests via loopback, so a web timeout cannot stop a batch.', 'auto-ai-seo' ),
			'limited'   => __( 'Limited environment — processing a couple of items per request. Consider running via WP-CLI.', 'auto-ai-seo' ),
		);
		$out[] = $paths[ $path ];

		if ( 0 === $s['max_execution_time'] ) {
			$out[] = __( 'PHP reports no execution limit, but hosts often cut web requests off in front of PHP — measured timing is used instead of this value.', 'auto-ai-seo' );
		}
		if ( $calib ) {
			$out[] = sprintf(
				/* translators: 1: sample count, 2: median seconds, 3: timeout seconds */
				__( 'Measured on this site: %1$d samples, median %2$ss per item — per-call timeout set to %3$ss.', 'auto-ai-seo' ),
				$calib['n'], $calib['median'], self::timeout_for( $kind )
			);
		} else {
			$out[] = __( 'No measurements yet — conservative defaults in use. They tighten automatically once a batch has run.', 'auto-ai-seo' );
		}
		$hf = self::health_factor( $kind );
		if ( $hf < 1.0 ) {
			$out[] = sprintf(
				/* translators: %s: percentage */
				__( 'Recent failures detected — throughput reduced to %s%% until things recover.', 'auto-ai-seo' ),
				(int) ( $hf * 100 )
			);
		}
		if ( ! empty( $s['photon'] ) ) {
			$out[] = __( 'Jetpack image CDN detected — images are resized on the CDN, saving local processing.', 'auto-ai-seo' );
		}
		return $out;
	}
}
