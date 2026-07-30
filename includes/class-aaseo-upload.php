<?php
defined( 'ABSPATH' ) || exit;

/**
 * 上传即命名:图片一进媒体库,就按**图片内容**得到文件名、标题和 alt。
 *
 * 这是 alt 模块的上游版本。alt 模块是"事后补",这里是"进门就对":
 * 截图工具吐出的 `Screenshot 2026-07-30 at 11.24.03.png`、微信存下的 `mmexport1690...jpg`,
 * 对读者、搜索引擎、和三年后翻媒体库的你都是零信息。文件名是 URL 的一部分,
 * 一旦发布就固化在正文里,**事后改名要连正文一起改**——所以必须在落盘前定名。
 *
 * 一次视觉调用同时产出三样东西(文件名 / 标题 / alt),不为同一张图付三次钱。
 *
 * 时序上必须同步:文件名要先定,URL 才能生成。因此这里刻意接受一次约 2 秒的等待,
 * 并把超时压到 8 秒——上传本来就在等,但绝不能把编辑器卡死。
 * 拿不到结果就沉默放行(保留原文件名),永不因 AI 失败挡住上传。
 */
class AASEO_Upload {

	/** 命名结果在一次请求内的暂存:prefilter 与 add_attachment 之间传递,避免二次调用 */
	private static $pending = array();

	public static function init() {
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'rename_on_upload' ) );
		// sideload 是另一条独立管道:媒体库"从 URL 导入"、wp media import、
		// 以及将来发文机器人抓图都走它 —— 不挂这条,自动化侧全部漏网(实测踩过)
		add_filter( 'wp_handle_sideload_prefilter', array( __CLASS__, 'rename_on_upload' ) );
		add_action( 'add_attachment', array( __CLASS__, 'apply_meta' ) );
		// 经典编辑器的粘贴上传(原生只支持拖拽,不支持粘贴)
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'paste_script' ) );
	}

	/**
	 * 粘贴即上传:在文章编辑页监听剪贴板,图片直接传媒体库并插入正文。
	 * 服务端这套命名/alt 流水线对它同样生效 —— 粘贴的截图落库时就有正经名字。
	 */
	public static function paste_script( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		wp_register_script( 'aaseo-paste', false, array( 'jquery' ), AASEO_VERSION, true );
		wp_enqueue_script( 'aaseo-paste' );
		wp_add_inline_script( 'aaseo-paste', self::paste_js( array(
			'endpoint' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'post'     => (int) get_the_ID(),
		) ) );
	}

	private static function paste_js( array $cfg ) {
		$json = wp_json_encode( $cfg );
		/*
		 * 拼接而不用 heredoc:wp.org 的 Plugin Check 明确禁止 heredoc 语法
		 * (PluginCheck.CodeAnalysis.Heredoc.NotAllowed)。零依赖(除 jQuery 事件桥),
		 * TinyMCE 可视模式与文本模式都要接住。
		 */
		$js = array(
			'(function () {',
			'	var cfg = {{CFG}};',
			'',
			'	function upload( file, onDone, onFail ) {',
			'		var fd = new FormData();',
			'		// 粘贴内容多为无名 blob,给个机器名形态让服务端知道"该由 AI 命名"',
			'		var ext = ( file.type.split( \'/\' )[1] || \'png\' ).replace( \'jpeg\', \'jpg\' );',
			'		fd.append( \'file\', file, ( file.name && file.name !== \'image.png\' ? file.name : \'pasted-\' + Date.now() + \'.\' + ext ) );',
			'		if ( cfg.post ) { fd.append( \'post\', cfg.post ); }',
			'		var xhr = new XMLHttpRequest();',
			'		xhr.open( \'POST\', cfg.endpoint );',
			'		xhr.setRequestHeader( \'X-WP-Nonce\', cfg.nonce );',
			'		xhr.onload = function () {',
			'			if ( xhr.status >= 200 && xhr.status < 300 ) {',
			'				try { onDone( JSON.parse( xhr.responseText ) ); return; } catch ( e ) {}',
			'			}',
			'			onFail( \'HTTP \' + xhr.status );',
			'		};',
			'		xhr.onerror = function () { onFail( \'network\' ); };',
			'		xhr.send( fd );',
			'	}',
			'',
			'	function imgHtml( media ) {',
			'		var alt = ( media.alt_text || \'\' ).replace( /"/g, \'&quot;\' );',
			'		return \'<img src="\' + media.source_url + \'" alt="\' + alt + \'" class="alignnone size-full wp-image-\' + media.id + \'" />\';',
			'	}',
			'',
			'	function grabImages( ev ) {',
			'		var dt = ev.clipboardData || ( ev.originalEvent && ev.originalEvent.clipboardData );',
			'		if ( ! dt || ! dt.items ) { return []; }',
			'		var files = [];',
			'		for ( var i = 0; i < dt.items.length; i++ ) {',
			'			if ( dt.items[ i ].kind === \'file\' && dt.items[ i ].type.indexOf( \'image/\' ) === 0 ) {',
			'				var f = dt.items[ i ].getAsFile();',
			'				if ( f ) { files.push( f ); }',
			'			}',
			'		}',
			'		return files;',
			'	}',
			'',
			'	// —— 可视模式(TinyMCE):占位符插在光标处,传完原地替换 ——',
			'	if ( window.jQuery ) {',
			'		jQuery( document ).on( \'tinymce-editor-init\', function ( _e, editor ) {',
			'			editor.on( \'paste\', function ( ev ) {',
			'				var files = grabImages( ev );',
			'				if ( ! files.length ) { return; } // 纯文本粘贴不拦',
			'				ev.preventDefault();',
			'				files.forEach( function ( f ) {',
			'					var ph = \'aaseo-ph-\' + Math.random().toString( 36 ).slice( 2 );',
			'					editor.insertContent( \'<span id="\' + ph + \'">⏳ 图片上传中…</span>\' );',
			'					upload( f, function ( media ) {',
			'						var el = editor.dom.get( ph );',
			'						if ( el ) { editor.dom.setOuterHTML( el, imgHtml( media ) ); }',
			'					}, function ( why ) {',
			'						var el = editor.dom.get( ph );',
			'						if ( el ) { editor.dom.setOuterHTML( el, \'<span style="color:#b32d2e">图片上传失败(\' + why + \')</span>\' ); }',
			'					} );',
			'				} );',
			'			} );',
			'		} );',
			'	}',
			'',
			'	// —— 文本模式(textarea#content):在光标处插占位文本,完成后替换 ——',
			'	document.addEventListener( \'paste\', function ( ev ) {',
			'		var ta = ev.target;',
			'		if ( ! ta || ta.id !== \'content\' || ta.tagName !== \'TEXTAREA\' ) { return; }',
			'		var files = grabImages( ev );',
			'		if ( ! files.length ) { return; }',
			'		ev.preventDefault();',
			'		files.forEach( function ( f ) {',
			'			var ph = \'[图片上传中 \' + Math.random().toString( 36 ).slice( 2, 7 ) + \']\';',
			'			var s = ta.selectionStart, e = ta.selectionEnd;',
			'			ta.value = ta.value.slice( 0, s ) + ph + ta.value.slice( e );',
			'			ta.selectionStart = ta.selectionEnd = s + ph.length;',
			'			upload( f, function ( media ) {',
			'				ta.value = ta.value.replace( ph, imgHtml( media ) );',
			'			}, function ( why ) {',
			'				ta.value = ta.value.replace( ph, \'[图片上传失败: \' + why + \']\' );',
			'			} );',
			'		} );',
			'	} );',
			'})();',
		);
		return str_replace( '{{CFG}}', $json, implode( "
", $js ) );
	}

	private static function enabled() {
		return (bool) apply_filters( 'aaseo_name_uploads', AASEO_Options::job( 'upload', 'enabled', true ) );
	}

	/**
	 * 落盘前改名。**任何异常都必须放行原文件** —— 挡住上传比名字难看严重得多。
	 */
	public static function rename_on_upload( $file ) {
		if ( ! self::enabled() || ! AASEO_Options::is_configured() ) {
			return $file;
		}
		$mime = isset( $file['type'] ) ? (string) $file['type'] : '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return $file;
		}
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			return $file;
		}
		$read = self::read_scaled( $tmp, $mime );
		if ( ! $read ) {
			return $file;
		}
		$got = self::describe( $read['base64'], $read['mime'] );
		if ( ! $got ) {
			return $file;
		}

		/*
		 * **alt 与文件名解耦**:alt 的价值与文件名叫什么无关,所以一律生成;
		 * 而文件名和标题只在原名是机器名时才动 —— 人起的名字(含 URL)不碰。
		 */
		$got['rename'] = '' !== $got['slug'] && ! self::looks_intentional( (string) $file['name'] );
		if ( $got['rename'] ) {
			$ext  = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
			$slug = sanitize_title( $got['slug'] );
			if ( '' !== $slug && false === strpos( $slug, '%' ) ) {
				$file['name'] = $slug . ( '' !== $ext ? '.' . $ext : '' );
			} else {
				$got['rename'] = false; // 译名不可用 → 保留原名与原标题
			}
		}

		// 留给 add_attachment 用:此刻还没有附件 ID。
		// 同一请求内上传是串行的(prefilter → 建附件 → 下一个),用完即清,不会串。
		self::$pending['last'] = $got;
		return $file;
	}

	/** 把标题与 alt 落到刚建好的附件上 */
	public static function apply_meta( $attachment_id ) {
		if ( empty( self::$pending['last'] ) ) {
			return;
		}
		$got = self::$pending['last'];
		unset( self::$pending['last'] );

		// 标题只在改名时替换:原名是人起的,WP 由它生成的标题也该保留
		if ( ! empty( $got['rename'] ) && '' !== $got['title'] ) {
			wp_update_post( array( 'ID' => (int) $attachment_id, 'post_title' => $got['title'] ) );
		}
		// alt 一律补(仅在空时写,不覆盖任何已有值)
		if ( '' !== $got['alt'] && '' === trim( (string) get_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $got['alt'] );
		}
	}

	/**
	 * 这个文件名是人有意起的,还是设备/工具吐出来的?
	 *
	 * 判"设备吐的"要靠形态,不是靠穷举品牌:纯数字、时间戳、常见截图前缀、
	 * 十六进制串——这些共同点是"不含可读词"。宁可漏判(保留原名)也不误改人的命名。
	 */
	private static function looks_intentional( $name ) {
		$stem = strtolower( (string) pathinfo( $name, PATHINFO_FILENAME ) );
		$stem = trim( (string) preg_replace( '/[\s_.]+/', '-', $stem ), '-' );
		if ( '' === $stem ) {
			return false;
		}
		/*
		 * 设备/工具吐出来的典型形态。判形态而不是穷举品牌:共同点是"不含可读词"。
		 */
		$machine = array(
			'/^(screenshot|screen-shot|screen|image|img|photo|pic|untitled|capture|snip|snipaste)\b/',
			// 'pasted-' 是本插件粘贴上传自己生成的形态 —— 不列进来会被下面的
			// "含 ≥4 字母拉丁词就算人起的名"判成人名,自己跟自己打架(实测踩过)
			'/^(mmexport|wx-camera|wechat|qq|pasted|paste|clipboard|snipped)/',
			'/^(微信图片|图片|屏幕快照|截图)/u',
			'/^[\d-]{6,}$/',                        // 纯数字/时间戳
			'/^[0-9a-f]{16,}$/',                    // 十六进制串
			'/^(dsc|dscn|img|p|pxl|vid)-?\d+$/',    // 相机命名
		);
		foreach ( $machine as $re ) {
			if ( preg_match( $re, $stem ) ) {
				return false; // 机器名 → 该改
			}
		}
		/*
		 * 其余一律**当作人起的名保留**,只要里面有一处可读内容:
		 *   · 任一段 ≥2 个汉字/假名/韩文(中文一整串本来就不分词,按拉丁"数单词"会误杀);
		 *   · 或任一个 ≥4 字母的拉丁词(paypal、stripe、oracle 都算)。
		 * 这个方向是刻意的:改错人的命名(URL 会变)比留一个平庸名字严重得多。
		 */
		if ( preg_match( '/\p{Han}{2,}|\p{Hiragana}{2,}|\p{Katakana}{2,}|\p{Hangul}{2,}/u', $stem ) ) {
			return true;
		}
		foreach ( (array) preg_split( '/-+/', $stem ) as $w ) {
			if ( preg_match( '/^[a-z]{4,}$/', $w ) ) {
				return true;
			}
		}
		return false;
	}

	/** 读图并缩到视觉模型够用的尺寸(复用 alt 模块那套约束:600px 宽、永不放大) */
	private static function read_scaled( $path, $mime ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			// 没有图像处理库:小图直接原样送,大图放弃(不为一个文件名传几 MB)
			$size = (int) filesize( $path );
			if ( $size > 0 && $size <= 900 * 1024 ) {
				$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				return $raw ? array( 'base64' => base64_encode( $raw ), 'mime' => $mime ) : null;
			}
			return null;
		}
		$sz = $editor->get_size();
		if ( ! empty( $sz['width'] ) && $sz['width'] > 600 ) {
			$editor->resize( 600, null, false );
		}
		$editor->set_quality( 82 );
		$tmp = wp_tempnam( 'aaseo-up' );
		$out = $editor->save( $tmp, $mime );
		if ( is_wp_error( $out ) || empty( $out['path'] ) ) {
			return null;
		}
		$raw = file_get_contents( $out['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $out['path'] );
		if ( $tmp !== $out['path'] ) {
			wp_delete_file( $tmp );
		}
		return $raw ? array( 'base64' => base64_encode( $raw ), 'mime' => isset( $out['mime-type'] ) ? $out['mime-type'] : $mime ) : null;
	}

	/**
	 * 看图 → 得到 描述 / 标题 / 英文 slug。
	 *
	 * **刻意分两次调用,各用其长** —— 这是实测逼出来的结论:
	 * 曾让一次视觉调用输出"三行结构"(slug/标题/alt),同一张图三次上传只有一次
	 * 解析成功,而且成功那次的 alt 还被行切分截断。Captioner 与 VL-Instruct
	 * 都只稳定做一件事:**描述图片**。于是:
	 *   1. 视觉模型只出描述(复用 alt 模块那套已在 2178 张图上验证过的约束);
	 *   2. 文本模型把描述译成英文 slug(wp-ai-slug 给文章标题做的就是这件事)。
	 * 两次都是各自模型的拿手活,加起来约 3 秒,比一次不靠谱的调用值。
	 *
	 * @return array|null array( 'slug','title','alt' )
	 */
	private static function describe( $base64, $mime ) {
		$lang = AASEO_Options::site_language();
		$site = trim( (string) AASEO_Options::get( 'site_context' ) );
		$res  = AASEO_Client::vision(
			$base64,
			$mime,
			( '' !== $site ? 'Site: ' . $site . "
" : '' )
			. "Write the alt text for this image.
Rules:"
			. "
- Name what is actually visible: the page, panel, form fields, values or objects on screen."
			. "
- Start directly with the subject. Never open with \"This image shows\", \"A screenshot of\","
			. ' or any equivalent.'
			. "
- One clause, at most 100 characters. Shorter is better."
			. "
- Output the description only."
			. "
- Write it in {$lang}. This is required even though these instructions are in English.",
			array(
				'job'        => 'upload-describe',
				'max_tokens' => 120,
				// 上传路径上超时必须掐紧:用户在等图片出现,不是在等 AI
				'timeout'    => (int) apply_filters( 'aaseo_upload_timeout', 8 ),
				'system'     => sprintf(
					'You write image alt text in %s. Output only the description itself — no quotes, no explanation, no prefix.',
					$lang
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$desc = AASEO_Client::clean( $res['text'] );
		if ( '' === $desc || mb_strlen( $desc ) < 4 ) {
			return null;
		}

		$slug = self::slug_from( $desc );
		if ( '' === $slug ) {
			// 拿不到能进 URL 的名字 → 文件名保持原样,但描述照样用(alt 有价值)
			return array( 'slug' => '', 'title' => self::title_from( $desc ), 'alt' => self::fit_alt( $desc ) );
		}
		return array( 'slug' => $slug, 'title' => self::title_from( $desc ), 'alt' => self::fit_alt( $desc ) );
	}

	/**
	 * alt 上限:读屏软件会整句朗读,过长反而妨碍无障碍使用。
	 * 这里比 alt 模块更严(120 而非 150):模块有"超长就修补"的补救调用,
	 * 上传路径上没有 —— 与其写一条 120 字的空话堆砌进库,不如留空,
	 * 交给 alt 批量任务用它那套判定+修补重写。
	 */
	private static function fit_alt( $desc ) {
		return mb_strlen( $desc ) > 120 ? '' : $desc;
	}

	/** 标题取描述的第一个短句 —— 不额外花一次调用去"生成标题" */
	private static function title_from( $desc ) {
		$first = preg_split( '/[，,。.;；]/u', $desc );
		$t     = is_array( $first ) && '' !== trim( (string) $first[0] ) ? trim( $first[0] ) : $desc;
		return mb_substr( $t, 0, 80 );
	}

	/**
	 * 描述 → 英文 URL slug。用便宜的文本模型,不指望视觉模型跨语言输出
	 * (实测:它会跟着图片内容的语言走,中文截图就回中文 slug,进不了 URL)。
	 */
	private static function slug_from( $desc ) {
		$res = AASEO_Client::text(
			'You turn descriptions into short English URL slugs. Output only the slug, nothing else.',
			"Turn this image description into a URL slug: 2-4 English words, lowercase,
"
			. "joined by hyphens, ASCII letters and digits only. Output the slug and nothing else.

"
			. mb_substr( $desc, 0, 200 ),
			array(
				'job'        => 'upload-slug',
				'max_tokens' => 24,
				'kind'       => 'text',
				'timeout'    => (int) apply_filters( 'aaseo_upload_timeout', 8 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return '';
		}
		$slug = sanitize_title( AASEO_Client::clean( $res['text'] ) );
		// 译不出英文时 sanitize_title 会给出 %-编码,那种名字进 URL 等于乱码
		if ( '' === $slug || preg_match( '/%[0-9a-f]{2}/i', $slug ) || mb_strlen( $slug ) > 70 ) {
			return '';
		}
		return $slug;
	}
}
