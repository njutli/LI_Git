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
