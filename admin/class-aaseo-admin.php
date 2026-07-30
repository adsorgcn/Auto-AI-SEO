<?php
defined( 'ABSPATH' ) || exit;

/**
 * 后台界面。
 *
 * 刻意不上前端框架:主流 SEO 插件用 React/Vue 建 SPA,那是团队维护的规模;
 * 一人维护的插件用原生表单 + 少量 JS 才可持续,也让 wp.org 审核面更小。
 */
class AASEO_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'auto-ai-seo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_aaseo_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aaseo_job', array( __CLASS__, 'job_action' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Auto-AI-SEO', 'auto-ai-seo' ),
			__( 'Auto-AI-SEO', 'auto-ai-seo' ),
			self::CAP, self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-search', 81
		);
	}

	private static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	// ---------------------------------------------------------------- 保存

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'auto-ai-seo' ) );
		}
		check_admin_referer( 'aaseo_save' );
		$old = AASEO_Options::all();

		$base_in = isset( $_POST['api_base'] ) ? sanitize_text_field( wp_unslash( $_POST['api_base'] ) ) : '';
		$base    = '' !== $base_in ? esc_url_raw( trim( $base_in ), array( 'https' ) ) : $old['api_base'];
		if ( '' === $base || 0 !== strpos( $base, 'https://' ) ) {
			$base = AASEO_Options::defaults()['api_base']; // 密钥走请求头,只允许 https
		}

		$key_in  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$api_key = '' !== trim( $key_in ) ? trim( $key_in ) : $old['api_key']; // 留空 = 不改

		$cap_in = isset( $_POST['daily_token_cap'] ) ? sanitize_text_field( wp_unslash( $_POST['daily_token_cap'] ) ) : '';
		$cap    = '' === $cap_in ? $old['daily_token_cap'] : max( 0, (int) $cap_in );

		AASEO_Options::set( array(
			'api_key'         => $api_key,
			'api_base'        => $base,
			'daily_token_cap' => $cap,
			'site_context'    => isset( $_POST['site_context'] )
				? sanitize_textarea_field( wp_unslash( $_POST['site_context'] ) ) : $old['site_context'],
			'vision_models'   => self::parse_models( 'vision_models', $old['vision_models'] ),
			'text_models'     => self::parse_models( 'text_models', $old['text_models'] ),
			/*
			 * 两个布尔必须整组保存:
			 * ① 未勾选的 checkbox 根本不出现在 $_POST 里,所以要按 isset 显式落 false;
			 * ② all() 是浅合并 —— 只存其中一个键,另一个键会整个消失(取到 null)。
			 */
			'robots'          => array(
				'robots_txt'      => isset( $_POST['robots_txt'] ),
				'noindex_headers' => isset( $_POST['noindex_headers'] ),
			),
		) );

		wp_safe_redirect( self::url( array( 'saved' => 1 ) ) );
		exit;
	}

	/**
	 * 模型链:每行一个模型 ID。
	 * 仅由 save() 调用,nonce 与权限已在那里校验过。
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private static function parse_models( $field, $fallback ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			return $fallback;
		}
		$raw   = sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) );
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
		$valid = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '#^[\w.\-]+(/[\w.\-]+)*$#', $line ) ) {
				$valid[] = $line;
			}
		}
		return $valid ? $valid : $fallback;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/** 模块的 开始/暂停/恢复/取消 */
	public static function job_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'auto-ai-seo' ) );
		}
		$slug = isset( $_REQUEST['job'] ) ? sanitize_key( wp_unslash( $_REQUEST['job'] ) ) : '';
		$do   = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		check_admin_referer( 'aaseo_job_' . $slug . '_' . $do );

		$registry = AASEO_Jobs::instance();
		$notice   = '';
		switch ( $do ) {
			case 'start':
				$res    = $registry->start( $slug );
				$notice = is_wp_error( $res ) ? $res->get_error_message() : 'started';
				break;
			case 'pause':
				$registry->pause( $slug );
				$notice = 'paused';
				break;
			case 'resume':
				$registry->resume( $slug );
				$notice = 'resumed';
				break;
			case 'cancel':
				$registry->cancel( $slug );
				$notice = 'cancelled';
				break;
		}
		wp_safe_redirect( self::url( array( 'msg' => rawurlencode( $notice ) ) ) );
		exit;
	}

	// ---------------------------------------------------------------- 渲染

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'auto-ai-seo' ) );
		}
		$o     = AASEO_Options::all();
		$today = AASEO_Usage::today();

		// 这些查询参数只是本插件 redirect 回来的状态提示,不改数据,故无需 nonce
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$saved = isset( $_GET['saved'] );
		$msg   = isset( $_GET['msg'] )
			? rawurldecode( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto-AI-SEO', 'auto-ai-seo' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'auto-ai-seo' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( self::notice_label( $msg ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! AASEO_Options::is_configured() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Add an API key below to get started. Nothing runs until you do.', 'auto-ai-seo' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Tasks', 'auto-ai-seo' ); ?></h2>
			<?php self::render_jobs(); ?>

			<h2><?php esc_html_e( 'Usage today', 'auto-ai-seo' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: calls, 2: tokens, 3: money */
					esc_html__( '%1$s calls · %2$s tokens · %3$s', 'auto-ai-seo' ),
					esc_html( number_format_i18n( $today['calls'] ) ),
					esc_html( number_format_i18n( $today['tokens'] ) ),
					esc_html( AASEO_Usage::format_money( $today['micro'] ) )
				);
				if ( (int) $o['daily_token_cap'] > 0 ) {
					echo '<br>';
					printf(
						/* translators: 1: cap, 2: remaining */
						esc_html__( 'Daily cap %1$s tokens — %2$s remaining.', 'auto-ai-seo' ),
						esc_html( number_format_i18n( (int) $o['daily_token_cap'] ) ),
						esc_html( number_format_i18n( AASEO_Usage::remaining_today() ) )
					);
				}
				?>
			</p>

			<h2><?php esc_html_e( 'Environment', 'auto-ai-seo' ); ?></h2>
			<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( AASEO_Probe::explain( 'vision' ) as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>

			<h2><?php esc_html_e( 'Robots & indexing', 'auto-ai-seo' ); ?></h2>
			<?php self::render_robots(); ?>

			<h2><?php esc_html_e( 'Settings', 'auto-ai-seo' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aaseo_save' ); ?>
				<input type="hidden" name="action" value="aaseo_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aaseo-api-key"><?php esc_html_e( 'API key', 'auto-ai-seo' ); ?></label></th>
						<td>
							<input id="aaseo-api-key" type="password" name="api_key" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo esc_attr( $o['api_key'] ? __( 'Saved — leave blank to keep', 'auto-ai-seo' ) : 'sk-...' ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: signup link */
									esc_html__( 'Any OpenAI-compatible provider. No account yet? %s — signing up through that link grants bonus credits to both you and this project, and the free credits go a long way.', 'auto-ai-seo' ),
									'<a href="https://cloud.siliconflow.cn/i/tJXyk0DQ" target="_blank" rel="noopener">' . esc_html__( 'get a key from SiliconFlow', 'auto-ai-seo' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aaseo-api-base"><?php esc_html_e( 'API base URL', 'auto-ai-seo' ); ?></label></th>
						<td><input id="aaseo-api-base" type="url" name="api_base" class="regular-text" value="<?php echo esc_attr( $o['api_base'] ); ?>">
							<p class="description"><?php esc_html_e( 'HTTPS only.', 'auto-ai-seo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="aaseo-vision-models"><?php esc_html_e( 'Vision models', 'auto-ai-seo' ); ?></label></th>
						<td><textarea id="aaseo-vision-models" name="vision_models" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $o['vision_models'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line, in priority order. If one is unavailable the next is used automatically.', 'auto-ai-seo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="aaseo-text-models"><?php esc_html_e( 'Text models', 'auto-ai-seo' ); ?></label></th>
						<td><textarea id="aaseo-text-models" name="text_models" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $o['text_models'] ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="aaseo-daily-cap"><?php esc_html_e( 'Daily token cap', 'auto-ai-seo' ); ?></label></th>
						<td><input id="aaseo-daily-cap" type="number" name="daily_token_cap" min="0" step="10000" class="regular-text"
								value="<?php echo esc_attr( (int) $o['daily_token_cap'] ); ?>">
							<p class="description"><?php esc_html_e( 'Batches stop when the cap is hit and continue the next day. 0 = no cap.', 'auto-ai-seo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="aaseo-site-context"><?php esc_html_e( 'Site context', 'auto-ai-seo' ); ?></label></th>
						<td><textarea id="aaseo-site-context" name="site_context" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Tech review blog; recurring brands: xiaomi, dji, anker', 'auto-ai-seo' ); ?>"><?php echo esc_textarea( $o['site_context'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Describing your niche and recurring proper nouns noticeably improves accuracy.', 'auto-ai-seo' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Robots & indexing', 'auto-ai-seo' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="robots_txt" <?php checked( AASEO_Options::robots( 'robots_txt' ) ); ?>>
									<?php esc_html_e( 'Append rules to robots.txt', 'auto-ai-seo' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Adds to what WordPress and other plugins output — never replaces it.', 'auto-ai-seo' ); ?></p>
								<br>
								<label>
									<input type="checkbox" name="noindex_headers" <?php checked( AASEO_Options::robots( 'noindex_headers' ) ); ?>>
									<?php esc_html_e( 'Send noindex headers on outbound redirects', 'auto-ai-seo' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Keeps redirect endpoints (e.g. affiliate short links) out of search results while staying crawlable — blocking them in robots.txt would hide the noindex from crawlers and backfire.', 'auto-ai-seo' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>
				<p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'auto-ai-seo' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private static function render_jobs() {
		$jobs = AASEO_Jobs::instance()->all();
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'No task modules are active yet.', 'auto-ai-seo' ) . '</p>';
			return;
		}
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		foreach ( array( __( 'Task', 'auto-ai-seo' ), __( 'Pending', 'auto-ai-seo' ), __( 'Progress', 'auto-ai-seo' ), __( 'Status', 'auto-ai-seo' ), __( 'Actions', 'auto-ai-seo' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $jobs as $slug => $job ) {
			$s      = $job->state();
			$status = isset( $s['status'] ) ? $s['status'] : 'idle';
			echo '<tr>';
			echo '<td><strong>' . esc_html( $job->label() ) . '</strong>';
			if ( $job->description() ) {
				echo '<br><span class="description">' . esc_html( $job->description() ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( $job->count_candidates() ) ) . '</td>';
			echo '<td>' . esc_html( sprintf( '%d / %d', (int) $s['done'], (int) $s['queued'] ) );
			if ( (int) $s['failed'] ) {
				/* translators: %d: number of failed items */
				echo ' <span style="color:#b32d2e">' . esc_html( sprintf( __( '(%d failed)', 'auto-ai-seo' ), (int) $s['failed'] ) ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( self::status_label( $status ) );
			if ( ! empty( $s['note'] ) ) {
				echo '<br><span class="description">' . esc_html( $s['note'] ) . '</span>';
			}
			if ( ! empty( $s['skip_reasons'] ) ) {
				$why = AASEO_Jobs::skip_explanations();
				foreach ( (array) $s['skip_reasons'] as $reason => $n ) {
					echo '<br><span class="description">' . esc_html( sprintf(
						/* translators: 1: count, 2: reason */
						__( '%1$d skipped — %2$s', 'auto-ai-seo' ),
						(int) $n,
						isset( $why[ $reason ] ) ? $why[ $reason ] : $reason
					) ) . '</span>';
				}
			}
			if ( ! empty( $s['repairs'] ) ) {
				$why = AASEO_Jobs::repair_explanations();
				foreach ( (array) $s['repairs'] as $reason => $n ) {
					echo '<br><span class="description">' . esc_html( sprintf(
						/* translators: 1: count, 2: reason */
						__( '%1$d rewritten — %2$s', 'auto-ai-seo' ),
						(int) $n,
						isset( $why[ $reason ] ) ? $why[ $reason ] : $reason
					) ) . '</span>';
				}
			}
			echo '</td><td>';
			foreach ( self::actions_for( $status ) as $do => $label ) {
				$url = wp_nonce_url(
					admin_url( 'admin-post.php?action=aaseo_job&job=' . rawurlencode( $slug ) . '&do=' . $do ),
					'aaseo_job_' . $slug . '_' . $do
				);
				printf( '<a class="button button-small" href="%s">%s</a> ', esc_url( $url ), esc_html( $label ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * robots 状态面板。只读 —— 开关在下面的设置表单里,这里是"现在实际发生着什么"。
	 *
	 * 展示原则与任务面板一致:把判断做完再给人看。
	 * 被冲突守卫丢弃的规则要说清"为什么被丢",而不是无声地消失。
	 */
	private static function render_robots() {
		$p = AASEO_Robots::preview();

		if ( '' !== $p['physical_file'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf(
				/* translators: %s: file path */
				__( 'A real robots.txt file exists at %s. The web server serves that file directly, so no WordPress rules (from this or any plugin) apply. Edit or remove the file yourself — this plugin will never touch it.', 'auto-ai-seo' ),
				$p['physical_file']
			) ) . '</p></div>';
		}

		echo '<table class="widefat striped" style="max-width:760px"><tbody>';

		echo '<tr><td>' . esc_html__( 'robots.txt rules', 'auto-ai-seo' ) . '</td><td>'
			. esc_html( $p['enabled'] ? __( 'on', 'auto-ai-seo' ) : __( 'off', 'auto-ai-seo' ) ) . '</td></tr>';
		echo '<tr><td>' . esc_html__( 'noindex headers', 'auto-ai-seo' ) . '</td><td>'
			. esc_html( $p['headers'] ? __( 'on', 'auto-ai-seo' ) : __( 'off', 'auto-ai-seo' ) ) . '</td></tr>';

		echo '<tr><td>' . esc_html__( 'noindex paths', 'auto-ai-seo' ) . '</td><td>';
		if ( $p['noindex_paths'] ) {
			echo '<code>' . implode( '</code> <code>', array_map( 'esc_html', $p['noindex_paths'] ) ) . '</code>';
			echo '<br><span class="description">' . esc_html__( 'Registered by other plugins via the aaseo_noindex_paths filter. These stay crawlable on purpose: crawlers must be able to fetch a URL to see its noindex.', 'auto-ai-seo' ) . '</span>';
		} else {
			echo '<span class="description">' . esc_html__( 'none registered', 'auto-ai-seo' ) . '</span>';
		}
		echo '</td></tr>';

		if ( $p['dropped'] ) {
			echo '<tr><td>' . esc_html__( 'dropped rules', 'auto-ai-seo' ) . '</td><td><code>'
				. implode( '</code> <code>', array_map( 'esc_html', $p['dropped'] ) ) . '</code><br><span class="description" style="color:#b32d2e">'
				. esc_html__( 'These Disallow rules were refused: they overlap a noindex path, and blocking crawl there would hide the noindex from crawlers (or the 50-rule cap was hit).', 'auto-ai-seo' )
				. '</span></td></tr>';
		}

		echo '<tr><td>' . esc_html__( 'appended block', 'auto-ai-seo' ) . '</td><td>';
		if ( '' !== $p['block'] ) {
			echo '<pre style="margin:0;padding:8px;background:#f6f7f7;overflow:auto">' . esc_html( $p['block'] ) . '</pre>';
		} else {
			echo '<span class="description">' . esc_html__( 'nothing (module disabled or no rules)', 'auto-ai-seo' ) . '</span>';
		}
		echo '<span class="description">' . esc_html( sprintf(
			/* translators: %s: robots.txt URL */
			__( 'Live file: %s', 'auto-ai-seo' ),
			home_url( '/robots.txt' )
		) ) . '</span>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/** 操作回执 slug → 人话。URL 里传 slug 是刻意的:链接与语言无关,翻译发生在渲染时。 */
	private static function notice_label( $msg ) {
		$map = array(
			'started'   => __( 'Task started.', 'auto-ai-seo' ),
			'paused'    => __( 'Task paused.', 'auto-ai-seo' ),
			'resumed'   => __( 'Task resumed.', 'auto-ai-seo' ),
			'cancelled' => __( 'Queued actions cancelled.', 'auto-ai-seo' ),
		);
		return isset( $map[ $msg ] ) ? $map[ $msg ] : $msg;
	}

	/** 状态 slug → 人话(slug 是内部值,不能直接喂给用户) */
	private static function status_label( $status ) {
		$map = array(
			'idle'    => __( 'idle', 'auto-ai-seo' ),
			'running' => __( 'running', 'auto-ai-seo' ),
			'paused'  => __( 'paused', 'auto-ai-seo' ),
			'done'    => __( 'done', 'auto-ai-seo' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	private static function actions_for( $status ) {
		if ( 'running' === $status ) {
			return array( 'pause' => __( 'Pause', 'auto-ai-seo' ), 'cancel' => __( 'Cancel', 'auto-ai-seo' ) );
		}
		if ( 'paused' === $status ) {
			return array( 'resume' => __( 'Resume', 'auto-ai-seo' ), 'cancel' => __( 'Cancel', 'auto-ai-seo' ) );
		}
		return array( 'start' => __( 'Start', 'auto-ai-seo' ) );
	}
}
