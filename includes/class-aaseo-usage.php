<?php
defined( 'ABSPATH' ) || exit;

/**
 * 用量记账 + 人民币折算 + 每日配额闸门。
 *
 * 成本以"微元"(百万分之一元)整数存,避免浮点累加误差。
 * 预估优先用本站历史实测均值 —— 别处得来的经验值只做没有历史时的兜底。
 */
class AASEO_Usage {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aaseo_usage';
	}

	/**
	 * 记一次调用(成功或失败都记,失败的 token 为 0 但保留耗时,用于诊断)。
	 *
	 * 本类所有查询都直连本插件自建的用量表:没有对应的 WP API,表名由 $wpdb->prefix 拼出
	 * (非用户输入),且每次调用后数据即变,缓存会立刻失效 —— 故不加对象缓存。
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
	 */
	public static function record( $job, $model, $prompt_tokens, $completion_tokens, $seconds, $ok = true ) {
		global $wpdb;
		$cost = self::cost_micro( $model, $prompt_tokens, $completion_tokens );
		$wpdb->insert( self::table(), array(
			'job'               => (string) $job,
			'model'             => (string) $model,
			'prompt_tokens'     => (int) $prompt_tokens,
			'completion_tokens' => (int) $completion_tokens,
			'cost_micro'        => $cost,
			'seconds'           => round( (float) $seconds, 2 ),
			'ok'                => $ok ? 1 : 0,
			'created_at'        => current_time( 'mysql', true ),
		) );
		return $cost;
	}

	/** 微元 = token × 元每百万token (两个 1e6 相消) */
	public static function cost_micro( $model, $prompt_tokens, $completion_tokens ) {
		$p = AASEO_Options::price( $model );
		return (int) round( $prompt_tokens * $p['in'] + $completion_tokens * $p['out'] );
	}

	public static function format_money( $micro ) {
		$yuan = $micro / 1000000;
		if ( $yuan > 0 && $yuan < 0.01 ) {
			return '¥' . rtrim( rtrim( number_format( $yuan, 4, '.', '' ), '0' ), '.' );
		}
		return '¥' . number_format( $yuan, 2, '.', ',' );
	}

	/** 今日(站点时区)用量 */
	public static function today() {
		global $wpdb;
		$since = get_gmt_from_date( current_time( 'Y-m-d' ) . ' 00:00:00' );
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(prompt_tokens+completion_tokens),0) tokens,
			        COALESCE(SUM(cost_micro),0) micro, COUNT(*) calls
			 FROM $table WHERE created_at >= %s",
			$since
		), ARRAY_A );
		return array(
			'tokens' => (int) $row['tokens'],
			'micro'  => (int) $row['micro'],
			'calls'  => (int) $row['calls'],
		);
	}

	public static function remaining_today() {
		$cap = (int) AASEO_Options::get( 'daily_token_cap' );
		if ( $cap <= 0 ) {
			return PHP_INT_MAX;
		}
		return max( 0, $cap - self::today()['tokens'] );
	}

	public static function cap_reached() {
		return 0 === self::remaining_today();
	}

	/** 按模块/模型汇总(后台账单面板用) */
	public static function summary() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			"SELECT job, model, COUNT(*) calls,
			        SUM(prompt_tokens) pt, SUM(completion_tokens) ct,
			        SUM(cost_micro) micro, AVG(seconds) avg_sec,
			        SUM(CASE WHEN ok=0 THEN 1 ELSE 0 END) fails
			 FROM $table GROUP BY job, model ORDER BY calls DESC"
		);
	}

	/**
	 * 跑前预估。
	 *
	 * @param string $job       模块 slug(用于查该模块的历史实测)
	 * @param string $model     首选模型(按其单价算)
	 * @param int    $count     待处理条数
	 * @param array  $fallback  没有历史时的经验值 array('in'=>, 'out'=>)
	 */
	public static function estimate( $job, $model, $count, $fallback = array() ) {
		global $wpdb;
		$fallback = wp_parse_args( $fallback, array( 'in' => 300, 'out' => 20 ) );
		$table    = self::table();
		$row      = $wpdb->get_row( $wpdb->prepare(
			"SELECT AVG(prompt_tokens) pt, AVG(completion_tokens) ct, COUNT(*) n
			 FROM $table WHERE job=%s AND ok=1",
			$job
		), ARRAY_A );

		$measured = $row && (int) $row['n'] >= 3;
		$in       = $measured ? (float) $row['pt'] : (float) $fallback['in'];
		$out      = $measured ? (float) $row['ct'] : (float) $fallback['out'];
		$micro    = self::cost_micro( $model, $in * $count, $out * $count );
		$price    = AASEO_Options::price( $model );

		return array(
			'count'          => (int) $count,
			'tokens'         => (int) round( ( $in + $out ) * $count ),
			'micro'          => $micro,
			'money'          => self::format_money( $micro ),
			'measured'       => $measured,                  // 基于本站实测 or 默认估值
			'price_verified' => ! empty( $price['verified'] ), // 单价是否已核实
			'per_item'       => array( 'in' => (int) round( $in ), 'out' => (int) round( $out ) ),
		);
	}
}
