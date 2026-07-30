<?php
defined( 'ABSPATH' ) || exit;

/**
 * META 描述的存取与输出层。
 *
 * 存储:写进一个**可过滤的 postmeta 键**(默认 '_aaseo_description')。
 * 主题若有自己的 SEO 字段(如 JustNews 读 'wpcom_seo_description'),一行 filter
 * 就能把写入目标改成主题原生字段 —— 插件只当"内容供给方",不重造标签输出层,
 * 零冲突、停用插件后描述作为站点数据继续存在。
 *
 * 输出:主题不处理 description 时,由这里在 wp_head 补一个 <meta name="description">。
 * 主题/其它 SEO 插件已输出的站点应关掉(filter 或选项),避免重复标签。
 */
class AASEO_Meta {

	/**
	 * "已判定"标记。它同时是候选集的排除条件:
	 * 没有标记 = 还没看过 → 是候选;判定过(无论好坏)= 有标记 → 不再是候选。
	 * 文章一旦被编辑,save_post 会清掉标记,让它重新进入候选集 —— 内容变了,
	 * 旧判定就作废,这比"定期全量重判"既省钱又及时。
	 */
	const JUDGED_KEY = '_aaseo_desc_judged';

	public static function init() {
		// 优先级 2:排在常规输出前,但让主题(JustNews 用 1)先行 —— 主题输出时这里应当关闭
		add_action( 'wp_head', array( __CLASS__, 'output_tag' ), 2 );
		add_action( 'save_post', array( __CLASS__, 'invalidate' ), 10, 2 );
	}

	/**
	 * 描述写到哪个 postmeta 键。
	 *
	 * 默认私有键 '_aaseo_description'。主题有原生字段时用 filter 改指:
	 *   add_filter( 'aaseo_description_meta_key', fn() => 'wpcom_seo_description' );
	 */
	public static function meta_key() {
		$key = apply_filters( 'aaseo_description_meta_key', '_aaseo_description' );
		$key = is_string( $key ) ? sanitize_key( $key ) : '';
		return '' !== $key ? $key : '_aaseo_description';
	}

	/** 当前生效的描述(只读目标键,不做回落 —— 回落链属于判定逻辑) */
	public static function get( $post_id ) {
		return trim( (string) get_post_meta( (int) $post_id, self::meta_key(), true ) );
	}

	/** 写描述 + 打判定标记,一步完成 */
	public static function save( $post_id, $description, $verdict ) {
		update_post_meta( (int) $post_id, self::meta_key(), $description );
		self::mark_judged( $post_id, $verdict );
	}

	public static function mark_judged( $post_id, $verdict ) {
		update_post_meta( (int) $post_id, self::JUDGED_KEY, $verdict . ':' . time() );
	}

	/**
	 * 输出 <meta name="description">。
	 *
	 * 主题已经输出 description 的站点必须关掉这里(重复的 description 标签是硬伤):
	 *   add_filter( 'aaseo_output_description_tag', '__return_false' );
	 */
	public static function output_tag() {
		if ( ! is_singular() ) {
			return;
		}
		$enabled = (bool) AASEO_Options::job( 'meta', 'output_tag', true );
		if ( ! apply_filters( 'aaseo_output_description_tag', $enabled ) ) {
			return;
		}
		$desc = self::get( get_queried_object_id() );
		if ( '' === $desc ) {
			return;
		}
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}

	/**
	 * 文章被编辑 → 判定作废,重新进入候选集。
	 * update_post_meta 不触发 save_post,所以本插件自己的写入不会造成循环。
	 */
	public static function invalidate( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		delete_post_meta( $post_id, self::JUDGED_KEY );
	}
}
