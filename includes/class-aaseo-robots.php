<?php
defined( 'ABSPATH' ) || exit;

/**
 * robots.txt 规则与 X-Robots-Tag 头。**机械模块,不调 AI、不花钱、不进队列。**
 *
 * 这个模块的形状是被 Google 官方文档的两句话逼出来的,不是设计偏好:
 *
 *   1. robots.txt 的 Disallow **不能去除收录** —— 被挡的 URL 若有外链,
 *      仍会以"无摘要的裸 URL"出现在结果里。
 *   2. 被 Disallow 的 URL 上的 noindex **永远不会被读到** ——
 *      官方原文:"the crawler will never see the noindex rule"。
 *
 * 合起来意味着:Disallow + noindex 不是双保险,而是**互相抵消**。
 * 想让一个 URL 不被收录,前提是**必须允许抓取**,让爬虫读到 noindex。
 *
 * 所以本模块对联盟跳转链接一条 robots 规则都不写 —— 它的贡献是"保证 noindex 头"
 * 加上"结构性地拒绝写错"(见 conflict_guard)。少写规则不是省事,是正确。
 *
 * @link https://developers.google.com/search/docs/crawling-indexing/block-indexing
 */
class AASEO_Robots {

	/** 输出块的标记,用于幂等:同一个响应里被调用两次不会写两遍 */
	const MARKER_BEGIN = '# BEGIN Auto-AI-SEO';
	const MARKER_END   = '# END Auto-AI-SEO';

	/**
	 * 总共最多输出多少条 Disallow/Allow。
	 *
	 * 这个上限本身就是设计声明:这个接缝**结构上不适合**用来枚举清单
	 * (联盟短链、附件页、参数页……)。同时保护 Google 的 500 KiB 上限。
	 */
	const MAX_RULES = 50;

	/** @var string[] 最近一次构造时被丢弃的路径,供 CLI/后台展示 */
	private static $dropped = array();

	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 20, 1 );
		add_filter( 'wp_headers', array( __CLASS__, 'filter_headers' ), 10, 1 );
		add_filter( 'wp_redirect', array( __CLASS__, 'noindex_external' ), 10, 2 );
	}

	// ---------------------------------------------------------------- robots.txt

	/**
	 * 追加自己的规则块。**只追加,绝不替换 $output。**
	 *
	 * 优先级 20:有些插件在 10 上整个丢弃 $output(本站正要换掉的 smart-seo-tool
	 * 就是这么干的),排在它后面才不会被吞掉。
	 *
	 * 只接 1 个参数:第二个 $public 参数是 (bool) blog_public,而 WordPress.com 的
	 * blog_public 有 1/0/-1 三态,-1 转 bool 反而是 true —— 这个参数会说谎,不要用它。
	 *
	 * 这个函数里不许抛异常、不许查库、不许发 HTTP:/robots.txt 返 5xx 会让 Google
	 * **暂停抓取整站 12 小时**。
	 */
	public static function filter_robots_txt( $output ) {
		// 上游 filter 返回 null/数组是真实发生过的故障形态,先兜住
		if ( ! is_string( $output ) ) {
			$output = '';
		}
		if ( ! AASEO_Options::robots( 'robots_txt' ) ) {
			return $output;
		}
		if ( false !== strpos( $output, self::MARKER_BEGIN ) ) {
			return $output; // 幂等
		}
		$block = self::build_block();
		if ( '' === $block ) {
			return $output;
		}
		// 显式规范化换行:直接拼接会出现 "admin-ajax.phpUser-agent: *"
		return ( '' === trim( $output ) ? '' : rtrim( $output, "\r\n" ) . "\n\n" ) . $block . "\n";
	}

	/**
	 * 构造规则块。始终自带 User-agent 行 —— 绝不输出裸指令。
	 *
	 * 裸的 Disallow 会挂到恰好排在前面的那个组上,而相邻的 user-agent 行会被合并成
	 * 同一组(Google 规范里自己的例子就演示了这一点)。自带 user-agent 行,语义才封闭。
	 */
	private static function build_block() {
		$home_path = self::home_path();
		$rules     = self::collect_rules( $home_path );
		if ( ! $rules ) {
			return '';
		}
		$lines = array( self::MARKER_BEGIN );
		foreach ( $rules as $agent => $paths ) {
			$lines[] = 'User-agent: ' . $agent;
			foreach ( $paths['disallow'] as $p ) {
				$lines[] = 'Disallow: ' . $p;
			}
			foreach ( $paths['allow'] as $p ) {
				$lines[] = 'Allow: ' . $p;
			}
		}
		$lines[] = self::MARKER_END;
		return implode( "\n", $lines );
	}

	/**
	 * 汇总本插件自带规则 + 贡献者规则,过冲突守卫与条数上限。
	 *
	 * @return array agent => array( 'disallow' => string[], 'allow' => string[] )
	 */
	private static function collect_rules( $home_path ) {
		$out = array();
		foreach ( self::own_rules( $home_path ) as $r ) {
			self::merge_rule( $out, $r );
		}

		/**
		 * 额外的 robots.txt 规则。
		 *
		 * 路径必须是主机绝对路径、纯 ASCII、无控制字符。匹配是
		 * **区分大小写的前缀匹配**:'/qapress' 同时也会挡住 '/qapress-new'。
		 * 子目录安装请用 $home_path 前缀。
		 *
		 * **不要**把已经登记进 'aaseo_noindex_paths' 的路径再登记到这里 —— 会被丢弃。
		 * 原因见本类顶部注释:挡了抓取,noindex 就永远读不到。
		 *
		 * @param array  $rules     array( array( 'agent' => '*', 'disallow' => array(), 'allow' => array() ), … )
		 * @param string $home_path 域名根为 '',子目录安装为 '/blog'
		 */
		$contributed = apply_filters( 'aaseo_robots_rules', array(), $home_path );
		foreach ( (array) $contributed as $r ) {
			self::merge_rule( $out, $r );
		}

		return self::enforce_limits( $out );
	}

	/** 本插件自带的规则。目前只有一条 —— 克制不是偷懒。 */
	private static function own_rules( $home_path ) {
		$login = self::login_path();
		if ( '' === $login ) {
			return array();
		}
		/*
		 * 尾部不写 '*':按规范 Disallow 本身就是前缀匹配,'?' 是普通字符,
		 * 那个 '*' 是空操作。一行一个含义。
		 */
		return array(
			array( 'agent' => '*', 'disallow' => array( $login . '?redirect_to=' ) ),
		);
	}

	/**
	 * wp-login.php 的路径。用 site_url() 推导,**不要用 wp_login_url()**。
	 *
	 * 两点都是踩出来的:
	 *
	 * 1. 不能硬编码 '/wp-login.php'。子目录安装、以及"WordPress 装在自己目录里"
	 *    的布局下真实路径是 '/blog/wp-login.php' / '/wp/wp-login.php'。
	 *    site_url() 指向的是**核心所在地址**(home_url() 是站点地址),正是这里要的。
	 *
	 * 2. 不能用 wp_login_url():它会被 login_url filter 改写。会员制、社区、
	 *    安全类插件普遍把它指到自定义前台登录页 —— 实测本站被改成了
	 *    '/login?modal-type=login',而 /wp-login.php 本身依然存在。
	 *    照着它推导会得出"登录页被改名了"的错误结论,该挡的没挡上。
	 *
	 * 顺带也就不会泄露隐藏登录地址:我们输出的永远是标准路径,
	 * 被插件搬走时这条规则只是失效,而不会把新地址公开出去。
	 */
	private static function login_path() {
		return (string) wp_parse_url( site_url( 'wp-login.php' ), PHP_URL_PATH );
	}

	/** 站点相对根路径:域名根为 '',子目录安装为 '/blog' */
	private static function home_path() {
		return rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	}

	private static function merge_rule( array &$out, $rule ) {
		if ( ! is_array( $rule ) ) {
			return;
		}
		$agent = isset( $rule['agent'] ) ? trim( (string) $rule['agent'] ) : '*';
		if ( '' === $agent || preg_match( '/[^\x21-\x7e]/', $agent ) ) {
			return;
		}
		if ( ! isset( $out[ $agent ] ) ) {
			$out[ $agent ] = array( 'disallow' => array(), 'allow' => array() );
		}
		foreach ( array( 'disallow', 'allow' ) as $kind ) {
			foreach ( (array) ( isset( $rule[ $kind ] ) ? $rule[ $kind ] : array() ) as $path ) {
				$path = self::sanitize_path( $path );
				if ( '' !== $path && ! in_array( $path, $out[ $agent ][ $kind ], true ) ) {
					$out[ $agent ][ $kind ][] = $path;
				}
			}
		}
	}

	/** 路径必须以 / 开头、纯 ASCII 可打印、无空白 —— 一个字符不合就整条丢掉 */
	private static function sanitize_path( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' !== $path[0] ) {
			return '';
		}
		if ( preg_match( '/[^\x21-\x7e]/', $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * 过冲突守卫 + 条数上限。
	 *
	 * **冲突守卫是这个模块的招牌安全性质**:任何与 noindex 路径相等、包含或被包含的
	 * Disallow 一律丢弃。这样"既挡抓取又指望 noindex 生效"这个自相矛盾的配置,
	 * 无论贡献者或将来的维护者怎么登记,**都不可能被发布出去**。
	 */
	private static function enforce_limits( array $rules ) {
		$noindex = self::noindex_paths();
		$dropped = array();
		$count   = 0;

		foreach ( $rules as $agent => $paths ) {
			foreach ( array( 'disallow', 'allow' ) as $kind ) {
				$keep = array();
				foreach ( $paths[ $kind ] as $p ) {
					if ( 'disallow' === $kind && self::collides_with_noindex( $p, $noindex ) ) {
						$dropped[] = $p;
						continue;
					}
					if ( $count >= self::MAX_RULES ) {
						$dropped[] = $p;
						continue;
					}
					$keep[] = $p;
					++$count;
				}
				$rules[ $agent ][ $kind ] = $keep;
			}
			if ( ! $rules[ $agent ]['disallow'] && ! $rules[ $agent ]['allow'] ) {
				unset( $rules[ $agent ] );
			}
		}
		self::$dropped = $dropped;
		return $rules;
	}

	/** 最近一次被冲突守卫或条数上限丢弃的路径 */
	public static function dropped_rules() {
		return self::$dropped;
	}

	private static function collides_with_noindex( $path, array $noindex ) {
		$a = strtolower( rtrim( $path, '/' ) );
		foreach ( $noindex as $n ) {
			$b = strtolower( rtrim( $n, '/' ) );
			if ( '' === $a || '' === $b ) {
				continue;
			}
			if ( $a === $b || 0 === strpos( $a, $b ) || 0 === strpos( $b, $a ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- X-Robots-Tag

	/**
	 * 需要带 noindex 的路径。
	 *
	 * **刻意不缓存。** 每个前台请求最多走两次(一次构造 robots 块、一次匹配当前请求),
	 * apply_filters 扫一个几元素的数组是纳秒级开销。
	 *
	 * 曾经在这里放过一个每请求静态缓存,实测发现它会毁掉冲突守卫:早于贡献者注册
	 * filter 的那次调用算出的是**空清单**,一旦落定,后面登记的 noindex 路径就再也
	 * 看不见,于是"Disallow 与 noindex 撞车"这个本该不可能发生的配置又能发出去了。
	 * 一个能被调用顺序静默绕过的安全守卫等于没有守卫 —— 不拿正确性换那点纳秒。
	 */
	private static function noindex_paths() {
		/**
		 * 必须带 X-Robots-Tag: noindex 的路径。
		 *
		 * 每个前台请求都会执行 —— 保持廉价,不要查库、不要发 HTTP。
		 * 结尾带 '/' 表示"**该路径本身**及其下的所有内容"(登记 '/go/' 时 '/go' 也算);
		 * 不带斜杠则为精确匹配。
		 * 比较时忽略大小写、忽略查询串与结尾斜杠。
		 *
		 * @param array $paths 例如 array( '/go/' )
		 */
		$paths = apply_filters( 'aaseo_noindex_paths', array() );
		$clean = array();
		foreach ( (array) $paths as $p ) {
			$p = self::sanitize_path( $p );
			if ( '' !== $p ) {
				$clean[] = $p;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * 给登记过的路径加 noindex 头。
	 *
	 * 用 wp_headers filter 而不是裸 header():走核心记录在册的路径,别人也看得见,
	 * 且不用操心 headers_sent()。(调研到的几个插件全在用裸 header()。)
	 */
	public static function filter_headers( $headers ) {
		if ( ! is_array( $headers ) || ! AASEO_Options::robots( 'noindex_headers' ) ) {
			return $headers;
		}
		if ( is_admin() || ! self::path_needs_noindex( self::current_path() ) ) {
			return $headers;
		}
		if ( ! isset( $headers['X-Robots-Tag'] ) ) {
			$headers['X-Robots-Tag'] = 'noindex';
		}
		return $headers;
	}

	/**
	 * 零配置兜底:任何跳到外部域名的前台重定向都加 noindex。
	 *
	 * 这一条就覆盖了整个联盟短链面 —— 每个 slug、每种大小写、带不带查询串,
	 * 都不用登记。因为它们最终都走 wp_redirect()。
	 *
	 * 这里只能用裸 header():wp_headers 早已发完了,故须自查 headers_sent()。
	 * 只发 noindex 不发 nofollow —— 重定向响应上的 nofollow 没有文档定义的效果,
	 * 链接的 follow 语义属于锚点上的 rel="sponsored"。
	 */
	public static function noindex_external( $location, $status = 302 ) {
		unset( $status );
		if ( ! AASEO_Options::robots( 'noindex_headers' ) || is_admin() || headers_sent() ) {
			return $location;
		}
		$host = strtolower( (string) wp_parse_url( (string) $location, PHP_URL_HOST ) );
		if ( '' === $host || $host === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return $location; // 站内跳转,不管
		}
		header( 'X-Robots-Tag: noindex' );
		return $location;
	}

	private static function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return (string) wp_parse_url( $uri, PHP_URL_PATH );
	}

	private static function path_needs_noindex( $path ) {
		$path = strtolower( rtrim( (string) $path, '/' ) );
		if ( '' === $path ) {
			return false;
		}
		foreach ( self::noindex_paths() as $p ) {
			$needle = strtolower( $p );
			if ( '/' === substr( $p, -1 ) ) {
				if ( 0 === strpos( $path . '/', $needle ) ) {
					return true; // 前缀:该路径及其下所有内容
				}
			} elseif ( $path === rtrim( $needle, '/' ) ) {
				return true; // 精确匹配
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- 自检

	/**
	 * 是否存在物理 robots.txt 文件(存在则 WordPress 的动态规则完全不会被用到)。
	 *
	 * 用 get_home_path() 而不是 ABSPATH:后者是核心目录,在"WordPress 装在自己
	 * 目录里"的布局下是错的。这个函数只在后台可用,故先判断。
	 *
	 * **只报告,绝不写也绝不删。**给"一键生成 robots.txt"的插件会把动态 robots.txt
	 * 永久变成一个静态文件挡在前面 —— 那是帮倒忙。
	 */
	public static function physical_file() {
		if ( ! function_exists( 'get_home_path' ) ) {
			return '';
		}
		$path = get_home_path() . 'robots.txt';
		return file_exists( $path ) ? $path : '';
	}

	/** 预览将要输出的块(供 CLI 与后台展示)。不做 output buffering。 */
	public static function preview() {
		// 先构造,再读 dropped —— 不依赖数组字面量的求值顺序
		$block = self::build_block();
		return array(
			'enabled'       => (bool) AASEO_Options::robots( 'robots_txt' ),
			'headers'       => (bool) AASEO_Options::robots( 'noindex_headers' ),
			'block'         => $block,
			'login_path'    => self::login_path(),
			'home_path'     => self::home_path(),
			'noindex_paths' => self::noindex_paths(),
			'dropped'       => self::dropped_rules(),
			'physical_file' => self::physical_file(),
		);
	}
}
