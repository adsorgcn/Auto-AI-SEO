<?php
defined( 'ABSPATH' ) || exit;

/**
 * 结构化数据(JSON-LD)与归档页 canonical。**机械模块,不调 AI。**
 *
 * 只输出 Google 现行文档仍在用的类型:Article / BreadcrumbList / WebSite(+SearchAction)
 * / Organization。**不做 HowTo 和 FAQ** —— 富结果分别于 2023-09 与 2026-06 下线,
 * 输出它们只是给页面加重量(这条查过官方 changelog,不是印象)。
 *
 * canonical 只补主题普遍缺的那块:首页/归档/分页。单篇的 canonical 主题和核心
 * 都会给 —— 我们不去重复,重复的 canonical 比没有更糟。
 */
class AASEO_Schema {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 5 );
	}

	/** 配置组:嵌套数组,与 robots 同款自兜默认值(all() 是浅合并) */
	public static function conf( $key = null, $fallback = null ) {
		$d    = array( 'jsonld' => true, 'archive_canonical' => true );
		$conf = array_merge( $d, (array) AASEO_Options::get( 'schema', array() ) );
		if ( null === $key ) {
			return $conf;
		}
		return array_key_exists( $key, $conf ) ? $conf[ $key ] : $fallback;
	}

	public static function output() {
		self::canonical();
		self::jsonld();
	}

	// ---------------------------------------------------------------- canonical

	/**
	 * 首页/归档/分页的 canonical。分页页规范到**它自己**(page/2 指向 page/2)——
	 * 把分页都指向第一页会让 Google 丢弃深页里的内容链接,这是官方文档明确不建议的。
	 */
	private static function canonical() {
		$enabled = (bool) self::conf( 'archive_canonical' );
		if ( ! apply_filters( 'aaseo_output_archive_canonical', $enabled ) ) {
			return;
		}
		if ( is_singular() || is_admin() || is_search() || is_404() ) {
			return; // 单篇交给主题/核心;搜索与 404 不该有 canonical
		}
		$url = '';
		if ( is_home() && ! is_front_page() ) {
			// "文章页"(page_for_posts):它的规范地址是那张页面自己,不是首页
			$blog = (int) get_option( 'page_for_posts' );
			$url  = $blog ? (string) get_permalink( $blog ) : home_url( '/' );
		} elseif ( is_front_page() ) {
			$url = home_url( '/' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				$url  = is_wp_error( $link ) ? '' : $link;
			}
		}
		if ( '' === $url ) {
			return;
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			global $wp_rewrite;
			// 跟着站点的固定链接形态走:硬编码 /page/N/ 在朴素固定链接下是坏 URL
			if ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->using_permalinks() ) {
				$url = trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $paged . '/';
			} else {
				$url = add_query_arg( 'paged', $paged, $url );
			}
		}
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	}

	// ---------------------------------------------------------------- JSON-LD

	private static function jsonld() {
		$enabled = (bool) self::conf( 'jsonld' );
		if ( ! apply_filters( 'aaseo_output_jsonld', $enabled ) ) {
			return;
		}
		$graphs = array();
		if ( is_front_page() || is_home() ) {
			$graphs[] = self::website();
			$graphs[] = self::organization();
		} elseif ( is_singular( 'post' ) ) {
			$graphs[] = self::article();
			$graphs[] = self::breadcrumbs_singular();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$graphs[] = self::breadcrumbs_term();
		}
		$graphs = array_values( array_filter( $graphs ) );
		if ( ! $graphs ) {
			return;
		}
		/**
		 * 输出前的最后一道过滤 —— 站点可增删节点。
		 *
		 * @param array $graphs JSON-LD 节点数组
		 */
		$graphs = apply_filters( 'aaseo_jsonld_graphs', $graphs );
		foreach ( $graphs as $g ) {
			echo '<script type="application/ld+json">'
				. wp_json_encode( $g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
				. '</script>' . "\n";
		}
	}

	/**
	 * 不带 SearchAction:站内链接搜索框富结果 2024-11 已停止展示 ——
	 * 按本模块"只输出现行文档仍在用的类型"的自家规矩删掉(评审揪出的自相矛盾)。
	 */
	private static function website() {
		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'name'     => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'url'      => home_url( '/' ),
		);
	}

	private static function organization() {
		$org = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'url'      => home_url( '/' ),
		);
		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			$org['logo'] = $icon;
		}
		return $org;
	}

	private static function article() {
		$post = get_post();
		if ( ! $post ) {
			return array();
		}
		$a = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => mb_substr( wp_strip_all_tags( get_the_title( $post ) ), 0, 110 ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => get_permalink( $post ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => wp_strip_all_tags( get_the_author_meta( 'display_name', (int) $post->post_author ) ),
			),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			),
		);
		// 描述用本插件生成的那份 —— 全文理解的产物,不是截断
		$desc = AASEO_Meta::get( $post->ID );
		if ( '' !== $desc ) {
			$a['description'] = $desc;
		}
		$img = get_the_post_thumbnail_url( $post, 'full' );
		if ( ! $img && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $m ) ) {
			$img = $m[1];
		}
		if ( $img ) {
			$a['image'] = $img;
		}
		return $a;
	}

	private static function breadcrumbs_singular() {
		$post = get_post();
		if ( ! $post ) {
			return array();
		}
		$items   = array();
		$items[] = self::crumb( 1, __( 'Home', 'ilang-auto-ai-seo' ), home_url( '/' ) );
		$cats    = get_the_category( $post->ID );
		$pos     = 2;
		if ( $cats && ! is_wp_error( $cats ) ) {
			$link = get_term_link( $cats[0] );
			if ( ! is_wp_error( $link ) ) {
				$items[] = self::crumb( $pos, $cats[0]->name, $link );
				++$pos;
			}
		}
		// 末级是当前页:规范允许省略 item
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => wp_strip_all_tags( get_the_title( $post ) ),
		);
		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	private static function breadcrumbs_term() {
		$term = get_queried_object();
		if ( ! $term || is_wp_error( $term ) || empty( $term->name ) ) {
			return array();
		}
		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(
				self::crumb( 1, __( 'Home', 'ilang-auto-ai-seo' ), home_url( '/' ) ),
				array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => wp_strip_all_tags( $term->name ),
				),
			),
		);
	}

	private static function crumb( $pos, $name, $url ) {
		return array(
			'@type'    => 'ListItem',
			'position' => (int) $pos,
			'name'     => wp_strip_all_tags( (string) $name ),
			'item'     => (string) $url,
		);
	}
}
