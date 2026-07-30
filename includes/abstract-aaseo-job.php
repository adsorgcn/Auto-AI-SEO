<?php
defined( 'ABSPATH' ) || exit;

/**
 * 功能模块基类 —— 骨架的唯一扩展点。
 *
 * 一个 Job 代表一类批量工作(生成图片 alt、重写描述、生成归档页描述……)。
 * 子类只需回答三个问题:
 *   1. 哪些对象需要处理?          → find_candidates()
 *   2. 怎么处理其中一个?           → handle_one()
 *   3. 处理一条大约花多少 token?    → estimate_tokens()  (供跑前预估)
 *
 * 排队 / 断点续跑 / 失败重试 / 每日配额 / 用量记账 / 进度统计,一律由骨架提供,子类不管。
 */
abstract class AASEO_Job {

	/** 机器标识,用作 Action Scheduler 的 group 与 option 键的一部分。仅小写字母与连字符。 */
	abstract public function slug();

	/** 给人看的名字 */
	abstract public function label();

	/** 一句话说明这个模块做什么(显示在后台) */
	public function description() {
		return '';
	}

	/**
	 * 找出待处理对象的 ID 列表。**游标分页,不是 offset 分页。**
	 *
	 * 必须分页取,不要一次返回上万个 ID —— 大站点上会撞 MySQL 查询长度上限也吃内存。
	 *
	 * 为什么是游标而不是 offset:候选集的定义是"还没处理的对象",**处理完就从集合里消失**。
	 * 用 offset 翻页时集合在脚下缩水,offset=200 指向的已经不是原来第 200 条,
	 * 于是每翻一页就漏掉一批;翻到越界还会返回空数组,让入队链误判为"扫完了"而提前收工。
	 * 这是实测踩过的坑:2183 张图只跑了 1200 张。
	 *
	 * 游标分页(keyset pagination)没有这个问题:只要"取排序键小于游标的下一批",
	 * 集合怎么缩水都不影响,每条恰好被访问一次,且游标严格递减 → 必然终止。
	 *
	 * 实现约定:
	 *   · $cursor 为 **0 表示从头开始**(不设上界);
	 *   · 否则只返回排序键 **严格小于** $cursor 的对象;
	 *   · 一律按排序键**降序**返回;
	 *   · 骨架取你返回的最小键作为下一批的游标。
	 *
	 * (哨兵用 0 而不是 PHP_INT_MAX:游标要经 JSON 存进 Action Scheduler 的参数表,
	 *  超大整数在某些平台上往返会变成浮点丢精度。)
	 *
	 * @param int $limit
	 * @param int $cursor 0 = 从头;否则只要键 < 此值的对象
	 * @return int[] 降序
	 */
	abstract public function find_candidates( $limit, $cursor );

	/** 待处理总数(用于进度与跑前预估) */
	abstract public function count_candidates();

	/**
	 * 处理单个对象。
	 *
	 * 返回 WP_Error 时骨架记一次失败并写进 Action Scheduler 日志。**没有自动重试**,
	 * 也不需要:候选集的定义是"还没处理成功的对象",失败的对象仍留在集合里,
	 * 下一次运行自然会再处理它 —— 候选集本身就是重试机制。
	 *
	 * @param int $object_id
	 * @return true|'skipped'|WP_Error
	 */
	abstract public function handle_one( $object_id );

	/** 单条的经验 token 数,用于跑前预估。骨架有实测历史时会优先用实测值。 */
	public function estimate_tokens() {
		return array( 'in' => 300, 'out' => 20 );
	}

	/** 这个模块用视觉模型还是文本模型 */
	public function model_kind() {
		return 'text'; // 'text' | 'vision'
	}

	/** 是否已具备运行条件(如需要 API key) */
	public function is_ready() {
		return AASEO_Options::is_configured();
	}

	// ---------------------------------------------------------------- 骨架提供

	/** Action Scheduler 的 hook 名 */
	final public function hook() {
		return 'aaseo_run_' . str_replace( '-', '_', $this->slug() );
	}

	/** Action Scheduler 的 group 名(便于在 工具→Scheduled Actions 里筛选与批量取消) */
	final public function group() {
		return 'aaseo-' . $this->slug();
	}

	/** 本模块的运行态(排队中/已完成/失败 计数与状态) */
	final public function state() {
		return AASEO_Jobs::state( $this->slug() );
	}
}
