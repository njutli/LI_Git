## 5、关键结构

```c
                          [index by chunk]
                           hlist_bl_head
                          ┌──────────────┬──────────┬─────────┬────────────┐
snapshot->pending->table  │              │          │         │ ......     │
snapshot->complete->table └──────┬───────┴──────────┴─────────┴────────────┘
                                 │
                               ┌─┴─┐
                               │   │
                               └─┬─┘
                                 │
                               ┌─┴─┐
             sort by old_chunk │   │
                               └─┬─┘
                                 │                             dm_exception
                               ┌─┴─┐                          ┌─────────┐
                               │   │ ◄────hlist_bl_node ◄─────┤hash_list│
                               └─┬─┘                          │old_chunk│
                                 │                            │new_chunk│
                                 │                            └─────────┘
                                 .
                                 .
                                 .
// 索引函数：dm_lookup_exception()
```

```c
// 以 origin 设备的 block_device 的 hash 作为数组索引
// 每一个数组成员，是一个链表，链表元素是 struct origin 的list_head(同一链表上的origin设备的block_device的hash相同)
// 每个 origin 设备有一个 snapshot 链表，在给某个origin设备第一次注册snapshot时会分配 struct origin 插入链表中，同时当前注册的snapshot会插入snapshot链表中，后续再给这个origin设备注册snapshot时直接插入链表中
       [index by block_device]
             list_head
         ┌──────────────┬──────────┬─────────┬────────────┐
_origins │              │          │         │ ......     │
         └──────┬───────┴──────────┴─────────┴────────────┘
                │
              ┌─┴─┐
              │   │  different block_device(used as origin)
              └─┬─┘  with same hash
                │
              ┌─┴─┐
              │   │  check bdev_equal(o->bdev, origin)
              └─┬─┘  to find exact node
                │                             origin
              ┌─┴─┐                          ┌─────────┐
              │   │ ◄────block_device  ◄─────┤bdev     │
              └─┬─┘                          │hash_list│  ┌───┐  ┌───┐
                │                            │snapshots├──┤   ├──┤   ├───......
                │                            └─────────┘  └───┘  └───┘
                .                                      dm_snapshot
                .
                .
// 索引函数：__lookup_origin
// 每个 origin 可以对应多个 snapshot
```

```c
// 以 origin 设备的底层设备的 block_device 的 hash 作为数组索引
// 每一个数组成员，是一个链表，链表元素是 struct dm_origin 的list_head(同一链表上的origin设备的底层设备的block_device的hash相同)
// 在 origin 设备创建时(origin_ctr)分配 dm_origin，同时关联底层设备；在 origin 设备resume时(origin_resume)插入对应的链表
          [index by block_device]
                list_head
            ┌──────────────┬──────────┬─────────┬────────────┐
_dm_origins │              │          │         │ ......     │
            └──────┬───────┴──────────┴─────────┴────────────┘
                   │
                 ┌─┴─┐
                 │   │  different block_device
                 └─┬─┘  with same hash
                   │
                 ┌─┴─┐
                 │   │  check bdev_equal(o->bdev, origin)
                 └─┬─┘  to find exact node
                   │                             dm_origin
                 ┌─┴─┐                          ┌─────────┐
                 │   │ ◄────block_device  ◄─────┤dev      │     ┌──────────┐
                 └─┬─┘                          │ti       ├────►│dm_target │
                   │                            │hash_list│     └──────────┘
                   │                            └─────────┘
                   .
                   .
                   .
// 索引函数：__lookup_dm_origin
// 每个 dm_origin 只对应一个 dm_target
```


```c
struct dm_snapshot {
	struct rw_semaphore lock;

	struct dm_dev *origin;
	struct dm_dev *cow;

	struct dm_target *ti;

	/* List of snapshots per Origin */
	struct list_head list;

	/*
	 * You can't use a snapshot if this is 0 (e.g. if full).
	 * A snapshot-merge target never clears this.
	 */
	int valid;

	/*
	 * The snapshot overflowed because of a write to the snapshot device.
	 * We don't have to invalidate the snapshot in this case, but we need
	 * to prevent further writes.
	 */
	int snapshot_overflowed;

	/* Origin writes don't trigger exceptions until this is set */
	int active;

	atomic_t pending_exceptions_count;

	spinlock_t pe_allocation_lock;

	/* Protected by "pe_allocation_lock" */
	sector_t exception_start_sequence;

	/* Protected by kcopyd single-threaded callback */
	sector_t exception_complete_sequence;

	/*
	 * A list of pending exceptions that completed out of order.
	 * Protected by kcopyd single-threaded callback.
	 */
	struct rb_root out_of_order_tree;

	mempool_t pending_pool;

    // 两个数组，每个数组元素是一个 dm_exception 链表，根据 old_chunk(即原始设备上的 chunk 偏移) 计算索引
    // dm_exception 记录 old_chunk 和 new_chunk(即 COW 设备上的 chunk 偏移)的映射关系
    // pending：当前映射关系正被用于下IO
    // complete：已有IO利用当前映射关系完成了
	struct dm_exception_table pending;
	struct dm_exception_table complete;

	/*
	 * pe_lock protects all pending_exception operations and access
	 * as well as the snapshot_bios list.
	 */
	spinlock_t pe_lock;

	/* Chunks with outstanding reads */
	spinlock_t tracked_chunk_lock;
	struct hlist_head tracked_chunk_hash[DM_TRACKED_CHUNK_HASH_SIZE];

	/* The on disk metadata handler */
	struct dm_exception_store *store;

	unsigned in_progress;
	struct wait_queue_head in_progress_wait;

	struct dm_kcopyd_client *kcopyd_client;

	/* Wait for events based on state_bits */
	unsigned long state_bits;

	/* Range of chunks currently being merged. */
	chunk_t first_merging_chunk;
	int num_merging_chunks;

	/*
	 * The merge operation failed if this flag is set.
	 * Failure modes are handled as follows:
	 * - I/O error reading the header
	 *   	=> don't load the target; abort.
	 * - Header does not have "valid" flag set
	 *   	=> use the origin; forget about the snapshot.
	 * - I/O error when reading exceptions
	 *   	=> don't load the target; abort.
	 *         (We can't use the intermediate origin state.)
	 * - I/O error while merging
	 *	=> stop merging; set merge_failed; process I/O normally.
	 */
	bool merge_failed:1;

	bool discard_zeroes_cow:1;
	bool discard_passdown_origin:1;

	/*
	 * Incoming bios that overlap with chunks being merged must wait
	 * for them to be committed.
	 */
	struct bio_list bios_queued_during_merge;

	/*
	 * Flush data after merge.
	 */
	struct bio flush_bio;
};

```
## 6、关键函数

### （1）snapshot_resume

```c
static void snapshot_resume(struct dm_target *ti)
{
	struct dm_snapshot *s = ti->private;
	struct dm_snapshot *snap_src = NULL, *snap_dest = NULL, *snap_merging = NULL;
	struct dm_origin *o;
	struct mapped_device *origin_md = NULL;
	bool must_restart_merging = false;

	down_read(&_origins_lock);

	o = __lookup_dm_origin(s->origin->bdev); // 查找当前的 real 设备是否有 snapshot-origin
	if (o)
		origin_md = dm_table_get_md(o->ti->table); // 获取 snapshot-origin 的 mapped_device
	if (!origin_md) { // 获取不到，说明没有 snapshot-origin -- 1. 未创建 snapshot-origin; 2. 已经开始 merge，snapshot-origin 已删除
		(void) __find_snapshots_sharing_cow(s, NULL, NULL, &snap_merging); // 查找 snapshot-merge 设备
		if (snap_merging)
			origin_md = dm_table_get_md(snap_merging->ti->table); // 查到了，则获取 snapshot-merge 设备的 mapped_device
	}
	/*
	 * origin_md:
	 * 1. NULL -- 从未有过 snapshot-origin
	 * 2. snapshot-origin 的 mapped_device
	 * 3. snapshot-merge 的 mapped_device
	 */

	/*
	 * 1. 当前是 snapshot 的 resume 流程
	 *    处理：条件不成立， origin_md 为 NULL(没创建过 snapshot-origin)， 不为 NULL(创建过 snapshot-origin)
	 *    后续：需要 suspend origin，如果正在进行 merging，需要停止 merging，因为在创建 snapshot 的过程中，要保证原始设备不被修改。
	 *          写 snapshot-origin 会修改原始设备数据，merging 也会修改原始设备数据
	 * 2. 当前是 snapshot-merge 的 resume 流程
	 *    处理：条件成立， origin_md 置 NULL
	 *    后续：无需做特殊处理
	 */

	if (origin_md == dm_table_get_md(ti->table))
		origin_md = NULL;
	if (origin_md) {
		if (dm_hold(origin_md))
			origin_md = NULL;
	}

	up_read(&_origins_lock);

	if (origin_md) {
		dm_internal_suspend_fast(origin_md);
		if (snap_merging && test_bit(RUNNING_MERGE, &snap_merging->state_bits)) {
			must_restart_merging = true;
			stop_merge(snap_merging);
		}
	}

	down_read(&_origins_lock);

	(void) __find_snapshots_sharing_cow(s, &snap_src, &snap_dest, NULL);
	/* 
	 * 查找到两个使用相同cow设备的snapshot -- snap_src snap_dest
	 * 当前 resume 流程中的snapshot，必然是 snap_src snap_dest 中的一个
	 * 创建流程中会注册 snapshot，register_snapshot 中会检查使用相同cow设备的snapshot数量，如果达到两个，则注册失败
	 * 当前 snapshot 必然已注册成功，因此必然是上述两个 snapshot 其中一个
	 *
	 * 1、两个 shapshot(结构体) 都是 snapshot(设备类型)
	 * 2、两个 snapshot(结构体)，一个是 snapshot(设备类型)，另一个是 snapshot-merge
	 */

	if (snap_src && snap_dest) {
		down_write(&snap_src->lock);
		down_write_nested(&snap_dest->lock, SINGLE_DEPTH_NESTING);
		__handover_exceptions(snap_src, snap_dest);
		up_write(&snap_dest->lock);
		up_write(&snap_src->lock);
	}

	up_read(&_origins_lock);

	/*
	 * 恢复 merging 进程，resume 之前被 suspend 的 snapshot-origin
	 */
	if (origin_md) {
		if (must_restart_merging)
			start_merge(snap_merging);
		dm_internal_resume_fast(origin_md);
		dm_put(origin_md);
	}

	/* s 可能作为 snap_dest 接收了 snap_src 的 chunk size，因此需要按照 chunk size 大小顺序重新插入链表注册 */
	/* Now we have correct chunk size, reregister */
	reregister_snapshot(s);

	down_write(&s->lock);
	s->active = 1;
	up_write(&s->lock);
}
```

### （2）persistent_prepare_merge

```c
static int persistent_prepare_merge(struct dm_exception_store *store,
				    chunk_t *last_old_chunk,
				    chunk_t *last_new_chunk)
{
	struct pstore *ps = get_info(store);
	struct core_exception ce;
	int nr_consecutive;
	int r;

	/*
	 * When current area is empty, move back to preceding area.
	 */
	/*
	 * 当前 area 的下一个空闲 exception 为0，即 current_area 为空
	 */
	if (!ps->current_committed) {
		/*
		 * Have we finished?
		 */
		/*
		 * 如果当前 area 索引是0，说明 merge 已经完成，没有需要 merge 的内容
		 * 如果当前 area 索引非0，需回到上一个有非空闲 exception 的 area，查找待 merge 的数据
		 */
		if (!ps->current_area)
			return 0;

		ps->current_area--;
		r = area_io(ps, REQ_OP_READ, 0);
		if (r < 0)
			return r;
		/*
		 * 回到上一个 area，该 area 满，下一个空闲 exception 的索引应为 exceptions_per_area
		 */
		ps->current_committed = ps->exceptions_per_area;
	}
	/*
	 * 最后一个待同步的 chunk
	 */
	read_exception(ps, ps->area, ps->current_committed - 1, &ce);
	*last_old_chunk = ce.old_chunk;
	*last_new_chunk = ce.new_chunk;

	/*
	 * Find number of consecutive chunks within the current area,
	 * working backwards.
	 */
	for (nr_consecutive = 1; nr_consecutive < ps->current_committed;
	     nr_consecutive++) {
		read_exception(ps, ps->area,
			       ps->current_committed - 1 - nr_consecutive, &ce);
		if (ce.old_chunk != *last_old_chunk - nr_consecutive ||
		    ce.new_chunk != *last_new_chunk - nr_consecutive)
			break;
	}

	return nr_consecutive;
}
```

### （3）dm_insert_exception -- 插入exception

```c
dm_consecutive_chunk_count & dm_consecutive_chunk_count_inc
// dm_exception->new_chunk 的映射范围与实际 new_chunk 的边界
DM_CHUNK_NUMBER_BITS
// 获取映射长度
dm_consecutive_chunk_count
// 增加映射长度
dm_consecutive_chunk_count_inc

static void dm_insert_exception(struct dm_exception_table *eh,
				struct dm_exception *new_e)
{
	struct hlist_bl_head *l;
	struct hlist_bl_node *pos;
	struct dm_exception *e = NULL;

	l = &eh->table[exception_hash(eh, new_e->old_chunk)];

	/* Add immediately if this table doesn't support consecutive chunks */
	if (!eh->hash_shift)
		goto out;

	/* List is ordered by old_chunk */
	hlist_bl_for_each_entry(e, pos, l, hash_list) {
		/* Insert after an existing chunk? */
        /* 如果待添加的映射的 old_chunk 刚好在某个 exception 的映射范围后一个 */
		/* 则向后扩大已有映射的范围，包含已有映射和新的映射 */
		if (new_e->old_chunk == (e->old_chunk +
					 dm_consecutive_chunk_count(e) + 1) &&
		    new_e->new_chunk == (dm_chunk_number(e->new_chunk) +
					 dm_consecutive_chunk_count(e) + 1)) {
			dm_consecutive_chunk_count_inc(e);
			free_completed_exception(new_e);
			return;
		}

		/* Insert before an existing chunk? */
        /* 同上，向前扩大已有映射的范围 */
		if (new_e->old_chunk == (e->old_chunk - 1) &&
		    new_e->new_chunk == (dm_chunk_number(e->new_chunk) - 1)) {
			dm_consecutive_chunk_count_inc(e);
			e->old_chunk--;
			e->new_chunk--;
			free_completed_exception(new_e);
			return;
		}

		if (new_e->old_chunk < e->old_chunk)
			break;
	}

out:
	if (!e) {
		/*
		 * Either the table doesn't support consecutive chunks or slot
		 * l is empty.
		 */
		hlist_bl_add_head(&new_e->hash_list, l);
	} else if (new_e->old_chunk < e->old_chunk) {
		/* Add before an existing exception */
		hlist_bl_add_before(&new_e->hash_list, &e->hash_list);
	} else {
		/* Add to l's tail: e is the last exception in this slot */
		hlist_bl_add_behind(&new_e->hash_list, &e->hash_list);
	}
}
```

### （4）**dm_lookup_exception -- 查找 exception**

```c
static struct dm_exception *dm_lookup_exception(struct dm_exception_table *et,
						chunk_t chunk)
{
	struct hlist_bl_head *slot;
	struct hlist_bl_node *pos;
	struct dm_exception *e;

	slot = &et->table[exception_hash(et, chunk)];
	hlist_bl_for_each_entry(e, pos, slot, hash_list)
        // 待查找的 chunk 是否在当前 exception 的映射范围内
        // 通过 dm_consecutive_chunk_count(e) 获取当前 exception 的映射长度
		if (chunk >= e->old_chunk &&
		    chunk <= e->old_chunk + dm_consecutive_chunk_count(e))
			return e;

	return NULL;
}
```

### （5） snapshot_merge_map

### snapshot_merge_map

```c
static int snapshot_merge_map(struct dm_target *ti, struct bio *bio)
{
	struct dm_exception *e;
	struct dm_snapshot *s = ti->private;
	int r = DM_MAPIO_REMAPPED;
	chunk_t chunk;

	init_tracked_chunk(bio);

	if (bio->bi_opf & REQ_PREFLUSH) {
		// 如果是对 snapshot 设备下的 flush 则重定向到原始设备
		if (!dm_bio_get_target_bio_nr(bio))
			bio_set_dev(bio, s->origin->bdev);
		// 否则，重定向到 COW 设备
		else
			bio_set_dev(bio, s->cow->bdev);
		return DM_MAPIO_REMAPPED;
	}

	// 不处理 discard 命令
	if (unlikely(bio_op(bio) == REQ_OP_DISCARD)) {
		/* Once merging, discards no longer effect change */
		bio_endio(bio);
		return DM_MAPIO_SUBMITTED;
	}

	chunk = sector_to_chunk(s->store, bio->bi_iter.bi_sector);

	down_write(&s->lock);

	/* Full merging snapshots are redirected to the origin */
	if (!s->valid)
		goto redirect_to_origin;

	/* If the block is already remapped - use that */
	/* 找到了映射 */
	e = dm_lookup_exception(&s->complete, chunk);
	if (e) {
		/* Queue writes overlapping with chunks being merged */
		/* 如果是写IO，并且写的范围在当前正在 merge 的 chunks 内 */
		if (bio_data_dir(bio) == WRITE &&
		    chunk >= s->first_merging_chunk &&
		    chunk < (s->first_merging_chunk +
			     s->num_merging_chunks)) {
			// 将当前写IO重定向到原始设备，并将IO添加到 bios_queued_during_merge 链表中，等 merge 完成后处理
			bio_set_dev(bio, s->origin->bdev);
			bio_list_add(&s->bios_queued_during_merge, bio);
			r = DM_MAPIO_SUBMITTED;
			goto out_unlock;
		}

		// 如果不是写IO，或者写IO的范围不处于当前正在 merge 的 chunks 内
		// 重定向到 COW 设备
		remap_exception(s, e, bio, chunk);

		// 如果是写IO，需要 track 对应 chunk
		if (bio_data_dir(bio) == WRITE)
			track_chunk(s, bio, chunk);
		goto out_unlock;
	}

redirect_to_origin:
	bio_set_dev(bio, s->origin->bdev);

	// 如果是写IO，重定向到对应的 snapshot-origin 设备；否则由上层直接下发到原始设备
	if (bio_data_dir(bio) == WRITE) {
		up_write(&s->lock);
		return do_origin(s->origin, bio, false);
	}

out_unlock:
	up_write(&s->lock);

	return r;
}
```

### （6）__find_snapshots_sharing_cow

snapshot 映射的原始设备和cow设备作为输入，查找 snap_src/snap_dest/snap_merge，返回使用给定cow设备作为自身cow设备的snapshot数量

```c
/*
 * _origins_lock must be held when calling this function.
 * Returns number of snapshots registered using the supplied cow device, plus:
 * snap_src - a snapshot suitable for use as a source of exception handover
 * snap_dest - a snapshot capable of receiving exception handover.
 * snap_merge - an existing snapshot-merge target linked to the same origin.
 *   There can be at most one snapshot-merge target. The parameter is optional.
 *
 * Possible return values and states of snap_src and snap_dest.
 *   0: NULL, NULL  - first new snapshot
 *   1: snap_src, NULL - normal snapshot
 *   2: snap_src, snap_dest  - waiting for handover 创建出 snap_dest，但 snap_dest->active 为0，未开始交接
 *   2: snap_src, NULL - handed over, waiting for old to be deleted snap_dest->active 为1，交接完成，等待删除snap_src
 *   1: NULL, snap_dest - source got destroyed without handover 创建出 snap_dest，未开始交接就删除了snap_src
 */
static int __find_snapshots_sharing_cow(struct dm_snapshot *snap,
					struct dm_snapshot **snap_src,
					struct dm_snapshot **snap_dest,
					struct dm_snapshot **snap_merge)
{
	struct dm_snapshot *s;
	struct origin *o;
	int count = 0;
	int active;

	/* 查找原始设备是否注册过 dm_snapshot */
	o = __lookup_origin(snap->origin->bdev);
	if (!o)
		goto out;

	list_for_each_entry(s, &o->snapshots, list) {
		/* 
		 * snapshot 和 snapshot-merge 会映射到同样的 原始设备 和 cow 设备，
		 * 并且会通过同一个原始设备的hash值以 dm_snapshot 结构体注册到 _dm_origins 链表中 
		 * 通过 dm_target 来判断当前注册的 dm_snapshot 是属于 snapshot 还是 snapshot-merge
		 */
		if (dm_target_is_snapshot_merge(s->ti) && snap_merge)
			*snap_merge = s;
		if (!bdev_equal(s->cow->bdev, snap->cow->bdev))
			continue;

		down_read(&s->lock);
		active = s->active;
		up_read(&s->lock);

		if (active) {
			if (snap_src)
				*snap_src = s;
		} else if (snap_dest)
			*snap_dest = s;

		count++;
	}

out:
	return count;
}
```

### （7）copy_callback -- copy I/O 回调

```c
/*
 * Called when the copy I/O has finished.  kcopyd actually runs
 * this code so don't block.
 */
static void copy_callback(int read_err, unsigned long write_err, void *context)
{
	struct dm_snap_pending_exception *pe = context;
	struct dm_snapshot *s = pe->snap;

	pe->copy_error = read_err || write_err;

	// 当前 dm_snap_pending_exception 之前的 dm_snap_pending_exception 都已经完成
	// exception_sequence 为 n(从 0 开始)，表明当前的 dm_napshot 之前有 n 个 dm_snap_pending_exception 生成
	// 写 snapshot-origin: 创建映射(内存中 dm_snap_pending_exception) --> 从原始设备读数据到内存 --> 从内存拷贝数据到COW --> 映射落盘(COW中)
	// dm_snapshot 管理的映射落盘前，会在此处将 s->exception_complete_sequence++
	// pe->exception_sequence == s->exception_complete_sequence 表明之前 n 个映射(编号0~(n-1))已经落盘，当前处理编号为 n 的映射
	if (pe->exception_sequence == s->exception_complete_sequence) {
		struct rb_node *next;

		s->exception_complete_sequence++;
		complete_exception(pe);

		next = rb_first(&s->out_of_order_tree);
		while (next) {
			pe = rb_entry(next, struct dm_snap_pending_exception,
					out_of_order_node);
			if (pe->exception_sequence != s->exception_complete_sequence)
				break;
			next = rb_next(next);
			s->exception_complete_sequence++;
			rb_erase(&pe->out_of_order_node, &s->out_of_order_tree);
			complete_exception(pe);
			cond_resched();
		}
	} else {
		struct rb_node *parent = NULL;
		struct rb_node **p = &s->out_of_order_tree.rb_node;
		struct dm_snap_pending_exception *pe2;

		while (*p) {
			pe2 = rb_entry(*p, struct dm_snap_pending_exception, out_of_order_node);
			parent = *p;

			BUG_ON(pe->exception_sequence == pe2->exception_sequence);
			if (pe->exception_sequence < pe2->exception_sequence)
				p = &((*p)->rb_left);
			else
				p = &((*p)->rb_right);
		}

		rb_link_node(&pe->out_of_order_node, parent, p);
		rb_insert_color(&pe->out_of_order_node, &s->out_of_order_tree);
	}
	account_end_copy(s);
}
```





## 7、COW 设备

```c
             n: pstore->exceptions_per_area
             m: area index

             ┌──────── area(0)                  ┌────────area(1)                      ┌─────────area(m)
             │                                  │                                     │
             │                                  │                                     │
             │                                  │                                     │
header chunk │                                  │                                     │
       ┌─────▼─────┬─────┬─────────────────┬────▼──────────┬─────────┬───┬────────────▼───┐
       │ 0   │ 1   │ 2   │  ......         │n+1 │m*(n+1)+1 │m*(n+1)+2│...│m*(n+1)+n+1 │...│
       └─────┼─────┴─────┴─────────────────┴────┼──────────┴─────────┴───┴────────────┴───┘
             │                                  │
             └─────────────┬────────────────────┘
                           │
                           ▼
                      n+1 chunks

                                chunk 0: disk_header
                        chunk m*(n+1)+1: map of old_chunk and new_chunk in current area
          chunk m*(n+1)+2 ~ m*(n+1)+n+1: data
// COW 设备磁盘结构
```



```c
              structure of a chunk in memory

   ┌────────────────number of consecutive chunks
   │
   │                     ┌─────────chunk index
   │                     │
┌──▼─┬───────────────────▼────────────────────────┐
│    │                                            │  chunk
└────▲────────────────────────────────────────────┘
     │
     └─────DM_CHUNK_NUMBER_BITS 56


                  __le64
                ┌─────────┬─────────┐
                │old_chunk│new_chunk│ disk_exception
                ├─────────┴─────────┤
                │                   │
                │                   │
                └────┬──────────────┘
                     │
                ┌────▼────┬─────────┬─────┬──────┐
           ┌────►    1    │    2    │ ... │ data │
           │    └─────────┴─────────┴─────┴──────┘
           │
           │
           └──────area(n)
// area 的第一个 chunk 中保存着形式为 disk_exception 的映射
// 每组映射由两个变量 chunk 组成
// 内存中每个 chunk 的低56 bit用于存储 chunk 的索引值，即对应的磁盘位置，高8 bit存储连续的 chunk 数
// 磁盘中 disk_exception 中的 chunk 只存储索引值
```



```c
// COW 设备上的头 disk_header
// 魔术字
#define SNAP_MAGIC 0x70416e53

[root@localhost ~]# lsblk
NAME                    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                       8:0    0   10G  0 disk
├─volumeGroup-base-real 252:1    0    1G  0 lvm
│ ├─volumeGroup-base    252:0    0    1G  0 lvm
│ └─volumeGroup-snap    252:3    0    1G  0 lvm
└─volumeGroup-snap-cow  252:2    0  100M  0 lvm
  └─volumeGroup-snap    252:3    0    1G  0 lvm
sdb                       8:16   0    5G  0 disk
sdc                       8:32   0   20G  0 disk
sdd                       8:48   0   30G  0 disk
sde                       8:64   0  100G  0 disk
sdf                       8:80   0    8M  0 disk
vda                     253:0    0   20G  0 disk /
[root@localhost ~]# hexdump -n 128 /dev/mapper/volumeGroup-snap-cow
0000000 6e53 7041 0001 0000 0001 0000 0008 0000
0000010 0000 0000 0000 0000 0000 0000 0000 0000
*
0000080
[root@localhost ~]#

struct disk_header {
	__le32 magic;
	__le32 valid;
	__le32 version;
	__le32 chunk_size;
} __packed;
```

```c
// 当前一个 chunk 为 8 个 sector，即 8 * 512 = 4096 = 0x1000
[root@localhost ~]# hexdump -n 10240 /dev/mapper/volumeGroup-snap-cow
// 第一个 chunk 存 disk_header
0000000 6e53 7041 0001 0000 0001 0000 0008 0000
0000010 0000 0000 0000 0000 0000 0000 0000 0000
*
// 第二个 chunk 存 映射关系
0001000 0000 0000 0000 0000 0002 0000 0000 0000
0001010 0001 0000 0000 0000 0003 0000 0000 0000
......
// 第三个 chunk 开始存数据
0002000 066d 8a77 a53a 3836 9e6d f3b7 241e 7021
0002010 395e 61bf 4540 0993 93e2 aee7 4d7a 0eb8
......

```

## 8、可选特性

### （1）可选特性：

 **discard_zeroes_cow**：对snapshot下发discard会将cow设备对应区域清零
 **discard_passdown_origin**：对snapshot下发discard将会传递到下层设备

### （2）Persistent配置：

 **P**：快照数据落盘，复位后依然存在
 **PO**：Persistent+Overflow（cow溢出后snapshot不失效，可通过status命令查询溢出状态）（cow溢出后必然有部分数据丢失，此时snapshot不再具有数据恢复的功能）
 **N**：快照数据在内存中，复位后丢失

```
// 确认Persistent配置成PO，溢出后写cow情况
配置 P/PO 在 cow 溢出后写 cow 都会返回 IO 错误
1) PO 模式
写 snapshot 导致 cow 溢出，不会将 snapshot 设置为 invalidate，在溢出后仍可使用该 snapshot 进行 merge
写 snapshot-origin 导致 cow 溢出，将 snapshot 设置为 invalidate
2) P 模式
写 snapshot 导致 cow 溢出，会将 snapshot 设置为 invalidate，在溢出后不可使用该 snapshot 进行 merge
写 snapshot-origin 导致 cow 溢出，将 snapshot 设置为 invalidate
```



## 9、其他

```c
snapshot_ctr
 dm_exception_store_create
  get_type // "P"

snapshot_dtr
 dm_exception_store_destroy

dm_snapshot_init
 dm_exception_store_init
  dm_transient_snapshot_init
  dm_persistent_snapshot_init
   dm_exception_store_type_register // _exception_store_types

dm_snapshot_exit
 dm_exception_store_exit
  dm_persistent_snapshot_exit
   dm_exception_store_type_unregister

// 新增映射关系
alloc_pending_exception
__find_pending_exception
 __insert_pending_exception
  persistent_prepare_exception // s->store->type->prepare_exception
   // e->new_chunk = ps->next_free

track_chunk & stop_tracking_chunk
snapshot(包括 merge)下IO前 track 对应 chunk，IO完成后在 snapshot_end_io 中停止 track
```


```c
[root@localhost ~]# dmsetup status /dev/mapper/snap
0 2097152 snapshot 144/204800 16
[root@localhost ~]#

// 元数据占用空间(sector个数)
16 = (ps->current_area + 1 + NUM_SNAPSHOT_HDR_CHUNKS) * store->chunk_size
ps->current_area: 当前使用的area(从0开始)
NUM_SNAPSHOT_HDR_CHUNKS: 设备头占用的第一个chunk
每个area的第一个chunk存映射，即元数据；
例如当前在使用area0,则area0上的第一个chunk为已使用的元数据空间，当前已使用的元数据空间(chunk数)为1+NUM_SNAPSHOT_HDR_CHUNKS
所以若当前的area为area_n，当前元数据空间为(ps->current_area + 1 + NUM_SNAPSHOT_HDR_CHUNKS) * store->chunk_size
```



# 六、dm-zero

dm-zero 不映射到任何实际设备，写IO会全部丢弃，读IO会返回全0.

```
// 创建 dm-zero 设备 (10T)
root@lilingfeng-virtual-machine:~# dmsetup create zero1 --table "0 21474836480 zero"
root@lilingfeng-virtual-machine:~# lsblk
NAME    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
nvme0n1 259:0    0   20G  0 disk
sdb       8:16   0   20G  0 disk
sr0      11:0    1 1024M  0 rom
zero1   253:0    0   10T  0 dm
sdc       8:32   0   20G  0 disk
sda       8:0    0   60G  0 disk
├─sda2    8:2    0    1K  0 part
├─sda5    8:5    0  975M  0 part [SWAP]
└─sda1    8:1    0   59G  0 part /
root@lilingfeng-virtual-machine:~#

// 将 dm-zero 作为 origin 设备， sdb 作为 cow 设备，创建可保存20G数据的 dm-snapshot 设备 -- 稀疏设备
root@lilingfeng-virtual-machine:~# dmsetup create sparse --table "0 21474836480 snapshot /dev/mapper/zero1 /dev/sdb p 128"
root@lilingfeng-virtual-machine:~# lsblk
NAME     MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
nvme0n1  259:0    0   20G  0 disk
sdb        8:16   0   20G  0 disk
└─sparse 253:1    0   10T  0 dm
sr0       11:0    1 1024M  0 rom
zero1    253:0    0   10T  0 dm
└─sparse 253:1    0   10T  0 dm
sdc        8:32   0   20G  0 disk
sda        8:0    0   60G  0 disk
├─sda2     8:2    0    1K  0 part
├─sda5     8:5    0  975M  0 part [SWAP]
└─sda1     8:1    0   59G  0 part /
root@lilingfeng-virtual-machine:~#

```



# 七、dm-crypt

## 1、使用方式

```c
// 存储 cryptsetup 工具相关数据
./cryptsetup luksFormat -c aes-xts-plain64 /dev/sda
// 创建 dm-crypt 设备
./cryptsetup luksOpen /dev/sda test_sda
mkfs.ext4 /dev/dm-0
mount /dev/dm-0 /mnt/sda

./cryptsetup status test_sda

umount /mnt/sda
./cryptsetup luksClose test_sda

// 系统调用
[2023-09-26 20:05:16]  ioctl(5, DM_DEV_CREATE, {version=4.0.0, data_size=16384, name="test_sda", uuid="CRYPT-LUKS1-0b963457582d4d05820d585e89c2396d-test_sda", flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=305, dev=makedev(252, 0), name="test_sda", uuid="CRYPT-LUKS1-0b963457582d4d05820d585e89c2396d-test_sda", target_count=0, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG}) = 0
[2023-09-26 20:05:16]  ioctl(5, DM_TABLE_LOAD, {version=4.0.0, data_size=16384, data_start=312, dev=makedev(252, 0), target_count=1, flags=DM_EXISTS_FLAG|DM_PERSISTENT_DEV_FLAG|DM_SECURE_DATA_FLAG, {sector_start=0, length=20967424, target_type="crypt", string="aes-xts-plain64 59f9a6b235967195"...}} => {version=4.43.0, data_size=305, data_start=312, dev=makedev(252, 0), name="test_sda", uuid="CRYPT-LUKS1-0b963457582d4d05820d585e89c2396d-test_sda", target_count=0, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_PERSISTENT_DEV_FLAG|DM_INACTIVE_PRESENT_FLAG}) = 0
[2023-09-26 20:05:16]  ioctl(5, DM_DEV_SUSPEND, {version=4.0.0, data_size=16384, name="test_sda", event_nr=4194304, flags=DM_EXISTS_FLAG|DM_SECURE_DATA_FLAG} => {version=4.43.0, data_size=305, dev=makedev(252, 0), name="test_sda", uuid="CRYPT-LUKS1-0b963457582d4d05820d585e89c2396d-test_sda", target_count=1, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG|DM_UEVENT_GENERATED_FLAG}) = 0

// document 示例
dmsetup create crypt1 --table "0 `blockdev --getsz $1` crypt aes-cbc-essiv:sha256 babebabebabebabebabebabebabebabe 0 $1 0"
```


## 2、可选特性

### （1）authenticated encryption

认证加密，为IO增加完整性校验

https://man7.org/linux/man-pages/man8/cryptsetup.8.html

https://www.kernel.org/doc/html/latest/admin-guide/device-mapper/dm-crypt.html

需开启 **CONFIG_BLK_DEV_INTEGRITY**

```
// 示例
./cryptsetup luksFormat -c aes-xts-plain64 --integrity hmac-sha256 /dev/sdc
```


```
commit ef43aa38063a6b2b3c6618e28ab35794f4f1fe29
Author: Milan Broz <gmazyland@gmail.com>
Date:   Wed Jan 4 20:23:54 2017 +0100

    dm crypt: add cryptographic data integrity protection (authenticated encryption)

    Allow the use of per-sector metadata, provided by the dm-integrity
    module, for integrity protection and persistently stored per-sector
    Initialization Vector (IV).  The underlying device must support the
    "DM-DIF-EXT-TAG" dm-integrity profile.

    The per-bio integrity metadata is allocated by dm-crypt for every bio.

    Example of low-level mapping table for various types of use:
     DEV=/dev/sdb
     SIZE=417792

     # Additional HMAC with CBC-ESSIV, key is concatenated encryption key + HMAC key
     SIZE_INT=389952
     dmsetup create x --table "0 $SIZE_INT integrity $DEV 0 32 J 0"
     dmsetup create y --table "0 $SIZE_INT crypt aes-cbc-essiv:sha256 \
     11ff33c6fb942655efb3e30cf4c0fd95f5ef483afca72166c530ae26151dd83b \
     00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff \
     0 /dev/mapper/x 0 1 integrity:32:hmac(sha256)"

     # AEAD (Authenticated Encryption with Additional Data) - GCM with random IVs
     # GCM in kernel uses 96bits IV and we store 128bits auth tag (so 28 bytes metadata space)
     SIZE_INT=393024
     dmsetup create x --table "0 $SIZE_INT integrity $DEV 0 28 J 0"
     dmsetup create y --table "0 $SIZE_INT crypt aes-gcm-random \
     11ff33c6fb942655efb3e30cf4c0fd95f5ef483afca72166c530ae26151dd83b \
     0 /dev/mapper/x 0 1 integrity:28:aead"

     # Random IV only for XTS mode (no integrity protection but provides atomic random sector change)
     SIZE_INT=401272
     dmsetup create x --table "0 $SIZE_INT integrity $DEV 0 16 J 0"
     dmsetup create y --table "0 $SIZE_INT crypt aes-xts-random \
     11ff33c6fb942655efb3e30cf4c0fd95f5ef483afca72166c530ae26151dd83b \
     0 /dev/mapper/x 0 1 integrity:16:none"

     # Random IV with XTS + HMAC integrity protection
     SIZE_INT=377656
     dmsetup create x --table "0 $SIZE_INT integrity $DEV 0 48 J 0"
     dmsetup create y --table "0 $SIZE_INT crypt aes-xts-random \
     11ff33c6fb942655efb3e30cf4c0fd95f5ef483afca72166c530ae26151dd83b \
     00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff \
     0 /dev/mapper/x 0 1 integrity:48:hmac(sha256)"

    Both AEAD and HMAC protection authenticates not only data but also
    sector metadata.

    HMAC protection is implemented through autenc wrapper (so it is
    processed the same way as an authenticated mode).

    In HMAC mode there are two keys (concatenated in dm-crypt mapping
    table).  First is the encryption key and the second is the key for
    authentication (HMAC).  (It is userspace decision if these keys are
    independent or somehow derived.)

    The sector request for AEAD/HMAC authenticated encryption looks like this:
     |----- AAD -------|------ DATA -------|-- AUTH TAG --|
     | (authenticated) | (auth+encryption) |              |
     | sector_LE |  IV |  sector in/out    |  tag in/out  |

    For writes, the integrity fields are calculated during AEAD encryption
    of every sector and stored in bio integrity fields and sent to
    underlying dm-integrity target for storage.

    For reads, the integrity metadata is verified during AEAD decryption of
    every sector (they are filled in by dm-integrity, but the integrity
    fields are pre-allocated in dm-crypt).

    There is also an experimental support in cryptsetup utility for more
    friendly configuration (part of LUKS2 format).

    Because the integrity fields are not valid on initial creation, the
    device must be "formatted".  This can be done by direct-io writes to the
    device (e.g. dd in direct-io mode).  For now, there is available trivial
    tool to do this, see: https://github.com/mbroz/dm_int_tools

    Signed-off-by: Milan Broz <gmazyland@gmail.com>
    Signed-off-by: Ondrej Mosnacek <omosnacek@gmail.com>
    Signed-off-by: Vashek Matyas <matyas@fi.muni.cz>
    Signed-off-by: Mike Snitzer <snitzer@redhat.com>

```



# 八、dm-integrity

额外的per-sector tags用于存储完整性信息

写扇区和 integrity tag 必须是原子的（使用日志保证，扇区数据和完整性标签写入一个journal，提交该journal，然后将数据和完整性标签复制到各自的位置）

dm-integrity + dm-crypt
dm-crypt加密数据后通过dm-integrity落盘，若加密设备的数据被修改，不会由dm-crypt返回随机数据，而是由dm-integrity返回IO错误

使用位图(bitmap)替代日志(journal)，如果位图中的某个位为1，则对应区域的数据和完整性标记不同步-如果机器崩溃，未同步的区域将被重新计算

加载target：
1) 超级块包含0，则格式化设备；
2) 超级块不包含0，有效，加载 target；
3) 超级块不包含0，无效，加载 target 失败

第一次使用 target：
1) 超级块清零
2) 加载一个扇区大小的 dm-integrity target，内核驱动将格式化这个设备
3) 卸载 dm-integrity target
4) 从超级块读取 "provided_data_sectors"
5) 加载"provided_data_sectors"大小的 target
6) 如果要使用 dm-crypt 则以"provided_data_sectors"为大小创建

target 参数：
1) 底层设备
2) 保留扇区数
3) integrity tag大小
4) 模式
D - direct writes 无日志，数据和integrity tags各自写入
J - journaled writes 日志写，数据和integrity tags原子写入
B - bitmap模式
R- recovery 模式，日志不重演，checksum不校验，设备不可写
5) 附加参数

状态：
1) 完整性不匹配的数量
2) provided data sectors —— 用户可用sector
3) 当前正在重新计算的位置

块设备分布：
1) 保留扇区
2) 超级块 4k
3) 日志区(拆分成多个section)
section组成：
元数据区域 4K
数据区域
4) tag区和数据区

```
[root@fedora ~]# integritysetup open /dev/sdb test
[root@fedora ~]# lsblk
NAME   MAJ:MIN RM  SIZE RO TYPE  MOUNTPOINT
sda      8:0    0   20G  0 disk
sdb      8:16   0    8M  0 disk
└─test 252:0    0  7.8M  0 crypt
sdc      8:32   0    8M  0 disk
sdd      8:48   0    2G  0 disk
vda    253:0    0   10G  0 disk  /
vdb    253:16   0   30G  0 disk
[root@fedora ~]# echo 11111111111111111 > testfile
[root@fedora ~]# echo 2222222222222222 > testfile2
[root@fedora ~]# dd if=testfile of=/dev/mapper/test oflag=direct
[root@fedora ~]# hexdump -n 1k /dev/mapper/test
0000000 3131 3131 3131 3131 3131 3131 3131 3131
0000010 0a31 0000 0000 0000 0000 0000 0000 0000
0000020 0000 0000 0000 0000 0000 0000 0000 0000
*
0000400
[root@fedora ~]# hexdump -n 1k -s 8k /dev/sdb
0002000 3131 3131 3131 3131 3131 3131 3131 3131
0002010 0a31 0000 0000 0000 0000 0000 0000 0000
0002020 0000 0000 0000 0000 0000 0000 0000 0000
*
00021f0 0000 0000 0000 0000 222a 2222 2222 2222
0002200 0000 0000 0000 0000 0000 0000 0000 0000
*
00023f0 0000 0000 0000 0000 222b 2222 2222 2222
0002400
[root@fedora ~]# hexdump -n 1k -s 220k /dev/sdb
0037000 3131 3131 3131 3131 3131 3131 3131 3131
0037010 0a31 0000 0000 0000 0000 0000 0000 0000
0037020 0000 0000 0000 0000 0000 0000 0000 0000
*
0037400
[root@fedora ~]# dd if=testfile2 of=/dev/sdb bs=1k seek=220 oflag=direct
0+1 records in
0+1 records out
17 bytes copied, 0.0163694 s, 1.0 kB/s
[root@fedora ~]# hexdump -n 1k /dev/mapper/test
[  838.322950] device-mapper: integrity: dm-0: Checksum failed at sector 0x0
[  838.336223] device-mapper: integrity: dm-0: Checksum failed at sector 0x0
[  838.337622] Buffer I/O error on dev dm-0, logical block 0, async page read
hexdump: /dev/mapper/test: Input/output error
[root@fedora ~]#
[root@fedora ~]# dd if=testfile of=/dev/sdb bs=1k seek=220 oflag=direct
0+1 records in
0+1 records out
18 bytes copied, 0.00885483 s, 2.0 kB/s
[root@fedora ~]# hexdump -n 1k /dev/mapper/test
0000000 3131 3131 3131 3131 3131 3131 3131 3131
0000010 0a31 0000 0000 0000 0000 0000 0000 0000
0000020 0000 0000 0000 0000 0000 0000 0000 0000
*
0000400
[root@fedora ~]#


dmsetup create test --table "0 32768 integrity /dev/sdc 0 424 J 0"
dmsetup create test --table "0 8192 integrity /dev/sdb 0 16 J 1 internal_hash:crc32c"
strace -v -s 512 dmsetup create test --table "0 15944 integrity /dev/sdd 0 4 J 3 block_size:512 internal_hash:crc32c fix_padding"

dd if=testfile of=/dev/mapper/test oflag=direct
hexdump -n 1k /dev/mapper/test

hexdump -n 1k -s 8k /dev/sdb
hexdump -n 1k -s 580k /dev/sdb
dd if=testfile2 of=/dev/sdb bs=1k seek=580 oflag=direct


性能影响：


bi_integrity
internal_hash
```



```
strace -v -s 512 integritysetup format /dev/sdd

// dm-integrity 下发 DM_TABLE_LOAD ioctl时需带flag DM_SECURE_DATA_FLAG，dmsetup 无法支持
ioctl(7, DM_TABLE_LOAD, {version=[4, 0, 0], data_size=16384, data_start=312, dev=makedev(0xfc, 0x2), target_count=1, flags=DM_EXISTS_FLAG|DM_PERSISTENT_DEV_FLAG|DM_SECURE_DATA_FLAG, {sector_start=0, length=8, target_type="integrity", string="/dev/sdd 0 4 J 3 block_size:512 internal_hash:crc32c fix_padding"}} => {version=[4, 43, 0], data_size=305, data_start=312, dev=makedev(0xfc, 0x2), name="temporary-cryptsetup-d2a69fdf-5b1e-4148-ac44-87b2e4cb5199", uuid="CRYPT-INTEGRITY-temporary-cryptsetup-d2a69fdf-5b1e-4148-ac44-87b2e4cb5199", target_count=0, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_PERSISTENT_DEV_FLAG|DM_INACTIVE_PRESENT_FLAG}) = 0

strace -v -s 512 integritysetup open /dev/sdd test
```

# 九、dm-verity

https://gitlab.com/cryptsetup/cryptsetup.git

```bash
// 检测安装 popt-devel
rpm -q popt-devel
dnf install popt-devel
```
