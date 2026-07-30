<?php
defined( 'ABSPATH' ) || exit;

/**
 * 配置读写。全部存在单个 option 里,不进 autoload。
 */
class AASEO_Options {

	const KEY = 'aaseo_options';

	/**
	 * 单价表(元/百万 token)。
	 * 服务商公开定价页未列出的模型,一律按表内已知最高价计 —— 宁可高估成本,绝不低估。
	 * 用户可在设置里覆盖任一模型的单价。
	 */
	const PRICES = array(
		'Qwen/Qwen3-Omni-30B-A3B-Captioner' => array( 'in' => 0.60, 'out' => 4.80, 'verified' => false ),
		'Qwen/Qwen3-VL-8B-Instruct'         => array( 'in' => 0.60, 'out' => 4.80, 'verified' => false ),
		'Qwen/Qwen3.5-9B'                   => array( 'in' => 0.60, 'out' => 4.80, 'verified' => false ),
		'Qwen/Qwen3.5-27B'                  => array( 'in' => 0.60, 'out' => 4.80, 'verified' => true ),
		'Qwen/Qwen3.5-35B-A3B'              => array( 'in' => 0.40, 'out' => 3.20, 'verified' => true ),
	);

	public static function defaults() {
		return array(
			'api_key'         => '',
			'api_base'        => 'https://api.siliconflow.cn',
			'site_context'    => '',
			// 模型链:顺序即优先级,前一个失败自动降级到下一个。探针可重排,用户可改。
			'vision_models'   => array(
				'Qwen/Qwen3-Omni-30B-A3B-Captioner',
				'Qwen/Qwen3-VL-8B-Instruct',
			),
			'text_models'     => array(
				'Qwen/Qwen3.5-9B',
				'Qwen/Qwen3.5-27B',
			),
			'daily_token_cap' => 2000000,   // 0 = 不限
			'price_override'  => array(),
			'jobs'            => array(),   // 各模块自己的开关与参数
			// 机械模块(不调 AI)的开关。两个机制彼此独立,所以是两个开关而不是一个。
			'robots'          => array(
				'robots_txt'      => true,
				'noindex_headers' => true,
			),
		);
	}

	public static function all() {
		$saved = get_option( self::KEY );
		return is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function set( array $patch ) {
		$new = array_merge( self::all(), $patch );
		update_option( self::KEY, $new, false );
		return $new;
	}

	/**
	 * robots 模块配置。
	 *
	 * 注意这里自己又 merge 了一次默认值:all() 用的是**浅** array_merge,
	 * 所以只保存了 robots 里一个键时,另一个键会整个消失(取到 null 而不是默认值)。
	 * 凡是嵌套数组的配置都得这样各自兜一层 —— 这是 all() 的既有坑,不是本方法多余。
	 */
	public static function robots( $key = null, $fallback = null ) {
		$d    = self::defaults();
		$conf = array_merge( $d['robots'], (array) self::get( 'robots', array() ) );
		if ( null === $key ) {
			return $conf;
		}
		return array_key_exists( $key, $conf ) ? $conf[ $key ] : $fallback;
	}

	/** 模块级配置 */
	public static function job( $slug, $key = null, $fallback = null ) {
		$jobs = self::get( 'jobs', array() );
		$conf = isset( $jobs[ $slug ] ) && is_array( $jobs[ $slug ] ) ? $jobs[ $slug ] : array();
		if ( null === $key ) {
			return $conf;
		}
		return array_key_exists( $key, $conf ) ? $conf[ $key ] : $fallback;
	}

	public static function is_configured() {
		return '' !== trim( (string) self::get( 'api_key' ) );
	}

	/** 模型链 */
	public static function models( $kind = 'text' ) {
		$chain = self::get( 'vision' === $kind ? 'vision_models' : 'text_models', array() );
		return array_values( array_filter( array_map( 'trim', (array) $chain ) ) );
	}

	/** 某模型单价(用户覆盖优先) */
	public static function price( $model ) {
		$override = self::get( 'price_override', array() );
		if ( isset( $override[ $model ]['in'], $override[ $model ]['out'] ) ) {
			return array(
				'in'       => (float) $override[ $model ]['in'],
				'out'      => (float) $override[ $model ]['out'],
				'verified' => true,
			);
		}
		if ( isset( self::PRICES[ $model ] ) ) {
			return self::PRICES[ $model ];
		}
		$max = array( 'in' => 0.0, 'out' => 0.0, 'verified' => false );
		foreach ( self::PRICES as $p ) {
			$max['in']  = max( $max['in'], $p['in'] );
			$max['out'] = max( $max['out'], $p['out'] );
		}
		return $max;
	}

	/**
	 * 站点语言的自然语言名称,写进提示词让 AI 用站点语言输出。
	 * 这是"同一套代码适配任何语言"的落点 —— 不做逐语言规则表。
	 */
	public static function site_language() {
		$locale = get_locale();
		$map    = array(
			'zh_CN' => '简体中文', 'zh_TW' => '繁體中文', 'zh_HK' => '繁體中文',
			'ja'    => '日本語', 'ko_KR' => '한국어', 'ru_RU' => 'русский',
			'ar'    => 'العربية', 'fa_IR' => 'فارسی', 'he_IL' => 'עברית',
			'es_ES' => 'español', 'fr_FR' => 'français', 'de_DE' => 'Deutsch',
			'pt_BR' => 'português', 'it_IT' => 'italiano', 'tr_TR' => 'Türkçe',
			'id_ID' => 'Bahasa Indonesia', 'vi'    => 'Tiếng Việt', 'th'  => 'ไทย',
			'hi_IN' => 'हिन्दी', 'pl_PL' => 'polski', 'nl_NL' => 'Nederlands',
		);
		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}
		$short = substr( $locale, 0, 2 );
		foreach ( $map as $code => $name ) {
			if ( 0 === strpos( $code, $short ) ) {
				return $name;
			}
		}
		return 'English';
	}

	/**
	 * 站点语言所用文字的 Unicode 属性,用来判断"模型是不是没按站点语言输出"。
	 *
	 * 只覆盖非拉丁文字。拉丁字母语言(西/法/德/葡/意/土/印尼/越/波/荷/英)之间
	 * 无法靠字符集区分 —— 与其用词表猜语种,不如老实返回空值:不做这项判断,
	 * 也就不会把一条正确的西班牙语描述当成"英语跑偏"误杀。
	 *
	 * @return string PCRE 片段;空字符串表示该语言不适用此判断。
	 */
	public static function site_script() {
		$short = strtolower( substr( get_locale(), 0, 2 ) );
		$map   = array(
			'zh' => '\p{Han}',
			'ja' => '\p{Han}|\p{Hiragana}|\p{Katakana}',
			'ko' => '\p{Hangul}',
			'ru' => '\p{Cyrillic}', 'uk' => '\p{Cyrillic}', 'bg' => '\p{Cyrillic}',
			'sr' => '\p{Cyrillic}', 'mk' => '\p{Cyrillic}', 'be' => '\p{Cyrillic}',
			'ar' => '\p{Arabic}', 'fa' => '\p{Arabic}', 'ur' => '\p{Arabic}', 'ps' => '\p{Arabic}',
			'he' => '\p{Hebrew}', 'yi' => '\p{Hebrew}',
			'hi' => '\p{Devanagari}', 'mr' => '\p{Devanagari}', 'ne' => '\p{Devanagari}',
			'bn' => '\p{Bengali}', 'ta' => '\p{Tamil}', 'te' => '\p{Telugu}',
			'kn' => '\p{Kannada}', 'ml' => '\p{Malayalam}', 'gu' => '\p{Gujarati}',
			'pa' => '\p{Gurmukhi}', 'si' => '\p{Sinhala}', 'or' => '\p{Oriya}',
			'th' => '\p{Thai}', 'lo' => '\p{Lao}', 'km' => '\p{Khmer}', 'my' => '\p{Myanmar}',
			'el' => '\p{Greek}', 'ka' => '\p{Georgian}', 'hy' => '\p{Armenian}',
			'am' => '\p{Ethiopic}', 'ti' => '\p{Ethiopic}',
		);
		return isset( $map[ $short ] ) ? $map[ $short ] : '';
	}
}
